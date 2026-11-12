[file name]: audit_log.php
[file content begin]
<?php
require_once 'config_database.php';

// Vérifier l'authentification et les permissions admin
if (!est_connecte() || !a_la_permission('admin')) {
    rediriger('dashboard.php');
}

// Variables pour la pagination et le filtrage
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$limit = 50;
$offset = ($page - 1) * $limit;

// Variables de filtrage
$filtre_table = isset($_GET['table']) ? securiser($_GET['table']) : '';
$filtre_action = isset($_GET['action']) ? securiser($_GET['action']) : '';
$filtre_user = isset($_GET['user']) ? securiser($_GET['user']) : '';
$filtre_date_debut = isset($_GET['date_debut']) ? securiser($_GET['date_debut']) : '';
$filtre_date_fin = isset($_GET['date_fin']) ? securiser($_GET['date_fin']) : '';

// Construction de la requête avec filtres
$where_conditions = [];
$params = [];
$types = '';

if (!empty($filtre_table)) {
    $where_conditions[] = "table_name = ?";
    $params[] = $filtre_table;
    $types .= 's';
}

if (!empty($filtre_action)) {
    $where_conditions[] = "action = ?";
    $params[] = $filtre_action;
    $types .= 's';
}

if (!empty($filtre_user)) {
    $where_conditions[] = "user_id LIKE ?";
    $params[] = "%$filtre_user%";
    $types .= 's';
}

if (!empty($filtre_date_debut)) {
    $where_conditions[] = "DATE(changed_at) >= ?";
    $params[] = $filtre_date_debut;
    $types .= 's';
}

if (!empty($filtre_date_fin)) {
    $where_conditions[] = "DATE(changed_at) <= ?";
    $params[] = $filtre_date_fin;
    $types .= 's';
}

$where_sql = '';
if (!empty($where_conditions)) {
    $where_sql = 'WHERE ' . implode(' AND ', $where_conditions);
}

// Récupérer le nombre total d'enregistrements
$sql_count = "SELECT COUNT(*) as total FROM audit_log $where_sql";
$stmt_count = $pdo->prepare($sql_count);
if (!empty($params)) {
    $stmt_count->execute($params);
} else {
    $stmt_count->execute();
}
$total_logs = $stmt_count->fetch()['total'];
$total_pages = ceil($total_logs / $limit);

// Récupérer les logs d'audit
$sql = "SELECT * FROM audit_log $where_sql ORDER BY changed_at DESC LIMIT ? OFFSET ?";
$params[] = $limit;
$params[] = $offset;
$types .= 'ii';

$stmt = $pdo->prepare($sql);
if (!empty($params)) {
    $stmt->execute($params);
} else {
    $stmt->execute([$limit, $offset]);
}
$logs_audit = $stmt->fetchAll();

// Récupérer les tables distinctes pour le filtre
$stmt_tables = $pdo->query("SELECT DISTINCT table_name FROM audit_log ORDER BY table_name");
$tables_distinctes = $stmt_tables->fetchAll();

// Récupérer les actions distinctes pour le filtre
$stmt_actions = $pdo->query("SELECT DISTINCT action FROM audit_log ORDER BY action");
$actions_distinctes = $stmt_actions->fetchAll();

// Récupérer les utilisateurs distincts pour le filtre
$stmt_users = $pdo->query("SELECT DISTINCT user_id FROM audit_log WHERE user_id IS NOT NULL ORDER BY user_id");
$users_distincts = $stmt_users->fetchAll();
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Journal d'Audit - <?php echo APP_NAME; ?></title>
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
        .card-header {
            border-bottom: 1px solid #e3e6f0;
        }
        .badge-action-INSERT { background-color: #28a745; }
        .badge-action-UPDATE { background-color: #ffc107; color: #000; }
        .badge-action-DELETE { background-color: #dc3545; }
        .json-pre {
            font-size: 0.8rem;
            max-height: 200px;
            overflow-y: auto;
        }
        .filters-card {
            background: #f8f9fa;
            border-left: 4px solid #007bff;
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
                                <a class="nav-link active" href="audit_log.php">
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
                <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center mb-4">
                    <h1 class="h2">
                        <i class="fas fa-clipboard-list text-primary me-2"></i>Journal d'Audit
                    </h1>
                    <div class="btn-toolbar mb-2 mb-md-0">
                        <span class="badge bg-primary fs-6">
                            <i class="fas fa-user-shield me-1"></i><?php echo ucfirst($_SESSION['user_role']); ?>
                        </span>
                    </div>
                </div>

                <!-- Cartes de statistiques -->
                <div class="row mb-4">
                    <div class="col-xl-3 col-md-6 mb-4">
                        <div class="card stat-card border-left-primary shadow h-100 py-2">
                            <div class="card-body">
                                <div class="row no-gutters align-items-center">
                                    <div class="col mr-2">
                                        <div class="text-xs font-weight-bold text-primary text-uppercase mb-1">
                                            Total des Logs
                                        </div>
                                        <div class="h5 mb-0 font-weight-bold text-gray-800">
                                            <?php echo number_format($total_logs); ?>
                                        </div>
                                    </div>
                                    <div class="col-auto">
                                        <i class="fas fa-clipboard-list fa-2x text-gray-300"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <?php
                    // Statistiques par type d'action
                    $stmt_stats = $pdo->query("
                        SELECT action, COUNT(*) as count 
                        FROM audit_log 
                        GROUP BY action 
                        ORDER BY count DESC
                    ");
                    $stats_actions = $stmt_stats->fetchAll();
                    ?>

                    <?php foreach($stats_actions as $stat): ?>
                    <div class="col-xl-3 col-md-6 mb-4">
                        <div class="card stat-card border-left-<?php 
                            echo $stat['action'] == 'INSERT' ? 'success' : 
                                 ($stat['action'] == 'UPDATE' ? 'warning' : 'danger'); 
                        ?> shadow h-100 py-2">
                            <div class="card-body">
                                <div class="row no-gutters align-items-center">
                                    <div class="col mr-2">
                                        <div class="text-xs font-weight-bold text-<?php 
                                            echo $stat['action'] == 'INSERT' ? 'success' : 
                                                 ($stat['action'] == 'UPDATE' ? 'warning' : 'danger'); 
                                        ?> text-uppercase mb-1">
                                            <?php echo $stat['action']; ?>s
                                        </div>
                                        <div class="h5 mb-0 font-weight-bold text-gray-800">
                                            <?php echo number_format($stat['count']); ?>
                                        </div>
                                    </div>
                                    <div class="col-auto">
                                        <i class="fas fa-<?php 
                                            echo $stat['action'] == 'INSERT' ? 'plus' : 
                                                 ($stat['action'] == 'UPDATE' ? 'edit' : 'trash'); 
                                        ?> fa-2x text-gray-300"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>

                <!-- Filtres -->
                <div class="card shadow mb-4 filters-card">
                    <div class="card-header bg-light">
                        <h6 class="m-0 font-weight-bold text-primary">
                            <i class="fas fa-filter me-2"></i>Filtres
                        </h6>
                    </div>
                    <div class="card-body">
                        <form method="GET" action="" class="row g-3">
                            <div class="col-md-3">
                                <label class="form-label">Table</label>
                                <select name="table" class="form-select">
                                    <option value="">Toutes les tables</option>
                                    <?php foreach($tables_distinctes as $table): ?>
                                        <option value="<?php echo securiser($table['table_name']); ?>" 
                                            <?php echo $filtre_table == $table['table_name'] ? 'selected' : ''; ?>>
                                            <?php echo securiser($table['table_name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-2">
                                <label class="form-label">Action</label>
                                <select name="action" class="form-select">
                                    <option value="">Toutes les actions</option>
                                    <?php foreach($actions_distinctes as $action): ?>
                                        <option value="<?php echo securiser($action['action']); ?>" 
                                            <?php echo $filtre_action == $action['action'] ? 'selected' : ''; ?>>
                                            <?php echo securiser($action['action']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-2">
                                <label class="form-label">Utilisateur</label>
                                <select name="user" class="form-select">
                                    <option value="">Tous les utilisateurs</option>
                                    <?php foreach($users_distincts as $user): ?>
                                        <option value="<?php echo securiser($user['user_id']); ?>" 
                                            <?php echo $filtre_user == $user['user_id'] ? 'selected' : ''; ?>>
                                            <?php echo securiser($user['user_id']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-2">
                                <label class="form-label">Date début</label>
                                <input type="date" name="date_debut" class="form-control" 
                                       value="<?php echo $filtre_date_debut; ?>">
                            </div>
                            <div class="col-md-2">
                                <label class="form-label">Date fin</label>
                                <input type="date" name="date_fin" class="form-control" 
                                       value="<?php echo $filtre_date_fin; ?>">
                            </div>
                            <div class="col-md-1 d-flex align-items-end">
                                <button type="submit" class="btn btn-primary w-100">
                                    <i class="fas fa-search"></i>
                                </button>
                            </div>
                        </form>
                        <?php if($filtre_table || $filtre_action || $filtre_user || $filtre_date_debut || $filtre_date_fin): ?>
                        <div class="mt-3">
                            <a href="audit_log.php" class="btn btn-sm btn-outline-secondary">
                                <i class="fas fa-times me-1"></i>Effacer les filtres
                            </a>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Tableau des logs -->
                <div class="card shadow">
                    <div class="card-header bg-white d-flex justify-content-between align-items-center">
                        <h6 class="m-0 font-weight-bold text-primary">
                            <i class="fas fa-list me-2"></i>Logs d'Audit
                        </h6>
                        <div>
                            <span class="text-muted small">
                                Page <?php echo $page; ?> sur <?php echo $total_pages; ?>
                            </span>
                        </div>
                    </div>
                    <div class="card-body p-0">
                        <?php if (!empty($logs_audit)): ?>
                            <div class="table-responsive">
                                <table class="table table-hover table-striped mb-0">
                                    <thead class="table-light">
                                        <tr>
                                            <th>ID</th>
                                            <th>Date/Heure</th>
                                            <th>Table</th>
                                            <th>Action</th>
                                            <th>ID Enregistrement</th>
                                            <th>Utilisateur</th>
                                            <th>IP</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($logs_audit as $log): ?>
                                            <tr>
                                                <td class="text-muted small">#<?php echo $log['id']; ?></td>
                                                <td>
                                                    <small>
                                                        <?php echo date('d/m/Y', strtotime($log['changed_at'])); ?><br>
                                                        <span class="text-muted"><?php echo date('H:i:s', strtotime($log['changed_at'])); ?></span>
                                                    </small>
                                                </td>
                                                <td>
                                                    <span class="badge bg-secondary"><?php echo securiser($log['table_name']); ?></span>
                                                </td>
                                                <td>
                                                    <span class="badge badge-action-<?php echo $log['action']; ?>">
                                                        <?php echo securiser($log['action']); ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <code><?php echo securiser($log['record_id']); ?></code>
                                                </td>
                                                <td>
                                                    <?php if ($log['user_id']): ?>
                                                        <span class="badge bg-info"><?php echo securiser($log['user_id']); ?></span>
                                                    <?php else: ?>
                                                        <span class="text-muted">Système</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <small class="text-muted"><?php echo securiser($log['ip_address']); ?></small>
                                                </td>
                                                <td>
                                                    <button type="button" class="btn btn-sm btn-outline-primary" 
                                                            data-bs-toggle="modal" 
                                                            data-bs-target="#modalDetails<?php echo $log['id']; ?>">
                                                        <i class="fas fa-eye"></i>
                                                    </button>
                                                </td>
                                            </tr>

                                            <!-- Modal pour les détails -->
                                            <div class="modal fade" id="modalDetails<?php echo $log['id']; ?>" tabindex="-1">
                                                <div class="modal-dialog modal-lg">
                                                    <div class="modal-content">
                                                        <div class="modal-header">
                                                            <h5 class="modal-title">
                                                                Détails du Log #<?php echo $log['id']; ?>
                                                            </h5>
                                                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                                        </div>
                                                        <div class="modal-body">
                                                            <div class="row mb-3">
                                                                <div class="col-md-6">
                                                                    <strong>Table:</strong> <?php echo securiser($log['table_name']); ?>
                                                                </div>
                                                                <div class="col-md-6">
                                                                    <strong>Action:</strong> 
                                                                    <span class="badge badge-action-<?php echo $log['action']; ?>">
                                                                        <?php echo securiser($log['action']); ?>
                                                                    </span>
                                                                </div>
                                                            </div>
                                                            <div class="row mb-3">
                                                                <div class="col-md-6">
                                                                    <strong>ID Enregistrement:</strong> 
                                                                    <code><?php echo securiser($log['record_id']); ?></code>
                                                                </div>
                                                                <div class="col-md-6">
                                                                    <strong>Date/Heure:</strong> 
                                                                    <?php echo date('d/m/Y H:i:s', strtotime($log['changed_at'])); ?>
                                                                </div>
                                                            </div>
                                                            <div class="row mb-3">
                                                                <div class="col-md-6">
                                                                    <strong>Utilisateur:</strong> 
                                                                    <?php echo $log['user_id'] ? securiser($log['user_id']) : 'Système'; ?>
                                                                </div>
                                                                <div class="col-md-6">
                                                                    <strong>IP:</strong> 
                                                                    <?php echo securiser($log['ip_address']); ?>
                                                                </div>
                                                            </div>
                                                            
                                                            <?php if ($log['old_values']): ?>
                                                            <div class="mb-3">
                                                                <strong>Anciennes Valeurs:</strong>
                                                                <pre class="json-pre bg-light p-2 border rounded"><?php 
                                                                    echo json_encode(json_decode($log['old_values']), JSON_PRETTY_PRINT); 
                                                                ?></pre>
                                                            </div>
                                                            <?php endif; ?>
                                                            
                                                            <?php if ($log['new_values']): ?>
                                                            <div class="mb-3">
                                                                <strong>Nouvelles Valeurs:</strong>
                                                                <pre class="json-pre bg-light p-2 border rounded"><?php 
                                                                    echo json_encode(json_decode($log['new_values']), JSON_PRETTY_PRINT); 
                                                                ?></pre>
                                                            </div>
                                                            <?php endif; ?>
                                                        </div>
                                                        <div class="modal-footer">
                                                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Fermer</button>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php else: ?>
                            <div class="text-center py-5">
                                <i class="fas fa-clipboard-list fa-3x text-muted mb-3"></i>
                                <h5 class="text-muted">Aucun log d'audit trouvé</h5>
                                <p class="text-muted">Les actions du système apparaîtront ici.</p>
                            </div>
                        <?php endif; ?>
                    </div>
                    
                    <!-- Pagination -->
                    <?php if ($total_pages > 1): ?>
                    <div class="card-footer bg-white">
                        <nav aria-label="Pagination">
                            <ul class="pagination justify-content-center mb-0">
                                <?php if ($page > 1): ?>
                                    <li class="page-item">
                                        <a class="page-link" href="?<?php 
                                            echo http_build_query(array_merge($_GET, ['page' => $page - 1]));
                                        ?>">Précédent</a>
                                    </li>
                                <?php endif; ?>
                                
                                <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                                    <li class="page-item <?php echo $i == $page ? 'active' : ''; ?>">
                                        <a class="page-link" href="?<?php 
                                            echo http_build_query(array_merge($_GET, ['page' => $i]));
                                        ?>"><?php echo $i; ?></a>
                                    </li>
                                <?php endfor; ?>
                                
                                <?php if ($page < $total_pages): ?>
                                    <li class="page-item">
                                        <a class="page-link" href="?<?php 
                                            echo http_build_query(array_merge($_GET, ['page' => $page + 1]));
                                        ?>">Suivant</a>
                                    </li>
                                <?php endif; ?>
                            </ul>
                        </nav>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- Actions d'administration -->
                <?php if (a_la_permission('admin')): ?>
                <div class="row mt-4">
                    <div class="col-12">
                        <div class="card shadow">
                            <div class="card-header bg-dark text-white">
                                <h6 class="m-0 font-weight-bold">
                                    <i class="fas fa-cogs me-2"></i>Actions d'Administration
                                </h6>
                            </div>
                            <div class="card-body">
                                <div class="row text-center">
                                    <div class="col-md-4 mb-3">
                                        <form method="POST" action="export_audit.php" class="d-inline">
                                            <button type="submit" class="btn btn-success w-100 py-2">
                                                <i class="fas fa-file-export me-2"></i>Exporter en CSV
                                            </button>
                                        </form>
                                    </div>
                                    <div class="col-md-4 mb-3">
                                        <button type="button" class="btn btn-warning w-100 py-2" 
                                                data-bs-toggle="modal" data-bs-target="#modalNettoyage">
                                            <i class="fas fa-broom me-2"></i>Nettoyer les vieux logs
                                        </button>
                                    </div>
                                    <div class="col-md-4 mb-3">
                                        <a href="rapports_audit.php" class="btn btn-info w-100 py-2">
                                            <i class="fas fa-chart-bar me-2"></i>Rapports d'Audit
                                        </a>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Modal de nettoyage -->
                <div class="modal fade" id="modalNettoyage" tabindex="-1">
                    <div class="modal-dialog">
                        <div class="modal-content">
                            <div class="modal-header">
                                <h5 class="modal-title">Nettoyage des Logs d'Audit</h5>
                                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                            </div>
                            <div class="modal-body">
                                <p>Cette action supprimera les logs d'audit de plus de 6 mois.</p>
                                <p class="text-warning">
                                    <i class="fas fa-exclamation-triangle me-1"></i>
                                    Cette action est irréversible.
                                </p>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annuler</button>
                                <form method="POST" action="nettoyer_audit.php" class="d-inline">
                                    <button type="submit" class="btn btn-warning">
                                        <i class="fas fa-broom me-1"></i>Nettoyer
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
            </main>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Auto-actualisation toutes les 2 minutes pour les logs récents
        setTimeout(function() {
            window.location.reload();
        }, 120000);

        // Fonction pour formater l'affichage JSON
        document.addEventListener('DOMContentLoaded', function() {
            const jsonPres = document.querySelectorAll('.json-pre');
            jsonPres.forEach(pre => {
                try {
                    const jsonContent = pre.textContent;
                    const parsedJson = JSON.parse(jsonContent);
                    pre.textContent = JSON.stringify(parsedJson, null, 2);
                } catch (e) {
                    // Si ce n'est pas du JSON valide, on laisse tel quel
                }
            });
        });
    </script>
</body>
</html>
[file content end]