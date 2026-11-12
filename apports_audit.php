<?php
require_once 'config_database.php';

// Vérifier l'authentification et les permissions admin
if (!est_connecte() || !a_la_permission('admin')) {
    rediriger('dashboard.php');
}

// Variables pour les périodes de rapport
$periode = isset($_GET['periode']) ? securiser($_GET['periode']) : '30jours';
$date_debut = isset($_GET['date_debut']) ? securiser($_GET['date_debut']) : '';
$date_fin = isset($_GET['date_fin']) ? securiser($_GET['date_fin']) : '';

// Déterminer les dates en fonction de la période
switch ($periode) {
    case '7jours':
        $date_debut_auto = date('Y-m-d', strtotime('-7 days'));
        $date_fin_auto = date('Y-m-d');
        break;
    case '30jours':
        $date_debut_auto = date('Y-m-d', strtotime('-30 days'));
        $date_fin_auto = date('Y-m-d');
        break;
    case '90jours':
        $date_debut_auto = date('Y-m-d', strtotime('-90 days'));
        $date_fin_auto = date('Y-m-d');
        break;
    case 'annee_courante':
        $date_debut_auto = date('Y-01-01');
        $date_fin_auto = date('Y-m-d');
        break;
    case 'personnalise':
        // Utiliser les dates fournies par l'utilisateur
        $date_debut_auto = $date_debut;
        $date_fin_auto = $date_fin;
        break;
    default:
        $date_debut_auto = date('Y-m-d', strtotime('-30 days'));
        $date_fin_auto = date('Y-m-d');
}

// Statistiques générales d'audit
try {
    // Activité par table
    $sql_activite_table = "
        SELECT table_name, COUNT(*) as total_actions,
               SUM(CASE WHEN action = 'INSERT' THEN 1 ELSE 0 END) as inserts,
               SUM(CASE WHEN action = 'UPDATE' THEN 1 ELSE 0 END) as updates,
               SUM(CASE WHEN action = 'DELETE' THEN 1 ELSE 0 END) as deletes
        FROM audit_log 
        WHERE DATE(changed_at) BETWEEN ? AND ?
        GROUP BY table_name 
        ORDER BY total_actions DESC
    ";
    $stmt_activite_table = $pdo->prepare($sql_activite_table);
    $stmt_activite_table->execute([$date_debut_auto, $date_fin_auto]);
    $activite_par_table = $stmt_activite_table->fetchAll();

    // Activité par utilisateur
    $sql_activite_user = "
        SELECT user_id, COUNT(*) as total_actions,
               SUM(CASE WHEN action = 'INSERT' THEN 1 ELSE 0 END) as inserts,
               SUM(CASE WHEN action = 'UPDATE' THEN 1 ELSE 0 END) as updates,
               SUM(CASE WHEN action = 'DELETE' THEN 1 ELSE 0 END) as deletes
        FROM audit_log 
        WHERE DATE(changed_at) BETWEEN ? AND ?
        AND user_id IS NOT NULL
        GROUP BY user_id 
        ORDER BY total_actions DESC
    ";
    $stmt_activite_user = $pdo->prepare($sql_activite_user);
    $stmt_activite_user->execute([$date_debut_auto, $date_fin_auto]);
    $activite_par_utilisateur = $stmt_activite_user->fetchAll();

    // Évolution temporelle (par jour)
    $sql_evolution_temporelle = "
        SELECT DATE(changed_at) as date_jour, 
               COUNT(*) as total_actions,
               SUM(CASE WHEN action = 'INSERT' THEN 1 ELSE 0 END) as inserts,
               SUM(CASE WHEN action = 'UPDATE' THEN 1 ELSE 0 END) as updates,
               SUM(CASE WHEN action = 'DELETE' THEN 1 ELSE 0 END) as deletes
        FROM audit_log 
        WHERE DATE(changed_at) BETWEEN ? AND ?
        GROUP BY DATE(changed_at)
        ORDER BY date_jour
    ";
    $stmt_evolution = $pdo->prepare($sql_evolution_temporelle);
    $stmt_evolution->execute([$date_debut_auto, $date_fin_auto]);
    $evolution_temporelle = $stmt_evolution->fetchAll();

    // Actions critiques (DELETEs récents)
    $sql_actions_critiques = "
        SELECT al.*, u.nom_complet
        FROM audit_log al
        LEFT JOIN utilisateurs u ON al.user_id = u.username
        WHERE al.action = 'DELETE'
        AND DATE(al.changed_at) BETWEEN ? AND ?
        ORDER BY al.changed_at DESC
        LIMIT 20
    ";
    $stmt_critiques = $pdo->prepare($sql_actions_critiques);
    $stmt_critiques->execute([$date_debut_auto, $date_fin_auto]);
    $actions_critiques = $stmt_critiques->fetchAll();

    // Statistiques globales pour la période
    $sql_stats_globales = "
        SELECT 
            COUNT(*) as total_actions,
            SUM(CASE WHEN action = 'INSERT' THEN 1 ELSE 0 END) as total_inserts,
            SUM(CASE WHEN action = 'UPDATE' THEN 1 ELSE 0 END) as total_updates,
            SUM(CASE WHEN action = 'DELETE' THEN 1 ELSE 0 END) as total_deletes,
            COUNT(DISTINCT table_name) as tables_impactees,
            COUNT(DISTINCT user_id) as utilisateurs_actifs
        FROM audit_log 
        WHERE DATE(changed_at) BETWEEN ? AND ?
    ";
    $stmt_stats = $pdo->prepare($sql_stats_globales);
    $stmt_stats->execute([$date_debut_auto, $date_fin_auto]);
    $stats_globales = $stmt_stats->fetch();

} catch (PDOException $e) {
    $erreur = "Erreur lors du chargement des rapports d'audit: " . $e->getMessage();
}
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Rapports d'Audit - <?php echo APP_NAME; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
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
        .chart-container {
            position: relative;
            height: 300px;
            width: 100%;
        }
        .filters-card {
            background: #f8f9fa;
            border-left: 4px solid #007bff;
        }
        .table-hover tbody tr:hover {
            background-color: rgba(0, 123, 255, 0.1);
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
                                <a class="nav-link active" href="rapports_audit.php">
                                    <i class="fas fa-chart-bar"></i>Rapports d'Audit
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
                        <i class="fas fa-chart-bar text-primary me-2"></i>Rapports d'Audit
                    </h1>
                    <div class="btn-toolbar mb-2 mb-md-0">
                        <span class="badge bg-primary fs-6">
                            <i class="fas fa-user-shield me-1"></i><?php echo ucfirst($_SESSION['user_role']); ?>
                        </span>
                    </div>
                </div>

                <!-- Filtres de période -->
                <div class="card shadow mb-4 filters-card">
                    <div class="card-header bg-light">
                        <h6 class="m-0 font-weight-bold text-primary">
                            <i class="fas fa-calendar-alt me-2"></i>Période d'Analyse
                        </h6>
                    </div>
                    <div class="card-body">
                        <form method="GET" action="" class="row g-3">
                            <div class="col-md-3">
                                <label class="form-label">Période</label>
                                <select name="periode" class="form-select" id="periodeSelect">
                                    <option value="7jours" <?php echo $periode == '7jours' ? 'selected' : ''; ?>>7 derniers jours</option>
                                    <option value="30jours" <?php echo $periode == '30jours' ? 'selected' : ''; ?>>30 derniers jours</option>
                                    <option value="90jours" <?php echo $periode == '90jours' ? 'selected' : ''; ?>>90 derniers jours</option>
                                    <option value="annee_courante" <?php echo $periode == 'annee_courante' ? 'selected' : ''; ?>>Année courante</option>
                                    <option value="personnalise" <?php echo $periode == 'personnalise' ? 'selected' : ''; ?>>Personnalisée</option>
                                </select>
                            </div>
                            <div class="col-md-3 date-fields" style="<?php echo $periode != 'personnalise' ? 'display: none;' : ''; ?>">
                                <label class="form-label">Date début</label>
                                <input type="date" name="date_debut" class="form-control" 
                                       value="<?php echo $date_debut; ?>">
                            </div>
                            <div class="col-md-3 date-fields" style="<?php echo $periode != 'personnalise' ? 'display: none;' : ''; ?>">
                                <label class="form-label">Date fin</label>
                                <input type="date" name="date_fin" class="form-control" 
                                       value="<?php echo $date_fin; ?>">
                            </div>
                            <div class="col-md-3 d-flex align-items-end">
                                <button type="submit" class="btn btn-primary w-100">
                                    <i class="fas fa-sync-alt me-1"></i>Actualiser
                                </button>
                            </div>
                        </form>
                        <div class="mt-2 text-muted small">
                            <i class="fas fa-info-circle me-1"></i>
                            Période analysée: du <?php echo date('d/m/Y', strtotime($date_debut_auto)); ?> au <?php echo date('d/m/Y', strtotime($date_fin_auto)); ?>
                        </div>
                    </div>
                </div>

                <!-- Cartes de statistiques globales -->
                <div class="row mb-4">
                    <div class="col-xl-2 col-md-4 mb-4">
                        <div class="card stat-card border-left-primary shadow h-100 py-2">
                            <div class="card-body">
                                <div class="row no-gutters align-items-center">
                                    <div class="col mr-2">
                                        <div class="text-xs font-weight-bold text-primary text-uppercase mb-1">
                                            Total Actions
                                        </div>
                                        <div class="h5 mb-0 font-weight-bold text-gray-800">
                                            <?php echo number_format($stats_globales['total_actions']); ?>
                                        </div>
                                    </div>
                                    <div class="col-auto">
                                        <i class="fas fa-clipboard-list fa-2x text-gray-300"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="col-xl-2 col-md-4 mb-4">
                        <div class="card stat-card border-left-success shadow h-100 py-2">
                            <div class="card-body">
                                <div class="row no-gutters align-items-center">
                                    <div class="col mr-2">
                                        <div class="text-xs font-weight-bold text-success text-uppercase mb-1">
                                            INSERT
                                        </div>
                                        <div class="h5 mb-0 font-weight-bold text-gray-800">
                                            <?php echo number_format($stats_globales['total_inserts']); ?>
                                        </div>
                                    </div>
                                    <div class="col-auto">
                                        <i class="fas fa-plus fa-2x text-gray-300"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="col-xl-2 col-md-4 mb-4">
                        <div class="card stat-card border-left-warning shadow h-100 py-2">
                            <div class="card-body">
                                <div class="row no-gutters align-items-center">
                                    <div class="col mr-2">
                                        <div class="text-xs font-weight-bold text-warning text-uppercase mb-1">
                                            UPDATE
                                        </div>
                                        <div class="h5 mb-0 font-weight-bold text-gray-800">
                                            <?php echo number_format($stats_globales['total_updates']); ?>
                                        </div>
                                    </div>
                                    <div class="col-auto">
                                        <i class="fas fa-edit fa-2x text-gray-300"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="col-xl-2 col-md-4 mb-4">
                        <div class="card stat-card border-left-danger shadow h-100 py-2">
                            <div class="card-body">
                                <div class="row no-gutters align-items-center">
                                    <div class="col mr-2">
                                        <div class="text-xs font-weight-bold text-danger text-uppercase mb-1">
                                            DELETE
                                        </div>
                                        <div class="h5 mb-0 font-weight-bold text-gray-800">
                                            <?php echo number_format($stats_globales['total_deletes']); ?>
                                        </div>
                                    </div>
                                    <div class="col-auto">
                                        <i class="fas fa-trash fa-2x text-gray-300"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="col-xl-2 col-md-4 mb-4">
                        <div class="card stat-card border-left-info shadow h-100 py-2">
                            <div class="card-body">
                                <div class="row no-gutters align-items-center">
                                    <div class="col mr-2">
                                        <div class="text-xs font-weight-bold text-info text-uppercase mb-1">
                                            Tables
                                        </div>
                                        <div class="h5 mb-0 font-weight-bold text-gray-800">
                                            <?php echo number_format($stats_globales['tables_impactees']); ?>
                                        </div>
                                    </div>
                                    <div class="col-auto">
                                        <i class="fas fa-table fa-2x text-gray-300"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="col-xl-2 col-md-4 mb-4">
                        <div class="card stat-card border-left-secondary shadow h-100 py-2">
                            <div class="card-body">
                                <div class="row no-gutters align-items-center">
                                    <div class="col mr-2">
                                        <div class="text-xs font-weight-bold text-secondary text-uppercase mb-1">
                                            Utilisateurs
                                        </div>
                                        <div class="h5 mb-0 font-weight-bold text-gray-800">
                                            <?php echo number_format($stats_globales['utilisateurs_actifs']); ?>
                                        </div>
                                    </div>
                                    <div class="col-auto">
                                        <i class="fas fa-users fa-2x text-gray-300"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="row">
                    <!-- Graphique de répartition des actions -->
                    <div class="col-lg-6 mb-4">
                        <div class="card shadow h-100">
                            <div class="card-header bg-primary text-white">
                                <h6 class="m-0 font-weight-bold">
                                    <i class="fas fa-chart-pie me-2"></i>Répartition des Actions
                                </h6>
                            </div>
                            <div class="card-body">
                                <div class="chart-container">
                                    <canvas id="chartRepartitionActions"></canvas>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Graphique d'évolution temporelle -->
                    <div class="col-lg-6 mb-4">
                        <div class="card shadow h-100">
                            <div class="card-header bg-success text-white">
                                <h6 class="m-0 font-weight-bold">
                                    <i class="fas fa-chart-line me-2"></i>Évolution Temporelle
                                </h6>
                            </div>
                            <div class="card-body">
                                <div class="chart-container">
                                    <canvas id="chartEvolutionTemporelle"></canvas>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="row">
                    <!-- Activité par table -->
                    <div class="col-lg-6 mb-4">
                        <div class="card shadow h-100">
                            <div class="card-header bg-info text-white d-flex justify-content-between align-items-center">
                                <h6 class="m-0 font-weight-bold">
                                    <i class="fas fa-table me-2"></i>Activité par Table
                                </h6>
                                <span class="badge bg-light text-dark"><?php echo count($activite_par_table); ?> tables</span>
                            </div>
                            <div class="card-body p-0">
                                <?php if (!empty($activite_par_table)): ?>
                                    <div class="table-responsive">
                                        <table class="table table-hover table-striped mb-0">
                                            <thead class="table-light">
                                                <tr>
                                                    <th>Table</th>
                                                    <th>Total</th>
                                                    <th>INSERT</th>
                                                    <th>UPDATE</th>
                                                    <th>DELETE</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($activite_par_table as $table): ?>
                                                    <tr>
                                                        <td>
                                                            <strong><?php echo securiser($table['table_name']); ?></strong>
                                                        </td>
                                                        <td>
                                                            <span class="badge bg-primary"><?php echo number_format($table['total_actions']); ?></span>
                                                        </td>
                                                        <td>
                                                            <span class="badge bg-success"><?php echo number_format($table['inserts']); ?></span>
                                                        </td>
                                                        <td>
                                                            <span class="badge bg-warning text-dark"><?php echo number_format($table['updates']); ?></span>
                                                        </td>
                                                        <td>
                                                            <span class="badge bg-danger"><?php echo number_format($table['deletes']); ?></span>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                <?php else: ?>
                                    <div class="text-center py-5">
                                        <i class="fas fa-table fa-3x text-muted mb-3"></i>
                                        <h5 class="text-muted">Aucune activité enregistrée</h5>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <!-- Activité par utilisateur -->
                    <div class="col-lg-6 mb-4">
                        <div class="card shadow h-100">
                            <div class="card-header bg-warning text-dark d-flex justify-content-between align-items-center">
                                <h6 class="m-0 font-weight-bold">
                                    <i class="fas fa-users me-2"></i>Activité par Utilisateur
                                </h6>
                                <span class="badge bg-dark"><?php echo count($activite_par_utilisateur); ?> utilisateurs</span>
                            </div>
                            <div class="card-body p-0">
                                <?php if (!empty($activite_par_utilisateur)): ?>
                                    <div class="table-responsive">
                                        <table class="table table-hover table-striped mb-0">
                                            <thead class="table-light">
                                                <tr>
                                                    <th>Utilisateur</th>
                                                    <th>Total</th>
                                                    <th>INSERT</th>
                                                    <th>UPDATE</th>
                                                    <th>DELETE</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($activite_par_utilisateur as $user): ?>
                                                    <tr>
                                                        <td>
                                                            <strong><?php echo securiser($user['user_id']); ?></strong>
                                                        </td>
                                                        <td>
                                                            <span class="badge bg-primary"><?php echo number_format($user['total_actions']); ?></span>
                                                        </td>
                                                        <td>
                                                            <span class="badge bg-success"><?php echo number_format($user['inserts']); ?></span>
                                                        </td>
                                                        <td>
                                                            <span class="badge bg-warning text-dark"><?php echo number_format($user['updates']); ?></span>
                                                        </td>
                                                        <td>
                                                            <span class="badge bg-danger"><?php echo number_format($user['deletes']); ?></span>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                <?php else: ?>
                                    <div class="text-center py-5">
                                        <i class="fas fa-users fa-3x text-muted mb-3"></i>
                                        <h5 class="text-muted">Aucune activité utilisateur</h5>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Actions critiques (DELETEs) -->
                <div class="row">
                    <div class="col-12 mb-4">
                        <div class="card shadow">
                            <div class="card-header bg-danger text-white d-flex justify-content-between align-items-center">
                                <h6 class="m-0 font-weight-bold">
                                    <i class="fas fa-exclamation-triangle me-2"></i>Actions Critiques (SUPPRESSIONS)
                                </h6>
                                <span class="badge bg-light text-dark"><?php echo count($actions_critiques); ?> actions</span>
                            </div>
                            <div class="card-body p-0">
                                <?php if (!empty($actions_critiques)): ?>
                                    <div class="table-responsive">
                                        <table class="table table-hover table-striped mb-0">
                                            <thead class="table-light">
                                                <tr>
                                                    <th>Date/Heure</th>
                                                    <th>Table</th>
                                                    <th>ID Enregistrement</th>
                                                    <th>Utilisateur</th>
                                                    <th>IP</th>
                                                    <th>Actions</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($actions_critiques as $log): ?>
                                                    <tr>
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
                                                                    <h5 class="modal-title text-danger">
                                                                        <i class="fas fa-exclamation-triangle me-2"></i>Détails de la Suppression #<?php echo $log['id']; ?>
                                                                    </h5>
                                                                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                                                </div>
                                                                <div class="modal-body">
                                                                    <div class="alert alert-warning">
                                                                        <i class="fas fa-exclamation-triangle me-2"></i>
                                                                        <strong>Action critique:</strong> Cette action a supprimé définitivement des données.
                                                                    </div>
                                                                    <div class="row mb-3">
                                                                        <div class="col-md-6">
                                                                            <strong>Table:</strong> <?php echo securiser($log['table_name']); ?>
                                                                        </div>
                                                                        <div class="col-md-6">
                                                                            <strong>Action:</strong> 
                                                                            <span class="badge bg-danger">DELETE</span>
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
                                                                        <strong>Données Supprimées:</strong>
                                                                        <pre class="json-pre bg-light p-2 border rounded"><?php 
                                                                            echo json_encode(json_decode($log['old_values']), JSON_PRETTY_PRINT); 
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
                                        <i class="fas fa-check-circle fa-3x text-success mb-3"></i>
                                        <h5 class="text-success">Aucune action critique</h5>
                                        <p class="text-muted">Aucune suppression enregistrée pendant cette période.</p>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Actions d'export -->
                <div class="row mt-4">
                    <div class="col-12">
                        <div class="card shadow">
                            <div class="card-header bg-dark text-white">
                                <h6 class="m-0 font-weight-bold">
                                    <i class="fas fa-download me-2"></i>Export des Rapports
                                </h6>
                            </div>
                            <div class="card-body">
                                <div class="row text-center">
                                    <div class="col-md-3 mb-3">
                                        <button class="btn btn-outline-primary w-100" onclick="exporterPDF()">
                                            <i class="fas fa-file-pdf me-2"></i>Export PDF
                                        </button>
                                    </div>
                                    <div class="col-md-3 mb-3">
                                        <button class="btn btn-outline-success w-100" onclick="exporterExcel()">
                                            <i class="fas fa-file-excel me-2"></i>Export Excel
                                        </button>
                                    </div>
                                    <div class="col-md-3 mb-3">
                                        <button class="btn btn-outline-info w-100" onclick="exporterCSV()">
                                            <i class="fas fa-file-csv me-2"></i>Export CSV
                                        </button>
                                    </div>
                                    <div class="col-md-3 mb-3">
                                        <a href="rapports_audit.php?periode=<?php echo $periode; ?>&date_debut=<?php echo $date_debut; ?>&date_fin=<?php echo $date_fin; ?>" 
                                           class="btn btn-outline-secondary w-100">
                                            <i class="fas fa-print me-2"></i>Version Imprimable
                                        </a>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </main>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Gestion de l'affichage des champs de date personnalisés
        document.getElementById('periodeSelect').addEventListener('change', function() {
            const dateFields = document.querySelectorAll('.date-fields');
            if (this.value === 'personnalise') {
                dateFields.forEach(field => field.style.display = 'block');
            } else {
                dateFields.forEach(field => field.style.display = 'none');
            }
        });

        // Graphique de répartition des actions
        const ctxRepartition = document.getElementById('chartRepartitionActions').getContext('2d');
        const chartRepartition = new Chart(ctxRepartition, {
            type: 'doughnut',
            data: {
                labels: ['INSERT', 'UPDATE', 'DELETE'],
                datasets: [{
                    data: [
                        <?php echo $stats_globales['total_inserts']; ?>,
                        <?php echo $stats_globales['total_updates']; ?>,
                        <?php echo $stats_globales['total_deletes']; ?>
                    ],
                    backgroundColor: [
                        '#28a745',
                        '#ffc107',
                        '#dc3545'
                    ],
                    borderWidth: 2,
                    borderColor: '#fff'
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'bottom'
                    },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                const label = context.label || '';
                                const value = context.raw || 0;
                                const total = context.dataset.data.reduce((a, b) => a + b, 0);
                                const percentage = Math.round((value / total) * 100);
                                return `${label}: ${value} (${percentage}%)`;
                            }
                        }
                    }
                }
            }
        });

        // Graphique d'évolution temporelle
        const ctxEvolution = document.getElementById('chartEvolutionTemporelle').getContext('2d');
        const chartEvolution = new Chart(ctxEvolution, {
            type: 'line',
            data: {
                labels: [
                    <?php foreach ($evolution_temporelle as $jour): ?>
                        "<?php echo date('d/m', strtotime($jour['date_jour'])); ?>",
                    <?php endforeach; ?>
                ],
                datasets: [
                    {
                        label: 'INSERT',
                        data: [
                            <?php foreach ($evolution_temporelle as $jour): ?>
                                <?php echo $jour['inserts']; ?>,
                            <?php endforeach; ?>
                        ],
                        borderColor: '#28a745',
                        backgroundColor: 'rgba(40, 167, 69, 0.1)',
                        tension: 0.4,
                        fill: true
                    },
                    {
                        label: 'UPDATE',
                        data: [
                            <?php foreach ($evolution_temporelle as $jour): ?>
                                <?php echo $jour['updates']; ?>,
                            <?php endforeach; ?>
                        ],
                        borderColor: '#ffc107',
                        backgroundColor: 'rgba(255, 193, 7, 0.1)',
                        tension: 0.4,
                        fill: true
                    },
                    {
                        label: 'DELETE',
                        data: [
                            <?php foreach ($evolution_temporelle as $jour): ?>
                                <?php echo $jour['deletes']; ?>,
                            <?php endforeach; ?>
                        ],
                        borderColor: '#dc3545',
                        backgroundColor: 'rgba(220, 53, 69, 0.1)',
                        tension: 0.4,
                        fill: true
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'top'
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        title: {
                            display: true,
                            text: "Nombre d'actions"
                        }
                    },
                    x: {
                        title: {
                            display: true,
                            text: 'Dates'
                        }
                    }
                }
            }
        });

        // Fonctions d'export (à implémenter)
        function exporterPDF() {
            alert('Fonction d\'export PDF à implémenter');
        }

        function exporterExcel() {
            alert('Fonction d\'export Excel à implémenter');
        }

        function exporterCSV() {
            alert('Fonction d\'export CSV à implémenter');
        }

        // Auto-refresh toutes les 5 minutes
        setTimeout(function() {
            window.location.reload();
        }, 300000); // 5 minutes
    </script>
</body>
</html>
[file content end]