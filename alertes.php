<?php
require_once 'config_database.php';

// Vérifier l'authentification
if (!est_connecte()) {
    rediriger('login.php');
}

// Variables pour la pagination et le filtrage
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = 20;
$offset = ($page - 1) * $limit;
$type_alerte = isset($_GET['type_alerte']) ? securiser($_GET['type_alerte']) : '';
$severite = isset($_GET['severite']) ? securiser($_GET['severite']) : '';
$lue = isset($_GET['lue']) ? securiser($_GET['lue']) : '';

// Construire la requête avec filtres
$where_conditions = [];
$params = [];

if (!empty($type_alerte)) {
    $where_conditions[] = "type_alerte = ?";
    $params[] = $type_alerte;
}

if (!empty($severite)) {
    $where_conditions[] = "severite = ?";
    $params[] = $severite;
}

if ($lue !== '') {
    $where_conditions[] = "lue = ?";
    $params[] = $lue;
}

// Déterminer les alertes à afficher selon le rôle
if (a_la_permission('admin')) {
    // Admin voit toutes les alertes
    $where_conditions[] = "destinataire_type IN ('membre', 'employe', 'systeme')";
} elseif (a_la_permission('gestionnaire') || a_la_permission('caissier')) {
    // Gestionnaires et caissiers voient les alertes système et employé
    $where_conditions[] = "destinataire_type IN ('employe', 'systeme')";
} else {
    // Autres rôles ne voient que leurs propres alertes
    $where_conditions[] = "(destinataire_type = 'employe' AND destinataire_id = ?)";
    $params[] = $_SESSION['user_id'];
}

$where_clause = '';
if (!empty($where_conditions)) {
    $where_clause = "WHERE " . implode(" AND ", $where_conditions);
}

try {
    // Compter le nombre total d'alertes
    $count_sql = "SELECT COUNT(*) as total FROM alertes $where_clause";
    $stmt_count = $pdo->prepare($count_sql);
    $stmt_count->execute($params);
    $total_alertes = $stmt_count->fetch()['total'];
    $total_pages = ceil($total_alertes / $limit);

    // Récupérer les alertes avec pagination
    $sql = "
        SELECT a.*, 
               CASE 
                   WHEN a.destinataire_type = 'membre' THEN m.nom 
                   WHEN a.destinataire_type = 'employe' THEN u.nom_complet 
                   ELSE 'Système' 
               END as nom_destinataire,
               CASE 
                   WHEN a.destinataire_type = 'membre' THEN m.prenom 
                   ELSE '' 
               END as prenom_destinataire
        FROM alertes a
        LEFT JOIN membres m ON a.destinataire_type = 'membre' AND a.destinataire_id = m.code_membre
        LEFT JOIN utilisateurs u ON a.destinataire_type = 'employe' AND a.destinataire_id = u.id
        $where_clause
        ORDER BY a.date_creation DESC
        LIMIT ? OFFSET ?
    ";
    
    $params_pagination = array_merge($params, [$limit, $offset]);
    $stmt_alertes = $pdo->prepare($sql);
    $stmt_alertes->execute($params_pagination);
    $alertes = $stmt_alertes->fetchAll();

    // Marquer les alertes comme lues si demandé
    if (isset($_GET['marquer_lues']) && $_GET['marquer_lues'] == 'true') {
        $sql_marquer_lues = "UPDATE alertes SET lue = TRUE, date_lue = NOW() WHERE lue = FALSE";
        if (!a_la_permission('admin')) {
            $sql_marquer_lues .= " AND destinataire_type = 'employe' AND destinataire_id = ?";
            $pdo->prepare($sql_marquer_lues)->execute([$_SESSION['user_id']]);
        } else {
            $pdo->query($sql_marquer_lues);
        }
        
        // Rediriger pour éviter la resoumission du formulaire
        rediriger('alertes.php?success=alertes_marquees_lues');
    }

    // Supprimer les alertes lues si demandé
    if (isset($_GET['supprimer_lues']) && $_GET['supprimer_lues'] == 'true') {
        $sql_supprimer_lues = "DELETE FROM alertes WHERE lue = TRUE";
        if (!a_la_permission('admin')) {
            $sql_supprimer_lues .= " AND destinataire_type = 'employe' AND destinataire_id = ?";
            $pdo->prepare($sql_supprimer_lues)->execute([$_SESSION['user_id']]);
        } else {
            $pdo->query($sql_supprimer_lues);
        }
        
        // Rediriger pour éviter la resoumission du formulaire
        rediriger('alertes.php?success=alertes_lues_supprimees');
    }

} catch (PDOException $e) {
    $erreur = "Erreur lors du chargement des alertes: " . $e->getMessage();
}

// Récupérer les types d'alertes uniques pour le filtre
$stmt_types = $pdo->query("SELECT DISTINCT type_alerte FROM alertes ORDER BY type_alerte");
$types_alertes = $stmt_types->fetchAll(PDO::FETCH_COLUMN);

// Récupérer les sévérités uniques pour le filtre
$stmt_severites = $pdo->query("SELECT DISTINCT severite FROM alertes ORDER BY severite");
$severites = $stmt_severites->fetchAll(PDO::FETCH_COLUMN);
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestion des Alertes - <?php echo APP_NAME; ?></title>
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
        .badge-severite-faible { background-color: #28a745; }
        .badge-severite-moyenne { background-color: #ffc107; color: #000; }
        .badge-severite-elevee { background-color: #fd7e14; }
        .badge-severite-critique { background-color: #dc3545; }
        .alerte-non-lue { 
            background-color: #f8f9fa;
            border-left: 4px solid #007bff;
        }
        .alerte-lue {
            background-color: #ffffff;
            opacity: 0.8;
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
        .card-header {
            border-bottom: 1px solid #e3e6f0;
        }
        .pagination .page-link {
            color: #2c3e50;
        }
        .pagination .page-item.active .page-link {
            background-color: #2c3e50;
            border-color: #2c3e50;
        }
    </style>
</head>
<body>
    <div class="container-fluid">
        <div class="row">
            <!-- Sidebar (identique au dashboard) -->
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
                <!-- Header -->
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <div>
                        <h1 class="h3 mb-0">
                            <i class="fas fa-bell text-warning me-2"></i>Gestion des Alertes
                        </h1>
                        <p class="text-muted mb-0">Consultez et gérez toutes les alertes du système</p>
                    </div>
                    <div>
                        <span class="badge bg-light text-dark">
                            <i class="fas fa-user-shield me-1"></i><?php echo ucfirst($_SESSION['user_role']); ?>
                        </span>
                    </div>
                </div>

                <!-- Messages de succès/erreur -->
                <?php if (isset($erreur)): ?>
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        <i class="fas fa-exclamation-triangle me-2"></i><?php echo $erreur; ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <?php if (isset($_GET['success'])): ?>
                    <div class="alert alert-success alert-dismissible fade show" role="alert">
                        <i class="fas fa-check-circle me-2"></i>
                        <?php 
                        switch($_GET['success']) {
                            case 'alertes_marquees_lues':
                                echo 'Toutes les alertes ont été marquées comme lues.';
                                break;
                            case 'alertes_lues_supprimees':
                                echo 'Les alertes lues ont été supprimées.';
                                break;
                            default:
                                echo 'Opération effectuée avec succès.';
                        }
                        ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <!-- Filtres et actions -->
                <div class="card shadow mb-4">
                    <div class="card-header bg-light">
                        <h6 class="m-0 font-weight-bold text-primary">
                            <i class="fas fa-filter me-2"></i>Filtres et Actions
                        </h6>
                    </div>
                    <div class="card-body">
                        <form method="GET" class="row g-3">
                            <div class="col-md-3">
                                <label class="form-label">Type d'alerte</label>
                                <select name="type_alerte" class="form-select">
                                    <option value="">Tous les types</option>
                                    <?php foreach ($types_alertes as $type): ?>
                                        <option value="<?php echo $type; ?>" <?php echo $type_alerte == $type ? 'selected' : ''; ?>>
                                            <?php echo ucfirst(str_replace('_', ' ', $type)); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Sévérité</label>
                                <select name="severite" class="form-select">
                                    <option value="">Toutes les sévérités</option>
                                    <?php foreach ($severites as $sev): ?>
                                        <option value="<?php echo $sev; ?>" <?php echo $severite == $sev ? 'selected' : ''; ?>>
                                            <?php echo ucfirst($sev); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Statut</label>
                                <select name="lue" class="form-select">
                                    <option value="">Toutes</option>
                                    <option value="0" <?php echo $lue === '0' ? 'selected' : ''; ?>>Non lues</option>
                                    <option value="1" <?php echo $lue === '1' ? 'selected' : ''; ?>>Lues</option>
                                </select>
                            </div>
                            <div class="col-md-3 d-flex align-items-end">
                                <div class="btn-group w-100">
                                    <button type="submit" class="btn btn-primary">
                                        <i class="fas fa-search me-1"></i>Filtrer
                                    </button>
                                    <a href="alertes.php" class="btn btn-outline-secondary">
                                        <i class="fas fa-redo me-1"></i>Réinitialiser
                                    </a>
                                </div>
                            </div>
                        </form>

                        <div class="row mt-3">
                            <div class="col-12">
                                <div class="btn-group">
                                    <a href="alertes.php?marquer_lues=true" class="btn btn-success btn-sm">
                                        <i class="fas fa-check-double me-1"></i>Marquer toutes comme lues
                                    </a>
                                    <a href="alertes.php?supprimer_lues=true" class="btn btn-danger btn-sm" onclick="return confirm('Êtes-vous sûr de vouloir supprimer toutes les alertes lues ?')">
                                        <i class="fas fa-trash me-1"></i>Supprimer les alertes lues
                                    </a>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Liste des alertes -->
                <div class="card shadow">
                    <div class="card-header bg-warning text-dark d-flex justify-content-between align-items-center">
                        <h6 class="m-0 font-weight-bold">
                            <i class="fas fa-bell me-2"></i>Liste des Alertes
                            <span class="badge bg-dark ms-2"><?php echo number_format($total_alertes); ?></span>
                        </h6>
                        <div>
                            <span class="badge bg-danger me-2">Non lues: <?php 
                                $non_lues = array_filter($alertes, function($a) { return !$a['lue']; });
                                echo count($non_lues);
                            ?></span>
                        </div>
                    </div>
                    <div class="card-body">
                        <?php if (!empty($alertes)): ?>
                            <div class="list-group">
                                <?php foreach ($alertes as $alerte): ?>
                                    <div class="list-group-item <?php echo $alerte['lue'] ? 'alerte-lue' : 'alerte-non-lue'; ?>">
                                        <div class="d-flex w-100 justify-content-between align-items-start">
                                            <div class="flex-grow-1 me-3">
                                                <div class="d-flex align-items-center mb-2">
                                                    <h6 class="mb-0">
                                                        <i class="fas fa-<?php 
                                                            switch($alerte['type_alerte']) {
                                                                case 'retard_paiement': echo 'exclamation-triangle'; break;
                                                                case 'cotisation_expire': echo 'clock'; break;
                                                                case 'pret_approche_echeance': echo 'calendar-alt'; break;
                                                                case 'systeme': echo 'cogs'; break;
                                                                case 'securite': echo 'shield-alt'; break;
                                                                default: echo 'info-circle';
                                                            }
                                                        ?> me-2 text-<?php 
                                                            switch($alerte['severite']) {
                                                                case 'critique': echo 'danger'; break;
                                                                case 'elevee': echo 'warning'; break;
                                                                case 'moyenne': echo 'info'; break;
                                                                case 'faible': echo 'success'; break;
                                                                default: echo 'secondary';
                                                            }
                                                        ?>"></i>
                                                        <?php echo securiser($alerte['titre']); ?>
                                                    </h6>
                                                    <span class="badge badge-severite-<?php echo $alerte['severite']; ?> ms-2">
                                                        <?php echo ucfirst($alerte['severite']); ?>
                                                    </span>
                                                    <?php if (!$alerte['lue']): ?>
                                                        <span class="badge bg-primary ms-2">Nouveau</span>
                                                    <?php endif; ?>
                                                </div>
                                                <p class="mb-2"><?php echo securiser($alerte['message']); ?></p>
                                                <div class="text-muted small">
                                                    <i class="fas fa-user me-1"></i>
                                                    <?php 
                                                    if ($alerte['destinataire_type'] == 'membre') {
                                                        echo 'Membre: ' . securiser($alerte['nom_destinataire'] . ' ' . $alerte['prenom_destinataire']);
                                                    } elseif ($alerte['destinataire_type'] == 'employe') {
                                                        echo 'Employé: ' . securiser($alerte['nom_destinataire']);
                                                    } else {
                                                        echo 'Système';
                                                    }
                                                    ?>
                                                    • 
                                                    <i class="fas fa-clock me-1"></i>
                                                    <?php echo date('d/m/Y H:i', strtotime($alerte['date_creation'])); ?>
                                                    <?php if ($alerte['lue']): ?>
                                                        • <i class="fas fa-check text-success me-1"></i>Lue le: <?php echo date('d/m/Y H:i', strtotime($alerte['date_lue'])); ?>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                            <div class="flex-shrink-0">
                                                <span class="badge bg-secondary">
                                                    <?php echo ucfirst(str_replace('_', ' ', $alerte['type_alerte'])); ?>
                                                </span>
                                            </div>
                                        </div>
                                        <?php if (!empty($alerte['lien_action'])): ?>
                                            <div class="mt-2">
                                                <a href="<?php echo securiser($alerte['lien_action']); ?>" class="btn btn-sm btn-outline-primary">
                                                    <i class="fas fa-external-link-alt me-1"></i>Voir les détails
                                                </a>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>

                            <!-- Pagination -->
                            <?php if ($total_pages > 1): ?>
                                <nav class="mt-4">
                                    <ul class="pagination justify-content-center">
                                        <?php if ($page > 1): ?>
                                            <li class="page-item">
                                                <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page - 1])); ?>">
                                                    <i class="fas fa-chevron-left"></i> Précédent
                                                </a>
                                            </li>
                                        <?php endif; ?>

                                        <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                                            <li class="page-item <?php echo $i == $page ? 'active' : ''; ?>">
                                                <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $i])); ?>">
                                                    <?php echo $i; ?>
                                                </a>
                                            </li>
                                        <?php endfor; ?>

                                        <?php if ($page < $total_pages): ?>
                                            <li class="page-item">
                                                <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page + 1])); ?>">
                                                    Suivant <i class="fas fa-chevron-right"></i>
                                                </a>
                                            </li>
                                        <?php endif; ?>
                                    </ul>
                                </nav>
                            <?php endif; ?>

                        <?php else: ?>
                            <div class="text-center py-5">
                                <i class="fas fa-bell-slash fa-3x text-muted mb-3"></i>
                                <h5 class="text-muted">Aucune alerte trouvée</h5>
                                <p class="text-muted">Aucune alerte ne correspond à vos critères de recherche.</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </main>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Auto-actualisation toutes les 2 minutes pour les nouvelles alertes
        setTimeout(function() {
            window.location.reload();
        }, 120000);

        // Confirmation pour les actions critiques
        document.addEventListener('DOMContentLoaded', function() {
            const linksSuppression = document.querySelectorAll('a[href*="supprimer_lues"]');
            linksSuppression.forEach(link => {
                link.addEventListener('click', function(e) {
                    if (!confirm('Êtes-vous sûr de vouloir supprimer toutes les alertes lues ? Cette action est irréversible.')) {
                        e.preventDefault();
                    }
                });
            });
        });
    </script>
</body>
</html>