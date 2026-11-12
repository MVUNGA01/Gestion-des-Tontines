<?php
require_once 'config_database.php';

// Vérifier l'authentification
if (!est_connecte()) {
    rediriger('login.php');
}

// Vérifier les permissions (au moins analyste)
if (!a_la_permission('analyste') && !a_la_permission('admin')) {
    $_SESSION['erreur'] = "Vous n'avez pas les permissions nécessaires pour accéder aux rapports.";
    rediriger('dashboard.php');
}

// Variables pour les filtres
$periode = isset($_GET['periode']) ? securiser($_GET['periode']) : 'mois_courant';
$date_debut = isset($_GET['date_debut']) ? securiser($_GET['date_debut']) : '';
$date_fin = isset($_GET['date_fin']) ? securiser($_GET['date_fin']) : '';
$categorie_filter = isset($_GET['categorie']) ? (int)$_GET['categorie'] : '';
$type_pret_filter = isset($_GET['type_pret']) ? (int)$_GET['type_pret'] : '';

// Définir les dates par défaut selon la période
$date_now = date('Y-m-d');
switch ($periode) {
    case 'semaine_courante':
        $default_debut = date('Y-m-d', strtotime('monday this week'));
        $default_fin = date('Y-m-d', strtotime('sunday this week'));
        break;
    case 'mois_courant':
        $default_debut = date('Y-m-01');
        $default_fin = date('Y-m-t');
        break;
    case 'trimestre_courant':
        $current_month = date('n');
        $current_quarter = ceil($current_month / 3);
        $start_month = ($current_quarter - 1) * 3 + 1;
        $end_month = $start_month + 2;
        $default_debut = date('Y-' . sprintf('%02d', $start_month) . '-01');
        $default_fin = date('Y-' . sprintf('%02d', $end_month) . '-t');
        break;
    case 'annee_courante':
        $default_debut = date('Y-01-01');
        $default_fin = date('Y-12-31');
        break;
    case 'personnalise':
        $default_debut = $date_debut ?: date('Y-m-01');
        $default_fin = $date_fin ?: date('Y-m-t');
        break;
    default:
        $default_debut = date('Y-m-01');
        $default_fin = date('Y-m-t');
}

// Utiliser les dates personnalisées si fournies
$date_debut = $date_debut ?: $default_debut;
$date_fin = $date_fin ?: $default_fin;

try {
    // Récupérer les catégories pour le filtre
    $stmt_categories = $pdo->query("SELECT id, nom_categorie FROM categories_membres WHERE statut = 'actif' ORDER BY nom_categorie");
    $categories = $stmt_categories->fetchAll();

    // Récupérer les types de prêt pour le filtre
    $stmt_types = $pdo->query("SELECT id, nom_type FROM types_pret WHERE statut = 'actif' ORDER BY nom_type");
    $types_pret = $stmt_types->fetchAll();

    // Statistiques globales
    $stats = [
        'prets' => [],
        'paiements' => [],
        'membres' => [],
        'dettes' => []
    ];

    // 1. Statistiques des prêts
    $sql_prets = "SELECT 
        COUNT(*) as total,
        SUM(CASE WHEN statut = 'actif' THEN 1 ELSE 0 END) as actifs,
        SUM(CASE WHEN statut = 'en_attente' THEN 1 ELSE 0 END) as en_attente,
        SUM(CASE WHEN statut = 'en_retard' THEN 1 ELSE 0 END) as en_retard,
        SUM(CASE WHEN statut = 'complet' THEN 1 ELSE 0 END) as completes,
        SUM(montant) as montant_total,
        SUM(montant_restant) as dette_totale,
        AVG(taux_interet) as taux_moyen
    FROM prets WHERE 1=1";
    
    $params_prets = [];
    
    if (!empty($date_debut) && !empty($date_fin)) {
        $sql_prets .= " AND DATE(date_creation) BETWEEN ? AND ?";
        $params_prets = [$date_debut, $date_fin];
    }
    
    $stmt_prets = $pdo->prepare($sql_prets);
    $stmt_prets->execute($params_prets);
    $stats['prets'] = $stmt_prets->fetch();

    // 2. Statistiques des paiements
    $sql_paiements = "SELECT 
        COUNT(*) as total,
        SUM(montant_paye) as montant_total,
        AVG(montant_paye) as moyenne,
        MIN(montant_paye) as minimum,
        MAX(montant_paye) as maximum,
        COUNT(DISTINCT pret_id) as prets_payes,
        methode_paiement,
        COUNT(*) as nombre
    FROM paiements 
    WHERE statut IN ('complet', 'partiel')";
    
    $params_paiements = [];
    
    if (!empty($date_debut) && !empty($date_fin)) {
        $sql_paiements .= " AND DATE(date_paiement) BETWEEN ? AND ?";
        $params_paiements = [$date_debut, $date_fin];
    }
    
    $sql_paiements .= " GROUP BY methode_paiement";
    
    $stmt_paiements = $pdo->prepare($sql_paiements);
    $stmt_paiements->execute($params_paiements);
    $stats['paiements'] = $stmt_paiements->fetchAll();

    // 3. Évolution des prêts par mois
    $sql_evolution = "SELECT 
        DATE_FORMAT(date_creation, '%Y-%m') as mois,
        COUNT(*) as nombre_prets,
        SUM(montant) as montant_total,
        AVG(montant) as moyenne_montant
    FROM prets 
    WHERE date_creation >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
    GROUP BY DATE_FORMAT(date_creation, '%Y-%m')
    ORDER BY mois";
    
    $stmt_evolution = $pdo->prepare($sql_evolution);
    $stmt_evolution->execute();
    $evolution_data = $stmt_evolution->fetchAll();

    // 4. Répartition par type de prêt
    $sql_repartition_type = "SELECT 
        tp.nom_type,
        COUNT(p.id) as nombre,
        SUM(p.montant) as montant_total,
        AVG(p.taux_interet) as taux_moyen
    FROM prets p
    INNER JOIN plans_pret pp ON p.plan_pret_id = pp.id
    INNER JOIN types_pret tp ON pp.type_pret_id = tp.id";
    
    $params_repartition = [];
    
    if (!empty($date_debut) && !empty($date_fin)) {
        $sql_repartition_type .= " WHERE DATE(p.date_creation) BETWEEN ? AND ?";
        $params_repartition = [$date_debut, $date_fin];
    }
    
    $sql_repartition_type .= " GROUP BY tp.id, tp.nom_type ORDER BY nombre DESC";
    
    $stmt_repartition = $pdo->prepare($sql_repartition_type);
    $stmt_repartition->execute($params_repartition);
    $repartition_type = $stmt_repartition->fetchAll();

    // 5. Top 10 des emprunteurs
    $sql_top_emprunteurs = "SELECT 
        m.code_membre,
        CONCAT(m.nom, ' ', m.prenom) as nom_complet,
        cm.nom_categorie,
        COUNT(p.id) as nombre_prets,
        SUM(p.montant) as total_emprunte,
        SUM(CASE WHEN p.statut IN ('actif', 'en_retard') THEN p.montant_restant ELSE 0 END) as dette_actuelle,
        MAX(e.score_credit) as score_credit,
        e.cotisation_a_jour
    FROM membres m
    LEFT JOIN prets p ON m.code_membre = p.code_membre
    LEFT JOIN categories_membres cm ON m.categorie_id = cm.id
    LEFT JOIN emprunteurs e ON m.code_membre = e.code_membre";
    
    $params_top = [];
    
    if (!empty($date_debut) && !empty($date_fin)) {
        $sql_top_emprunteurs .= " WHERE DATE(p.date_creation) BETWEEN ? AND ?";
        $params_top = [$date_debut, $date_fin];
    }
    
    $sql_top_emprunteurs .= " GROUP BY m.code_membre, m.nom, m.prenom, cm.nom_categorie, e.score_credit, e.cotisation_a_jour
                            HAVING COUNT(p.id) > 0
                            ORDER BY total_emprunte DESC
                            LIMIT 10";
    
    $stmt_top = $pdo->prepare($sql_top_emprunteurs);
    $stmt_top->execute($params_top);
    $top_emprunteurs = $stmt_top->fetchAll();

    // 6. Prêts en retard
    $sql_retard = "SELECT 
        p.code_pret,
        m.code_membre,
        CONCAT(m.nom, ' ', m.prenom) as nom_complet,
        p.montant_restant,
        p.date_fin,
        DATEDIFF(CURDATE(), p.date_fin) as jours_retard,
        COUNT(ep.id) as echeances_en_retard
    FROM prets p
    INNER JOIN membres m ON p.code_membre = m.code_membre
    LEFT JOIN echeances_pret ep ON p.id = ep.pret_id AND ep.statut = 'en_retard'
    WHERE p.statut = 'en_retard'
    GROUP BY p.id, p.code_pret, m.code_membre, m.nom, m.prenom, p.montant_restant, p.date_fin
    ORDER BY jours_retard DESC";
    
    $stmt_retard = $pdo->prepare($sql_retard);
    $stmt_retard->execute();
    $prets_retard = $stmt_retard->fetchAll();

    // 7. Performance des catégories
    $sql_categories_perf = "SELECT 
        cm.nom_categorie,
        COUNT(DISTINCT m.code_membre) as nombre_membres,
        COUNT(p.id) as nombre_prets,
        SUM(p.montant) as montant_total_prets,
        AVG(p.taux_interet) as taux_moyen,
        SUM(CASE WHEN p.statut IN ('actif', 'en_retard') THEN p.montant_restant ELSE 0 END) as dette_totale,
        COUNT(DISTINCT CASE WHEN e.cotisation_a_jour = TRUE THEN m.code_membre END) as membres_cotisation_a_jour
    FROM categories_membres cm
    LEFT JOIN membres m ON cm.id = m.categorie_id
    LEFT JOIN prets p ON m.code_membre = p.code_membre
    LEFT JOIN emprunteurs e ON m.code_membre = e.code_membre
    GROUP BY cm.id, cm.nom_categorie
    ORDER BY montant_total_prets DESC";
    
    $stmt_categories_perf = $pdo->prepare($sql_categories_perf);
    $stmt_categories_perf->execute();
    $categories_perf = $stmt_categories_perf->fetchAll();

} catch (PDOException $e) {
    $erreur = "Erreur lors du chargement des rapports: " . $e->getMessage();
    error_log("Erreur rapports.php: " . $e->getMessage());
}

// Fonction pour formater les montants
function format_montant($montant) {
    return number_format($montant, 0, ',', ' ') . ' FBu';
}

// Fonction pour générer une couleur unique pour les graphiques
function get_chart_color($index) {
    $colors = [
        '#3498db', '#2ecc71', '#e74c3c', '#f39c12', '#9b59b6',
        '#1abc9c', '#d35400', '#c0392b', '#16a085', '#8e44ad'
    ];
    return $colors[$index % count($colors)];
}
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Rapports et Statistiques - <?php echo APP_NAME; ?></title>
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
            margin-bottom: 20px;
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
        .chart-container {
            position: relative;
            height: 300px;
            margin-bottom: 30px;
        }
        .report-section {
            background: white;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 30px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        .report-section h4 {
            border-bottom: 2px solid #3498db;
            padding-bottom: 10px;
            margin-bottom: 20px;
            color: #2c3e50;
        }
        .kpi-card {
            text-align: center;
            padding: 20px;
            border-radius: 8px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            margin-bottom: 20px;
        }
        .kpi-card .kpi-value {
            font-size: 2.5rem;
            font-weight: bold;
            margin: 10px 0;
        }
        .kpi-card .kpi-label {
            font-size: 0.9rem;
            opacity: 0.9;
        }
        .table-responsive {
            max-height: 400px;
            overflow-y: auto;
        }
        .filter-card {
            background: #f8f9fa;
            border-left: 4px solid #3498db;
        }
    </style>
</head>
<body>
    <div class="container-fluid">
        <div class="row">
            <!-- Sidebar -->
            <nav class="col-md-3 col-lg-2 d-md-block sidebar">
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
                                <a class="nav-link active" href="rapports.php">
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
                            <i class="fas fa-chart-bar me-2"></i>Rapports et Statistiques
                        </h1>
                        <p class="text-muted mb-0">Analyse et suivi des performances du système</p>
                    </div>
                    <div>
                        <button class="btn btn-primary" onclick="window.print()">
                            <i class="fas fa-print me-2"></i>Imprimer le rapport
                        </button>
                    </div>
                </div>

                <!-- Messages d'alerte -->
                <?php if (isset($_SESSION['erreur'])): ?>
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        <?php echo securiser($_SESSION['erreur']); unset($_SESSION['erreur']); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <!-- Filtres -->
                <div class="card filter-card shadow mb-4">
                    <div class="card-header">
                        <h6 class="m-0 font-weight-bold">
                            <i class="fas fa-filter me-2"></i>Filtres du Rapport
                        </h6>
                    </div>
                    <div class="card-body">
                        <form method="GET" class="row g-3">
                            <div class="col-md-3">
                                <label class="form-label">Période</label>
                                <select class="form-select" name="periode" id="periodeSelect">
                                    <option value="semaine_courante" <?= $periode === 'semaine_courante' ? 'selected' : '' ?>>Semaine courante</option>
                                    <option value="mois_courant" <?= $periode === 'mois_courant' ? 'selected' : '' ?>>Mois courant</option>
                                    <option value="trimestre_courant" <?= $periode === 'trimestre_courant' ? 'selected' : '' ?>>Trimestre courant</option>
                                    <option value="annee_courante" <?= $periode === 'annee_courante' ? 'selected' : '' ?>>Année courante</option>
                                    <option value="personnalise" <?= $periode === 'personnalise' ? 'selected' : '' ?>>Période personnalisée</option>
                                </select>
                            </div>
                            
                            <div class="col-md-3" id="dateDebutGroup" style="<?= $periode === 'personnalise' ? '' : 'display: none;' ?>">
                                <label class="form-label">Date début</label>
                                <input type="date" class="form-control" name="date_debut" value="<?= $date_debut ?>">
                            </div>
                            
                            <div class="col-md-3" id="dateFinGroup" style="<?= $periode === 'personnalise' ? '' : 'display: none;' ?>">
                                <label class="form-label">Date fin</label>
                                <input type="date" class="form-control" name="date_fin" value="<?= $date_fin ?>">
                            </div>
                            
                            <div class="col-md-3">
                                <label class="form-label">Catégorie membre</label>
                                <select class="form-select" name="categorie">
                                    <option value="">Toutes catégories</option>
                                    <?php foreach ($categories as $cat): ?>
                                        <option value="<?= $cat['id'] ?>" <?= $categorie_filter == $cat['id'] ? 'selected' : '' ?>>
                                            <?= securiser($cat['nom_categorie']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="col-md-3">
                                <label class="form-label">Type de prêt</label>
                                <select class="form-select" name="type_pret">
                                    <option value="">Tous types</option>
                                    <?php foreach ($types_pret as $type): ?>
                                        <option value="<?= $type['id'] ?>" <?= $type_pret_filter == $type['id'] ? 'selected' : '' ?>>
                                            <?= securiser($type['nom_type']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="col-md-12 mt-3">
                                <div class="d-flex justify-content-between">
                                    <button type="submit" class="btn btn-primary">
                                        <i class="fas fa-search me-2"></i>Appliquer les filtres
                                    </button>
                                    <a href="rapports.php" class="btn btn-secondary">
                                        <i class="fas fa-redo me-2"></i>Réinitialiser
                                    </a>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- KPI Cards -->
                <div class="row mb-4">
                    <div class="col-xl-3 col-md-6 mb-4">
                        <div class="card kpi-card">
                            <div class="card-body">
                                <div class="kpi-value"><?= number_format($stats['prets']['total'] ?? 0) ?></div>
                                <div class="kpi-label">Total Prêts</div>
                                <small>Sur la période</small>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-xl-3 col-md-6 mb-4">
                        <div class="card kpi-card" style="background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);">
                            <div class="card-body">
                                <div class="kpi-value"><?= format_montant($stats['prets']['montant_total'] ?? 0) ?></div>
                                <div class="kpi-label">Montant Total</div>
                                <small>Capital octroyé</small>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-xl-3 col-md-6 mb-4">
                        <div class="card kpi-card" style="background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);">
                            <div class="card-body">
                                <div class="kpi-value"><?= format_montant($stats['prets']['dette_totale'] ?? 0) ?></div>
                                <div class="kpi-label">Dette Active</div>
                                <small>Montant restant dû</small>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-xl-3 col-md-6 mb-4">
                        <div class="card kpi-card" style="background: linear-gradient(135deg, #43e97b 0%, #38f9d7 100%);">
                            <div class="card-body">
                                <div class="kpi-value"><?= number_format($stats['prets']['taux_moyen'] ?? 0, 2) ?>%</div>
                                <div class="kpi-label">Taux Moyen</div>
                                <small>Taux d'intérêt moyen</small>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Section 1: Évolution temporelle -->
                <div class="report-section">
                    <h4><i class="fas fa-chart-line me-2"></i>Évolution des Prêts (12 derniers mois)</h4>
                    <div class="chart-container">
                        <canvas id="evolutionChart"></canvas>
                    </div>
                </div>

                <!-- Section 2: Répartition par type -->
                <div class="row">
                    <div class="col-md-6">
                        <div class="report-section">
                            <h4><i class="fas fa-chart-pie me-2"></i>Répartition par Type de Prêt</h4>
                            <div class="chart-container">
                                <canvas id="typeChart"></canvas>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-md-6">
                        <div class="report-section">
                            <h4><i class="fas fa-money-check-alt me-2"></i>Méthodes de Paiement</h4>
                            <div class="chart-container">
                                <canvas id="paiementChart"></canvas>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Section 3: Top 10 emprunteurs -->
                <div class="report-section">
                    <h4><i class="fas fa-trophy me-2"></i>Top 10 des Emprunteurs</h4>
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead class="table-light">
                                <tr>
                                    <th>#</th>
                                    <th>Membre</th>
                                    <th>Catégorie</th>
                                    <th>Nombre de Prêts</th>
                                    <th>Total Emprunté</th>
                                    <th>Dette Actuelle</th>
                                    <th>Score</th>
                                    <th>Cotisation</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($top_emprunteurs as $index => $emprunteur): ?>
                                <tr>
                                    <td><?= $index + 1 ?></td>
                                    <td>
                                        <strong><?= securiser($emprunteur['nom_complet']) ?></strong>
                                        <br><small class="text-muted"><?= securiser($emprunteur['code_membre']) ?></small>
                                    </td>
                                    <td><?= securiser($emprunteur['nom_categorie']) ?></td>
                                    <td class="text-center"><?= $emprunteur['nombre_prets'] ?></td>
                                    <td class="text-end"><?= format_montant($emprunteur['total_emprunte']) ?></td>
                                    <td class="text-end <?= $emprunteur['dette_actuelle'] > 0 ? 'text-danger fw-bold' : 'text-success' ?>">
                                        <?= format_montant($emprunteur['dette_actuelle']) ?>
                                    </td>
                                    <td class="text-center">
                                        <span class="badge bg-<?= $emprunteur['score_credit'] >= 700 ? 'success' : ($emprunteur['score_credit'] >= 500 ? 'warning' : 'danger') ?>">
                                            <?= $emprunteur['score_credit'] ?>
                                        </span>
                                    </td>
                                    <td class="text-center">
                                        <?php if ($emprunteur['cotisation_a_jour']): ?>
                                            <span class="badge bg-success"><i class="fas fa-check"></i></span>
                                        <?php else: ?>
                                            <span class="badge bg-danger"><i class="fas fa-times"></i></span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Section 4: Prêts en retard -->
                <div class="report-section">
                    <h4><i class="fas fa-exclamation-triangle me-2 text-danger"></i>Prêts en Retard</h4>
                    <?php if (empty($prets_retard)): ?>
                        <div class="alert alert-success">
                            <i class="fas fa-check-circle me-2"></i>Aucun prêt en retard actuellement.
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead class="table-light">
                                    <tr>
                                        <th>Code Prêt</th>
                                        <th>Membre</th>
                                        <th>Montant Restant</th>
                                        <th>Date d'échéance</th>
                                        <th>Jours de retard</th>
                                        <th>Échéances retard</th>
                                        <th>Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($prets_retard as $pret): ?>
                                    <tr class="<?= $pret['jours_retard'] > 30 ? 'table-danger' : 'table-warning' ?>">
                                        <td><strong><?= securiser($pret['code_pret']) ?></strong></td>
                                        <td><?= securiser($pret['nom_complet']) ?><br><small><?= securiser($pret['code_membre']) ?></small></td>
                                        <td class="text-end fw-bold"><?= format_montant($pret['montant_restant']) ?></td>
                                        <td><?= date('d/m/Y', strtotime($pret['date_fin'])) ?></td>
                                        <td class="text-center">
                                            <span class="badge bg-<?= $pret['jours_retard'] > 30 ? 'danger' : 'warning' ?>">
                                                <?= $pret['jours_retard'] ?> jours
                                            </span>
                                        </td>
                                        <td class="text-center"><?= $pret['echeances_en_retard'] ?></td>
                                        <td>
                                            <a href="paiements.php?pret_id=<?= $pret['id'] ?>" class="btn btn-sm btn-primary">
                                                <i class="fas fa-cash-register me-1"></i>Paiement
                                            </a>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Section 5: Performance par catégorie -->
                <div class="report-section">
                    <h4><i class="fas fa-chart-bar me-2"></i>Performance par Catégorie</h4>
                    <div class="chart-container">
                        <canvas id="categorieChart"></canvas>
                    </div>
                </div>

                <!-- Section 6: Tableau récapitulatif -->
                <div class="report-section">
                    <h4><i class="fas fa-table me-2"></i>Récapitulatif Statistiques</h4>
                    <div class="row">
                        <div class="col-md-6">
                            <div class="card">
                                <div class="card-header bg-light">
                                    <h6 class="mb-0">Statut des Prêts</h6>
                                </div>
                                <div class="card-body">
                                    <ul class="list-group list-group-flush">
                                        <li class="list-group-item d-flex justify-content-between">
                                            <span>Prêts Actifs</span>
                                            <span class="badge bg-primary"><?= $stats['prets']['actifs'] ?? 0 ?></span>
                                        </li>
                                        <li class="list-group-item d-flex justify-content-between">
                                            <span>En Attente</span>
                                            <span class="badge bg-warning"><?= $stats['prets']['en_attente'] ?? 0 ?></span>
                                        </li>
                                        <li class="list-group-item d-flex justify-content-between">
                                            <span>En Retard</span>
                                            <span class="badge bg-danger"><?= $stats['prets']['en_retard'] ?? 0 ?></span>
                                        </li>
                                        <li class="list-group-item d-flex justify-content-between">
                                            <span>Complets</span>
                                            <span class="badge bg-success"><?= $stats['prets']['completes'] ?? 0 ?></span>
                                        </li>
                                    </ul>
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-md-6">
                            <div class="card">
                                <div class="card-header bg-light">
                                    <h6 class="mb-0">Paiements</h6>
                                </div>
                                <div class="card-body">
                                    <ul class="list-group list-group-flush">
                                        <?php foreach ($stats['paiements'] as $paiement): ?>
                                        <li class="list-group-item d-flex justify-content-between">
                                            <span><?= ucfirst(str_replace('_', ' ', $paiement['methode_paiement'])) ?></span>
                                            <span>
                                                <?= number_format($paiement['nombre']) ?> 
                                                <small class="text-muted">(<?= format_montant($paiement['montant_total']) ?>)</small>
                                            </span>
                                        </li>
                                        <?php endforeach; ?>
                                    </ul>
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
        // Gestion de l'affichage des dates personnalisées
        document.getElementById('periodeSelect').addEventListener('change', function() {
            const isCustom = this.value === 'personnalise';
            document.getElementById('dateDebutGroup').style.display = isCustom ? 'block' : 'none';
            document.getElementById('dateFinGroup').style.display = isCustom ? 'block' : 'none';
        });

        // Données pour les graphiques
        const evolutionData = {
            labels: [<?php foreach ($evolution_data as $data): ?>"<?= $data['mois'] ?>",<?php endforeach; ?>],
            datasets: [{
                label: 'Nombre de prêts',
                data: [<?php foreach ($evolution_data as $data): ?><?= $data['nombre_prets'] ?>,<?php endforeach; ?>],
                borderColor: '#3498db',
                backgroundColor: 'rgba(52, 152, 219, 0.1)',
                fill: true,
                tension: 0.4
            }, {
                label: 'Montant total (millions)',
                data: [<?php foreach ($evolution_data as $data): ?><?= round($data['montant_total'] / 1000000, 2) ?>,<?php endforeach; ?>],
                borderColor: '#2ecc71',
                backgroundColor: 'rgba(46, 204, 113, 0.1)',
                fill: true,
                tension: 0.4,
                yAxisID: 'y1'
            }]
        };

        const typeData = {
            labels: [<?php foreach ($repartition_type as $type): ?>"<?= $type['nom_type'] ?>",<?php endforeach; ?>],
            datasets: [{
                data: [<?php foreach ($repartition_type as $type): ?><?= $type['montant_total'] ?>,<?php endforeach; ?>],
                backgroundColor: [
                    <?php foreach ($repartition_type as $index => $type): ?>
                    '<?= get_chart_color($index) ?>',
                    <?php endforeach; ?>
                ]
            }]
        };

        const paiementData = {
            labels: [<?php foreach ($stats['paiements'] as $paiement): ?>"<?= ucfirst(str_replace('_', ' ', $paiement['methode_paiement'])) ?>",<?php endforeach; ?>],
            datasets: [{
                data: [<?php foreach ($stats['paiements'] as $paiement): ?><?= $paiement['nombre'] ?>,<?php endforeach; ?>],
                backgroundColor: [
                    <?php foreach ($stats['paiements'] as $index => $paiement): ?>
                    '<?= get_chart_color($index) ?>',
                    <?php endforeach; ?>
                ]
            }]
        };

        const categorieData = {
            labels: [<?php foreach ($categories_perf as $cat): ?>"<?= $cat['nom_categorie'] ?>",<?php endforeach; ?>],
            datasets: [{
                label: 'Montant total prêté',
                data: [<?php foreach ($categories_perf as $cat): ?><?= $cat['montant_total_prets'] ?>,<?php endforeach; ?>],
                backgroundColor: '#3498db'
            }, {
                label: 'Dette active',
                data: [<?php foreach ($categories_perf as $cat): ?><?= $cat['dette_totale'] ?>,<?php endforeach; ?>],
                backgroundColor: '#e74c3c'
            }]
        };

        // Initialisation des graphiques
        document.addEventListener('DOMContentLoaded', function() {
            // Graphique d'évolution
            const evolutionCtx = document.getElementById('evolutionChart').getContext('2d');
            new Chart(evolutionCtx, {
                type: 'line',
                data: evolutionData,
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    scales: {
                        y: {
                            beginAtZero: true,
                            title: {
                                display: true,
                                text: 'Nombre de prêts'
                            }
                        },
                        y1: {
                            position: 'right',
                            beginAtZero: true,
                            title: {
                                display: true,
                                text: 'Montant (millions FBu)'
                            }
                        }
                    }
                }
            });

            // Graphique des types
            const typeCtx = document.getElementById('typeChart').getContext('2d');
            new Chart(typeCtx, {
                type: 'doughnut',
                data: typeData,
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'right'
                        }
                    }
                }
            });

            // Graphique des paiements
            const paiementCtx = document.getElementById('paiementChart').getContext('2d');
            new Chart(paiementCtx, {
                type: 'pie',
                data: paiementData,
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'right'
                        }
                    }
                }
            });

            // Graphique des catégories
            const categorieCtx = document.getElementById('categorieChart').getContext('2d');
            new Chart(categorieCtx, {
                type: 'bar',
                data: categorieData,
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    scales: {
                        y: {
                            beginAtZero: true,
                            ticks: {
                                callback: function(value) {
                                    return (value / 1000000).toFixed(1) + 'M';
                                }
                            }
                        }
                    }
                }
            });
        });

        // Fonction d'export Excel (basique)
        function exportToExcel() {
            alert('Fonction d\'export Excel à implémenter');
            // Ici, vous pouvez ajouter une requête AJAX pour générer un fichier Excel
        }
    </script>
</body>
</html>