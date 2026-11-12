<?php
require_once 'config_database.php';

// Vérifier l'authentification
if (!est_connecte()) {
    rediriger('login.php');
}

// Vérifier les permissions (au moins gestionnaire)
if (!a_la_permission('gestionnaire')) {
    $_SESSION['erreur'] = "Vous n'avez pas les permissions nécessaires pour accéder à cette page.";
    rediriger('dashboard.php');
}

// Variables pour la pagination et la recherche
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = 20;
$offset = ($page - 1) * $limit;
$recherche = isset($_GET['recherche']) ? securiser($_GET['recherche']) : '';
$statut_filtre = isset($_GET['statut']) ? securiser($_GET['statut']) : '';

// Initialiser les variables pour éviter les erreurs
$total_emprunteurs = 0;
$total_pages = 0;
$emprunteurs = [];
$stats = [
    'total' => 0,
    'eligibles' => 0,
    'suspendus' => 0,
    'blacklistes' => 0,
    'en_attente' => 0,
    'cotisations_a_jour' => 0
];

try {
    // Construction de la requête de base
    $sql = "SELECT 
            e.*,
            m.nom, 
            m.prenom, 
            m.email, 
            m.telephone,
            m.date_inscription,
            m.salaire_mensuel,
            m.adresse,
            cm.nom_categorie,
            cm.cotisation_mensuelle,
            -- Calcul de la dette actuelle : somme des montants restants de tous les prêts actifs
            COALESCE(SUM(p.montant_restant), 0) as dette_calculee
        FROM emprunteurs e
        INNER JOIN membres m ON e.code_membre = m.code_membre
        INNER JOIN categories_membres cm ON m.categorie_id = cm.id
        LEFT JOIN prets p ON m.code_membre = p.code_membre AND p.statut IN ('actif', 'en_retard', 'approuve')
        WHERE 1=1";
   
    $params = [];
    
    // Application des filtres
    if (!empty($recherche)) {
        $sql .= " AND (m.nom LIKE ? OR m.prenom LIKE ? OR m.email LIKE ? OR m.telephone LIKE ? OR e.code_membre LIKE ?)";
        $search_term = "%$recherche%";
        $params = array_merge($params, [$search_term, $search_term, $search_term, $search_term, $search_term]);
    }

    if (!empty($statut_filtre) && $statut_filtre != 'tous') {
        $sql .= " AND e.statut = ?";
        $params[] = $statut_filtre;
    }

    // Ajouter le GROUP BY
    $sql .= " GROUP BY e.code_membre";
    
    // Compter le nombre total d'emprunteurs pour la pagination
    $count_sql = "SELECT COUNT(DISTINCT e.code_membre) as total FROM emprunteurs e
                 INNER JOIN membres m ON e.code_membre = m.code_membre
                 INNER JOIN categories_membres cm ON m.categorie_id = cm.id
                 WHERE 1=1";
    
    $count_params = [];
    
    if (!empty($recherche)) {
        $count_sql .= " AND (m.nom LIKE ? OR m.prenom LIKE ? OR m.email LIKE ? OR m.telephone LIKE ? OR e.code_membre LIKE ?)";
        $search_term = "%$recherche%";
        $count_params = array_merge($count_params, [$search_term, $search_term, $search_term, $search_term, $search_term]);
    }

    if (!empty($statut_filtre) && $statut_filtre != 'tous') {
        $count_sql .= " AND e.statut = ?";
        $count_params[] = $statut_filtre;
    }
    
    $stmt_count = $pdo->prepare($count_sql);
    $stmt_count->execute($count_params);
    $total_emprunteurs = $stmt_count->fetch()['total'];
    $total_pages = ceil($total_emprunteurs / $limit);
    
    // Ajout du tri et de la pagination
    $sql .= " ORDER BY e.derniere_modification DESC LIMIT ? OFFSET ?";
    $params[] = $limit;
    $params[] = $offset;
    
    // Exécution de la requête principale
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $emprunteurs = $stmt->fetchAll();
    
    // Statistiques pour les cartes
    $stmt_stats = $pdo->query("
        SELECT 
            COUNT(*) as total,
            SUM(CASE WHEN statut = 'eligible' THEN 1 ELSE 0 END) as eligibles,
            SUM(CASE WHEN statut = 'suspendu' THEN 1 ELSE 0 END) as suspendus,
            SUM(CASE WHEN statut = 'blackliste' THEN 1 ELSE 0 END) as blacklistes,
            SUM(CASE WHEN statut = 'en_attente' THEN 1 ELSE 0 END) as en_attente,
            SUM(CASE WHEN cotisation_a_jour = TRUE THEN 1 ELSE 0 END) as cotisations_a_jour
        FROM emprunteurs
    ");
    $stats = $stmt_stats->fetch();
    
} catch (PDOException $e) {
    $erreur = "Erreur lors du chargement des données: " . $e->getMessage();
}

// Traitement des actions (changement de statut)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    try {
        $code_membre = securiser($_POST['code_membre']);
        $action = securiser($_POST['action']);
        
        switch ($action) {
            case 'changer_statut':
                $nouveau_statut = securiser($_POST['nouveau_statut']);
                $raison = securiser($_POST['raison'] ?? '');
                
                $stmt = $pdo->prepare("UPDATE emprunteurs SET statut = ?, derniere_modification = NOW() WHERE code_membre = ?");
                $stmt->execute([$nouveau_statut, $code_membre]);
                
                // Journaliser l'action
                $stmt_audit = $pdo->prepare("
                    INSERT INTO audit_log (table_name, action, record_id, old_values, new_values, user_id, ip_address)
                    VALUES ('emprunteurs', 'UPDATE', ?, ?, ?, ?, ?)
                ");
                $stmt_audit->execute([
                    $code_membre,
                    json_encode(['statut' => 'ancien_statut']),
                    json_encode(['statut' => $nouveau_statut, 'raison' => $raison]),
                    $_SESSION['user_id'],
                    $_SERVER['REMOTE_ADDR']
                ]);
                
                $_SESSION['succes'] = "Statut de l'emprunteur mis à jour avec succès.";
                break;
        }
        
        // Rediriger pour éviter la resoumission du formulaire
        rediriger('emprunteurs.php');
        
    } catch (PDOException $e) {
        $erreur = "Erreur lors du traitement: " . $e->getMessage();
    }
}
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestion des Emprunteurs - <?php echo APP_NAME; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css" rel="stylesheet">
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
        .badge-statut {
            font-size: 0.8em;
            padding: 6px 12px;
        }
        .table-actions {
            white-space: nowrap;
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
                                <a class="nav-link active" href="emprunteurs.php">
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
                <!-- En-tête -->
                <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center py-3 mb-4 border-bottom">
                    <h1 class="h2">
                        <i class="fas fa-user-check me-2"></i>Gestion des Emprunteurs
                    </h1>
                    <div class="btn-toolbar mb-2 mb-md-0">
                        <div class="btn-group me-2">
                            <a href="dashboard.php" class="btn btn-sm btn-outline-secondary">
                                <i class="fas fa-arrow-left me-1"></i>Retour
                            </a>
                            <button type="button" class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#modalFiltres">
                                <i class="fas fa-filter me-1"></i>Filtres
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Messages d'alerte -->
                <?php if (isset($_SESSION['succes'])): ?>
                    <div class="alert alert-success alert-dismissible fade show" role="alert">
                        <i class="fas fa-check-circle me-2"></i><?php echo $_SESSION['succes']; ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                    <?php unset($_SESSION['succes']); ?>
                <?php endif; ?>

                <?php if (isset($erreur)): ?>
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        <i class="fas fa-exclamation-triangle me-2"></i><?php echo $erreur; ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <!-- Cartes de statistiques -->
                <div class="row mb-4">
                    <div class="col-xl-2 col-md-4 mb-4">
                        <div class="card stat-card border-left-primary shadow h-100 py-2">
                            <div class="card-body">
                                <div class="row no-gutters align-items-center">
                                    <div class="col mr-2">
                                        <div class="text-xs font-weight-bold text-primary text-uppercase mb-1">
                                            Total
                                        </div>
                                        <div class="h5 mb-0 font-weight-bold text-gray-800">
                                            <?php echo number_format($stats['total'] ?? 0); ?>
                                        </div>
                                    </div>
                                    <div class="col-auto">
                                        <i class="fas fa-users fa-2x text-gray-300"></i>
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
                                            Éligibles
                                        </div>
                                        <div class="h5 mb-0 font-weight-bold text-gray-800">
                                            <?php echo number_format($stats['eligibles'] ?? 0); ?>
                                        </div>
                                    </div>
                                    <div class="col-auto">
                                        <i class="fas fa-check-circle fa-2x text-gray-300"></i>
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
                                            En Attente
                                        </div>
                                        <div class="h5 mb-0 font-weight-bold text-gray-800">
                                            <?php echo number_format($stats['en_attente'] ?? 0); ?>
                                        </div>
                                    </div>
                                    <div class="col-auto">
                                        <i class="fas fa-clock fa-2x text-gray-300"></i>
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
                                            Blacklistés
                                        </div>
                                        <div class="h5 mb-0 font-weight-bold text-gray-800">
                                            <?php echo number_format($stats['blacklistes'] ?? 0); ?>
                                        </div>
                                    </div>
                                    <div class="col-auto">
                                        <i class="fas fa-ban fa-2x text-gray-300"></i>
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
                                            Cotisations à Jour
                                        </div>
                                        <div class="h5 mb-0 font-weight-bold text-gray-800">
                                            <?php echo number_format($stats['cotisations_a_jour'] ?? 0); ?>
                                        </div>
                                    </div>
                                    <div class="col-auto">
                                        <i class="fas fa-coins fa-2x text-gray-300"></i>
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
                                            Suspendus
                                        </div>
                                        <div class="h5 mb-0 font-weight-bold text-gray-800">
                                            <?php echo number_format($stats['suspendus'] ?? 0); ?>
                                        </div>
                                    </div>
                                    <div class="col-auto">
                                        <i class="fas fa-pause-circle fa-2x text-gray-300"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Tableau des emprunteurs -->
                <div class="card shadow">
                    <div class="card-header bg-white d-flex justify-content-between align-items-center">
                        <h6 class="m-0 font-weight-bold text-primary">
                            <i class="fas fa-list me-2"></i>Liste des Emprunteurs
                        </h6>
                        <span class="badge bg-primary"><?php echo number_format($total_emprunteurs); ?> résultat(s)</span>
                    </div>
                    <div class="card-body">
                        <?php if (!empty($emprunteurs)): ?>
                            <div class="table-responsive">
                                <table class="table table-hover table-striped">
                                    <thead class="table-light">
                                        <tr>
                                            <th>Code Membre</th>
                                            <th>Nom & Prénom</th>
                                            <th>Catégorie</th>
                                            <th>Score</th>
                                            <th>Statut</th>
                                           
                                            <th>Dette Actuelle</th>
                                            <th>Cotisation</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($emprunteurs as $emprunteur): ?>
                                            <tr>
                                                <td>
                                                    <strong><?php echo securiser($emprunteur['code_membre']); ?></strong>
                                                </td>
                                                <td>
                                                    <div class="fw-bold"><?php echo securiser($emprunteur['nom'] . ' ' . $emprunteur['prenom']); ?></div>
                                                    <small class="text-muted"><?php echo securiser($emprunteur['email']); ?></small>
                                                </td>
                                                <td>
                                                    <span class="badge bg-info"><?php echo securiser($emprunteur['nom_categorie']); ?></span>
                                                </td>
                                                <td>
                                                    <span class="badge 
                                                        <?php 
                                                        if ($emprunteur['score_credit'] >= 700) echo 'bg-success';
                                                        elseif ($emprunteur['score_credit'] >= 500) echo 'bg-warning';
                                                        else echo 'bg-danger';
                                                        ?>
                                                    ">
                                                        <?php echo securiser($emprunteur['score_credit']); ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <?php
                                                    $badge_class = [
                                                        'eligible' => 'bg-success',
                                                        'en_attente' => 'bg-warning',
                                                        'suspendu' => 'bg-danger',
                                                        'blackliste' => 'bg-dark'
                                                    ][$emprunteur['statut']] ?? 'bg-secondary';
                                                    ?>
                                                    <span class="badge <?php echo $badge_class; ?> badge-statut">
                                                        <?php echo ucfirst(securiser($emprunteur['statut'])); ?>
                                                    </span>
                                                </td>
                                                
                                                <td>
                                                    <?php if ($emprunteur['dette_calculee'] > 0): ?>
                                                        <span class="text-danger fw-bold">
                                                            <?php echo number_format($emprunteur['dette_calculee'], 0, ',', ' '); ?> FBu
                                                        </span>
                                                    <?php else: ?>
                                                        <span class="text-success">Aucune dette</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php if ($emprunteur['cotisation_a_jour']): ?>
                                                        <span class="badge bg-success">À jour</span>
                                                    <?php else: ?>
                                                        <span class="badge bg-warning">En retard</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="table-actions">
                                                    <div class="btn-group btn-group-sm">
                                                        <button type="button" class="btn btn-outline-primary" 
                                                                data-bs-toggle="modal" 
                                                                data-bs-target="#modalDetails"
                                                                data-code="<?php echo securiser($emprunteur['code_membre']); ?>"
                                                                data-nom="<?php echo securiser($emprunteur['nom'] . ' ' . $emprunteur['prenom']); ?>"
                                                                data-email="<?php echo securiser($emprunteur['email']); ?>"
                                                                data-telephone="<?php echo securiser($emprunteur['telephone']); ?>"
                                                                data-adresse="<?php echo securiser($emprunteur['adresse'] ?? ''); ?>"
                                                                data-salaire="<?php echo number_format($emprunteur['salaire_mensuel'] ?? 0, 0, ',', ' '); ?>"
                                                                data-categorie="<?php echo securiser($emprunteur['nom_categorie']); ?>"
                                                                data-score="<?php echo securiser($emprunteur['score_credit']); ?>"
                                                                data-statut="<?php echo securiser($emprunteur['statut']); ?>"
                                                                data-dette="<?php echo number_format($emprunteur['dette_calculee'], 0, ',', ' '); ?>"
                                                                data-cotisation="<?php echo $emprunteur['cotisation_a_jour'] ? 'À jour' : 'En retard'; ?>"
                                                                data-date-inscription="<?php echo date('d/m/Y', strtotime($emprunteur['date_inscription'])); ?>">
                                                            <i class="fas fa-eye"></i> Voir
                                                        </button>
                                                        <button type="button" class="btn btn-outline-warning" 
                                                                data-bs-toggle="modal" 
                                                                data-bs-target="#modalStatut"
                                                                data-code="<?php echo securiser($emprunteur['code_membre']); ?>"
                                                                data-nom="<?php echo securiser($emprunteur['nom'] . ' ' . $emprunteur['prenom']); ?>"
                                                                data-statut-actuel="<?php echo securiser($emprunteur['statut']); ?>">
                                                            <i class="fas fa-edit"></i>
                                                        </button>
                                                    </div>
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
                                        <?php if ($page > 1): ?>
                                            <li class="page-item">
                                                <a class="page-link" href="?page=<?php echo $page - 1; ?>&recherche=<?php echo $recherche; ?>&statut=<?php echo $statut_filtre; ?>">Précédent</a>
                                            </li>
                                        <?php endif; ?>

                                        <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                                            <li class="page-item <?php echo $i == $page ? 'active' : ''; ?>">
                                                <a class="page-link" href="?page=<?php echo $i; ?>&recherche=<?php echo $recherche; ?>&statut=<?php echo $statut_filtre; ?>"><?php echo $i; ?></a>
                                            </li>
                                        <?php endfor; ?>

                                        <?php if ($page < $total_pages): ?>
                                            <li class="page-item">
                                                <a class="page-link" href="?page=<?php echo $page + 1; ?>&recherche=<?php echo $recherche; ?>&statut=<?php echo $statut_filtre; ?>">Suivant</a>
                                            </li>
                                        <?php endif; ?>
                                    </ul>
                                </nav>
                            <?php endif; ?>

                        <?php else: ?>
                            <div class="text-center py-5">
                                <i class="fas fa-users fa-4x text-muted mb-3"></i>
                                <h5 class="text-muted">Aucun emprunteur trouvé</h5>
                                <p class="text-muted">Aucun emprunteur ne correspond à vos critères de recherche.</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </main>
        </div>
    </div>

    <!-- Modal Filtres -->
    <div class="modal fade" id="modalFiltres" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-filter me-2"></i>Filtrer les emprunteurs</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="GET" action="">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label for="recherche" class="form-label">Recherche</label>
                            <input type="text" class="form-control" id="recherche" name="recherche" 
                                   value="<?php echo $recherche; ?>" 
                                   placeholder="Nom, prénom, email, téléphone...">
                        </div>
                        <div class="mb-3">
                            <label for="statut" class="form-label">Statut</label>
                            <select class="form-select" id="statut" name="statut">
                                <option value="tous" <?php echo $statut_filtre == 'tous' ? 'selected' : ''; ?>>Tous les statuts</option>
                                <option value="eligible" <?php echo $statut_filtre == 'eligible' ? 'selected' : ''; ?>>Éligible</option>
                                <option value="en_attente" <?php echo $statut_filtre == 'en_attente' ? 'selected' : ''; ?>>En attente</option>
                                <option value="suspendu" <?php echo $statut_filtre == 'suspendu' ? 'selected' : ''; ?>>Suspendu</option>
                                <option value="blackliste" <?php echo $statut_filtre == 'blackliste' ? 'selected' : ''; ?>>Blacklisté</option>
                            </select>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <a href="emprunteurs.php" class="btn btn-secondary">Réinitialiser</a>
                        <button type="submit" class="btn btn-primary">Appliquer les filtres</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Modal Détails Emprunteur -->
    <div class="modal fade" id="modalDetails" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Détails de l'Emprunteur</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-6">
                            <h6 class="text-primary">Informations Personnelles</h6>
                            <table class="table table-sm">
                                <tr>
                                    <th width="40%">Code Membre:</th>
                                    <td id="detail-code"></td>
                                </tr>
                                <tr>
                                    <th>Nom & Prénom:</th>
                                    <td id="detail-nom"></td>
                                </tr>
                                <tr>
                                    <th>Email:</th>
                                    <td id="detail-email"></td>
                                </tr>
                                <tr>
                                    <th>Téléphone:</th>
                                    <td id="detail-telephone"></td>
                                </tr>
                                <tr>
                                    <th>Adresse:</th>
                                    <td id="detail-adresse"></td>
                                </tr>
                                <tr>
                                    <th>Salaire Mensuel:</th>
                                    <td id="detail-salaire"> FBu</td>
                                </tr>
                                <tr>
                                    <th>Date d'Inscription:</th>
                                    <td id="detail-date-inscription"></td>
                                </tr>
                            </table>
                        </div>
                        <div class="col-md-6">
                            <h6 class="text-primary">Informations Emprunteur</h6>
                            <table class="table table-sm">
                                <tr>
                                    <th width="40%">Catégorie:</th>
                                    <td id="detail-categorie"></td>
                                </tr>
                                <tr>
                                    <th>Score de Crédit:</th>
                                    <td id="detail-score"></td>
                                </tr>
                                <tr>
                                    <th>Statut:</th>
                                    <td id="detail-statut"></td>
                                </tr>
                                
                                <tr>
                                    <th>Dette Actuelle:</th>
                                    <td id="detail-dette"> FBu</td>
                                </tr>
                                <tr>
                                    <th>Cotisation:</th>
                                    <td id="detail-cotisation"></td>
                                </tr>
                            </table>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Fermer</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal Changement de Statut -->
    <div class="modal fade" id="modalStatut" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST" action="">
                    <div class="modal-header">
                        <h5 class="modal-title">Changer le statut</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" name="action" value="changer_statut">
                        <input type="hidden" name="code_membre" id="statut-code-membre">
                        
                        <div class="mb-3">
                            <label class="form-label">Emprunteur</label>
                            <p class="form-control-plaintext fw-bold" id="statut-nom-emprunteur"></p>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Statut actuel</label>
                            <p class="form-control-plaintext" id="statut-actuel"></p>
                        </div>
                        
                        <div class="mb-3">
                            <label for="nouveau_statut" class="form-label">Nouveau statut</label>
                            <select class="form-select" id="nouveau_statut" name="nouveau_statut" required>
                                <option value="">Sélectionner un statut</option>
                                <option value="eligible">Éligible</option>
                                <option value="en_attente">En attente</option>
                                <option value="suspendu">Suspendu</option>
                                <option value="blackliste">Blacklisté</option>
                            </select>
                        </div>
                        
                        <div class="mb-3">
                            <label for="raison" class="form-label">Raison (optionnelle)</label>
                            <textarea class="form-control" id="raison" name="raison" rows="3" 
                                      placeholder="Expliquez la raison du changement de statut..."></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annuler</button>
                        <button type="submit" class="btn btn-primary">Enregistrer</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Script pour le modal de détails
        const modalDetails = document.getElementById('modalDetails');
        if (modalDetails) {
            modalDetails.addEventListener('show.bs.modal', function (event) {
                const button = event.relatedTarget;
                
                document.getElementById('detail-code').textContent = button.getAttribute('data-code');
                document.getElementById('detail-nom').textContent = button.getAttribute('data-nom');
                document.getElementById('detail-email').textContent = button.getAttribute('data-email');
                document.getElementById('detail-telephone').textContent = button.getAttribute('data-telephone');
                document.getElementById('detail-adresse').textContent = button.getAttribute('data-adresse') || 'Non renseignée';
                document.getElementById('detail-salaire').textContent = button.getAttribute('data-salaire') + ' FBu';
                document.getElementById('detail-date-inscription').textContent = button.getAttribute('data-date-inscription');
                document.getElementById('detail-categorie').textContent = button.getAttribute('data-categorie');
                document.getElementById('detail-score').textContent = button.getAttribute('data-score');
                document.getElementById('detail-statut').textContent = button.getAttribute('data-statut');
                document.getElementById('detail-dette').textContent = button.getAttribute('data-dette') + ' FBu';
                document.getElementById('detail-cotisation').textContent = button.getAttribute('data-cotisation');
            });
        }

        // Script pour le modal de changement de statut
        const modalStatut = document.getElementById('modalStatut');
        if (modalStatut) {
            modalStatut.addEventListener('show.bs.modal', function (event) {
                const button = event.relatedTarget;
                
                document.getElementById('statut-code-membre').value = button.getAttribute('data-code');
                document.getElementById('statut-nom-emprunteur').textContent = button.getAttribute('data-nom');
                document.getElementById('statut-actuel').textContent = button.getAttribute('data-statut-actuel');
            });
        }
    </script>
</body>
</html>