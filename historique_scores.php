<?php
require_once 'config_database.php';

// Vérifier l'authentification et les permissions
if (!est_connecte()) {
    rediriger('login.php');
}

// Vérifier la permission admin
if (!a_la_permission('admin')) {
    $_SESSION['erreur'] = "Accès refusé. Permissions insuffisantes.";
    rediriger('dashboard.php');
}

// Variables pour la pagination et la recherche
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = 20;
$offset = ($page - 1) * $limit;
$recherche = isset($_GET['recherche']) ? trim($_GET['recherche']) : '';
$type_filtre = isset($_GET['type_filtre']) ? $_GET['type_filtre'] : '';

try {
    // Construire la requête avec filtres
    $where_conditions = [];
    $params = [];
    
    if (!empty($recherche)) {
        $where_conditions[] = "(m.code_membre LIKE ? OR m.nom LIKE ? OR m.prenom LIKE ?)";
        $params[] = "%$recherche%";
        $params[] = "%$recherche%";
        $params[] = "%$recherche%";
    }
    
    if (!empty($type_filtre)) {
        $where_conditions[] = "hs.type_modification = ?";
        $params[] = $type_filtre;
    }
    
    $where_sql = '';
    if (!empty($where_conditions)) {
        $where_sql = 'WHERE ' . implode(' AND ', $where_conditions);
    }
    
    // Requête pour récupérer l'historique
    $sql = "
        SELECT 
            hs.*,
            m.code_membre,
            m.nom,
            m.prenom,
            m.email,
            cm.nom_categorie,
            u.nom_complet as modificateur_nom
        FROM historique_scores hs
        LEFT JOIN membres m ON hs.code_membre = m.code_membre
        LEFT JOIN categories_membres cm ON m.categorie_id = cm.id
        LEFT JOIN utilisateurs u ON hs.modifie_par = u.username
        $where_sql
        ORDER BY hs.date_modification DESC
        LIMIT ? OFFSET ?
    ";
    
    $stmt = $pdo->prepare($sql);
    $params[] = $limit;
    $params[] = $offset;
    $stmt->execute($params);
    $historique_scores = $stmt->fetchAll();
    
    // Compter le total pour la pagination
    $count_sql = "
        SELECT COUNT(*) as total
        FROM historique_scores hs
        LEFT JOIN membres m ON hs.code_membre = m.code_membre
        $where_sql
    ";
    
    $stmt_count = $pdo->prepare($count_sql);
    $count_params = array_slice($params, 0, -2); // Retirer limit et offset
    $stmt_count->execute($count_params);
    $total_records = $stmt_count->fetch()['total'];
    $total_pages = ceil($total_records / $limit);
    
    // Statistiques pour le header
    $stmt_stats = $pdo->query("
        SELECT 
            COUNT(*) as total_modifications,
            COUNT(DISTINCT code_membre) as membres_affectes,
            AVG(nouveau_score - ancien_score) as variation_moyenne
        FROM historique_scores
        WHERE date_modification >= DATE_SUB(NOW(), INTERVAL 30 DAY)
    ");
    $stats = $stmt_stats->fetch();
    
} catch (PDOException $e) {
    $erreur = "Erreur lors du chargement de l'historique: " . $e->getMessage();
}
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Historique des Scores - <?php echo APP_NAME; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://cdn.datatables.net/1.11.5/css/dataTables.bootstrap5.min.css" rel="stylesheet">
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
            transform: translateY(-2px);
        }
        .score-up {
            color: #28a745;
        }
        .score-down {
            color: #dc3545;
        }
        .score-neutral {
            color: #6c757d;
        }
        .badge-score {
            font-size: 0.8em;
            padding: 4px 8px;
        }
        .table-hover tbody tr:hover {
            background-color: rgba(0, 0, 0, 0.075);
        }
        .search-box {
            background: #f8f9fa;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 20px;
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
                                <a class="nav-link active" href="historique_scores.php">
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
                <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
                    <h1 class="h2">
                        <i class="fas fa-chart-line text-primary me-2"></i>Historique des Scores de Crédit
                    </h1>
                    <div class="btn-toolbar mb-2 mb-md-0">
                        <span class="badge bg-primary">
                            <i class="fas fa-user-shield me-1"></i><?php echo ucfirst($_SESSION['user_role']); ?>
                        </span>
                    </div>
                </div>

                <!-- Messages d'alerte -->
                <?php if (isset($erreur)): ?>
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        <i class="fas fa-exclamation-triangle me-2"></i><?php echo $erreur; ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <?php if (isset($_SESSION['success'])): ?>
                    <div class="alert alert-success alert-dismissible fade show" role="alert">
                        <i class="fas fa-check-circle me-2"></i><?php echo $_SESSION['success']; ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                    <?php unset($_SESSION['success']); ?>
                <?php endif; ?>

                <!-- Cartes de statistiques -->
                <div class="row mb-4">
                    <div class="col-xl-3 col-md-6 mb-4">
                        <div class="card stat-card border-left-primary shadow h-100">
                            <div class="card-body">
                                <div class="row no-gutters align-items-center">
                                    <div class="col mr-2">
                                        <div class="text-xs font-weight-bold text-primary text-uppercase mb-1">
                                            Modifications (30 jours)
                                        </div>
                                        <div class="h5 mb-0 font-weight-bold text-gray-800">
                                            <?php echo number_format($stats['total_modifications'] ?? 0); ?>
                                        </div>
                                    </div>
                                    <div class="col-auto">
                                        <i class="fas fa-sync-alt fa-2x text-gray-300"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="col-xl-3 col-md-6 mb-4">
                        <div class="card stat-card border-left-success shadow h-100">
                            <div class="card-body">
                                <div class="row no-gutters align-items-center">
                                    <div class="col mr-2">
                                        <div class="text-xs font-weight-bold text-success text-uppercase mb-1">
                                            Membres Affectés
                                        </div>
                                        <div class="h5 mb-0 font-weight-bold text-gray-800">
                                            <?php echo number_format($stats['membres_affectes'] ?? 0); ?>
                                        </div>
                                    </div>
                                    <div class="col-auto">
                                        <i class="fas fa-users fa-2x text-gray-300"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="col-xl-3 col-md-6 mb-4">
                        <div class="card stat-card border-left-info shadow h-100">
                            <div class="card-body">
                                <div class="row no-gutters align-items-center">
                                    <div class="col mr-2">
                                        <div class="text-xs font-weight-bold text-info text-uppercase mb-1">
                                            Variation Moyenne
                                        </div>
                                        <div class="h5 mb-0 font-weight-bold text-gray-800">
                                            <?php 
                                            $variation = $stats['variation_moyenne'] ?? 0;
                                            $class = $variation > 0 ? 'score-up' : ($variation < 0 ? 'score-down' : 'score-neutral');
                                            echo '<span class="' . $class . '">' . number_format($variation, 1) . ' pts</span>';
                                            ?>
                                        </div>
                                    </div>
                                    <div class="col-auto">
                                        <i class="fas fa-chart-bar fa-2x text-gray-300"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="col-xl-3 col-md-6 mb-4">
                        <div class="card stat-card border-left-warning shadow h-100">
                            <div class="card-body">
                                <div class="row no-gutters align-items-center">
                                    <div class="col mr-2">
                                        <div class="text-xs font-weight-bold text-warning text-uppercase mb-1">
                                            Dernière Mise à Jour
                                        </div>
                                        <div class="h6 mb-0 font-weight-bold text-gray-800">
                                            <?php echo date('d/m/Y H:i'); ?>
                                        </div>
                                    </div>
                                    <div class="col-auto">
                                        <i class="fas fa-clock fa-2x text-gray-300"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Formulaire de recherche -->
                <div class="card search-box shadow mb-4">
                    <div class="card-body">
                        <form method="GET" class="row g-3">
                            <div class="col-md-6">
                                <label for="recherche" class="form-label">Rechercher</label>
                                <input type="text" class="form-control" id="recherche" name="recherche" 
                                       value="<?php echo htmlspecialchars($recherche); ?>" 
                                       placeholder="Code membre, nom, prénom...">
                            </div>
                            <div class="col-md-4">
                                <label for="type_filtre" class="form-label">Type de modification</label>
                                <select class="form-select" id="type_filtre" name="type_filtre">
                                    <option value="">Tous les types</option>
                                    <option value="automatique" <?php echo $type_filtre === 'automatique' ? 'selected' : ''; ?>>Automatique</option>
                                    <option value="manuel" <?php echo $type_filtre === 'manuel' ? 'selected' : ''; ?>>Manuel</option>
                                    <option value="correction" <?php echo $type_filtre === 'correction' ? 'selected' : ''; ?>>Correction</option>
                                </select>
                            </div>
                            <div class="col-md-2 d-flex align-items-end">
                                <button type="submit" class="btn btn-primary w-100">
                                    <i class="fas fa-search me-1"></i>Filtrer
                                </button>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Tableau des scores -->
                <div class="card shadow">
                    <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
                        <h6 class="m-0 font-weight-bold">
                            <i class="fas fa-list me-2"></i>Historique des Modifications de Scores
                        </h6>
                        <span class="badge bg-light text-dark">
                            <?php echo number_format($total_records); ?> enregistrement(s)
                        </span>
                    </div>
                    <div class="card-body">
                        <?php if (!empty($historique_scores)): ?>
                            <div class="table-responsive">
                                <table class="table table-hover table-striped">
                                    <thead>
                                        <tr>
                                            <th>Membre</th>
                                            <th>Catégorie</th>
                                            <th>Ancien Score</th>
                                            <th>Nouveau Score</th>
                                            <th>Variation</th>
                                            <th>Type</th>
                                            <th>Raison</th>
                                            <th>Modifié par</th>
                                            <th>Date</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($historique_scores as $historique): ?>
                                            <?php
                                            $variation = $historique['nouveau_score'] - $historique['ancien_score'];
                                            $variation_class = $variation > 0 ? 'score-up' : ($variation < 0 ? 'score-down' : 'score-neutral');
                                            $variation_icon = $variation > 0 ? 'fa-arrow-up' : ($variation < 0 ? 'fa-arrow-down' : 'fa-minus');
                                            ?>
                                            <tr>
                                                <td>
                                                    <strong><?php echo securiser($historique['code_membre']); ?></strong><br>
                                                    <small class="text-muted"><?php echo securiser($historique['nom'] . ' ' . $historique['prenom']); ?></small>
                                                </td>
                                                <td>
                                                    <span class="badge bg-secondary"><?php echo securiser($historique['nom_categorie']); ?></span>
                                                </td>
                                                <td>
                                                    <span class="badge bg-light text-dark"><?php echo $historique['ancien_score']; ?></span>
                                                </td>
                                                <td>
                                                    <span class="badge 
                                                        <?php 
                                                        if ($historique['nouveau_score'] >= 700) echo 'bg-success';
                                                        elseif ($historique['nouveau_score'] >= 500) echo 'bg-warning';
                                                        else echo 'bg-danger';
                                                        ?>
                                                    "><?php echo $historique['nouveau_score']; ?></span>
                                                </td>
                                                <td>
                                                    <span class="<?php echo $variation_class; ?>">
                                                        <i class="fas <?php echo $variation_icon; ?> me-1"></i>
                                                        <?php echo abs($variation); ?> pts
                                                    </span>
                                                </td>
                                                <td>
                                                    <?php
                                                    $badge_class = [
                                                        'automatique' => 'bg-info',
                                                        'manuel' => 'bg-primary',
                                                        'correction' => 'bg-warning'
                                                    ][$historique['type_modification']] ?? 'bg-secondary';
                                                    ?>
                                                    <span class="badge <?php echo $badge_class; ?>">
                                                        <?php echo ucfirst($historique['type_modification']); ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <small><?php echo securiser($historique['raison'] ?: 'N/A'); ?></small>
                                                </td>
                                                <td>
                                                    <small><?php echo securiser($historique['modificateur_nom'] ?: 'Système'); ?></small>
                                                </td>
                                                <td>
                                                    <small><?php echo date('d/m/Y H:i', strtotime($historique['date_modification'])); ?></small>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>

                            <!-- Pagination -->
                            <?php if ($total_pages > 1): ?>
                                <nav aria-label="Navigation des pages">
                                    <ul class="pagination justify-content-center">
                                        <?php if ($page > 1): ?>
                                            <li class="page-item">
                                                <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page - 1])); ?>">
                                                    <i class="fas fa-chevron-left"></i>
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
                                                    <i class="fas fa-chevron-right"></i>
                                                </a>
                                            </li>
                                        <?php endif; ?>
                                    </ul>
                                </nav>
                            <?php endif; ?>

                        <?php else: ?>
                            <div class="text-center py-5">
                                <i class="fas fa-inbox fa-3x text-muted mb-3"></i>
                                <h5 class="text-muted">Aucun historique de scores trouvé</h5>
                                <p class="text-muted">Les modifications de scores apparaîtront ici.</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Actions supplémentaires -->
                <div class="row mt-4">
                    <div class="col-md-6">
                        <div class="card shadow">
                            <div class="card-header bg-info text-white">
                                <h6 class="m-0 font-weight-bold">
                                    <i class="fas fa-download me-2"></i>Export des Données
                                </h6>
                            </div>
                            <div class="card-body">
                                <p class="card-text">Exportez l'historique des scores pour analyse externe.</p>
                                <a href="#" class="btn btn-outline-info">
                                    <i class="fas fa-file-csv me-1"></i>Exporter en CSV
                                </a>
                                <a href="#" class="btn btn-outline-primary ms-2">
                                    <i class="fas fa-file-excel me-1"></i>Exporter en Excel
                                </a>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="card shadow">
                            <div class="card-header bg-warning text-dark">
                                <h6 class="m-0 font-weight-bold">
                                    <i class="fas fa-chart-bar me-2"></i>Analyse des Scores
                                </h6>
                            </div>
                            <div class="card-body">
                                <p class="card-text">Consultez les tendances et statistiques des scores.</p>
                                <a href="rapports.php?type=scores" class="btn btn-outline-warning">
                                    <i class="fas fa-chart-line me-1"></i>Voir les Rapports
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            </main>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.datatables.net/1.11.5/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.11.5/js/dataTables.bootstrap5.min.js"></script>
    
    <script>
        $(document).ready(function() {
            // Initialisation de DataTables
            $('table').DataTable({
                language: {
                    url: '//cdn.datatables.net/plug-ins/1.11.5/i18n/fr-FR.json'
                },
                pageLength: 20,
                responsive: true
            });

            // Auto-actualisation toutes les 10 minutes
            setTimeout(function() {
                window.location.reload();
            }, 600000);
        });
    </script>
</body>
</html>