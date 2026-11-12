<?php
require_once 'config_database.php';

// Vérifier l'authentification
if (!est_connecte()) {
    rediriger('login.php');
}

// Vérifier les permissions
if (!a_la_permission('admin') && !a_la_permission('gestionnaire') && !a_la_permission('caissier')) {
    $_SESSION['erreur'] = "Vous n'avez pas la permission d'accéder à cette page.";
    rediriger('dashboard.php');
}

// Variables pour la pagination et la recherche
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = 20;
$offset = ($page - 1) * $limit;
$recherche = isset($_GET['recherche']) ? securiser($_GET['recherche']) : '';
$statut_filter = isset($_GET['statut']) ? securiser($_GET['statut']) : '';
$type_filter = isset($_GET['type_pret']) ? (int)$_GET['type_pret'] : '';

try {
    // Construire la requête de base avec jointures
    $sql = "SELECT p.*, m.nom, m.prenom, m.code_membre, 
                   pp.nom_pret, tp.nom_type,
                   COUNT(ep.id) as echeances_total,
                   SUM(CASE WHEN ep.statut = 'payee' THEN 1 ELSE 0 END) as echeances_payees
            FROM prets p
            INNER JOIN membres m ON p.code_membre = m.code_membre
            INNER JOIN plans_pret pp ON p.plan_pret_id = pp.id
            INNER JOIN types_pret tp ON pp.type_pret_id = tp.id
            LEFT JOIN echeances_pret ep ON p.id = ep.pret_id
            WHERE 1=1";
    
    $params = [];
    
    // Appliquer les filtres
    if (!empty($recherche)) {
        $sql .= " AND (p.code_pret LIKE ? OR m.nom LIKE ? OR m.prenom LIKE ? OR m.code_membre LIKE ?)";
        $search_term = "%$recherche%";
        $params = array_merge($params, [$search_term, $search_term, $search_term, $search_term]);
    }
    
    if (!empty($statut_filter)) {
        $sql .= " AND p.statut = ?";
        $params[] = $statut_filter;
    }
    
    if (!empty($type_filter)) {
        $sql .= " AND pp.type_pret_id = ?";
        $params[] = $type_filter;
    }
    
    // Grouper pour éviter les doublons
    $sql .= " GROUP BY p.id";
    
    // Compter le total pour la pagination
    $count_sql = "SELECT COUNT(DISTINCT p.id) as total FROM prets p
                  INNER JOIN membres m ON p.code_membre = m.code_membre
                  INNER JOIN plans_pret pp ON p.plan_pret_id = pp.id
                  WHERE 1=1";
    
    if (!empty($recherche)) {
        $count_sql .= " AND (p.code_pret LIKE ? OR m.nom LIKE ? OR m.prenom LIKE ? OR m.code_membre LIKE ?)";
    }
    if (!empty($statut_filter)) {
        $count_sql .= " AND p.statut = ?";
    }
    if (!empty($type_filter)) {
        $count_sql .= " AND pp.type_pret_id = ?";
    }
    
    $stmt_count = $pdo->prepare($count_sql);
    $stmt_count->execute($params);
    $total_prets = $stmt_count->fetch()['total'];
    $total_pages = ceil($total_prets / $limit);
    
    // Ajouter le tri et la pagination
    $sql .= " ORDER BY p.date_creation DESC LIMIT ? OFFSET ?";
    $params[] = $limit;
    $params[] = $offset;
    
    // Exécuter la requête principale
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $prets = $stmt->fetchAll();
    
    // Récupérer les types de prêt pour le filtre
    $stmt_types = $pdo->query("SELECT id, nom_type FROM types_pret WHERE statut = 'actif'");
    $types_pret = $stmt_types->fetchAll();
    
} catch (PDOException $e) {
    $erreur = "Erreur lors du chargement des prêts: " . $e->getMessage();
}

// Traitement des actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        try {
            switch ($_POST['action']) {
                case 'changer_statut':
                    if (a_la_permission('admin') || a_la_permission('gestionnaire')) {
                        $pret_id = (int)$_POST['pret_id'];
                        $nouveau_statut = securiser($_POST['nouveau_statut']);
                        
                        $stmt = $pdo->prepare("UPDATE prets SET statut = ? WHERE id = ?");
                        $stmt->execute([$nouveau_statut, $pret_id]);
                        
                        $_SESSION['succes'] = "Statut du prêt mis à jour avec succès.";
                    } else {
                        $_SESSION['erreur'] = "Vous n'avez pas la permission de modifier les prêts.";
                    }
                    break;
                    
                case 'traiter_pret':
                    if (a_la_permission('admin') || a_la_permission('gestionnaire')) {
                        $pret_id = (int)$_POST['pret_id'];
                        
                        // Rediriger vers la page de traitement
                        rediriger('traiter_pret.php?id=' . $pret_id);
                    } else {
                        $_SESSION['erreur'] = "Vous n'avez pas la permission de traiter les prêts.";
                    }
                    break;
            }
            
            rediriger('prets.php?' . http_build_query($_GET));
            
        } catch (PDOException $e) {
            $_SESSION['erreur'] = "Erreur lors de l'opération: " . $e->getMessage();
        }
    }
}
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestion des Prêts - <?php echo APP_NAME; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        .sidebar { background: #2c3e50; color: white; height: 100vh; position: fixed; padding-top: 20px; overflow-y: auto; }
        .sidebar .nav-link { color: #bdc3c7; padding: 12px 15px; margin: 3px 0; font-size: 0.9rem; }
        .sidebar .nav-link:hover, .sidebar .nav-link.active { color: white; background: #34495e; }
        .sidebar .nav-link i { width: 20px; margin-right: 8px; }
        .main-content { margin-left: 250px; padding: 20px; }
        .stat-card { border-radius: 10px; transition: transform 0.3s; }
        .stat-card:hover { transform: translateY(-5px); }
        .menu-section { margin: 15px 0; padding: 10px 0; border-bottom: 1px solid #34495e; }
        .menu-section-title { font-size: 0.8rem; text-transform: uppercase; color: #95a5a6; padding: 0 15px; margin-bottom: 10px; font-weight: bold; }
        .progress { height: 8px; }
        .table-actions { white-space: nowrap; }
    </style>
</head>
<body>
    <div class="container-fluid">
        <div class="row">
            <!-- Sidebar (identique aux autres pages) -->
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
                                <a class="nav-link active" href="prets.php">
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
                            <i class="fas fa-file-invoice-dollar me-2"></i>Gestion des Prêts
                        </h1>
                        <p class="text-muted mb-0">Liste et gestion de tous les prêts du système</p>
                    </div>
                    <div>
                        <?php if (a_la_permission('admin') || a_la_permission('gestionnaire')): ?>
                            <a href="nouveau_pret.php" class="btn btn-primary">
                                <i class="fas fa-plus-circle me-2"></i>Nouveau Prêt
                            </a>
                        <?php endif; ?>
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

                <!-- Cartes de statistiques -->
                <div class="row mb-4">
                    <div class="col-xl-3 col-md-6">
                        <div class="card bg-white text-black stat-card mb-4">
                            <div class="card-body">
                                <div class="d-flex justify-content-between">
                                    <div>
                                        <div class="text-xs font-weight-bold text-uppercase mb-1">Total Prêts</div>
                                        <div class="h5 mb-0"><?php echo number_format($total_prets); ?></div>
                                    </div>
                                    <div class="col-auto">
                                        <i class="fas fa-file-invoice-dollar fa-2x"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-xl-3 col-md-6">
                        <div class="card bg-white text-black stat-card mb-4">
                            <div class="card-body">
                                <div class="d-flex justify-content-between">
                                    <div>
                                        <div class="text-xs font-weight-bold text-uppercase mb-1">Prêts Actifs</div>
                                        <div class="h5 mb-0">
                                            <?php 
                                                try {
                                                    $stmt = $pdo->query("SELECT COUNT(*) FROM prets WHERE statut = 'actif'");
                                                    echo number_format($stmt->fetchColumn());
                                                } catch (PDOException $e) {
                                                    echo "0";
                                                }
                                            ?>
                                        </div>
                                    </div>
                                    <div class="col-auto">
                                        <i class="fas fa-check-circle fa-2x"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-xl-3 col-md-6">
                        <div class="card bg-white text-black stat-card mb-4">
                            <div class="card-body">
                                <div class="d-flex justify-content-between">
                                    <div>
                                        <div class="text-xs font-weight-bold text-uppercase mb-1">En Attente</div>
                                        <div class="h5 mb-0">
                                            <?php 
                                                try {
                                                    $stmt = $pdo->query("SELECT COUNT(*) FROM prets WHERE statut = 'en_attente'");
                                                    echo number_format($stmt->fetchColumn());
                                                } catch (PDOException $e) {
                                                    echo "0";
                                                }
                                            ?>
                                        </div>
                                    </div>
                                    <div class="col-auto">
                                        <i class="fas fa-clock fa-2x"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-xl-3 col-md-6">
                        <div class="card bg-white text-black stat-card mb-4">
                            <div class="card-body">
                                <div class="d-flex justify-content-between">
                                    <div>
                                        <div class="text-xs font-weight-bold text-uppercase mb-1">En Retard</div>
                                        <div class="h5 mb-0">
                                            <?php 
                                                try {
                                                    $stmt = $pdo->query("SELECT COUNT(*) FROM prets WHERE statut = 'en_retard'");
                                                    echo number_format($stmt->fetchColumn());
                                                } catch (PDOException $e) {
                                                    echo "0";
                                                }
                                            ?>
                                        </div>
                                    </div>
                                    <div class="col-auto">
                                        <i class="fas fa-exclamation-triangle fa-2x"></i>
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
                            <div class="col-md-4">
                                <label for="recherche" class="form-label">Recherche</label>
                                <input type="text" class="form-control" id="recherche" name="recherche" 
                                       value="<?php echo $recherche; ?>" placeholder="Code prêt, nom membre...">
                            </div>
                            <div class="col-md-3">
                                <label for="statut" class="form-label">Statut</label>
                                <select class="form-select" id="statut" name="statut">
                                    <option value="">Tous les statuts</option>
                                    <option value="en_attente" <?php echo $statut_filter === 'en_attente' ? 'selected' : ''; ?>>En attente</option>
                                    <option value="approuve" <?php echo $statut_filter === 'approuve' ? 'selected' : ''; ?>>Approuvé</option>
                                    <option value="actif" <?php echo $statut_filter === 'actif' ? 'selected' : ''; ?>>Actif</option>
                                    <option value="en_retard" <?php echo $statut_filter === 'en_retard' ? 'selected' : ''; ?>>En retard</option>
                                    <option value="complet" <?php echo $statut_filter === 'complet' ? 'selected' : ''; ?>>Complet</option>
                                    <option value="annule" <?php echo $statut_filter === 'annule' ? 'selected' : ''; ?>>Annulé</option>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label for="type_pret" class="form-label">Type de prêt</label>
                                <select class="form-select" id="type_pret" name="type_pret">
                                    <option value="">Tous les types</option>
                                    <?php foreach ($types_pret as $type): ?>
                                        <option value="<?php echo $type['id']; ?>" 
                                                <?php echo $type_filter == $type['id'] ? 'selected' : ''; ?>>
                                            <?php echo securiser($type['nom_type']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-2 d-flex align-items-end">
                                <button type="submit" class="btn btn-primary w-100">
                                    <i class="fas fa-search me-2"></i>Filtrer
                                </button>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Tableau des prêts -->
                <div class="card shadow">
                    <div class="card-header bg-white">
                        <h6 class="m-0 font-weight-bold text-primary">
                            <i class="fas fa-list me-2"></i>Liste des Prêts
                        </h6>
                    </div>
                    <div class="card-body">
                        <?php if (empty($prets)): ?>
                            <div class="text-center py-5">
                                <i class="fas fa-file-invoice-dollar fa-3x text-muted mb-3"></i>
                                <h5 class="text-muted">Aucun prêt trouvé</h5>
                                <p class="text-muted">Aucun prêt ne correspond à vos critères de recherche.</p>
                                <?php if (a_la_permission('admin') || a_la_permission('gestionnaire')): ?>
                                    <a href="nouveau_pret.php" class="btn btn-primary mt-2">
                                        <i class="fas fa-plus-circle me-2"></i>Créer un nouveau prêt
                                    </a>
                                <?php endif; ?>
                            </div>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-hover table-bordered">
                                    <thead class="table-light">
                                        <tr>
                                            <th>Code Prêt</th>
                                            <th>Membre</th>
                                            <th>Type</th>
                                            <th>Montant</th>
                                            <th>Durée</th>
                                            <th>Mensualité</th>
                                            <th>Statut</th>
                                            <th>Progression</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($prets as $pret): 
                                            $pourcentage_paye = $pret['echeances_total'] > 0 ? 
                                                ($pret['echeances_payees'] / $pret['echeances_total']) * 100 : 0;
                                        ?>
                                            <tr>
                                                <td>
                                                    <strong><?php echo securiser($pret['code_pret']); ?></strong>
                                                    <br>
                                                    <small class="text-muted">
                                                        <?php echo date('d/m/Y', strtotime($pret['date_creation'])); ?>
                                                    </small>
                                                </td>
                                                <td>
                                                    <?php echo securiser($pret['nom'] . ' ' . $pret['prenom']); ?>
                                                    <br>
                                                    <small class="text-muted"><?php echo securiser($pret['code_membre']); ?></small>
                                                </td>
                                                <td><?php echo securiser($pret['nom_type']); ?></td>
                                                <td class="text-end"><?php echo number_format($pret['montant'], 2); ?> FBu</td>
                                                <td class="text-center"><?php echo $pret['duree_mois']; ?> mois</td>
                                                <td class="text-end"><?php echo number_format($pret['mensualite'], 2); ?> FBu</td>
                                                <td>
                                                    <span class="badge bg-<?php 
                                                        switch($pret['statut']) {
                                                            case 'actif': echo 'success'; break;
                                                            case 'en_attente': echo 'warning'; break;
                                                            case 'approuve': echo 'info'; break;
                                                            case 'en_retard': echo 'danger'; break;
                                                            case 'complet': echo 'secondary'; break;
                                                            case 'annule': echo 'dark'; break;
                                                            default: echo 'secondary';
                                                        }
                                                    ?>">
                                                        <?php echo ucfirst(str_replace('_', ' ', $pret['statut'])); ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <div class="d-flex align-items-center">
                                                        <div class="progress flex-grow-1 me-2" style="height: 6px;">
                                                            <div class="progress-bar bg-success" 
                                                                 style="width: <?php echo $pourcentage_paye; ?>%">
                                                            </div>
                                                        </div>
                                                        <small class="text-muted"><?php echo round($pourcentage_paye); ?>%</small>
                                                    </div>
                                                    <small class="text-muted">
                                                        <?php echo $pret['echeances_payees']; ?>/<?php echo $pret['echeances_total']; ?> éch.
                                                    </small>
                                                </td>
                                                <td class="table-actions">
                                                    <!-- Bouton Traiter (visible seulement pour admin et gestionnaire) -->
                                                    <?php if (a_la_permission('admin') || a_la_permission('gestionnaire')): ?>
                                                        <form method="POST" class="d-inline" onsubmit="return confirm('Voulez-vous traiter ce prêt ?');">
                                                            <input type="hidden" name="pret_id" value="<?php echo $pret['id']; ?>">
                                                            <input type="hidden" name="action" value="traiter_pret">
                                                            <button type="submit" class="btn btn-primary btn-sm" title="Traiter le prêt">
                                                                <i class="fas fa-cog"></i> Traiter
                                                            </button>
                                                        </form>
                                                    <?php endif; ?>
                                                    
                                                    <a href="details_pret.php?id=<?php echo $pret['id']; ?>" 
                                                       class="btn btn-info btn-sm" title="Voir détails">
                                                        <i class="fas fa-eye"></i>
                                                    </a>
                                                    
                                                    <?php if (a_la_permission('admin') || a_la_permission('gestionnaire')): ?>
                                                        <a href="modifier_pret.php?id=<?php echo $pret['id']; ?>" 
                                                           class="btn btn-warning btn-sm" title="Modifier">
                                                            <i class="fas fa-edit"></i>
                                                        </a>
                                                    <?php endif; ?>
                                                    
                                                    <!-- Menu déroulant pour changement de statut -->
                                                    <?php if (a_la_permission('admin') || a_la_permission('gestionnaire')): ?>
                                                        <div class="btn-group">
                                                            <button type="button" class="btn btn-secondary btn-sm dropdown-toggle" 
                                                                    data-bs-toggle="dropdown" title="Changer statut">
                                                                <i class="fas fa-exchange-alt"></i>
                                                            </button>
                                                            <ul class="dropdown-menu">
                                                                <li>
                                                                    <form method="POST" class="d-inline">
                                                                        <input type="hidden" name="pret_id" value="<?php echo $pret['id']; ?>">
                                                                        <input type="hidden" name="action" value="changer_statut">
                                                                        <input type="hidden" name="nouveau_statut" value="en_attente">
                                                                        <button type="submit" class="dropdown-item">Marquer en attente</button>
                                                                    </form>
                                                                </li>
                                                                <li>
                                                                    <form method="POST" class="d-inline">
                                                                        <input type="hidden" name="pret_id" value="<?php echo $pret['id']; ?>">
                                                                        <input type="hidden" name="action" value="changer_statut">
                                                                        <input type="hidden" name="nouveau_statut" value="approuve">
                                                                        <button type="submit" class="dropdown-item">Marquer approuvé</button>
                                                                    </form>
                                                                </li>
                                                                <li>
                                                                    <form method="POST" class="d-inline">
                                                                        <input type="hidden" name="pret_id" value="<?php echo $pret['id']; ?>">
                                                                        <input type="hidden" name="action" value="changer_statut">
                                                                        <input type="hidden" name="nouveau_statut" value="actif">
                                                                        <button type="submit" class="dropdown-item">Marquer actif</button>
                                                                    </form>
                                                                </li>
                                                                <li><hr class="dropdown-divider"></li>
                                                                <li>
                                                                    <form method="POST" class="d-inline" onsubmit="return confirm('Êtes-vous sûr de vouloir annuler ce prêt ?');">
                                                                        <input type="hidden" name="pret_id" value="<?php echo $pret['id']; ?>">
                                                                        <input type="hidden" name="action" value="changer_statut">
                                                                        <input type="hidden" name="nouveau_statut" value="annule">
                                                                        <button type="submit" class="dropdown-item text-danger">Annuler</button>
                                                                    </form>
                                                                </li>
                                                            </ul>
                                                        </div>
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
                                            <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page - 1])); ?>">
                                                <i class="fas fa-chevron-left"></i> Précédent
                                            </a>
                                        </li>
                                        
                                        <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                                            <li class="page-item <?php echo $i == $page ? 'active' : ''; ?>">
                                                <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $i])); ?>">
                                                    <?php echo $i; ?>
                                                </a>
                                            </li>
                                        <?php endfor; ?>
                                        
                                        <li class="page-item <?php echo $page >= $total_pages ? 'disabled' : ''; ?>">
                                            <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page + 1])); ?>">
                                                Suivant <i class="fas fa-chevron-right"></i>
                                            </a>
                                        </li>
                                    </ul>
                                </nav>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </main>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Confirmation pour les actions sensibles
        document.addEventListener('DOMContentLoaded', function() {
            const forms = document.querySelectorAll('form[onsubmit]');
            forms.forEach(form => {
                form.onsubmit = function(e) {
                    if (!confirm(this.getAttribute('onsubmit').replace('return confirm(\'', '').replace('\');', ''))) {
                        e.preventDefault();
                    }
                };
            });
        });
    </script>
</body>
</html>