<?php
require_once 'config_database.php';

// Vérifier l'authentification
if (!est_connecte()) {
    rediriger('login.php');
}

// Vérifier les permissions
if (!a_la_permission('admin') && !a_la_permission('gestionnaire')) {
    $_SESSION['erreur'] = "Vous n'avez pas la permission de créer des prêts.";
    rediriger('dashboard.php');
}

// Initialiser les variables
$erreurs = [];
$succes = '';
$membres = [];
$plans_pret = [];
$donnees_formulaire = [
    'code_membre' => '',
    'plan_pret_id' => '',
    'montant' => '',
    'duree_mois' => '',
    'motif_pret' => '',
    'garanties_provisees' => ''
];

// Récupérer la liste des membres éligibles - REQUÊTE CORRIGÉE
try {
    $sql_membres = "SELECT m.code_membre, m.nom, m.prenom, m.email, 
                           cm.nom_categorie, e.score_credit, e.cotisation_a_jour,
                           e.dette_actuelle, e.statut as statut_emprunteur,
                           m.date_inscription
                    FROM membres m
                    INNER JOIN categories_membres cm ON m.categorie_id = cm.id
                    INNER JOIN emprunteurs e ON m.code_membre = e.code_membre
                    WHERE m.statut = 'actif' 
                    AND e.statut = 'eligible'
                    ORDER BY m.nom, m.prenom";
    $stmt_membres = $pdo->query($sql_membres);
    $membres = $stmt_membres->fetchAll();
    
    // Debug: Vérifier si des membres sont trouvés
    if (empty($membres)) {
        error_log("Aucun membre éligible trouvé dans la base de données");
    }
} catch (PDOException $e) {
    $erreurs[] = "Erreur lors du chargement des membres: " . $e->getMessage();
    error_log("Erreur SQL membres: " . $e->getMessage());
}

// Récupérer la liste des plans de prêt actifs avec toutes les informations nécessaires
try {
    $sql_plans = "SELECT pp.*, tp.nom_type, tp.couleur,
                         pp.score_credit_min, pp.anciennete_min_mois, pp.cotisation_requise,
                         pp.duree_min_cotisation_mois, pp.montant_min_cotisation_total,
                         pp.autoriser_multiple_prets, pp.categories_membres_json
                  FROM plans_pret pp
                  INNER JOIN types_pret tp ON pp.type_pret_id = tp.id
                  WHERE pp.statut = 'actif'
                  ORDER BY tp.nom_type, pp.nom_pret";
    $stmt_plans = $pdo->query($sql_plans);
    $plans_pret = $stmt_plans->fetchAll();
} catch (PDOException $e) {
    $erreurs[] = "Erreur lors du chargement des plans de prêt: " . $e->getMessage();
    error_log("Erreur SQL plans de prêt: " . $e->getMessage());
}

// Traitement du formulaire
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Récupérer et valider les données
    $donnees_formulaire['code_membre'] = securiser($_POST['code_membre'] ?? '');
    $donnees_formulaire['plan_pret_id'] = securiser($_POST['plan_pret_id'] ?? '');
    $donnees_formulaire['montant'] = floatval($_POST['montant'] ?? 0);
    $donnees_formulaire['duree_mois'] = intval($_POST['duree_mois'] ?? 0);
    $donnees_formulaire['motif_pret'] = securiser($_POST['motif_pret'] ?? '');
    $donnees_formulaire['garanties_provisees'] = securiser($_POST['garanties_provisees'] ?? '');

    // Validation
    if (empty($donnees_formulaire['code_membre'])) {
        $erreurs[] = "Veuillez sélectionner un membre.";
    }

    if (empty($donnees_formulaire['plan_pret_id'])) {
        $erreurs[] = "Veuillez sélectionner un plan de prêt.";
    }

    if (empty($donnees_formulaire['montant']) || $donnees_formulaire['montant'] <= 0) {
        $erreurs[] = "Le montant doit être supérieur à 0.";
    }

    if (empty($donnees_formulaire['duree_mois']) || $donnees_formulaire['duree_mois'] <= 0) {
        $erreurs[] = "La durée doit être supérieure à 0 mois.";
    }

    // Si pas d'erreurs, vérifier l'éligibilité et créer le prêt
    if (empty($erreurs)) {
        try {
            // Récupérer les détails du plan de prêt
            $stmt_plan = $pdo->prepare("
                SELECT pp.*, tp.nom_type, 
                       pp.score_credit_min, pp.anciennete_min_mois, pp.cotisation_requise,
                       pp.duree_min_cotisation_mois, pp.montant_min_cotisation_total,
                       pp.autoriser_multiple_prets, pp.categories_membres_json
                FROM plans_pret pp
                INNER JOIN types_pret tp ON pp.type_pret_id = tp.id
                WHERE pp.id = ?
            ");
            $stmt_plan->execute([$donnees_formulaire['plan_pret_id']]);
            $plan_pret = $stmt_plan->fetch();

            if (!$plan_pret) {
                $erreurs[] = "Plan de prêt non trouvé.";
            } else {
                // Vérifier les limites du plan
                if ($donnees_formulaire['montant'] < $plan_pret['montant_min'] || $donnees_formulaire['montant'] > $plan_pret['montant_max']) {
                    $erreurs[] = "Le montant doit être compris entre " . number_format($plan_pret['montant_min'], 2) . " et " . number_format($plan_pret['montant_max'], 2) . " FBu";
                }

                if ($donnees_formulaire['duree_mois'] < $plan_pret['duree_min_mois'] || $donnees_formulaire['duree_mois'] > $plan_pret['duree_max_mois']) {
                    $erreurs[] = "La durée doit être comprise entre " . $plan_pret['duree_min_mois'] . " et " . $plan_pret['duree_max_mois'] . " mois";
                }

                // Vérifier l'éligibilité du membre selon les critères du plan
                if (empty($erreurs)) {
                    $eligible = true;
                    $message_eligibilite = '';

                    // Vérifier si le membre a déjà un prêt en cours (sauf si autorisé par le plan)
                    if (!$plan_pret['autoriser_multiple_prets']) {
                        $stmt_verif_pret = $pdo->prepare("SELECT COUNT(*) as nb_prets_en_cours FROM prets WHERE code_membre = ? AND statut IN ('en_attente', 'approuve', 'actif')");
                        $stmt_verif_pret->execute([$donnees_formulaire['code_membre']]);
                        $result = $stmt_verif_pret->fetch();
                        
                        if ($result['nb_prets_en_cours'] > 0) {
                            $eligible = false;
                            $message_eligibilite = "Ce membre a déjà un prêt en cours ou en attente d'approbation.";
                        }
                    }

                    // Vérifier le score de crédit minimum
                    if ($eligible && $plan_pret['score_credit_min'] > 0) {
                        $stmt_score = $pdo->prepare("SELECT score_credit FROM emprunteurs WHERE code_membre = ?");
                        $stmt_score->execute([$donnees_formulaire['code_membre']]);
                        $membre_score = $stmt_score->fetch();
                        
                        if ($membre_score && $membre_score['score_credit'] < $plan_pret['score_credit_min']) {
                            $eligible = false;
                            $message_eligibilite = "Score de crédit insuffisant. Minimum requis: " . $plan_pret['score_credit_min'];
                        }
                    }

                    // Vérifier l'ancienneté minimum
                    if ($eligible && $plan_pret['anciennete_min_mois'] > 0) {
                        $stmt_anciennete = $pdo->prepare("
                            SELECT TIMESTAMPDIFF(MONTH, date_inscription, NOW()) as anciennete_mois 
                            FROM membres 
                            WHERE code_membre = ?
                        ");
                        $stmt_anciennete->execute([$donnees_formulaire['code_membre']]);
                        $anciennete = $stmt_anciennete->fetch();
                        
                        if ($anciennete && $anciennete['anciennete_mois'] < $plan_pret['anciennete_min_mois']) {
                            $eligible = false;
                            $message_eligibilite = "Ancienneté insuffisante. Minimum requis: " . $plan_pret['anciennete_min_mois'] . " mois";
                        }
                    }

                    // Vérifier les cotisations - CORRECTION APPLIQUÉE ICI
                    if ($eligible && $plan_pret['cotisation_requise']) {
                        // CORRECTION : Calculer la durée totale couverte par les cotisations
                        $stmt_cotisation = $pdo->prepare("
                            SELECT SUM(periode_mois) as total_mois_cotises,
                                   COUNT(*) as nb_cotisations, 
                                   SUM(montant) as total_cotise
                            FROM cotisations 
                            WHERE code_membre = ? AND statut = 'valide'
                        ");
                        $stmt_cotisation->execute([$donnees_formulaire['code_membre']]);
                        $cotisations = $stmt_cotisation->fetch();
                        
                        // CORRECTION : Vérifier la durée totale des cotisations (en mois)
                        if ($plan_pret['duree_min_cotisation_mois'] > 0) {
                            $total_mois_cotises = intval($cotisations['total_mois_cotises'] ?? 0);
                            
                            if ($total_mois_cotises < $plan_pret['duree_min_cotisation_mois']) {
                                $eligible = false;
                                $message_eligibilite = "Durée totale des cotisations insuffisante. " . 
                                    $total_mois_cotises . " mois cotisés sur " . 
                                    $plan_pret['duree_min_cotisation_mois'] . " mois requis";
                            }
                        }
                        
                        // Vérifier le montant total des cotisations
                        if ($plan_pret['montant_min_cotisation_total'] > 0 && $cotisations['total_cotise'] < $plan_pret['montant_min_cotisation_total']) {
                            $eligible = false;
                            $message_eligibilite = "Montant total des cotisations insuffisant. " . 
                                number_format($cotisations['total_cotise'], 0) . " FBu sur " . 
                                number_format($plan_pret['montant_min_cotisation_total'], 0) . " FBu requis";
                        }
                    }

                    // Vérifier les catégories autorisées
                    if ($eligible && !empty($plan_pret['categories_membres_json'])) {
                        $categories_autorisees = json_decode($plan_pret['categories_membres_json'], true);
                        if (is_array($categories_autorisees) && !empty($categories_autorisees)) {
                            $stmt_categorie = $pdo->prepare("
                                SELECT categorie_id FROM membres WHERE code_membre = ?
                            ");
                            $stmt_categorie->execute([$donnees_formulaire['code_membre']]);
                            $membre_categorie = $stmt_categorie->fetch();
                            
                            if ($membre_categorie && !in_array($membre_categorie['categorie_id'], $categories_autorisees)) {
                                $eligible = false;
                                $message_eligibilite = "Ce plan de prêt n'est pas disponible pour la catégorie de ce membre.";
                            }
                        }
                    }

                    if (!$eligible) {
                        $erreurs[] = "Le membre n'est pas éligible pour ce prêt: " . $message_eligibilite;
                    }
                }
            }

            // Si toujours pas d'erreurs, créer le prêt
            if (empty($erreurs)) {
                $pdo->beginTransaction();

                try {
                    // Générer un code de prêt unique
                    $code_pret = 'PR' . date('YmdHis') . str_pad(mt_rand(1, 999), 3, '0', STR_PAD_LEFT);
                    
                    // Vérifier que le code n'existe pas déjà
                    $stmt_verif_code = $pdo->prepare("SELECT COUNT(*) as existe FROM prets WHERE code_pret = ?");
                    $stmt_verif_code->execute([$code_pret]);
                    $result_code = $stmt_verif_code->fetch();
                    
                    if ($result_code['existe'] > 0) {
                        // Regénérer le code si collision
                        $code_pret = 'PR' . date('YmdHis') . str_pad(mt_rand(100, 999), 3, '0', STR_PAD_LEFT);
                    }
                    
                    // Calculer les détails du prêt selon la méthode de calcul
                    $taux_interet_annuel = $plan_pret['taux_interet_annuel'];
                    $taux_interet_mensuel = ($taux_interet_annuel / 100) / 12;
                    
                    // Calcul de la mensualité selon la méthode définie dans le plan
                    $mensualite = calculer_mensualite(
                        $donnees_formulaire['montant'], 
                        $taux_interet_mensuel, 
                        $donnees_formulaire['duree_mois'],
                        $plan_pret['methode_calcul']
                    );
                    
                    $date_debut = date('Y-m-d');
                    $date_fin = date('Y-m-d', strtotime("+{$donnees_formulaire['duree_mois']} months"));
                    
                    // Calculer les frais de dossier
                    $frais_dossier = 0;
                    if ($plan_pret['frais_dossier'] > 0) {
                        if ($plan_pret['frais_dossier_type'] === 'pourcentage') {
                            $frais_dossier = ($donnees_formulaire['montant'] * $plan_pret['frais_dossier']) / 100;
                        } else {
                            $frais_dossier = $plan_pret['frais_dossier'];
                        }
                    }

                    // Calculer l'assurance
                    $assurance_montant = 0;
                    if ($plan_pret['assurance'] > 0) {
                        $assurance_montant = ($donnees_formulaire['montant'] * $plan_pret['assurance']) / 100;
                    }

                    // Insérer le prêt
                    $sql_insert = "INSERT INTO prets (
                        code_pret, code_membre, plan_pret_id, montant, montant_restant,
                        taux_interet, duree_mois, date_debut, date_fin, mensualite,
                        penalite_retard, frais_dossier, assurance, motif_pret,
                        garanties_provisees, conditions_remplies, verification_cotisations,
                        verification_dette, statut, date_creation
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, TRUE, TRUE, TRUE, 'en_attente', NOW())";

                    $stmt_insert = $pdo->prepare($sql_insert);
                    $stmt_insert->execute([
                        $code_pret,
                        $donnees_formulaire['code_membre'],
                        $donnees_formulaire['plan_pret_id'],
                        $donnees_formulaire['montant'],
                        $donnees_formulaire['montant'],
                        $taux_interet_annuel,
                        $donnees_formulaire['duree_mois'],
                        $date_debut,
                        $date_fin,
                        $mensualite,
                        $plan_pret['penalite_retard'],
                        $frais_dossier,
                        $assurance_montant,
                        $donnees_formulaire['motif_pret'],
                        $donnees_formulaire['garanties_provisees']
                    ]);

                    $pret_id = $pdo->lastInsertId();

                    // Générer les échéances selon la méthode de calcul
                    generer_echeances_pret(
                        $pdo, 
                        $pret_id, 
                        $donnees_formulaire['montant'], 
                        $taux_interet_mensuel, 
                        $donnees_formulaire['duree_mois'], 
                        $mensualite, 
                        $date_debut,
                        $plan_pret['methode_calcul']
                    );

                    $pdo->commit();

                    $_SESSION['succes'] = "Prêt créé avec succès! Code: " . $code_pret . ". En attente d'approbation.";
                    rediriger('detail_pret.php?id=' . $pret_id);

                } catch (Exception $e) {
                    $pdo->rollBack();
                    $erreurs[] = "Erreur lors de la création du prêt: " . $e->getMessage();
                    error_log("Erreur création prêt: " . $e->getMessage());
                }
            }

        } catch (PDOException $e) {
            $erreurs[] = "Erreur lors de la vérification d'éligibilité: " . $e->getMessage();
            error_log("Erreur vérification éligibilité: " . $e->getMessage());
        }
    }
}

// Fonction pour calculer la mensualité selon la méthode
function calculer_mensualite($montant, $taux_mensuel, $duree_mois, $methode_calcul = 'annuite_constante') {
    if ($taux_mensuel == 0) {
        return $montant / $duree_mois;
    }
    
    switch ($methode_calcul) {
        case 'lineaire':
            // Méthode linéaire : capital constant + intérêts dégressifs
            $capital_mensuel = $montant / $duree_mois;
            $interet_premier_mois = $montant * $taux_mensuel;
            return $capital_mensuel + $interet_premier_mois;
            
        case 'in fine':
            // Méthode in fine : intérêts payés chaque mois, capital à la fin
            return $montant * $taux_mensuel;
            
        case 'annuite_constante':
        default:
            // Méthode des annuités constantes (formule standard)
            return ($montant * $taux_mensuel) / (1 - pow(1 + $taux_mensuel, -$duree_mois));
    }
}

// Fonction pour générer les échéances selon la méthode de calcul
function generer_echeances_pret($pdo, $pret_id, $montant, $taux_mensuel, $duree_mois, $mensualite, $date_debut, $methode_calcul = 'annuite_constante') {
    $capital_restant = $montant;
    
    for ($i = 1; $i <= $duree_mois; $i++) {
        $date_echeance = date('Y-m-d', strtotime("+$i months", strtotime($date_debut)));
        
        switch ($methode_calcul) {
            case 'lineaire':
                // Méthode linéaire : capital constant
                $capital = $montant / $duree_mois;
                $interet = $capital_restant * $taux_mensuel;
                $mensualite_courante = $capital + $interet;
                break;
                
            case 'in fine':
                // Méthode in fine : seulement les intérêts, sauf dernier mois
                if ($i == $duree_mois) {
                    $capital = $montant;
                    $interet = $capital_restant * $taux_mensuel;
                    $mensualite_courante = $capital + $interet;
                } else {
                    $capital = 0;
                    $interet = $capital_restant * $taux_mensuel;
                    $mensualite_courante = $interet;
                }
                break;
                
            case 'annuite_constante':
            default:
                // Méthode des annuités constantes
                $interet = $capital_restant * $taux_mensuel;
                $capital = $mensualite - $interet;
                
                // Ajustement pour la dernière échéance
                if ($i == $duree_mois) {
                    $capital = $capital_restant;
                    $mensualite_courante = $capital + $interet;
                } else {
                    $mensualite_courante = $mensualite;
                }
                break;
        }
        
        $capital_restant -= $capital;
        
        // S'assurer que le capital restant ne devient pas négatif
        if ($capital_restant < 0) {
            $capital_restant = 0;
        }
        
        // Insérer l'échéance
        $sql_echeance = "INSERT INTO echeances_pret (
            pret_id, numero_echeance, date_echeance, montant_echeance,
            capital, interet, capital_restant, statut, date_creation
        ) VALUES (?, ?, ?, ?, ?, ?, ?, 'a_venir', NOW())";
        
        $stmt_echeance = $pdo->prepare($sql_echeance);
        $stmt_echeance->execute([
            $pret_id, $i, $date_echeance, $mensualite_courante,
            $capital, $interet, $capital_restant
        ]);
    }
}
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Nouveau Prêt - <?php echo APP_NAME; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        .sidebar {
            background: #2c3e50;
            color: white;
            height: 100vh;
            position: fixed;
            padding-top: 20px;
            overflow-y: auto;
        }
        .sidebar .nav-link {
            color: #bdc3c7;
            padding: 12px 15px;
            margin: 3px 0;
            font-size: 0.9rem;
        }
        .sidebar .nav-link:hover, .sidebar .nav-link.active {
            color: white;
            background: #34495e;
        }
        .sidebar .nav-link i {
            width: 20px;
            margin-right: 8px;
        }
        .main-content {
            margin-left: 250px;
            padding: 20px;
        }
        .menu-section {
            margin: 15px 0;
            padding: 10px 0;
            border-bottom: 1px solid #34495e;
        }
        .menu-section-title {
            font-size: 0.8rem;
            text-transform: uppercase;
            color: #95a5a6;
            padding: 0 15px;
            margin-bottom: 10px;
            font-weight: bold;
        }
        .card-plan {
            border-left: 4px solid #007bff;
            transition: all 0.3s;
        }
        .card-plan:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0,0,0,0.1);
        }
        .membre-info {
            background: #f8f9fa;
            border-radius: 5px;
            padding: 15px;
            margin-bottom: 20px;
        }
        .calcul-pret {
            background: #e8f4fd;
            border-radius: 5px;
            padding: 20px;
            margin-top: 20px;
        }
        .no-members {
            background-color: #fff3cd;
            border: 1px solid #ffeaa7;
            border-radius: 5px;
            padding: 15px;
            margin-bottom: 20px;
        }
        .critere-ok { color: #198754; }
        .critere-ko { color: #dc3545; }
        .plan-details { background: #f8f9fa; padding: 15px; border-radius: 5px; margin-bottom: 15px; }
    </style>
</head>
<body>
    <div class="container-fluid">
        <div class="row">
            <!-- Sidebar -->
            <nav class="col-md-3 col-lg-2 d-md-block sidebar collapse">
                <div class="position-sticky">
                    <div class="text-center mb-4">
                        <h4><i class="fas fa-hand-holding-usd me-2"></i><?php echo APP_NAME; ?></h4>
                    </div>
                    
                    <ul class="nav flex-column">
                        <li class="nav-item">
                            <a class="nav-link" href="dashboard.php">
                                <i class="fas fa-tachometer-alt"></i>Tableau de bord
                            </a>
                        </li>

                        <!-- Menu principal pour tous les utilisateurs -->
                        <div class="menu-section">
                            <div class="menu-section-title">Navigation Principale</div>
                            
                            <li class="nav-item">
                                <a class="nav-link" href="membres.php">
                                    <i class="fas fa-users"></i>Membres
                                </a>
                            </li>
                            <li class="nav-item">
                                <a class="nav-link" href="emprunteurs.php">
                                    <i class="fas fa-user-check"></i>Emprunteurs
                                </a>
                            </li>
                            <li class="nav-item">
                                <a class="nav-link" href="prets.php">
                                    <i class="fas fa-file-invoice-dollar"></i>Prêts
                                </a>
                            </li>
                            <li class="nav-item">
                                <a class="nav-link" href="paiements.php">
                                    <i class="fas fa-cash-register"></i>Paiements
                                </a>
                            </li>
                            <li class="nav-item">
                                <a class="nav-link" href="cotisations.php">
                                    <i class="fas fa-coins"></i>Cotisations
                                </a>
                            </li>
                        </div>

                        <!-- Menu Gestion pour gestionnaire et admin -->
                        <?php if (a_la_permission('gestionnaire') || a_la_permission('admin')): ?>
                        <div class="menu-section">
                            <div class="menu-section-title">Gestion</div>
                            
                            <li class="nav-item">
                                <a class="nav-link" href="categories_membres.php">
                                    <i class="fas fa-tags"></i>Catégories Membres
                                </a>
                            </li>
                            <li class="nav-item">
                                <a class="nav-link" href="types_pret.php">
                                    <i class="fas fa-list-alt"></i>Types de Prêt
                                </a>
                            </li>
                            <li class="nav-item">
                                <a class="nav-link" href="plans_pret.php">
                                    <i class="fas fa-clipboard-list"></i>Plans de Prêt
                                </a>
                            </li>
                        </div>
                        <?php endif; ?>

                        <!-- Menu Rapports pour analyste et admin -->
                        <?php if (a_la_permission('analyste') || a_la_permission('admin')): ?>
                        <div class="menu-section">
                            <div class="menu-section-title">Rapports</div>
                            
                            <li class="nav-item">
                                <a class="nav-link" href="rapports.php">
                                    <i class="fas fa-chart-bar"></i>Rapports
                                </a>
                            </li>
                        </div>
                        <?php endif; ?>

                        <!-- Menu Administration UNIQUEMENT pour admin -->
                        <?php if (a_la_permission('admin')): ?>
                        <div class="menu-section">
                            <div class="menu-section-title">Administration</div>
                            
                            <li class="nav-item">
                                <a class="nav-link" href="utilisateurs.php">
                                    <i class="fas fa-user-cog"></i>Utilisateurs
                                </a>
                            </li>
                            <li class="nav-item">
                                <a class="nav-link" href="audit_log.php">
                                    <i class="fas fa-clipboard-list"></i>Journal d'Audit
                                </a>
                            </li>
                            <li class="nav-item">
                                <a class="nav-link" href="historique_scores.php">
                                    <i class="fas fa-chart-line"></i>Historique Scores
                                </a>
                            </li>
                            <li class="nav-item">
                                <a class="nav-link" href="garanties.php">
                                    <i class="fas fa-shield-alt"></i>Garanties
                                </a>
                            </li>
                            <li class="nav-item">
                                <a class="nav-link" href="documents.php">
                                    <i class="fas fa-file-contract"></i>Documents
                                </a>
                            </li>
                            <li class="nav-item">
                                <a class="nav-link" href="parametres.php">
                                    <i class="fas fa-cogs"></i>Paramètres
                                </a>
                            </li>
                        </div>
                        <?php endif; ?>
                        
                        <!-- Déconnexion -->
                        <li class="nav-item mt-4">
                            <a class="nav-link" href="logout.php">
                                <i class="fas fa-sign-out-alt"></i>Déconnexion
                            </a>
                        </li>
                    </ul>
                </div>
            </nav>

            <!-- Main content -->
            <main class="col-md-9 ms-sm-auto col-lg-10 main-content">
                <!-- En-tête de page -->
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <div>
                        <h1 class="h3 mb-0">
                            <i class="fas fa-file-invoice-dollar me-2"></i>Nouveau Prêt
                        </h1>
                        <p class="text-muted mb-0">Créer un nouveau prêt pour un membre</p>
                    </div>
                    <div>
                        <a href="prets.php" class="btn btn-secondary">
                            <i class="fas fa-arrow-left me-2"></i>Retour aux prêts
                        </a>
                    </div>
                </div>

                <!-- Messages d'alerte -->
                <?php if (!empty($erreurs)): ?>
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        <h5 class="alert-heading"><i class="fas fa-exclamation-triangle me-2"></i>Erreurs</h5>
                        <ul class="mb-0">
                            <?php foreach ($erreurs as $erreur): ?>
                                <li><?php echo $erreur; ?></li>
                            <?php endforeach; ?>
                        </ul>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <?php if (isset($_SESSION['succes'])): ?>
                    <div class="alert alert-success alert-dismissible fade show" role="alert">
                        <?php echo $_SESSION['succes']; unset($_SESSION['succes']); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <div class="row">
                    <div class="col-lg-8">
                        <!-- Message si aucun membre éligible -->
                        <?php if (empty($membres)): ?>
                            <div class="no-members">
                                <h5><i class="fas fa-exclamation-triangle me-2"></i>Aucun membre éligible trouvé</h5>
                                <p class="mb-2">Pour créer un prêt, vous avez besoin de membres éligibles. Vérifiez que :</p>
                                <ul>
                                    <li>Des membres existent avec le statut "actif"</li>
                                    <li>Les membres ont le statut "eligible" dans la table emprunteurs</li>
                                    <li>Les membres ont des cotisations à jour</li>
                                </ul>
                                <div class="mt-3">
                                    <a href="membres.php" class="btn btn-primary me-2">
                                        <i class="fas fa-users me-1"></i>Gérer les membres
                                    </a>
                                    <a href="nouveau_membre.php" class="btn btn-success">
                                        <i class="fas fa-user-plus me-1"></i>Ajouter un membre
                                    </a>
                                </div>
                            </div>
                        <?php endif; ?>

                        <form method="POST" id="formPret" <?php echo empty($membres) ? 'style="display:none;"' : ''; ?>>
                            <div class="card shadow mb-4">
                                <div class="card-header bg-primary text-white">
                                    <h6 class="m-0 font-weight-bold">
                                        <i class="fas fa-info-circle me-2"></i>Informations de Base
                                    </h6>
                                </div>
                                <div class="card-body">
                                    <!-- Sélection du membre -->
                                    <div class="mb-3">
                                        <label for="code_membre" class="form-label">Membre *</label>
                                        <select class="form-select" id="code_membre" name="code_membre" required onchange="afficherInfoMembre()">
                                            <option value="">Sélectionner un membre...</option>
                                            <?php if (!empty($membres)): ?>
                                                <?php foreach ($membres as $membre): ?>
                                                    <option value="<?php echo $membre['code_membre']; ?>" 
                                                        <?php echo $donnees_formulaire['code_membre'] == $membre['code_membre'] ? 'selected' : ''; ?>>
                                                        <?php echo securiser($membre['nom'] . ' ' . $membre['prenom'] . ' (' . $membre['code_membre'] . ') - ' . $membre['nom_categorie']); ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            <?php else: ?>
                                                <option value="">Aucun membre éligible trouvé</option>
                                            <?php endif; ?>
                                        </select>
                                        <div class="form-text">Seuls les membres éligibles sont affichés.</div>
                                    </div>

                                    <!-- Informations du membre (affichées dynamiquement) -->
                                    <div id="info_membre" class="membre-info" style="display: none;">
                                        <h6><i class="fas fa-user me-2"></i>Informations du Membre</h6>
                                        <div class="row">
                                            <div class="col-md-4">
                                                <strong>Score de crédit:</strong> <span id="score_credit" class="badge bg-success"></span>
                                            </div>
                                            <div class="col-md-4">
                                                <strong>Cotisation:</strong> <span id="cotisation_statut" class="badge bg-success"></span>
                                            </div>
                                            
                                        </div>
                                        <div class="row mt-2">
                                            <div class="col-md-6">
                                                <strong>Catégorie:</strong> <span id="categorie_membre" class="fw-bold"></span>
                                            </div>
                                            <div class="col-md-6">
                                                <strong>Statut:</strong> <span id="statut_emprunteur" class="badge bg-success"></span>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Sélection du plan de prêt -->
                                    <div class="mb-3">
                                        <label for="plan_pret_id" class="form-label">Plan de Prêt *</label>
                                        <select class="form-select" id="plan_pret_id" name="plan_pret_id" required onchange="afficherDetailsPlan()">
                                            <option value="">Sélectionner un plan...</option>
                                            <?php if (!empty($plans_pret)): ?>
                                                <?php foreach ($plans_pret as $plan): ?>
                                                    <option value="<?php echo $plan['id']; ?>" 
                                                        data-taux="<?php echo $plan['taux_interet_annuel']; ?>"
                                                        data-methode-calcul="<?php echo $plan['methode_calcul']; ?>"
                                                        data-montant-min="<?php echo $plan['montant_min']; ?>"
                                                        data-montant-max="<?php echo $plan['montant_max']; ?>"
                                                        data-duree-min="<?php echo $plan['duree_min_mois']; ?>"
                                                        data-duree-max="<?php echo $plan['duree_max_mois']; ?>"
                                                        data-frais-dossier="<?php echo $plan['frais_dossier']; ?>"
                                                        data-frais-type="<?php echo $plan['frais_dossier_type']; ?>"
                                                        data-assurance="<?php echo $plan['assurance']; ?>"
                                                        data-penalite="<?php echo $plan['penalite_retard']; ?>"
                                                        data-score-min="<?php echo $plan['score_credit_min']; ?>"
                                                        data-anciennete-min="<?php echo $plan['anciennete_min_mois']; ?>"
                                                        data-cotisation-requise="<?php echo $plan['cotisation_requise']; ?>"
                                                        data-duree-min-cotisation="<?php echo $plan['duree_min_cotisation_mois']; ?>"
                                                        data-multiple-prets="<?php echo $plan['autoriser_multiple_prets']; ?>"
                                                        data-categories-json="<?php echo htmlspecialchars($plan['categories_membres_json'] ?? '[]'); ?>"
                                                        <?php echo $donnees_formulaire['plan_pret_id'] == $plan['id'] ? 'selected' : ''; ?>>
                                                        <?php echo securiser($plan['nom_type'] . ' - ' . $plan['nom_pret'] . ' (' . $plan['taux_interet_annuel'] . '%)'); ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            <?php else: ?>
                                                <option value="">Aucun plan de prêt actif trouvé</option>
                                            <?php endif; ?>
                                        </select>
                                    </div>

                                    <!-- Détails du plan (affichés dynamiquement) -->
                                    <div id="details_plan" class="plan-details" style="display: none;">
                                        <h6><i class="fas fa-clipboard-list me-2"></i>Détails du Plan</h6>
                                        <div class="row">
                                            <div class="col-md-4">
                                                <strong>Montant:</strong> <span id="plan_montant_min_max"></span>
                                            </div>
                                            <div class="col-md-4">
                                                <strong>Durée:</strong> <span id="plan_duree_min_max"></span> mois
                                            </div>
                                            <div class="col-md-4">
                                                <strong>Taux annuel:</strong> <span id="plan_taux" class="fw-bold"></span>%
                                            </div>
                                        </div>
                                        <div class="row mt-2">
                                            <div class="col-md-4">
                                                <strong>Méthode calcul:</strong> <span id="plan_methode_calcul"></span>
                                            </div>
                                            <div class="col-md-4">
                                                <strong>Frais de dossier:</strong> <span id="plan_frais_dossier"></span>
                                            </div>
                                            <div class="col-md-4">
                                                <strong>Assurance:</strong> <span id="plan_assurance"></span>%
                                            </div>
                                        </div>
                                        <div class="row mt-2">
                                            <div class="col-md-6">
                                                <strong>Pénalité retard:</strong> <span id="plan_penalite"></span>%
                                            </div>
                                            <div class="col-md-6">
                                                <strong>Prêts multiples:</strong> <span id="plan_multiple_prets"></span>
                                            </div>
                                        </div>
                                        
                                        <!-- Catégories de membres autorisées -->
                                        <div class="row mt-2">
                                            <div class="col-12">
                                                <strong>Catégories autorisées:</strong> <span id="plan_categories_autorisees"></span>
                                            </div>
                                        </div>
                                        
                                        <!-- Critères d'éligibilité -->
                                        <div class="mt-3" id="criteres_eligibilite" style="display: none;">
                                            <h6><i class="fas fa-user-check me-2"></i>Critères d'éligibilité</h6>
                                            <div class="row small">
                                                <div class="col-md-6">
                                                    <span id="critere_score"><i class="fas fa-question-circle me-1"></i>Score crédit: <span class="badge"></span></span>
                                                </div>
                                                <div class="col-md-6">
                                                    <span id="critere_anciennete"><i class="fas fa-question-circle me-1"></i>Ancienneté: <span class="badge"></span></span>
                                                </div>
                                                <div class="col-md-6">
                                                    <span id="critere_cotisation"><i class="fas fa-question-circle me-1"></i>Cotisations: <span class="badge"></span></span>
                                                </div>
                                                <div class="col-md-6">
                                                    <span id="critere_categorie"><i class="fas fa-question-circle me-1"></i>Catégorie: <span class="badge"></span></span>
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Montant et durée -->
                                    <div class="row">
                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label for="montant" class="form-label">Montant du Prêt (FBu) *</label>
                                                <input type="number" class="form-control" id="montant" name="montant" 
                                                       value="<?php echo $donnees_formulaire['montant']; ?>" 
                                                       min="0" step="1000" required oninput="calculerMensualite()">
                                                <div class="form-text" id="montant_guide"></div>
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label for="duree_mois" class="form-label">Durée (mois) *</label>
                                                <input type="number" class="form-control" id="duree_mois" name="duree_mois" 
                                                       value="<?php echo $donnees_formulaire['duree_mois']; ?>" 
                                                       min="1" required oninput="calculerMensualite()">
                                                <div class="form-text" id="duree_guide"></div>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Calcul de la mensualité (affiché dynamiquement) -->
                                    <div id="calcul_mensualite" class="calcul-pret" style="display: none;">
                                        <h6><i class="fas fa-calculator me-2"></i>Calcul du Prêt</h6>
                                        <div class="row text-center">
                                            <div class="col-md-4">
                                                <strong>Mensualité:</strong>
                                                <div id="mensualite_calculee" class="h5 text-primary"></div>
                                            </div>
                                            <div class="col-md-4">
                                                <strong>Total à rembourser:</strong>
                                                <div id="total_remboursement" class="h5 text-success"></div>
                                            </div>
                                            <div class="col-md-4">
                                                <strong>Coût du crédit:</strong>
                                                <div id="cout_credit" class="h5 text-warning"></div>
                                            </div>
                                        </div>
                                        <div class="row mt-2 text-center small">
                                            <div class="col-md-4">
                                                <strong>Frais de dossier:</strong>
                                                <div id="frais_dossier_calcule"></div>
                                            </div>
                                            <div class="col-md-4">
                                                <strong>Assurance:</strong>
                                                <div id="assurance_calculee"></div>
                                            </div>
                                            <div class="col-md-4">
                                                <strong>Méthode:</strong>
                                                <div id="methode_calcul"></div>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Motif et garanties -->
                                    <div class="mb-3">
                                        <label for="motif_pret" class="form-label">Motif du Prêt</label>
                                        <textarea class="form-control" id="motif_pret" name="motif_pret" rows="3" 
                                                  placeholder="Décrivez la raison du prêt..."><?php echo $donnees_formulaire['motif_pret']; ?></textarea>
                                    </div>

                                    <div class="mb-3">
                                        <label for="garanties_provisees" class="form-label">Garanties Provisées</label>
                                        <textarea class="form-control" id="garanties_provisees" name="garanties_provisees" rows="3" 
                                                  placeholder="Décrivez les garanties offertes..."><?php echo $donnees_formulaire['garanties_provisees']; ?></textarea>
                                    </div>
                                </div>
                            </div>

                            <!-- Boutons de soumission -->
                            <div class="d-grid gap-2 d-md-flex justify-content-md-end">
                                <a href="prets.php" class="btn btn-secondary me-md-2">
                                    <i class="fas fa-times me-2"></i>Annuler
                                </a>
                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-check me-2"></i>Créer le Prêt
                                </button>
                            </div>
                        </form>
                    </div>

                    <div class="col-lg-4">
                        <!-- Aide et informations -->
                        <div class="card shadow">
                            <div class="card-header bg-info text-white">
                                <h6 class="m-0 font-weight-bold">
                                    <i class="fas fa-question-circle me-2"></i>Informations
                                </h6>
                            </div>
                            <div class="card-body">
                                <h6>Processus de création</h6>
                                <ol class="small">
                                    <li>Sélectionnez un membre éligible</li>
                                    <li>Choisissez un plan de prêt adapté</li>
                                    <li>Définissez le montant et la durée</li>
                                    <li>Vérifiez les calculs automatiques</li>
                                    <li>Remplissez les informations complémentaires</li>
                                    <li>Soumettez pour approbation</li>
                                </ol>
                                
                                <h6 class="mt-3">Statuts du prêt</h6>
                                <ul class="small">
                                    <li><span class="badge bg-warning">En attente</span> - En attente d'approbation</li>
                                    <li><span class="badge bg-success">Approuvé</span> - Prêt approuvé</li>
                                    <li><span class="badge bg-primary">Actif</span> - Prêt en cours</li>
                                    <li><span class="badge bg-secondary">Rejeté</span> - Prêt refusé</li>
                                </ul>

                                <h6 class="mt-3">Méthodes de calcul</h6>
                                <ul class="small">
                                    <li><strong>Annuité constante</strong> - Mensualité fixe</li>
                                    <li><strong>Linéaire</strong> - Capital constant</li>
                                    <li><strong>In fine</strong> - Capital en fin de période</li>
                                </ul>
                            </div>
                        </div>
                    </div>
                </div>
            </main>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Données des membres pour JavaScript
        const membresData = <?php echo json_encode($membres ?: []); ?>;
        
        function afficherInfoMembre() {
            const codeMembre = document.getElementById('code_membre').value;
            const infoMembre = document.getElementById('info_membre');
            
            if (codeMembre) {
                const membre = membresData.find(m => m.code_membre === codeMembre);
                if (membre) {
                    document.getElementById('score_credit').textContent = membre.score_credit;
                    document.getElementById('cotisation_statut').textContent = membre.cotisation_a_jour ? 'À jour' : 'En retard';
                    document.getElementById('cotisation_statut').className = membre.cotisation_a_jour ? 'badge bg-success' : 'badge bg-danger';
                    document.getElementById('dette_actuelle').textContent = parseFloat(membre.dette_actuelle).toLocaleString('fr-FR') + ' FBu';
                    document.getElementById('categorie_membre').textContent = membre.nom_categorie;
                    document.getElementById('statut_emprunteur').textContent = membre.statut_emprunteur;
                    document.getElementById('statut_emprunteur').className = membre.statut_emprunteur === 'eligible' ? 'badge bg-success' : 'badge bg-warning';
                    
                    infoMembre.style.display = 'block';
                    verifierCriteresEligibilite();
                }
            } else {
                infoMembre.style.display = 'none';
            }
            calculerMensualite();
        }

        function afficherDetailsPlan() {
            const planSelect = document.getElementById('plan_pret_id');
            const detailsPlan = document.getElementById('details_plan');
            const selectedOption = planSelect.options[planSelect.selectedIndex];
            
            if (selectedOption && selectedOption.value) {
                const montantMin = parseFloat(selectedOption.getAttribute('data-montant-min')).toLocaleString('fr-FR');
                const montantMax = parseFloat(selectedOption.getAttribute('data-montant-max')).toLocaleString('fr-FR');
                const dureeMin = selectedOption.getAttribute('data-duree-min');
                const dureeMax = selectedOption.getAttribute('data-duree-max');
                const taux = selectedOption.getAttribute('data-taux');
                const methodeCalcul = selectedOption.getAttribute('data-methode-calcul');
                const fraisDossier = selectedOption.getAttribute('data-frais-dossier');
                const fraisType = selectedOption.getAttribute('data-frais-type');
                const assurance = selectedOption.getAttribute('data-assurance');
                const penalite = selectedOption.getAttribute('data-penalite');
                const multiplePrets = selectedOption.getAttribute('data-multiple-prets') === '1' ? 'Autorisés' : 'Non autorisés';
                const dureeMinCotisation = selectedOption.getAttribute('data-duree-min-cotisation');
                const categoriesJson = selectedOption.getAttribute('data-categories-json');
                
                document.getElementById('plan_montant_min_max').textContent = `${montantMin} - ${montantMax} FBu`;
                document.getElementById('plan_duree_min_max').textContent = `${dureeMin} - ${dureeMax}`;
                document.getElementById('plan_taux').textContent = taux;
                document.getElementById('plan_methode_calcul').textContent = methodeCalcul;
                document.getElementById('plan_penalite').textContent = penalite;
                document.getElementById('plan_multiple_prets').textContent = multiplePrets;
                
                let fraisText = parseFloat(fraisDossier).toLocaleString('fr-FR');
                if (fraisType === 'pourcentage') {
                    fraisText += '%';
                } else {
                    fraisText += ' FBu';
                }
                document.getElementById('plan_frais_dossier').textContent = fraisText;
                document.getElementById('plan_assurance').textContent = assurance;
                
                // Afficher les catégories autorisées
                let categoriesText = 'Toutes les catégories';
                try {
                    const categories = JSON.parse(categoriesJson);
                    if (Array.isArray(categories) && categories.length > 0) {
                        // Récupérer les noms des catégories (simulation)
                        categoriesText = categories.length + ' catégorie(s) spécifique(s)';
                    }
                } catch (e) {
                    categoriesText = 'Toutes les catégories';
                }
                document.getElementById('plan_categories_autorisees').textContent = categoriesText;
                
                detailsPlan.style.display = 'block';
                
                // Mettre à jour les placeholders et limites des inputs
                document.getElementById('montant').min = selectedOption.getAttribute('data-montant-min');
                document.getElementById('montant').max = selectedOption.getAttribute('data-montant-max');
                document.getElementById('montant_guide').textContent = `Entre ${montantMin} et ${montantMax} FBu`;
                
                document.getElementById('duree_mois').min = dureeMin;
                document.getElementById('duree_mois').max = dureeMax;
                document.getElementById('duree_guide').textContent = `Entre ${dureeMin} et ${dureeMax} mois`;

                // Afficher les critères d'éligibilité
                document.getElementById('criteres_eligibilite').style.display = 'block';
                verifierCriteresEligibilite();
            } else {
                detailsPlan.style.display = 'none';
                document.getElementById('criteres_eligibilite').style.display = 'none';
            }
            calculerMensualite();
        }

        function verifierCriteresEligibilite() {
            const planSelect = document.getElementById('plan_pret_id');
            const codeMembre = document.getElementById('code_membre').value;
            const selectedOption = planSelect.options[planSelect.selectedIndex];
            
            if (!selectedOption || !selectedOption.value || !codeMembre) return;
            
            const membre = membresData.find(m => m.code_membre === codeMembre);
            if (!membre) return;
            
            const scoreMin = parseInt(selectedOption.getAttribute('data-score-min')) || 0;
            const ancienneteMin = parseInt(selectedOption.getAttribute('data-anciennete-min')) || 0;
            const dureeMinCotisation = parseInt(selectedOption.getAttribute('data-duree-min-cotisation')) || 0;
            const categoriesJson = selectedOption.getAttribute('data-categories-json');
            
            // Vérifier le score de crédit
            const critereScore = document.querySelector('#critere_score .badge');
            if (scoreMin > 0) {
                const ok = membre.score_credit >= scoreMin;
                critereScore.textContent = `min ${scoreMin} (${membre.score_credit})`;
                critereScore.className = `badge ${ok ? 'bg-success' : 'bg-danger'}`;
                document.querySelector('#critere_score i').className = `fas ${ok ? 'fa-check-circle critere-ok' : 'fa-times-circle critere-ko'}`;
            } else {
                critereScore.textContent = 'aucun';
                critereScore.className = 'badge bg-secondary';
                document.querySelector('#critere_score i').className = 'fas fa-info-circle';
            }
            
            // Vérifier l'ancienneté
            const critereAnciennete = document.querySelector('#critere_anciennete .badge');
            if (ancienneteMin > 0) {
                // Calculer l'ancienneté à partir de la date d'inscription
                const dateInscription = new Date(membre.date_inscription);
                const aujourdhui = new Date();
                const diffMois = (aujourdhui.getFullYear() - dateInscription.getFullYear()) * 12 + (aujourdhui.getMonth() - dateInscription.getMonth());
                const ok = diffMois >= ancienneteMin;
                critereAnciennete.textContent = `min ${ancienneteMin} mois (${diffMois} mois)`;
                critereAnciennete.className = `badge ${ok ? 'bg-success' : 'bg-danger'}`;
                document.querySelector('#critere_anciennete i').className = `fas ${ok ? 'fa-check-circle critere-ok' : 'fa-times-circle critere-ko'}`;
            } else {
                critereAnciennete.textContent = 'aucune';
                critereAnciennete.className = 'badge bg-secondary';
                document.querySelector('#critere_anciennete i').className = 'fas fa-info-circle';
            }
            
            // Vérifier les cotisations
            const critereCotisation = document.querySelector('#critere_cotisation .badge');
            if (dureeMinCotisation > 0) {
                // Simuler les données de cotisation - à remplacer par vos données réelles
                const totalMoisCotises = 3; // Exemple: 3 mois de cotisations
                const ok = totalMoisCotises >= dureeMinCotisation;
                critereCotisation.textContent = `min ${dureeMinCotisation} mois (${totalMoisCotises} mois)`;
                critereCotisation.className = `badge ${ok ? 'bg-success' : 'bg-danger'}`;
                document.querySelector('#critere_cotisation i').className = `fas ${ok ? 'fa-check-circle critere-ok' : 'fa-times-circle critere-ko'}`;
            } else {
                critereCotisation.textContent = 'aucune';
                critereCotisation.className = 'badge bg-secondary';
                document.querySelector('#critere_cotisation i').className = 'fas fa-info-circle';
            }
            
            // Vérifier la catégorie
            const critereCategorie = document.querySelector('#critere_categorie .badge');
            try {
                const categoriesAutorisees = JSON.parse(categoriesJson);
                if (Array.isArray(categoriesAutorisees) && categoriesAutorisees.length > 0) {
                    // Simuler la vérification de la catégorie
                    const categorieOk = true; // À remplacer par la vérification réelle
                    critereCategorie.textContent = 'restreintes';
                    critereCategorie.className = `badge ${categorieOk ? 'bg-success' : 'bg-danger'}`;
                    document.querySelector('#critere_categorie i').className = `fas ${categorieOk ? 'fa-check-circle critere-ok' : 'fa-times-circle critere-ko'}`;
                } else {
                    critereCategorie.textContent = 'toutes';
                    critereCategorie.className = 'badge bg-secondary';
                    document.querySelector('#critere_categorie i').className = 'fas fa-info-circle';
                }
            } catch (e) {
                critereCategorie.textContent = 'toutes';
                critereCategorie.className = 'badge bg-secondary';
                document.querySelector('#critere_categorie i').className = 'fas fa-info-circle';
            }
        }

        function calculerMensualite() {
            const montant = parseFloat(document.getElementById('montant').value) || 0;
            const duree = parseInt(document.getElementById('duree_mois').value) || 0;
            const planSelect = document.getElementById('plan_pret_id');
            const selectedOption = planSelect.options[planSelect.selectedIndex];
            const calculDiv = document.getElementById('calcul_mensualite');
            
            if (montant > 0 && duree > 0 && selectedOption && selectedOption.value) {
                const tauxAnnuel = parseFloat(selectedOption.getAttribute('data-taux'));
                const tauxMensuel = (tauxAnnuel / 100) / 12;
                const methodeCalcul = selectedOption.getAttribute('data-methode-calcul');
                const fraisDossier = parseFloat(selectedOption.getAttribute('data-frais-dossier')) || 0;
                const fraisType = selectedOption.getAttribute('data-frais-type');
                const assurance = parseFloat(selectedOption.getAttribute('data-assurance')) || 0;
                
                // Calcul de la mensualité selon la méthode
                let mensualite;
                switch(methodeCalcul) {
                    case 'lineaire':
                        // Méthode linéaire : capital constant + intérêts dégressifs
                        const capitalMensuel = montant / duree;
                        const interetPremierMois = montant * tauxMensuel;
                        mensualite = capitalMensuel + interetPremierMois;
                        break;
                    case 'in fine':
                        // Méthode in fine : intérêts payés chaque mois, capital à la fin
                        mensualite = montant * tauxMensuel;
                        break;
                    case 'annuite_constante':
                    default:
                        // Méthode des annuités constantes
                        if (tauxMensuel === 0) {
                            mensualite = montant / duree;
                        } else {
                            mensualite = (montant * tauxMensuel) / (1 - Math.pow(1 + tauxMensuel, -duree));
                        }
                        break;
                }
                
                const totalRemboursement = methodeCalcul === 'in fine' ? 
                    (mensualite * (duree - 1)) + (montant + mensualite) : // Dernier mois: capital + intérêt
                    mensualite * duree;
                    
                const coutCredit = totalRemboursement - montant;
                
                // Calcul des frais
                let fraisDossierMontant = 0;
                if (fraisDossier > 0) {
                    if (fraisType === 'pourcentage') {
                        fraisDossierMontant = (montant * fraisDossier) / 100;
                    } else {
                        fraisDossierMontant = fraisDossier;
                    }
                }
                
                const assuranceMontant = (montant * assurance) / 100;
                
                document.getElementById('mensualite_calculee').textContent = mensualite.toLocaleString('fr-FR', {minimumFractionDigits: 2, maximumFractionDigits: 2}) + ' FBu';
                document.getElementById('total_remboursement').textContent = totalRemboursement.toLocaleString('fr-FR', {minimumFractionDigits: 2, maximumFractionDigits: 2}) + ' FBu';
                document.getElementById('cout_credit').textContent = coutCredit.toLocaleString('fr-FR', {minimumFractionDigits: 2, maximumFractionDigits: 2}) + ' FBu';
                document.getElementById('frais_dossier_calcule').textContent = fraisDossierMontant.toLocaleString('fr-FR', {minimumFractionDigits: 2, maximumFractionDigits: 2}) + ' FBu';
                document.getElementById('assurance_calculee').textContent = assuranceMontant.toLocaleString('fr-FR', {minimumFractionDigits: 2, maximumFractionDigits: 2}) + ' FBu';
                document.getElementById('methode_calcul').textContent = methodeCalcul;
                
                calculDiv.style.display = 'block';
            } else {
                calculDiv.style.display = 'none';
            }
        }

        // Initialiser les affichages au chargement
        document.addEventListener('DOMContentLoaded', function() {
            afficherInfoMembre();
            afficherDetailsPlan();
            calculerMensualite();
        });
    </script>
</body>
</html>