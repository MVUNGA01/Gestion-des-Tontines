<?php
require_once 'config_database.php';

// Vérifier l'authentification et les permissions
if (!est_connecte()) {
    rediriger('login.php');
}

if (!a_la_permission('admin') && !a_la_permission('gestionnaire')) {
    $_SESSION['erreur'] = "Accès non autorisé";
    rediriger('dashboard.php');
}

// Fonction utilitaire pour formater les montants
function format_montant($montant) {
    return number_format($montant, 0, ',', ' ') . ' FBu';
}

// Fonction pour générer un code de plan automatique selon la structure de la base
function generer_code_plan($pdo, $type_pret_id = null) {
    // Utiliser le format défini dans la base de données
    $prefixe = 'PLN';
    
    // Compter le nombre de plans existants
    $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM plans_pret WHERE code_plan LIKE ?");
    $stmt->execute([$prefixe . '%']);
    $result = $stmt->fetch();
    $numero = ($result['count'] + 1);
    
    // Formater le numéro sur 4 chiffres
    $numero_formate = str_pad($numero, 4, '0', STR_PAD_LEFT);
    
    return $prefixe . $numero_formate;
}

$action = $_GET['action'] ?? 'liste';
$id = $_GET['id'] ?? null;
$message = '';
$erreur = '';

try {
    // Récupérer les types de prêt pour les formulaires
    $stmt_types = $pdo->query("SELECT id, nom_type FROM types_pret WHERE statut = 'actif' ORDER BY nom_type");
    $types_pret = $stmt_types->fetchAll();

    // Récupérer les catégories de membres
    $stmt_categories = $pdo->query("SELECT id, code_categorie, nom_categorie FROM categories_membres WHERE statut = 'actif' ORDER BY nom_categorie");
    $categories_membres = $stmt_categories->fetchAll();

    // Traitement des formulaires
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $type_pret_id = securiser($_POST['type_pret_id'] ?? '');
        $nom = securiser($_POST['nom'] ?? '');
        $description = securiser($_POST['description'] ?? '');
        
        // Paramètres financiers
        $taux_interet_annuel = floatval($_POST['taux_interet_annuel'] ?? 0);
        $methode_calcul = securiser($_POST['methode_calcul'] ?? 'annuite_constante');
        
        // Durée et montants
        $duree_min_mois = intval($_POST['duree_min_mois'] ?? 0);
        $duree_max_mois = intval($_POST['duree_max_mois'] ?? 0);
        $montant_min = floatval($_POST['montant_min'] ?? 0);
        $montant_max = floatval($_POST['montant_max'] ?? 0);
        
        // Frais et pénalités
        $penalite_retard = floatval($_POST['penalite_retard'] ?? 0);
        $frais_dossier = floatval($_POST['frais_dossier'] ?? 0);
        $assurance = floatval($_POST['assurance'] ?? 0);
        $frais_dossier_type = securiser($_POST['frais_dossier_type'] ?? 'fixe');
        
        // Conditions d'éligibilité
        $score_credit_min = intval($_POST['score_credit_min'] ?? 300);
        $anciennete_min_mois = intval($_POST['anciennete_min_mois'] ?? 3);
        $cotisation_requise = isset($_POST['cotisation_requise']) ? 1 : 0;
        $duree_min_cotisation_mois = intval($_POST['duree_min_cotisation_mois'] ?? 3);
        $montant_min_cotisation_total = floatval($_POST['montant_min_cotisation_total'] ?? 0);
        $autoriser_multiple_prets = isset($_POST['autoriser_multiple_prets']) ? 1 : 0;
        // Colonne 'respecter_plafond_categorie' retirée car non présente dans la table
        
        // Catégories autorisées (JSON)
        $categories_autorisees = $_POST['categories_autorisees'] ?? [];
        $categories_membres_json = !empty($categories_autorisees) ? json_encode($categories_autorisees) : null;
        
        // Statut
        $statut = securiser($_POST['statut'] ?? 'brouillon');
        
        // Validation des données
        if (empty($type_pret_id) || empty($nom)) {
            $erreur = "Les champs Type de prêt et Nom sont obligatoires";
        } elseif ($montant_min <= 0 || $montant_max <= 0 || $montant_min > $montant_max) {
            $erreur = "Les montants minimum et maximum doivent être positifs et le montant maximum doit être supérieur au minimum";
        } elseif ($taux_interet_annuel < 0) {
            $erreur = "Le taux d'intérêt ne peut pas être négatif";
        } elseif ($duree_min_mois <= 0 || $duree_max_mois <= 0 || $duree_min_mois > $duree_max_mois) {
            $erreur = "Les durées doivent être positives et la durée maximale doit être supérieure à la durée minimale";
        } else {
            if ($action === 'ajouter') {
                // Générer le code du plan automatiquement
                $code_plan_auto = generer_code_plan($pdo, $type_pret_id);
                
                $sql = "INSERT INTO plans_pret (
    type_pret_id, nom_pret, description, code_plan,
    taux_interet_annuel, methode_calcul, duree_min_mois, duree_max_mois,
    montant_min, montant_max, penalite_retard, frais_dossier, assurance,
    frais_dossier_type, score_credit_min, anciennete_min_mois, cotisation_requise,
    duree_min_cotisation_mois, montant_min_cotisation_total, autoriser_multiple_prets,
    categories_membres_json, statut, cree_par
) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

                
                $stmt = $pdo->prepare($sql);
                $stmt->execute([
                    $type_pret_id, $nom, $description, $code_plan_auto,
                    $taux_interet_annuel, $methode_calcul, $duree_min_mois, $duree_max_mois,
                    $montant_min, $montant_max, $penalite_retard, $frais_dossier, $assurance,
                    $frais_dossier_type, $score_credit_min, $anciennete_min_mois, $cotisation_requise,
                    $duree_min_cotisation_mois, $montant_min_cotisation_total, $autoriser_multiple_prets,
                    $categories_membres_json, $statut, $_SESSION['user_id']
                ]);
                
                $message = "Plan de prêt créé avec succès. Code généré : " . $code_plan_auto;
                
            } elseif ($action === 'modifier' && $id) {
                // Pour la modification, on garde le code existant
                $sql = "UPDATE plans_pret SET 
    type_pret_id = ?, nom_pret = ?, description = ?,
    taux_interet_annuel = ?, methode_calcul = ?, duree_min_mois = ?, duree_max_mois = ?,
    montant_min = ?, montant_max = ?, penalite_retard = ?, frais_dossier = ?, assurance = ?,
    frais_dossier_type = ?, score_credit_min = ?, anciennete_min_mois = ?, cotisation_requise = ?,
    duree_min_cotisation_mois = ?, montant_min_cotisation_total = ?, autoriser_multiple_prets = ?,
    categories_membres_json = ?, statut = ?
    WHERE id = ?";
                
                $stmt = $pdo->prepare($sql);
                $stmt->execute([
                    $type_pret_id, $nom, $description,
                    $taux_interet_annuel, $methode_calcul, $duree_min_mois, $duree_max_mois,
                    $montant_min, $montant_max, $penalite_retard, $frais_dossier, $assurance,
                    $frais_dossier_type, $score_credit_min, $anciennete_min_mois, $cotisation_requise,
                    $duree_min_cotisation_mois, $montant_min_cotisation_total, $autoriser_multiple_prets,
                    $categories_membres_json, $statut, $id
                ]);
                
                $message = "Plan de prêt modifié avec succès";
            }
            
            if (empty($erreur)) {
                $_SESSION['message'] = $message;
                rediriger('plans_pret.php');
            }
        }
    }

    // Récupérer les données pour modification
    $plan = null;
    $categories_plan = [];
    if ($id && ($action === 'modifier' || $action === 'supprimer')) {
        $stmt = $pdo->prepare("
            SELECT pp.*, tp.nom_type as type_pret
            FROM plans_pret pp 
            LEFT JOIN types_pret tp ON pp.type_pret_id = tp.id 
            WHERE pp.id = ?
        ");
        $stmt->execute([$id]);
        $plan = $stmt->fetch();
        
        if (!$plan) {
            $_SESSION['erreur'] = "Plan de prêt non trouvé";
            rediriger('plans_pret.php');
        }
        
        // Décoder les catégories JSON si elles existent
        if ($plan['categories_membres_json']) {
            $categories_plan = json_decode($plan['categories_membres_json'], true);
            if (!is_array($categories_plan)) {
                $categories_plan = [];
            }
        }
    }

    // Action de suppression
    if ($action === 'supprimer' && $id && $_SERVER['REQUEST_METHOD'] === 'POST') {
        // Vérifier s'il y a des prêts associés à ce plan
        $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM prets WHERE plan_pret_id = ?");
        $stmt->execute([$id]);
        $result = $stmt->fetch();
        
        if ($result['count'] > 0) {
            $erreur = "Impossible de supprimer ce plan : des prêts y sont associés";
        } else {
            $stmt = $pdo->prepare("DELETE FROM plans_pret WHERE id = ?");
            $stmt->execute([$id]);
            $_SESSION['message'] = "Plan de prêt supprimé avec succès";
            rediriger('plans_pret.php');
        }
    }

} catch (PDOException $e) {
    $erreur = "Erreur de base de données : " . $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestion des Plans de Prêt - <?php echo APP_NAME; ?></title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.1.3/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        .card { margin-bottom: 20px; box-shadow: 0 0.125rem 0.25rem rgba(0, 0, 0, 0.075); }
        .table-actions { white-space: nowrap; }
        .statut-actif { color: #198754; }
        .statut-inactif { color: #6c757d; }
        .statut-brouillon { color: #ffc107; }
        .required:after { content: " *"; color: red; }
        .form-section { 
            background: #f8f9fa; 
            padding: 15px; 
            border-radius: 5px; 
            margin-bottom: 20px; 
            border-left: 4px solid #007bff;
        }
        .form-section h6 {
            color: #007bff;
            margin-bottom: 15px;
        }
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
    </style>
</head>
<body>
    <div class="container-fluid">
        <div class="row">
            <!-- Sidebar intégrée directement -->
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
                                <a class="nav-link active" href="plans_pret.php">
                                    <i class="fas fa-clipboard-list"></i>Plans de Prêt
                                </a>
                            </li>
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
                <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
                    <h1 class="h2">
                        <i class="fas fa-clipboard-list"></i>
                        Gestion des Plans de Prêt
                    </h1>
                </div>

                <!-- Messages -->
                <?php if (isset($_SESSION['message'])): ?>
                    <div class="alert alert-success"><?= $_SESSION['message']; unset($_SESSION['message']); ?></div>
                <?php endif; ?>
                <?php if (isset($_SESSION['erreur'])): ?>
                    <div class="alert alert-danger"><?= $_SESSION['erreur']; unset($_SESSION['erreur']); ?></div>
                <?php endif; ?>
                <?php if (!empty($message)): ?>
                    <div class="alert alert-success"><?= $message ?></div>
                <?php endif; ?>
                <?php if (!empty($erreur)): ?>
                    <div class="alert alert-danger"><?= $erreur ?></div>
                <?php endif; ?>

                <?php if ($action === 'liste'): ?>
                    <!-- Liste des plans de prêt -->
                    <div class="card">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <h5 class="mb-0">Liste des Plans de Prêt</h5>
                            <a href="?action=ajouter" class="btn btn-primary btn-sm">
                                <i class="fas fa-plus"></i> Nouveau Plan
                            </a>
                        </div>
                        <div class="card-body">
                            <div class="table-responsive">
                                <table class="table table-striped table-hover">
                                    <thead>
                                        <tr>
                                            <th>Code</th>
                                            <th>Nom</th>
                                            <th>Type</th>
                                            <th>Montant</th>
                                            <th>Taux</th>
                                            <th>Durée</th>
                                            <th>Statut</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php
                                        $stmt = $pdo->query("
                                            SELECT pp.*, tp.nom_type as type_pret
                                            FROM plans_pret pp 
                                            LEFT JOIN types_pret tp ON pp.type_pret_id = tp.id 
                                            ORDER BY pp.date_creation DESC
                                        ");
                                        while ($row = $stmt->fetch()):
                                            $statut_class = 'statut-' . $row['statut'];
                                        ?>
                                        <tr>
                                            <td><strong><?= htmlspecialchars($row['code_plan']) ?></strong></td>
                                            <td><?= htmlspecialchars($row['nom_pret']) ?></td>
                                            <td><?= htmlspecialchars($row['type_pret']) ?></td>
                                            <td><?= format_montant($row['montant_min']) ?> - <?= format_montant($row['montant_max']) ?></td>
                                            <td><?= $row['taux_interet_annuel'] ?>%</td>
                                            <td><?= $row['duree_min_mois'] ?>-<?= $row['duree_max_mois'] ?> mois</td>
                                            <td>
                                                <span class="<?= $statut_class ?>">
                                                    <i class="fas fa-circle"></i> <?= ucfirst($row['statut']) ?>
                                                </span>
                                            </td>
                                            <td class="table-actions">
                                                <a href="?action=modifier&id=<?= $row['id'] ?>" class="btn btn-warning btn-sm" title="Modifier">
                                                    <i class="fas fa-edit"></i>
                                                </a>
                                                <a href="?action=supprimer&id=<?= $row['id'] ?>" class="btn btn-danger btn-sm" title="Supprimer">
                                                    <i class="fas fa-trash"></i>
                                                </a>
                                            </td>
                                        </tr>
                                        <?php endwhile; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>

                <?php elseif ($action === 'ajouter' || $action === 'modifier'): ?>
                    <!-- Formulaire d'ajout/modification -->
                    <div class="card">
                        <div class="card-header">
                            <h5 class="mb-0">
                                <i class="fas <?= $action === 'ajouter' ? 'fa-plus' : 'fa-edit' ?>"></i>
                                <?= $action === 'ajouter' ? 'Ajouter un Plan de Prêt' : 'Modifier le Plan de Prêt' ?>
                            </h5>
                        </div>
                        <div class="card-body">
                            <form method="POST">
                                <div class="form-section">
                                    <h6><i class="fas fa-info-circle"></i> Informations de base</h6>
                                    <div class="row">
                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label class="form-label">Code du plan</label>
                                                <input type="text" class="form-control" 
                                                       value="<?= $action === 'ajouter' ? 'Généré automatiquement' : (isset($plan['code_plan']) ? htmlspecialchars($plan['code_plan']) : '') ?>" 
                                                       readonly disabled
                                                       style="background-color: #f8f9fa; color: #6c757d;">
                                                <small class="form-text text-muted">
                                                    Le code sera généré automatiquement lors de la création
                                                </small>
                                            </div>

                                            <div class="mb-3">
                                                <label class="form-label required">Type de prêt</label>
                                                <select name="type_pret_id" class="form-control" required>
                                                    <option value="">Sélectionnez un type</option>
                                                    <?php foreach ($types_pret as $type): ?>
                                                    <option value="<?= $type['id'] ?>" 
                                                        <?= (isset($plan) && $plan['type_pret_id'] == $type['id']) ? 'selected' : '' ?>>
                                                        <?= htmlspecialchars($type['nom_type']) ?>
                                                    </option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label class="form-label required">Nom</label>
                                                <input type="text" name="nom" class="form-control" 
                                                       value="<?= isset($plan['nom_pret']) ? htmlspecialchars($plan['nom_pret']) : '' ?>" required>
                                            </div>

                                            <div class="mb-3">
                                                <label class="form-label">Description</label>
                                                <textarea name="description" class="form-control" rows="2"><?= isset($plan['description']) ? htmlspecialchars($plan['description']) : '' ?></textarea>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <div class="form-section">
                                    <h6><i class="fas fa-calculator"></i> Paramètres financiers</h6>
                                    <div class="row">
                                        <div class="col-md-4">
                                            <div class="mb-3">
                                                <label class="form-label required">Taux d'intérêt annuel (%)</label>
                                                <input type="number" name="taux_interet_annuel" class="form-control" 
                                                       step="0.01" min="0" value="<?= isset($plan['taux_interet_annuel']) ? $plan['taux_interet_annuel'] : '' ?>" required>
                                            </div>
                                        </div>
                                        <div class="col-md-4">
                                            <div class="mb-3">
                                                <label class="form-label required">Méthode de calcul</label>
                                                <select name="methode_calcul" class="form-control" required>
                                                    <option value="annuite_constante" <?= (isset($plan) && $plan['methode_calcul'] === 'annuite_constante') ? 'selected' : '' ?>>Annuité constante</option>
                                                    <option value="lineaire" <?= (isset($plan) && $plan['methode_calcul'] === 'lineaire') ? 'selected' : '' ?>>Linéaire</option>
                                                    <option value="in fine" <?= (isset($plan) && $plan['methode_calcul'] === 'in fine') ? 'selected' : '' ?>>In fine</option>
                                                </select>
                                            </div>
                                        </div>
                                        <div class="col-md-4">
                                            <div class="mb-3">
                                                <label class="form-label">Pénalité de retard (%)</label>
                                                <input type="number" name="penalite_retard" class="form-control" 
                                                       step="0.01" min="0" value="<?= isset($plan['penalite_retard']) ? $plan['penalite_retard'] : 0 ?>">
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <div class="form-section">
                                    <h6><i class="fas fa-chart-line"></i> Montants et durées</h6>
                                    <div class="row">
                                        <div class="col-md-6">
                                            <div class="row">
                                                <div class="col-md-6">
                                                    <div class="mb-3">
                                                        <label class="form-label required">Montant minimum (FBu)</label>
                                                        <input type="number" name="montant_min" class="form-control" 
                                                               step="1000" min="0" value="<?= isset($plan['montant_min']) ? $plan['montant_min'] : '' ?>" required>
                                                    </div>
                                                </div>
                                                <div class="col-md-6">
                                                    <div class="mb-3">
                                                        <label class="form-label required">Montant maximum (FBu)</label>
                                                        <input type="number" name="montant_max" class="form-control" 
                                                               step="1000" min="0" value="<?= isset($plan['montant_max']) ? $plan['montant_max'] : '' ?>" required>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="row">
                                                <div class="col-md-6">
                                                    <div class="mb-3">
                                                        <label class="form-label required">Durée minimale (mois)</label>
                                                        <input type="number" name="duree_min_mois" class="form-control" 
                                                               min="1" value="<?= isset($plan['duree_min_mois']) ? $plan['duree_min_mois'] : '' ?>" required>
                                                    </div>
                                                </div>
                                                <div class="col-md-6">
                                                    <div class="mb-3">
                                                        <label class="form-label required">Durée maximale (mois)</label>
                                                        <input type="number" name="duree_max_mois" class="form-control" 
                                                               min="1" value="<?= isset($plan['duree_max_mois']) ? $plan['duree_max_mois'] : '' ?>" required>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <div class="form-section">
                                    <h6><i class="fas fa-money-bill-wave"></i> Frais supplémentaires</h6>
                                    <div class="row">
                                        <div class="col-md-4">
                                            <div class="mb-3">
                                                <label class="form-label">Frais de dossier (FBu)</label>
                                                <input type="number" name="frais_dossier" class="form-control" 
                                                       step="100" min="0" value="<?= isset($plan['frais_dossier']) ? $plan['frais_dossier'] : 0 ?>">
                                            </div>
                                        </div>
                                        <div class="col-md-4">
                                            <div class="mb-3">
                                                <label class="form-label">Type frais dossier</label>
                                                <select name="frais_dossier_type" class="form-control">
                                                    <option value="fixe" <?= (isset($plan) && $plan['frais_dossier_type'] === 'fixe') ? 'selected' : '' ?>>Fixe</option>
                                                    <option value="pourcentage" <?= (isset($plan) && $plan['frais_dossier_type'] === 'pourcentage') ? 'selected' : '' ?>>Pourcentage</option>
                                                </select>
                                            </div>
                                        </div>
                                        <div class="col-md-4">
                                            <div class="mb-3">
                                                <label class="form-label">Assurance (%)</label>
                                                <input type="number" name="assurance" class="form-control" 
                                                       step="0.01" min="0" value="<?= isset($plan['assurance']) ? $plan['assurance'] : 0 ?>">
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <div class="form-section">
                                    <h6><i class="fas fa-user-check"></i> Conditions d'éligibilité</h6>
                                    <div class="row">
                                        <div class="col-md-4">
                                            <div class="mb-3">
                                                <label class="form-label">Score crédit minimum</label>
                                                <input type="number" name="score_credit_min" class="form-control" 
                                                       min="0" max="1000" value="<?= isset($plan['score_credit_min']) ? $plan['score_credit_min'] : 300 ?>">
                                            </div>
                                        </div>
                                        <div class="col-md-4">
                                            <div class="mb-3">
                                                <label class="form-label">Ancienneté minimum (mois)</label>
                                                <input type="number" name="anciennete_min_mois" class="form-control" 
                                                       min="0" value="<?= isset($plan['anciennete_min_mois']) ? $plan['anciennete_min_mois'] : 3 ?>">
                                            </div>
                                        </div>
                                        <div class="col-md-4">
                                            <div class="mb-3">
                                                <label class="form-label">Statut</label>
                                                <select name="statut" class="form-control">
                                                    <option value="brouillon" <?= (isset($plan) && $plan['statut'] === 'brouillon') ? 'selected' : '' ?>>Brouillon</option>
                                                    <option value="actif" <?= (isset($plan) && $plan['statut'] === 'actif') ? 'selected' : '' ?>>Actif</option>
                                                    <option value="inactif" <?= (isset($plan) && $plan['statut'] === 'inactif') ? 'selected' : '' ?>>Inactif</option>
                                                </select>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <div class="row">
                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label class="form-label">Catégories de membres autorisées</label>
                                                <div style="max-height: 150px; overflow-y: auto; border: 1px solid #ddd; padding: 10px; border-radius: 4px;">
                                                    <?php foreach ($categories_membres as $categorie): ?>
                                                    <div class="form-check">
                                                        <input class="form-check-input" type="checkbox" name="categories_autorisees[]" 
                                                               value="<?= $categorie['id'] ?>" 
                                                               id="cat_<?= $categorie['id'] ?>"
                                                               <?= in_array($categorie['id'], $categories_plan) ? 'checked' : '' ?>>
                                                        <label class="form-check-label" for="cat_<?= $categorie['id'] ?>">
                                                            <?= htmlspecialchars($categorie['nom_categorie']) ?> (<?= htmlspecialchars($categorie['code_categorie']) ?>)
                                                        </label>
                                                    </div>
                                                    <?php endforeach; ?>
                                                </div>
                                                <small class="form-text text-muted">Laissez vide pour autoriser toutes les catégories</small>
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <div class="form-check mt-3">
                                                    <input class="form-check-input" type="checkbox" name="cotisation_requise" 
                                                           value="1" <?= (isset($plan) && $plan['cotisation_requise']) ? 'checked' : 'checked' ?>>
                                                    <label class="form-check-label">Cotisation requise</label>
                                                </div>
                                                
                                                <div class="form-check">
                                                    <input class="form-check-input" type="checkbox" name="autoriser_multiple_prets" 
                                                           value="1" <?= (isset($plan) && $plan['autoriser_multiple_prets']) ? 'checked' : '' ?>>
                                                    <label class="form-check-label">Autoriser prêts multiples</label>
                                                </div>
                                                
                                              
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <div class="d-flex justify-content-between">
                                    <a href="plans_pret.php" class="btn btn-secondary">
                                        <i class="fas fa-arrow-left"></i> Retour
                                    </a>
                                    <button type="submit" class="btn btn-primary">
                                        <i class="fas fa-save"></i> 
                                        <?= $action === 'ajouter' ? 'Créer le plan' : 'Modifier le plan' ?>
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>

                <?php elseif ($action === 'supprimer' && isset($plan)): ?>
                    <!-- Confirmation de suppression -->
                    <div class="card">
                        <div class="card-header">
                            <h5 class="mb-0 text-danger">
                                <i class="fas fa-exclamation-triangle"></i> Confirmation de suppression
                            </h5>
                        </div>
                        <div class="card-body">
                            <div class="alert alert-warning">
                                <h6><i class="fas fa-exclamation-circle"></i> Attention !</h6>
                                <p>Vous êtes sur le point de supprimer le plan de prêt suivant :</p>
                                <ul>
                                    <li><strong>Code :</strong> <?= htmlspecialchars($plan['code_plan']) ?></li>
                                    <li><strong>Nom :</strong> <?= htmlspecialchars($plan['nom_pret']) ?></li>
                                    <li><strong>Type :</strong> <?= htmlspecialchars($plan['type_pret']) ?></li>
                                </ul>
                                <p class="mb-0"><strong>Cette action est irréversible !</strong></p>
                            </div>

                            <?php if (!empty($erreur)): ?>
                                <div class="alert alert-danger"><?= $erreur ?></div>
                            <?php endif; ?>

                            <form method="POST">
                                <div class="d-flex justify-content-between">
                                    <a href="plans_pret.php" class="btn btn-secondary">
                                        <i class="fas fa-times"></i> Annuler
                                    </a>
                                    <button type="submit" class="btn btn-danger" 
                                            <?= !empty($erreur) ? 'disabled' : '' ?>>
                                        <i class="fas fa-trash"></i> Confirmer la suppression
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                <?php endif; ?>
            </main>
        </div>
    </div>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.1.3/js/bootstrap.bundle.min.js"></script>
    <script>
        // Script pour gérer l'affichage conditionnel des champs
        document.addEventListener('DOMContentLoaded', function() {
            // Mise à jour du label des frais de dossier selon le type
            const fraisDossierType = document.querySelector('select[name="frais_dossier_type"]');
            const fraisDossierInput = document.querySelector('input[name="frais_dossier"]');
            
            if (fraisDossierType && fraisDossierInput) {
                function updateFraisDossierLabel() {
                    const label = document.querySelector('label[for="frais_dossier"]');
                    if (fraisDossierType.value === 'pourcentage') {
                        label.textContent = 'Frais de dossier (%)';
                        fraisDossierInput.step = '0.01';
                        fraisDossierInput.max = '100';
                    } else {
                        label.textContent = 'Frais de dossier (FBu)';
                        fraisDossierInput.step = '100';
                        fraisDossierInput.max = '';
                    }
                }
                
                fraisDossierType.addEventListener('change', updateFraisDossierLabel);
                updateFraisDossierLabel(); // Initialisation
            }
        });
    </script>
</body>
</html>
