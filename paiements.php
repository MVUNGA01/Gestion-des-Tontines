<?php
require_once 'config_database.php';

// Vérifier l'authentification
if (!est_connecte()) {
    rediriger('login.php');
}

// Vérifier les permissions (au moins caissier)
if (!a_la_permission('caissier')) {
    $_SESSION['erreur'] = "Vous n'avez pas les permissions nécessaires pour accéder à cette page.";
    rediriger('dashboard.php');
}

// Variables pour la pagination et la recherche
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = 20;
$offset = ($page - 1) * $limit;
$recherche = isset($_GET['recherche']) ? securiser($_GET['recherche']) : '';
$statut_filter = isset($_GET['statut']) ? securiser($_GET['statut']) : '';
$methode_filter = isset($_GET['methode_paiement']) ? securiser($_GET['methode_paiement']) : '';
$date_debut = isset($_GET['date_debut']) ? securiser($_GET['date_debut']) : '';
$date_fin = isset($_GET['date_fin']) ? securiser($_GET['date_fin']) : '';
$pret_id_filter = isset($_GET['pret_id']) ? (int)$_GET['pret_id'] : 0;

function mettreAJourCapitalRestantEcheances($pdo, $pret_id) {
    // Récupérer toutes les échéances du prêt
    $stmt = $pdo->prepare("
        SELECT id, capital, capital_restant, numero_echeance, statut
        FROM echeances_pret 
        WHERE pret_id = ? 
        ORDER BY numero_echeance
    ");
    $stmt->execute([$pret_id]);
    $echeances = $stmt->fetchAll();
    
    // Recalculer le capital restant pour chaque échéance
    $capital_restant = 0;
    foreach ($echeances as $echeance) {
        if ($echeance['statut'] === 'payee') {
            $capital_restant = $echeance['capital_restant'];
        } else {
            // Mettre à jour le capital restant pour les échéances non payées
            $nouveau_capital_restant = $capital_restant - $echeance['capital'];
            $stmt_update = $pdo->prepare("
                UPDATE echeances_pret 
                SET capital_restant = ? 
                WHERE id = ?
            ");
            $stmt_update->execute([$nouveau_capital_restant, $echeance['id']]);
            $capital_restant = $nouveau_capital_restant;
        }
    }
}


// Fonction pour générer une référence de paiement incrémentale
function generer_reference_paiement($pdo) {
    // Récupérer la dernière référence
    $stmt = $pdo->query("SELECT reference_paiement FROM paiements WHERE reference_paiement LIKE 'PAY-%' ORDER BY id DESC LIMIT 1");
    $derniere_ref = $stmt->fetchColumn();
    
    if ($derniere_ref) {
        // Extraire le numéro et l'incrémenter
        $numero = (int)str_replace('PAY-', '', $derniere_ref);
        $nouveau_numero = $numero + 1;
    } else {
        // Première référence
        $nouveau_numero = 1;
    }
    
    // Formater avec des zéros devant (ex: PAY-000001)
    return 'PAY-' . str_pad($nouveau_numero, 6, '0', STR_PAD_LEFT);
}

try {
    // Construction de la requête de base
    $sql = "SELECT 
                p.*,
                pr.code_pret,
                pr.montant as montant_pret,
                pr.montant_restant,
                m.nom,
                m.prenom,
                m.code_membre,
                pp.nom_pret,
                tp.nom_type,
                u.nom_complet as enregistre_par_nom,
                ep.numero_echeance,
                ep.date_echeance,
                ep.montant_echeance
            FROM paiements p
            INNER JOIN prets pr ON p.pret_id = pr.id
            INNER JOIN membres m ON pr.code_membre = m.code_membre
            INNER JOIN plans_pret pp ON pr.plan_pret_id = pp.id
            INNER JOIN types_pret tp ON pp.type_pret_id = tp.id
            LEFT JOIN echeances_pret ep ON p.echeance_id = ep.id
            LEFT JOIN utilisateurs u ON p.enregistre_par = u.id
            WHERE 1=1";
    
    $params = [];
    
    // Application des filtres
    if (!empty($recherche)) {
        $sql .= " AND (pr.code_pret LIKE ? OR m.nom LIKE ? OR m.prenom LIKE ? OR m.code_membre LIKE ? OR p.reference_paiement LIKE ?)";
        $search_term = "%$recherche%";
        $params = array_merge($params, [$search_term, $search_term, $search_term, $search_term, $search_term]);
    }
    
    if (!empty($statut_filter)) {
        $sql .= " AND p.statut = ?";
        $params[] = $statut_filter;
    }
    
    if (!empty($methode_filter)) {
        $sql .= " AND p.methode_paiement = ?";
        $params[] = $methode_filter;
    }
    
    if (!empty($date_debut)) {
        $sql .= " AND DATE(p.date_paiement) >= ?";
        $params[] = $date_debut;
    }
    
    if (!empty($date_fin)) {
        $sql .= " AND DATE(p.date_paiement) <= ?";
        $params[] = $date_fin;
    }
    
    // Compter le nombre total pour la pagination
    $count_sql = "SELECT COUNT(*) as total FROM ($sql) as count_table";
    $stmt_count = $pdo->prepare($count_sql);
    $stmt_count->execute($params);
    $total_paiements = $stmt_count->fetch()['total'];
    $total_pages = ceil($total_paiements / $limit);
    
    // Ajout du tri et de la pagination
    $sql .= " ORDER BY p.date_paiement DESC LIMIT ? OFFSET ?";
    $params[] = $limit;
    $params[] = $offset;
    
    // Exécution de la requête principale
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $paiements = $stmt->fetchAll();
    
    // Statistiques pour les cartes
    $stmt_stats = $pdo->query("
        SELECT 
            COUNT(*) as total,
            SUM(montant_paye) as total_montant,
            SUM(CASE WHEN DATE(date_paiement) = CURDATE() THEN montant_paye ELSE 0 END) as aujourdhui_montant,
            SUM(CASE WHEN MONTH(date_paiement) = MONTH(CURDATE()) AND YEAR(date_paiement) = YEAR(CURDATE()) THEN montant_paye ELSE 0 END) as ce_mois_montant,
            COUNT(CASE WHEN DATE(date_paiement) = CURDATE() THEN 1 END) as aujourdhui_nombre,
            COUNT(CASE WHEN MONTH(date_paiement) = MONTH(CURDATE()) AND YEAR(date_paiement) = YEAR(CURDATE()) THEN 1 END) as ce_mois_nombre
        FROM paiements 
        WHERE statut IN ('complet', 'partiel')
    ");
    $stats = $stmt_stats->fetch();
    
    // Récupérer les méthodes de paiement pour le filtre
    $stmt_methodes = $pdo->query("SHOW COLUMNS FROM paiements LIKE 'methode_paiement'");
    $methodes_row = $stmt_methodes->fetch();
    preg_match("/^enum\(\'(.*)\'\)$/", $methodes_row['Type'], $matches);
    $methodes_paiement = explode("','", $matches[1]);
    
    // Récupérer la liste des prêts pour le formulaire
    $stmt_prets = $pdo->query("
        SELECT p.id, p.code_pret, p.montant_restant, m.nom, m.prenom, m.code_membre, p.mensualite
        FROM prets p
        INNER JOIN membres m ON p.code_membre = m.code_membre
        WHERE p.statut IN ('actif', 'en_retard')
        ORDER BY p.code_pret
    ");
    $liste_prets = $stmt_prets->fetchAll();
    
} catch (PDOException $e) {
    $erreur = "Erreur lors du chargement des données: " . $e->getMessage();
}

// Traitement du formulaire d'enregistrement de paiement
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    try {
        $action = securiser($_POST['action']);
        
        switch ($action) {
            case 'enregistrer_paiement':
                $pret_id = (int)$_POST['pret_id'];
                $montant_paye = (float)$_POST['montant_paye'];
                $methode_paiement = securiser($_POST['methode_paiement']);
                $reference_paiement = securiser($_POST['reference_paiement']);
                $type_paiement = securiser($_POST['type_paiement']);
                $echeance_id = !empty($_POST['echeance_id']) ? (int)$_POST['echeance_id'] : null;
                $notes = securiser($_POST['notes']);
                $date_paiement = securiser($_POST['date_paiement']);
                
                // Générer une référence automatique si non fournie
                if (empty($reference_paiement)) {
                    $reference_paiement = generer_reference_paiement($pdo);
                }
                
                // Récupérer les informations du prêt
                $stmt_pret = $pdo->prepare("
                    SELECT montant, montant_restant, code_membre, mensualite 
                    FROM prets 
                    WHERE id = ? AND statut IN ('actif', 'en_retard', 'approuve')
                ");
                $stmt_pret->execute([$pret_id]);
                $pret = $stmt_pret->fetch();
                
                if (!$pret) {
                    throw new Exception("Prêt non trouvé ou non éligible pour un paiement.");
                }
                
                // Validation
                if ($montant_paye <= 0) {
                    throw new Exception("Le montant payé doit être supérieur à zéro.");
                }
                
                // Calculer la répartition du paiement AVEC VERIFICATION
                $montant_restant = $pret['montant_restant'];
                $montant_capital = 0;
                $montant_interet = 0;
                $montant_assurance = 0;
                $montant_penalite = 0;

                if ($type_paiement === 'echeance' && $echeance_id) {
                    // Paiement d'une échéance spécifique - VERIFIER qu'elle appartient bien au prêt
                    $stmt_echeance = $pdo->prepare("
                        SELECT montant_echeance, capital, interet, assurance, penalite_retard,
                               capital_restant, statut, pret_id
                        FROM echeances_pret 
                        WHERE id = ? AND pret_id = ?
                    ");
                    $stmt_echeance->execute([$echeance_id, $pret_id]);
                    $echeance = $stmt_echeance->fetch();
                    
                    if (!$echeance) {
                        throw new Exception("Échéance non trouvée ou ne correspond pas au prêt.");
                    }
                    
                    if ($echeance['statut'] === 'payee') {
                        throw new Exception("Cette échéance a déjà été payée.");
                    }
                    
                    // Calcul précis de la répartition
                    $montant_total_echeance = $echeance['montant_echeance'] + $echeance['penalite_retard'];
                    
                    if ($montant_paye < $echeance['montant_echeance']) {
                        throw new Exception("Le montant payé est inférieur au montant de l'échéance.");
                    }
                    
                    // Répartition standard
                    $montant_capital = $echeance['capital'];
                    $montant_interet = $echeance['interet'];
                    $montant_assurance = $echeance['assurance'];
                    
                    // Gérer les pénalités
                    if ($montant_paye > $echeance['montant_echeance']) {
                        $montant_penalite = min($montant_paye - $echeance['montant_echeance'], $echeance['penalite_retard']);
                    }
                    
                } else {
                    // Paiement libre - répartition automatique AVEC VERIFICATION
                    if ($montant_paye > $montant_restant) {
                        // Le paiement dépasse le capital restant
                        $montant_capital = $montant_restant;
                        $montant_interet = $montant_paye - $montant_restant;
                    } else {
                        $montant_capital = $montant_paye;
                        $montant_interet = 0;
                    }
                }
                
                // Déterminer le statut du paiement
                $statut_paiement = 'complet';
                if ($echeance_id && $montant_paye < $echeance['montant_echeance']) {
                    $statut_paiement = 'partiel';
                }
                
                // Commencer la transaction
                $pdo->beginTransaction();
                
                // Enregistrer le paiement
                $stmt_insert = $pdo->prepare("
                    INSERT INTO paiements (
                        pret_id, echeance_id, montant_paye, methode_paiement, 
                        reference_paiement, type_paiement, statut, date_paiement,
                        montant_capital, montant_interet, montant_assurance, montant_penalite,
                        enregistre_par, notes
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                
                $stmt_insert->execute([
                    $pret_id,
                    $echeance_id,
                    $montant_paye,
                    $methode_paiement,
                    $reference_paiement,
                    $type_paiement,
                    $statut_paiement,
                    $date_paiement,
                    $montant_capital,
                    $montant_interet,
                    $montant_assurance,
                    $montant_penalite,
                    $_SESSION['user_id'],
                    $notes
                ]);
                
                $paiement_id = $pdo->lastInsertId();
                
                // Mettre à jour l'échéance si applicable
                if ($echeance_id) {
                    $nouveau_statut = 'payee';
                    $montant_total_paye = $montant_paye;
                    
                    // Vérifier si le paiement couvre exactement l'échéance
                    if ($montant_paye >= ($echeance['montant_echeance'] + $echeance['penalite_retard'])) {
                        $nouveau_statut = 'payee';
                    } else {
                        $nouveau_statut = 'partiel';
                    }
                    
                    $stmt_update_echeance = $pdo->prepare("
                        UPDATE echeances_pret 
                        SET statut = ?, 
                            date_paiement = ?,
                            montant_paye = ?,
                            penalite_retard = GREATEST(penalite_retard - ?, 0)
                        WHERE id = ?
                    ");
                    $stmt_update_echeance->execute([
                        $nouveau_statut, 
                        $date_paiement, 
                        $montant_paye, 
                        $montant_penalite, 
                        $echeance_id
                    ]);
                    
                    // Mettre à jour les échéances suivantes si nécessaire
                    mettreAJourCapitalRestantEcheances($pdo, $pret_id);
                }
                
                // Mettre à jour le prêt
                $nouveau_montant_restant = max($montant_restant - $montant_capital, 0);
                $sql_update_pret = "UPDATE prets 
                                   SET montant_restant = ?,
                                       statut = CASE 
                                           WHEN ? <= 0 THEN 'complet' 
                                           WHEN statut = 'en_retard' AND ? > 0 THEN 'actif'
                                           ELSE statut 
                                       END
                                   WHERE id = ?";
                
                $stmt_update_pret = $pdo->prepare($sql_update_pret);
                $stmt_update_pret->execute([$nouveau_montant_restant, $nouveau_montant_restant, $nouveau_montant_restant, $pret_id]);
                
                // Mettre à jour l'emprunteur
                $sql_update_emprunteur = "UPDATE emprunteurs 
                                         SET dette_actuelle = dette_actuelle - ?,
                                             total_rembourse = total_rembourse + ?,
                                             derniere_modification = CURRENT_TIMESTAMP
                                         WHERE code_membre = ?";
                
                $stmt_update_emprunteur = $pdo->prepare($sql_update_emprunteur);
                $stmt_update_emprunteur->execute([$montant_capital, $montant_paye, $pret['code_membre']]);
                
                // Valider la transaction
                $pdo->commit();
                
                // Journaliser l'action
                $stmt_audit = $pdo->prepare("
                    INSERT INTO audit_log (table_name, action, record_id, old_values, new_values, user_id, ip_address)
                    VALUES ('paiements', 'INSERT', ?, ?, ?, ?, ?)
                ");
                $stmt_audit->execute([
                    $paiement_id,
                    json_encode([]),
                    json_encode([
                        'pret_id' => $pret_id,
                        'montant_paye' => $montant_paye,
                        'methode_paiement' => $methode_paiement,
                        'reference_paiement' => $reference_paiement
                    ]),
                    $_SESSION['user_id'],
                    $_SERVER['REMOTE_ADDR']
                ]);
                
                $_SESSION['succes'] = "Paiement enregistré avec succès. Référence: " . $reference_paiement;
                break;
                
            case 'annuler_paiement':
                if (a_la_permission('admin') || a_la_permission('gestionnaire')) {
                    $paiement_id = (int)$_POST['paiement_id'];
                    
                    // Récupérer les informations du paiement avant annulation
                    $stmt_paiement = $pdo->prepare("SELECT * FROM paiements WHERE id = ?");
                    $stmt_paiement->execute([$paiement_id]);
                    $paiement = $stmt_paiement->fetch();
                    
                    if ($paiement) {
                        // Annuler le paiement
                        $stmt_annuler = $pdo->prepare("UPDATE paiements SET statut = 'annule' WHERE id = ?");
                        $stmt_annuler->execute([$paiement_id]);
                        
                        // Réinitialiser l'échéance si applicable
                        if ($paiement['echeance_id']) {
                            $stmt_reset_echeance = $pdo->prepare("
                                UPDATE echeances_pret 
                                SET statut = 'due', date_paiement = NULL, montant_paye = 0 
                                WHERE id = ?
                            ");
                            $stmt_reset_echeance->execute([$paiement['echeance_id']]);
                        }
                        
                        $_SESSION['succes'] = "Paiement annulé avec succès.";
                    }
                } else {
                    $_SESSION['erreur'] = "Vous n'avez pas la permission d'annuler des paiements.";
                }
                break;
        }
        
        // Rediriger pour éviter la resoumission du formulaire
        rediriger('paiements.php?' . http_build_query($_GET));
        
    } catch (Exception $e) {
        $pdo->rollBack();
        $erreur = "Erreur lors du traitement: " . $e->getMessage();
    }
}
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestion des Paiements - <?php echo APP_NAME; ?></title>
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
        .stat-card {
            border-radius: 10px;
            transition: transform 0.3s;
        }
        .stat-card:hover {
            transform: translateY(-5px);
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
        .table-actions {
            white-space: nowrap;
        }
        .card-header {
            border-bottom: 1px solid #e3e6f0;
        }
    </style>
</head>
<body>
    <div class="container-fluid">
        <div class="row">
            <!-- Sidebar identique au dashboard -->
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
                                <a class="nav-link active" href="paiements.php">
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
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <div>
                        <h1 class="h3 mb-0">
                            <i class="fas fa-cash-register me-2"></i>Gestion des Paiements
                        </h1>
                        <p class="text-muted mb-0">Enregistrement et suivi de tous les paiements</p>
                    </div>
                    <div>
                        <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modalNouveauPaiement" 
                                <?php if ($pret_id_filter > 0): ?>
                                onclick="preSelectionnerPret(<?php echo $pret_id_filter; ?>)"
                                <?php endif; ?>>
                            <i class="fas fa-plus me-2"></i>Nouveau Paiement
                        </button>
                    </div>
                </div>

                <!-- Messages d'alerte -->
                <?php if (isset($_SESSION['succes'])): ?>
                    <div class="alert alert-success alert-dismissible fade show" role="alert">
                        <?php echo $_SESSION['succes']; unset($_SESSION['succes']); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>
                
                <?php if (isset($_SESSION['erreur'])): ?>
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        <?php echo $_SESSION['erreur']; unset($_SESSION['erreur']); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <?php if (isset($erreur)): ?>
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        <?php echo $erreur; ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <!-- Cartes de statistiques -->
                <div class="row mb-4">
                    <div class="col-xl-3 col-md-6 mb-4">
                        <div class="card stat-card border-left-primary shadow h-100 py-2">
                            <div class="card-body">
                                <div class="row no-gutters align-items-center">
                                    <div class="col mr-2">
                                        <div class="text-xs font-weight-bold text-primary text-uppercase mb-1">
                                            Total Paiements
                                        </div>
                                        <div class="h5 mb-0 font-weight-bold text-gray-800">
                                            <?php echo number_format($stats['total'] ?? 0); ?>
                                        </div>
                                    </div>
                                    <div class="col-auto">
                                        <i class="fas fa-receipt fa-2x text-gray-300"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="col-xl-3 col-md-6 mb-4">
                        <div class="card stat-card border-left-success shadow h-100 py-2">
                            <div class="card-body">
                                <div class="row no-gutters align-items-center">
                                    <div class="col mr-2">
                                        <div class="text-xs font-weight-bold text-success text-uppercase mb-1">
                                            Montant Total
                                        </div>
                                        <div class="h5 mb-0 font-weight-bold text-gray-800">
                                            <?php echo number_format($stats['total_montant'] ?? 0, 2); ?> FBu
                                        </div>
                                    </div>
                                    <div class="col-auto">
                                        <i class="fas fa-money-bill-wave fa-2x text-gray-300"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="col-xl-3 col-md-6 mb-4">
                        <div class="card stat-card border-left-info shadow h-100 py-2">
                            <div class="card-body">
                                <div class="row no-gutters align-items-center">
                                    <div class="col mr-2">
                                        <div class="text-xs font-weight-bold text-info text-uppercase mb-1">
                                            Aujourd'hui
                                        </div>
                                        <div class="h5 mb-0 font-weight-bold text-gray-800">
                                            <?php echo number_format($stats['aujourdhui_nombre'] ?? 0); ?> paiements
                                        </div>
                                        <div class="text-xs text-muted">
                                            <?php echo number_format($stats['aujourdhui_montant'] ?? 0, 2); ?> FBu
                                        </div>
                                    </div>
                                    <div class="col-auto">
                                        <i class="fas fa-calendar-day fa-2x text-gray-300"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="col-xl-3 col-md-6 mb-4">
                        <div class="card stat-card border-left-warning shadow h-100 py-2">
                            <div class="card-body">
                                <div class="row no-gutters align-items-center">
                                    <div class="col mr-2">
                                        <div class="text-xs font-weight-bold text-warning text-uppercase mb-1">
                                            Ce Mois
                                        </div>
                                        <div class="h5 mb-0 font-weight-bold text-gray-800">
                                            <?php echo number_format($stats['ce_mois_nombre'] ?? 0); ?> paiements
                                        </div>
                                        <div class="text-xs text-muted">
                                            <?php echo number_format($stats['ce_mois_montant'] ?? 0, 2); ?> FBu
                                        </div>
                                    </div>
                                    <div class="col-auto">
                                        <i class="fas fa-calendar-alt fa-2x text-gray-300"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Filtres et recherche -->
                <div class="card shadow mb-4">
                    <div class="card-header bg-light">
                        <h6 class="m-0 font-weight-bold text-primary">
                            <i class="fas fa-filter me-2"></i>Filtres et Recherche
                        </h6>
                    </div>
                    <div class="card-body">
                        <form method="GET" class="row g-3">
                            <div class="col-md-3">
                                <input type="text" class="form-control" name="recherche" placeholder="Code prêt, nom membre, référence..." value="<?php echo $recherche; ?>">
                            </div>
                            <div class="col-md-2">
                                <select class="form-select" name="statut">
                                    <option value="">Tous les statuts</option>
                                    <option value="complet" <?php echo $statut_filter === 'complet' ? 'selected' : ''; ?>>Complet</option>
                                    <option value="partiel" <?php echo $statut_filter === 'partiel' ? 'selected' : ''; ?>>Partiel</option>
                                    <option value="en_attente" <?php echo $statut_filter === 'en_attente' ? 'selected' : ''; ?>>En attente</option>
                                    <option value="annule" <?php echo $statut_filter === 'annule' ? 'selected' : ''; ?>>Annulé</option>
                                </select>
                            </div>
                            <div class="col-md-2">
                                <select class="form-select" name="methode_paiement">
                                    <option value="">Toutes les méthodes</option>
                                    <?php foreach ($methodes_paiement as $methode): ?>
                                        <option value="<?php echo $methode; ?>" <?php echo $methode_filter === $methode ? 'selected' : ''; ?>>
                                            <?php echo ucfirst(str_replace('_', ' ', $methode)); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-2">
                                <input type="date" class="form-control" name="date_debut" value="<?php echo $date_debut; ?>" placeholder="Date début">
                            </div>
                            <div class="col-md-2">
                                <input type="date" class="form-control" name="date_fin" value="<?php echo $date_fin; ?>" placeholder="Date fin">
                            </div>
                            <div class="col-md-1">
                                <button type="submit" class="btn btn-primary w-100">
                                    <i class="fas fa-search"></i>
                                </button>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Tableau des paiements -->
                <div class="card shadow">
                    <div class="card-header bg-light d-flex justify-content-between align-items-center">
                        <h6 class="m-0 font-weight-bold text-primary">
                            <i class="fas fa-list me-2"></i>Historique des Paiements
                        </h6>
                        <span class="badge bg-primary"><?php echo number_format($total_paiements); ?> paiement(s)</span>
                    </div>
                    <div class="card-body">
                        <?php if (!empty($paiements)): ?>
                            <div class="table-responsive">
                                <table class="table table-hover">
                                    <thead class="table-light">
                                        <tr>
                                            <th>Référence</th>
                                            <th>Prêt</th>
                                            <th>Membre</th>
                                            <th>Montant</th>
                                            <th>Méthode</th>
                                            <th>Type</th>
                                            <th>Date</th>
                                            <th>Statut</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($paiements as $paiement): ?>
                                            <tr>
                                                <td>
                                                    <strong><?php echo securiser($paiement['reference_paiement']); ?></strong>
                                                </td>
                                                <td>
                                                    <strong><?php echo securiser($paiement['code_pret']); ?></strong>
                                                    <br>
                                                    <small class="text-muted"><?php echo securiser($paiement['nom_pret']); ?></small>
                                                </td>
                                                <td>
                                                    <strong><?php echo securiser($paiement['nom'] . ' ' . $paiement['prenom']); ?></strong>
                                                    <br>
                                                    <small class="text-muted"><?php echo securiser($paiement['code_membre']); ?></small>
                                                </td>
                                                <td>
                                                    <strong><?php echo number_format($paiement['montant_paye'], 2); ?> FBu</strong>
                                                    <br>
                                                    <small class="text-muted">
                                                        Capital: <?php echo number_format($paiement['montant_capital'], 2); ?> FBu
                                                    </small>
                                                </td>
                                                <td>
                                                    <span class="badge bg-info"><?php echo ucfirst(str_replace('_', ' ', $paiement['methode_paiement'])); ?></span>
                                                </td>
                                                <td>
                                                    <small><?php echo ucfirst($paiement['type_paiement']); ?></small>
                                                    <?php if ($paiement['numero_echeance']): ?>
                                                        <br>
                                                        <small class="text-muted">Échéance #<?php echo $paiement['numero_echeance']; ?></small>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <small><?php echo date('d/m/Y H:i', strtotime($paiement['date_paiement'])); ?></small>
                                                    <br>
                                                    <small class="text-muted">Par <?php echo securiser($paiement['enregistre_par_nom']); ?></small>
                                                </td>
                                                <td>
                                                    <?php
                                                    $badge_class = [
                                                        'complet' => 'bg-success',
                                                        'partiel' => 'bg-warning',
                                                        'en_attente' => 'bg-secondary',
                                                        'annule' => 'bg-danger'
                                                    ][$paiement['statut']] ?? 'bg-secondary';
                                                    ?>
                                                    <span class="badge <?php echo $badge_class; ?>">
                                                        <?php echo ucfirst($paiement['statut']); ?>
                                                    </span>
                                                </td>
                                                <td class="table-actions">
                                                    <button class="btn btn-sm btn-outline-info" 
                                                            data-bs-toggle="tooltip" 
                                                            title="Détails"
                                                            onclick="afficherDetailsPaiement(<?php echo $paiement['id']; ?>)">
                                                        <i class="fas fa-eye"></i>
                                                    </button>
                                                    <?php if (($paiement['statut'] === 'complet' || $paiement['statut'] === 'partiel') && (a_la_permission('admin') || a_la_permission('gestionnaire'))): ?>
                                                        <button class="btn btn-sm btn-outline-danger" 
                                                                data-bs-toggle="tooltip" 
                                                                title="Annuler"
                                                                onclick="confirmerAnnulation(<?php echo $paiement['id']; ?>)">
                                                            <i class="fas fa-times"></i>
                                                        </button>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>

                            <!-- Pagination -->
                            <?php if ($total_pages > 1): ?>
                                <nav aria-label="Pagination">
                                    <ul class="pagination justify-content-center">
                                        <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>">
                                            <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page - 1])); ?>">Précédent</a>
                                        </li>
                                        <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                                            <li class="page-item <?php echo $i == $page ? 'active' : ''; ?>">
                                                <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $i])); ?>"><?php echo $i; ?></a>
                                            </li>
                                        <?php endfor; ?>
                                        <li class="page-item <?php echo $page >= $total_pages ? 'disabled' : ''; ?>">
                                            <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page + 1])); ?>">Suivant</a>
                                        </li>
                                    </ul>
                                </nav>
                            <?php endif; ?>

                        <?php else: ?>
                            <div class="text-center py-5">
                                <i class="fas fa-receipt fa-3x text-muted mb-3"></i>
                                <h5 class="text-muted">Aucun paiement trouvé</h5>
                                <p class="text-muted">Aucun paiement ne correspond à vos critères de recherche.</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </main>
        </div>
    </div>

    <!-- Modal Nouveau Paiement -->
    <div class="modal fade" id="modalNouveauPaiement" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <form method="POST" id="formPaiement">
                    <div class="modal-header">
                        <h5 class="modal-title">
                            <i class="fas fa-plus me-2"></i>Nouveau Paiement
                        </h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" name="action" value="enregistrer_paiement">
                        
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label">Prêt *</label>
                                <select class="form-select" name="pret_id" id="selectPret" required>
                                    <option value="">Sélectionner un prêt</option>
                                    <?php foreach ($liste_prets as $pret): ?>
                                        <option value="<?php echo $pret['id']; ?>" 
                                                data-montant-restant="<?php echo $pret['montant_restant']; ?>"
                                                data-mensualite="<?php echo $pret['mensualite']; ?>"
                                                <?php if ($pret_id_filter > 0 && $pret['id'] == $pret_id_filter): ?>selected<?php endif; ?>>
                                            <?php echo securiser($pret['code_pret'] . ' - ' . $pret['nom'] . ' ' . $pret['prenom'] . ' (' . $pret['code_membre'] . ')'); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="col-md-6">
                                <label class="form-label">Type de Paiement *</label>
                                <select class="form-select" name="type_paiement" id="selectTypePaiement" required>
                                    <option value="libre">Paiement libre</option>
                                    <option value="echeance">Échéance</option>
                                </select>
                            </div>
                            
                            <div class="col-md-6" id="divEcheance" style="display: none;">
                                <label class="form-label">Échéance</label>
                                <select class="form-select" name="echeance_id" id="selectEcheance">
                                    <option value="">Sélectionner une échéance</option>
                                </select>
                            </div>
                            
                            <div class="col-md-6">
                                <label class="form-label">Méthode de Paiement *</label>
                                <select class="form-select" name="methode_paiement" required>
                                    <?php foreach ($methodes_paiement as $methode): ?>
                                        <option value="<?php echo $methode; ?>">
                                            <?php echo ucfirst(str_replace('_', ' ', $methode)); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="col-md-6">
                                <label class="form-label">Montant Payé (FBu) *</label>
                                <input type="number" class="form-control" name="montant_paye" step="0.01" min="0.01" required>
                            </div>
                            
                            <div class="col-md-6">
                                <label class="form-label">Date de Paiement *</label>
                                <input type="datetime-local" class="form-control" name="date_paiement" value="<?php echo date('Y-m-d\TH:i'); ?>" required>
                            </div>
                            
                            <div class="col-md-6">
                                <label class="form-label">Référence Paiement</label>
                                <input type="text" class="form-control" name="reference_paiement" placeholder="Laisser vide pour générer automatiquement">
                            </div>
                            
                            <div class="col-12">
                                <label class="form-label">Notes</label>
                                <textarea class="form-control" name="notes" rows="3" placeholder="Notes optionnelles..."></textarea>
                            </div>
                            
                            <!-- Informations du prêt -->
                            <div class="col-12">
                                <div class="card bg-light">
                                    <div class="card-body">
                                        <h6 class="card-title">Informations du Prêt</h6>
                                        <div class="row">
                                            <div class="col-md-4">
                                                <small class="text-muted">Montant restant:</small>
                                                <div id="infoMontantRestant" class="fw-bold">-</div>
                                            </div>
                                            <div class="col-md-4">
                                                <small class="text-muted">Mensualité:</small>
                                                <div id="infoMensualite" class="fw-bold">-</div>
                                            </div>
                                            <div class="col-md-4">
                                                <small class="text-muted">Prochaine échéance:</small>
                                                <div id="infoProchaineEcheance" class="fw-bold">-</div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annuler</button>
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-check me-2"></i>Enregistrer le Paiement
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Scripts -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Fonction pour pré-sélectionner un prêt spécifique
        function preSelectionnerPret(pretId) {
            const selectPret = document.getElementById('selectPret');
            if (selectPret) {
                selectPret.value = pretId;
                // Déclencher les événements de mise à jour
                selectPret.dispatchEvent(new Event('change'));
            }
        }

        // Si un pret_id est fourni dans l'URL, pré-sélectionner automatiquement
        document.addEventListener('DOMContentLoaded', function() {
            <?php if ($pret_id_filter > 0): ?>
            // Pré-sélectionner le prêt spécifique
            const selectPret = document.getElementById('selectPret');
            if (selectPret) {
                selectPret.value = <?php echo $pret_id_filter; ?>;
                // Déclencher les événements de mise à jour
                selectPret.dispatchEvent(new Event('change'));
                
                // Afficher automatiquement le modal si un prêt spécifique est demandé
                const modal = new bootstrap.Modal(document.getElementById('modalNouveauPaiement'));
                modal.show();
            }
            <?php endif; ?>
        });

        // Gestion du type de paiement
        document.getElementById('selectTypePaiement').addEventListener('change', function() {
            const divEcheance = document.getElementById('divEcheance');
            if (this.value === 'echeance') {
                divEcheance.style.display = 'block';
                chargerEcheances();
            } else {
                divEcheance.style.display = 'none';
            }
        });

        // Chargement des échéances selon le prêt sélectionné
        document.getElementById('selectPret').addEventListener('change', function() {
            chargerEcheances();
            mettreAJourInfosPret();
        });

        function chargerEcheances() {
            const pretId = document.getElementById('selectPret').value;
            const selectEcheance = document.getElementById('selectEcheance');
            
            if (!pretId) {
                selectEcheance.innerHTML = '<option value="">Sélectionner une échéance</option>';
                return;
            }
            
            // Afficher un indicateur de chargement
            selectEcheance.innerHTML = '<option value="">Chargement...</option>';
            
            fetch(`api_echeances.php?pret_id=${pretId}`)
                .then(response => {
                    if (!response.ok) {
                        throw new Error('Erreur réseau');
                    }
                    return response.json();
                })
                .then(data => {
                    if (data.error) {
                        selectEcheance.innerHTML = `<option value="">Erreur: ${data.error}</option>`;
                        return;
                    }
                    
                    selectEcheance.innerHTML = '<option value="">Sélectionner une échéance</option>';
                    
                    if (data.length === 0) {
                        selectEcheance.innerHTML += '<option value="">Aucune échéance disponible</option>';
                        return;
                    }
                    
                    data.forEach(echeance => {
                        const option = document.createElement('option');
                        option.value = echeance.id;
                        
                        const dateEcheance = new Date(echeance.date_echeance).toLocaleDateString('fr-FR');
                        const statut = echeance.statut === 'en_retard' ? ' (RETARD)' : 
                                      echeance.statut === 'due' ? ' (DÛ)' : '';
                        
                        option.textContent = `Échéance #${echeance.numero_echeance} - ${dateEcheance} - ${parseFloat(echeance.montant_echeance).toLocaleString('fr-FR')} FBu${statut}`;
                        option.dataset.montant = echeance.montant_echeance;
                        option.dataset.penalite = echeance.penalite_retard || 0;
                        
                        selectEcheance.appendChild(option);
                    });
                })
                .catch(error => {
                    console.error('Erreur:', error);
                    selectEcheance.innerHTML = '<option value="">Erreur de chargement</option>';
                });
        }

        function mettreAJourInfosPret() {
            const selectPret = document.getElementById('selectPret');
            const selectedOption = selectPret.options[selectPret.selectedIndex];
            
            if (selectedOption && selectedOption.value) {
                document.getElementById('infoMontantRestant').textContent = 
                    parseFloat(selectedOption.dataset.montantRestant).toLocaleString('fr-FR') + ' FBu';
                document.getElementById('infoMensualite').textContent = 
                    parseFloat(selectedOption.dataset.mensualite).toLocaleString('fr-FR') + ' FBu';
                
                // Mettre à jour le champ montant avec la mensualité si disponible
                const montantInput = document.querySelector('input[name="montant_paye"]');
                if (montantInput && !montantInput.value) {
                    montantInput.value = parseFloat(selectedOption.dataset.mensualite).toFixed(2);
                }
            } else {
                document.getElementById('infoMontantRestant').textContent = '-';
                document.getElementById('infoMensualite').textContent = '-';
            }
        }

        function afficherDetailsPaiement(paiementId) {
            // Redirection vers la page de détails (à implémenter)
            window.location.href = `details_paiement.php?id=${paiementId}`;
        }

        function confirmerAnnulation(paiementId) {
            if (confirm('Êtes-vous sûr de vouloir annuler ce paiement ? Cette action est irréversible.')) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.innerHTML = `
                    <input type="hidden" name="action" value="annuler_paiement">
                    <input type="hidden" name="paiement_id" value="${paiementId}">
                `;
                document.body.appendChild(form);
                form.submit();
            }
        }

        // Initialisation des tooltips Bootstrap
        const tooltips = document.querySelectorAll('[data-bs-toggle="tooltip"]');
        tooltips.forEach(tooltip => new bootstrap.Tooltip(tooltip));

        // Réinitialiser le formulaire quand le modal est fermé
        document.getElementById('modalNouveauPaiement').addEventListener('hidden.bs.modal', function () {
            document.getElementById('formPaiement').reset();
            document.getElementById('divEcheance').style.display = 'none';
            document.getElementById('infoMontantRestant').textContent = '-';
            document.getElementById('infoMensualite').textContent = '-';
            document.getElementById('infoProchaineEcheance').textContent = '-';
        });
    </script>
</body>
</html>