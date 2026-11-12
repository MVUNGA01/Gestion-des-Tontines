<?php
require_once 'config_database.php';

// Définition des constantes si non définies
if (!defined('APP_NAME')) {
    define('APP_NAME', 'Système de Gestion de Prêts');
}

// Fonctions utilitaires manquantes
if (!function_exists('est_connecte')) {
    function est_connecte() {
        return isset($_SESSION['utilisateur_id']) && !empty($_SESSION['utilisateur_id']);
    }
}

if (!function_exists('a_la_permission')) {
    function a_la_permission($permission_requise) {
        if (!isset($_SESSION['utilisateur_role'])) {
            return false;
        }
        
        $role_utilisateur = $_SESSION['utilisateur_role'];
        
        // Hiérarchie des permissions
        $hierarchie = [
            'admin' => ['admin', 'gestionnaire', 'caissier', 'analyste'],
            'gestionnaire' => ['gestionnaire', 'caissier', 'analyste'],
            'analyste' => ['analyste'],
            'caissier' => ['caissier']
        ];
        
        return in_array($permission_requise, $hierarchie[$role_utilisateur] ?? []);
    }
}

if (!function_exists('rediriger')) {
    function rediriger($url) {
        header("Location: $url");
        exit();
    }
}

if (!function_exists('securiser')) {
    function securiser($data) {
        if (is_array($data)) {
            return array_map('securiser', $data);
        }
        return htmlspecialchars(trim($data), ENT_QUOTES, 'UTF-8');
    }
}

// Démarrer la session
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

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
$categorie_filter = isset($_GET['categorie']) ? (int)$_GET['categorie'] : '';

// Initialiser les variables
$membres = [];
$categories = [];
$total_membres = 0;
$total_pages = 0;
$erreur = '';

try {
    // Construire la requête de base avec jointures (CORRIGÉ - suppression de e.montant_max_emprunt)
    $sql = "SELECT m.*, cm.nom_categorie, cm.code_categorie, 
                   e.score_credit, e.statut as statut_emprunteur, e.dette_actuelle,
                   e.cotisation_a_jour, e.mois_cotises_consecutifs,
                   -- Calcul du total des mois cotisés (somme des périodes_mois de toutes les cotisations)
                   (SELECT COALESCE(SUM(periode_mois), 0) 
                    FROM cotisations c 
                    WHERE c.code_membre = m.code_membre AND c.statut = 'valide') as total_mois_cotises
            FROM membres m
            INNER JOIN categories_membres cm ON m.categorie_id = cm.id
            LEFT JOIN emprunteurs e ON m.code_membre = e.code_membre
            WHERE 1=1";
    
    $params = [];
    
    // Appliquer les filtres
    if (!empty($recherche)) {
        $sql .= " AND (m.nom LIKE ? OR m.prenom LIKE ? OR m.code_membre LIKE ? OR m.email LIKE ?)";
        $search_term = "%$recherche%";
        $params = array_merge($params, [$search_term, $search_term, $search_term, $search_term]);
    }
    
    if (!empty($statut_filter)) {
        $sql .= " AND m.statut = ?";
        $params[] = $statut_filter;
    }
    
    if (!empty($categorie_filter)) {
        $sql .= " AND m.categorie_id = ?";
        $params[] = $categorie_filter;
    }
    
    // Compter le total pour la pagination
    $count_sql = "SELECT COUNT(*) as total FROM membres m
                 INNER JOIN categories_membres cm ON m.categorie_id = cm.id
                 LEFT JOIN emprunteurs e ON m.code_membre = e.code_membre
                 WHERE 1=1";
    
    $count_params = [];
    
    if (!empty($recherche)) {
        $count_sql .= " AND (m.nom LIKE ? OR m.prenom LIKE ? OR m.code_membre LIKE ? OR m.email LIKE ?)";
        $count_params = array_merge($count_params, [$search_term, $search_term, $search_term, $search_term]);
    }
    
    if (!empty($statut_filter)) {
        $count_sql .= " AND m.statut = ?";
        $count_params[] = $statut_filter;
    }
    
    if (!empty($categorie_filter)) {
        $count_sql .= " AND m.categorie_id = ?";
        $count_params[] = $categorie_filter;
    }
    
    $stmt_count = $pdo->prepare($count_sql);
    $stmt_count->execute($count_params);
    $total_membres = $stmt_count->fetch()['total'];
    $total_pages = ceil($total_membres / $limit);
    
    // Ajouter le tri et la pagination
    $sql .= " ORDER BY m.date_inscription DESC LIMIT ? OFFSET ?";
    $params[] = $limit;
    $params[] = $offset;
    
    // Exécuter la requête principale
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $membres = $stmt->fetchAll();
    
    // Récupérer les catégories pour le filtre
    $stmt_categories = $pdo->query("SELECT id, nom_categorie FROM categories_membres WHERE statut = 'actif'");
    $categories = $stmt_categories->fetchAll();
    
} catch (PDOException $e) {
    $erreur = "Erreur lors du chargement des membres: " . $e->getMessage();
    error_log("Erreur membres.php: " . $e->getMessage());
}

// Traitement des actions (suppression, changement de statut)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        try {
            switch ($_POST['action']) {
                case 'changer_statut':
                    $code_membre = securiser($_POST['code_membre']);
                    $nouveau_statut = securiser($_POST['nouveau_statut']);
                    
                    $stmt = $pdo->prepare("UPDATE membres SET statut = ? WHERE code_membre = ?");
                    $stmt->execute([$nouveau_statut, $code_membre]);
                    
                    $_SESSION['succes'] = "Statut du membre mis à jour avec succès.";
                    break;
                    
                case 'supprimer':
                    if (a_la_permission('admin')) {
                        $code_membre = securiser($_POST['code_membre']);
                        
                        // Vérifier si le membre a des prêts en cours
                        $stmt_check = $pdo->prepare("SELECT COUNT(*) as total FROM prets WHERE code_membre = ? AND statut IN ('actif', 'en_retard')");
                        $stmt_check->execute([$code_membre]);
                        $prets_actifs = $stmt_check->fetch()['total'];
                        
                        if ($prets_actifs > 0) {
                            $_SESSION['erreur'] = "Impossible de supprimer ce membre car il a des prêts en cours.";
                        } else {
                            $stmt = $pdo->prepare("DELETE FROM membres WHERE code_membre = ?");
                            $stmt->execute([$code_membre]);
                            $_SESSION['succes'] = "Membre supprimé avec succès.";
                        }
                    } else {
                        $_SESSION['erreur'] = "Vous n'avez pas la permission de supprimer des membres.";
                    }
                    break;
            }
            
            // Rediriger pour éviter la resoumission du formulaire
            rediriger('membres.php?' . http_build_query($_GET));
            
        } catch (PDOException $e) {
            $_SESSION['erreur'] = "Erreur lors de l'opération: " . $e->getMessage();
            error_log("Erreur POST membres.php: " . $e->getMessage());
        }
    }
}
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestion des Membres - <?php echo APP_NAME; ?></title>
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
        .badge-cotisation {
            font-size: 0.7em;
        }
        .table-actions {
            white-space: nowrap;
        }
        .avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            object-fit: cover;
        }
        .avatar-placeholder {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background-color: #6c757d;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
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
                                <a class="nav-link active" href="membres.php">
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
                            <i class="fas fa-users me-2"></i>Gestion des Membres
                        </h1>
                        <p class="text-muted mb-0">Liste et gestion de tous les membres du système</p>
                    </div>
                    <div>
                        <a href="nouveau_membre.php" class="btn btn-primary">
                            <i class="fas fa-user-plus me-2"></i>Nouveau Membre
                        </a>
                    </div>
                </div>

                <!-- Messages d'alerte -->
                <?php if (isset($_SESSION['succes'])): ?>
                    <div class="alert alert-success alert-dismissible fade show" role="alert">
                        <?php echo securiser($_SESSION['succes']); unset($_SESSION['succes']); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>
                
                <?php if (isset($_SESSION['erreur'])): ?>
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        <?php echo securiser($_SESSION['erreur']); unset($_SESSION['erreur']); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <?php if (!empty($erreur)): ?>
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        <?php echo securiser($erreur); ?>
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
                                            Total Membres
                                        </div>
                                        <div class="h5 mb-0 font-weight-bold text-gray-800">
                                            <?php echo number_format($total_membres); ?>
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
                        <div class="card stat-card border-left-success shadow h-100 py-2">
                            <div class="card-body">
                                <div class="row no-gutters align-items-center">
                                    <div class="col mr-2">
                                        <div class="text-xs font-weight-bold text-success text-uppercase mb-1">
                                            Membres Actifs
                                        </div>
                                        <div class="h5 mb-0 font-weight-bold text-gray-800">
                                            <?php
                                            try {
                                                $stmt_actifs = $pdo->query("SELECT COUNT(*) as total FROM membres WHERE statut = 'actif'");
                                                echo number_format($stmt_actifs->fetch()['total']);
                                            } catch (PDOException $e) {
                                                echo "0";
                                            }
                                            ?>
                                        </div>
                                    </div>
                                    <div class="col-auto">
                                        <i class="fas fa-user-check fa-2x text-gray-300"></i>
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
                                            Cotisations à Jour
                                        </div>
                                        <div class="h5 mb-0 font-weight-bold text-gray-800">
                                            <?php
                                            try {
                                                $stmt_cotisations = $pdo->query("SELECT COUNT(*) as total FROM emprunteurs WHERE cotisation_a_jour = TRUE");
                                                echo number_format($stmt_cotisations->fetch()['total']);
                                            } catch (PDOException $e) {
                                                echo "0";
                                            }
                                            ?>
                                        </div>
                                    </div>
                                    <div class="col-auto">
                                        <i class="fas fa-coins fa-2x text-gray-300"></i>
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
                                            En Attente
                                        </div>
                                        <div class="h5 mb-0 font-weight-bold text-gray-800">
                                            <?php
                                            try {
                                                $stmt_attente = $pdo->query("SELECT COUNT(*) as total FROM emprunteurs WHERE statut = 'en_attente'");
                                                echo number_format($stmt_attente->fetch()['total']);
                                            } catch (PDOException $e) {
                                                echo "0";
                                            }
                                            ?>
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
                                <input type="text" class="form-control" name="recherche" placeholder="Nom, prénom, code ou email..." value="<?php echo $recherche; ?>">
                            </div>
                            <div class="col-md-3">
                                <select class="form-select" name="statut">
                                    <option value="">Tous les statuts</option>
                                    <option value="actif" <?php echo $statut_filter === 'actif' ? 'selected' : ''; ?>>Actif</option>
                                    <option value="inactif" <?php echo $statut_filter === 'inactif' ? 'selected' : ''; ?>>Inactif</option>
                                    <option value="suspendu" <?php echo $statut_filter === 'suspendu' ? 'selected' : ''; ?>>Suspendu</option>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <select class="form-select" name="categorie">
                                    <option value="">Toutes les catégories</option>
                                    <?php foreach ($categories as $categorie): ?>
                                        <option value="<?php echo securiser($categorie['id']); ?>" <?php echo $categorie_filter == $categorie['id'] ? 'selected' : ''; ?>>
                                            <?php echo securiser($categorie['nom_categorie']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-2">
                                <button type="submit" class="btn btn-primary w-100">
                                    <i class="fas fa-search me-2"></i>Filtrer
                                </button>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Tableau des membres -->
                <div class="card shadow">
                    <div class="card-header bg-light d-flex justify-content-between align-items-center">
                        <h6 class="m-0 font-weight-bold text-primary">
                            <i class="fas fa-list me-2"></i>Liste des Membres
                        </h6>
                        <span class="badge bg-primary"><?php echo number_format($total_membres); ?> membre(s)</span>
                    </div>
                    <div class="card-body">
                        <?php if (!empty($membres)): ?>
                            <div class="table-responsive">
                                <table class="table table-hover">
                                    <thead class="table-light">
                                        <tr>
                                            <th>Membre</th>
                                            <th>Contact</th>
                                            <th>Catégorie</th>
                                            <th>Score</th>
                                            <th>Cotisation</th>
                                            <th>Total Mois Cotisés</th>
                                            <th>Dette</th>
                                            <th>Statut</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($membres as $membre): ?>
                                            <tr>
                                                <td>
                                                    <div class="d-flex align-items-center">
                                                        <?php if (!empty($membre['photo_profil'])): ?>
                                                            <img src="<?php echo securiser($membre['photo_profil']); ?>" alt="Photo profil" class="avatar me-3">
                                                        <?php else: ?>
                                                            <div class="avatar-placeholder me-3">
                                                                <i class="fas fa-user"></i>
                                                            </div>
                                                        <?php endif; ?>
                                                        <div>
                                                            <strong><?php echo securiser($membre['nom'] . ' ' . $membre['prenom']); ?></strong>
                                                            <br>
                                                            <small class="text-muted"><?php echo securiser($membre['code_membre']); ?></small>
                                                        </div>
                                                    </div>
                                                </td>
                                                <td>
                                                    <div>
                                                        <small><?php echo securiser($membre['email']); ?></small>
                                                        <br>
                                                        <small class="text-muted"><?php echo securiser($membre['telephone'] ?? 'Non renseigné'); ?></small>
                                                    </div>
                                                </td>
                                                <td>
                                                    <span class="badge bg-info"><?php echo securiser($membre['nom_categorie']); ?></span>
                                                </td>
                                                <td>
                                                    <?php if ($membre['score_credit']): ?>
                                                        <span class="badge bg-<?php echo $membre['score_credit'] >= 700 ? 'success' : ($membre['score_credit'] >= 500 ? 'warning' : 'danger'); ?>">
                                                            <?php echo securiser($membre['score_credit']); ?>
                                                        </span>
                                                    <?php else: ?>
                                                        <span class="badge bg-secondary">N/A</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php if ($membre['cotisation_a_jour']): ?>
                                                        <span class="badge bg-success badge-cotisation">
                                                            <i class="fas fa-check me-1"></i>À jour
                                                        </span>
                                                    <?php else: ?>
                                                        <span class="badge bg-danger badge-cotisation">
                                                            <i class="fas fa-times me-1"></i>En retard
                                                        </span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <span class="badge bg-info">
                                                        <?php echo $membre['total_mois_cotises'] ?? 0; ?> mois
                                                    </span>
                                                </td>
                                                <td>
                                                    <?php if ($membre['dette_actuelle'] > 0): ?>
                                                        <span class="text-danger fw-bold">
                                                            <?php echo number_format($membre['dette_actuelle'], 0, ',', ' '); ?> FBu
                                                        </span>
                                                    <?php else: ?>
                                                        <span class="text-success">Aucune</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <span class="badge bg-<?php 
                                                        switch($membre['statut']) {
                                                            case 'actif': echo 'success'; break;
                                                            case 'inactif': echo 'secondary'; break;
                                                            case 'suspendu': echo 'danger'; break;
                                                            default: echo 'secondary';
                                                        }
                                                    ?>">
                                                        <?php echo ucfirst(securiser($membre['statut'])); ?>
                                                    </span>
                                                </td>
                                                <td class="table-actions">
                                                    <div class="btn-group">
                                                        <a href="profil_membre.php?code=<?php echo securiser($membre['code_membre']); ?>" class="btn btn-sm btn-outline-primary" title="Voir profil">
                                                            <i class="fas fa-eye"></i>
                                                        </a>
                                                        <a href="modifier_membre.php?code=<?php echo securiser($membre['code_membre']); ?>" class="btn btn-sm btn-outline-warning" title="Modifier">
                                                            <i class="fas fa-edit"></i>
                                                        </a>
                                                        <?php if (a_la_permission('admin')): ?>
                                                            <button type="button" class="btn btn-sm btn-outline-danger" 
                                                                    onclick="confirmerSuppression('<?php echo securiser($membre['code_membre']); ?>', '<?php echo securiser($membre['nom'] . ' ' . $membre['prenom']); ?>')"
                                                                    title="Supprimer">
                                                                <i class="fas fa-trash"></i>
                                                            </button>
                                                        <?php endif; ?>
                                                    </div>
                                                    
                                                    <!-- Menu déroulant pour changement de statut -->
                                                    <div class="dropdown d-inline-block ms-1">
                                                        <button class="btn btn-sm btn-outline-secondary dropdown-toggle" type="button" data-bs-toggle="dropdown">
                                                            <i class="fas fa-cog"></i>
                                                        </button>
                                                        <ul class="dropdown-menu">
                                                            <li><h6 class="dropdown-header">Changer le statut</h6></li>
                                                            <li>
                                                                <form method="POST" class="d-inline">
                                                                    <input type="hidden" name="action" value="changer_statut">
                                                                    <input type="hidden" name="code_membre" value="<?php echo securiser($membre['code_membre']); ?>">
                                                                    <input type="hidden" name="nouveau_statut" value="actif">
                                                                    <button type="submit" class="dropdown-item <?php echo $membre['statut'] === 'actif' ? 'active' : ''; ?>">
                                                                        <i class="fas fa-check me-2"></i>Actif
                                                                    </button>
                                                                </form>
                                                            </li>
                                                            <li>
                                                                <form method="POST" class="d-inline">
                                                                    <input type="hidden" name="action" value="changer_statut">
                                                                    <input type="hidden" name="code_membre" value="<?php echo securiser($membre['code_membre']); ?>">
                                                                    <input type="hidden" name="nouveau_statut" value="inactif">
                                                                    <button type="submit" class="dropdown-item <?php echo $membre['statut'] === 'inactif' ? 'active' : ''; ?>">
                                                                        <i class="fas fa-pause me-2"></i>Inactif
                                                                    </button>
                                                                </form>
                                                            </li>
                                                            <li>
                                                                <form method="POST" class="d-inline">
                                                                    <input type="hidden" name="action" value="changer_statut">
                                                                    <input type="hidden" name="code_membre" value="<?php echo securiser($membre['code_membre']); ?>">
                                                                    <input type="hidden" name="nouveau_statut" value="suspendu">
                                                                    <button type="submit" class="dropdown-item <?php echo $membre['statut'] === 'suspendu' ? 'active' : ''; ?>">
                                                                        <i class="fas fa-ban me-2"></i>Suspendu
                                                                    </button>
                                                                </form>
                                                            </li>
                                                        </ul>
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
                                <i class="fas fa-users fa-4x text-muted mb-3"></i>
                                <h4 class="text-muted">Aucun membre trouvé</h4>
                                <p class="text-muted">Aucun membre ne correspond à vos critères de recherche.</p>
                                <a href="membres.php" class="btn btn-primary">Réinitialiser les filtres</a>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </main>
        </div>
    </div>

    <!-- Formulaire de suppression caché -->
    <form id="formSuppression" method="POST" style="display: none;">
        <input type="hidden" name="action" value="supprimer">
        <input type="hidden" name="code_membre" id="codeMembreSuppression">
    </form>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function confirmerSuppression(codeMembre, nomMembre) {
            if (confirm(`Êtes-vous sûr de vouloir supprimer le membre "${nomMembre}" ? Cette action est irréversible.`)) {
                document.getElementById('codeMembreSuppression').value = codeMembre;
                document.getElementById('formSuppression').submit();
            }
        }
    </script>
</body>
</html>