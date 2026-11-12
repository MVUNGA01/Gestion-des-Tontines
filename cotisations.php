<?php
require_once 'config_database.php';

// Vérifier l'authentification
if (!est_connecte()) {
    rediriger('login.php');
}

// Variables pour la pagination et la recherche
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = 20;
$offset = ($page - 1) * $limit;
$search = isset($_GET['search']) ? securiser($_GET['search']) : '';
$statut_filter = isset($_GET['statut']) ? securiser($_GET['statut']) : '';

// Variables pour les actions
$action = isset($_GET['action']) ? securiser($_GET['action']) : '';
$cotisation_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

// Variables pour les messages
$succes = '';
$erreur = '';
$erreur_ajout = '';

try {
    // Construire la requête de base avec jointures
    $sql = "SELECT 
                c.*,
                CONCAT(m.nom, ' ', m.prenom) AS nom_complet,
                m.telephone,
                cm.nom_categorie,
                cm.cotisation_mensuelle
            FROM cotisations c
            INNER JOIN membres m ON c.code_membre = m.code_membre
            INNER JOIN categories_membres cm ON m.categorie_id = cm.id
            WHERE 1=1";
    
    $params = [];
    
    // Appliquer les filtres
    if (!empty($search)) {
        $sql .= " AND (c.code_membre LIKE ? OR m.nom LIKE ? OR m.prenom LIKE ?)";
        $search_term = "%$search%";
        $params = array_merge($params, [$search_term, $search_term, $search_term]);
    }
    
    if (!empty($statut_filter)) {
        $sql .= " AND c.statut = ?";
        $params[] = $statut_filter;
    }
    
    // Compter le total pour la pagination
    $count_sql = "SELECT COUNT(*) as total FROM ($sql) as count_table";
    $stmt_count = $pdo->prepare($count_sql);
    $stmt_count->execute($params);
    $total_cotisations = $stmt_count->fetch()['total'];
    $total_pages = ceil($total_cotisations / $limit);
    
    // Ajouter le tri et la pagination
    $sql .= " ORDER BY c.date_paiement DESC LIMIT ? OFFSET ?";
    $params[] = $limit;
    $params[] = $offset;
    
    // Exécuter la requête principale
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $cotisations = $stmt->fetchAll();
    
    // Récupérer les statistiques pour l'affichage
    $stmt_stats = $pdo->query("
        SELECT 
            COUNT(*) as total,
            SUM(CASE WHEN statut = 'valide' THEN 1 ELSE 0 END) as valides,
            SUM(CASE WHEN statut = 'expire' THEN 1 ELSE 0 END) as expirees,
            SUM(CASE WHEN statut = 'en_attente' THEN 1 ELSE 0 END) as en_attente,
            SUM(montant) as montant_total
        FROM cotisations
        WHERE date_paiement >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
    ");
    $stats = $stmt_stats->fetch();
    
} catch (PDOException $e) {
    $erreur = "Erreur lors du chargement des cotisations: " . $e->getMessage();
}

// Traitement du formulaire d'ajout de cotisation
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (isset($_POST['ajouter_cotisation'])) {
            $code_membre = securiser($_POST['code_membre']);
            $montant = floatval($_POST['montant']);
            $date_debut = securiser($_POST['date_debut']);
            $date_fin = securiser($_POST['date_fin']);
            $methode_paiement = securiser($_POST['methode_paiement']);
            $notes = securiser($_POST['notes']);
            
            // Vérifier si le membre existe et récupérer sa catégorie
            $stmt_membre = $pdo->prepare("
                SELECT m.id, m.categorie_id, cm.cotisation_mensuelle, cm.id as categorie_id
                FROM membres m 
                INNER JOIN categories_membres cm ON m.categorie_id = cm.id 
                WHERE m.code_membre = ?
            ");
            $stmt_membre->execute([$code_membre]);
            $membre = $stmt_membre->fetch();
            
            if (!$membre) {
                $erreur_ajout = "Membre non trouvé";
            } else {
                // VÉRIFICATION CRITIQUE : Le montant doit correspondre à la cotisation de la catégorie
                $cotisation_attendue = floatval($membre['cotisation_mensuelle']);
                $montant_saisi = floatval($montant);
                
                if ($montant_saisi != $cotisation_attendue) {
                    $erreur_ajout = "Le montant saisi (".number_format($montant_saisi, 0)." FBu) ne correspond pas à la cotisation mensuelle de la catégorie (".number_format($cotisation_attendue, 0)." FBu). Veuillez saisir exactement ".number_format($cotisation_attendue, 0)." FBu.";
                } else {
                    // Calculer la période en mois
                    $date1 = new DateTime($date_debut);
                    $date2 = new DateTime($date_fin);
                    $interval = $date2->diff($date1);
                    $periode_mois = $interval->y * 12 + $interval->m;
                    
                    // Insérer la cotisation
                    $stmt_insert = $pdo->prepare("
                        INSERT INTO cotisations 
                        (code_membre, categorie_id, montant, montant_categorie, date_debut, date_fin, periode_mois, methode_paiement, notes, statut, date_paiement)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'valide', NOW())
                    ");
                    
                    if ($stmt_insert->execute([
                        $code_membre,
                        $membre['categorie_id'],
                        $montant,
                        $cotisation_attendue,
                        $date_debut,
                        $date_fin,
                        $periode_mois,
                        $methode_paiement,
                        $notes
                    ])) {
                        $_SESSION['succes'] = "Cotisation ajoutée avec succès!";
                        rediriger('cotisations.php');
                    } else {
                        $erreur_ajout = "Erreur lors de l'ajout de la cotisation.";
                    }
                }
            }
        }
        
        // Traitement de la modification de cotisation
        if (isset($_POST['modifier_cotisation'])) {
            $cotisation_id = (int)$_POST['cotisation_id'];
            $montant = floatval($_POST['montant']);
            $date_debut = securiser($_POST['date_debut']);
            $date_fin = securiser($_POST['date_fin']);
            $methode_paiement = securiser($_POST['methode_paiement']);
            $notes = securiser($_POST['notes']);
            $statut = securiser($_POST['statut']);
            
            // Pour la modification, récupérer la cotisation existante pour vérification
            $stmt_cotisation = $pdo->prepare("
                SELECT c.*, cm.cotisation_mensuelle 
                FROM cotisations c 
                INNER JOIN membres m ON c.code_membre = m.code_membre 
                INNER JOIN categories_membres cm ON m.categorie_id = cm.id 
                WHERE c.id = ?
            ");
            $stmt_cotisation->execute([$cotisation_id]);
            $cotisation_existante = $stmt_cotisation->fetch();
            
            if ($cotisation_existante) {
                $cotisation_attendue = floatval($cotisation_existante['cotisation_mensuelle']);
                $montant_saisi = floatval($montant);
                
                if ($montant_saisi != $cotisation_attendue) {
                    $erreur_ajout = "Le montant saisi (".number_format($montant_saisi, 0)." FBu) ne correspond pas à la cotisation mensuelle de la catégorie (".number_format($cotisation_attendue, 0)." FBu). Veuillez saisir exactement ".number_format($cotisation_attendue, 0)." FBu.";
                } else {
                    // Calculer la période en mois
                    $date1 = new DateTime($date_debut);
                    $date2 = new DateTime($date_fin);
                    $interval = $date2->diff($date1);
                    $periode_mois = $interval->y * 12 + $interval->m;
                    
                    $stmt_update = $pdo->prepare("
                        UPDATE cotisations 
                        SET montant = ?, date_debut = ?, date_fin = ?, 
                            periode_mois = ?,
                            methode_paiement = ?, 
                            notes = ?, statut = ?
                        WHERE id = ?
                    ");
                    
                    if ($stmt_update->execute([
                        $montant,
                        $date_debut,
                        $date_fin,
                        $periode_mois,
                        $methode_paiement,
                        $notes,
                        $statut,
                        $cotisation_id
                    ])) {
                        $_SESSION['succes'] = "Cotisation modifiée avec succès!";
                        rediriger('cotisations.php');
                    } else {
                        $erreur_ajout = "Erreur lors de la modification de la cotisation.";
                    }
                }
            } else {
                $erreur_ajout = "Cotisation non trouvée";
            }
        }
        
        // Traitement de la validation de cotisation
        if (isset($_POST['valider_cotisation'])) {
            $cotisation_id = (int)$_POST['cotisation_id'];
            
            $stmt_validate = $pdo->prepare("
                UPDATE cotisations 
                SET statut = 'valide' 
                WHERE id = ? AND statut = 'en_attente'
            ");
            
            if ($stmt_validate->execute([$cotisation_id])) {
                $_SESSION['succes'] = "Cotisation validée avec succès!";
                rediriger('cotisations.php');
            } else {
                $erreur_ajout = "Erreur lors de la validation de la cotisation.";
            }
        }
        
    } catch (PDOException $e) {
        $erreur_ajout = "Erreur lors de l'opération: " . $e->getMessage();
    }
}

// Récupérer les détails d'une cotisation pour modification
$cotisation_details = null;
if ($action === 'modifier' && $cotisation_id > 0) {
    try {
        $stmt_details = $pdo->prepare("
            SELECT c.*, CONCAT(m.nom, ' ', m.prenom) AS nom_complet, cm.nom_categorie, cm.cotisation_mensuelle
            FROM cotisations c
            INNER JOIN membres m ON c.code_membre = m.code_membre
            INNER JOIN categories_membres cm ON m.categorie_id = cm.id
            WHERE c.id = ?
        ");
        $stmt_details->execute([$cotisation_id]);
        $cotisation_details = $stmt_details->fetch();
        
        if (!$cotisation_details) {
            $_SESSION['erreur'] = "Cotisation non trouvée";
            rediriger('cotisations.php');
        }
    } catch (PDOException $e) {
        $erreur = "Erreur lors du chargement des détails: " . $e->getMessage();
    }
}

// Récupérer les détails d'une cotisation pour affichage
if ($action === 'voir' && $cotisation_id > 0) {
    try {
        $stmt_details = $pdo->prepare("
            SELECT c.*, CONCAT(m.nom, ' ', m.prenom) AS nom_complet, 
                   m.telephone, m.email, cm.nom_categorie, cm.cotisation_mensuelle
            FROM cotisations c
            INNER JOIN membres m ON c.code_membre = m.code_membre
            INNER JOIN categories_membres cm ON m.categorie_id = cm.id
            WHERE c.id = ?
        ");
        $stmt_details->execute([$cotisation_id]);
        $cotisation_details = $stmt_details->fetch();
        
        if (!$cotisation_details) {
            $_SESSION['erreur'] = "Cotisation non trouvée";
            rediriger('cotisations.php');
        }
    } catch (PDOException $e) {
        $erreur = "Erreur lors du chargement des détails: " . $e->getMessage();
    }
}

// Récupérer les messages de session
if (isset($_SESSION['succes'])) {
    $succes = $_SESSION['succes'];
    unset($_SESSION['succes']);
}
if (isset($_SESSION['erreur'])) {
    $erreur = $_SESSION['erreur'];
    unset($_SESSION['erreur']);
}
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestion des Cotisations - <?php echo APP_NAME; ?></title>
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
        .badge-expire {
            background-color: #e74c3c;
        }
        .badge-valide {
            background-color: #27ae60;
        }
        .badge-attente {
            background-color: #f39c12;
        }
        .badge-annule {
            background-color: #95a5a6;
        }
        .table-actions {
            white-space: nowrap;
        }
        .montant-fixe {
            background-color: #e9ecef;
            font-weight: bold;
            color: #495057;
        }
        .modal-backdrop.show {
            opacity: 0.5;
        }
        .montant-auto {
            background-color: #d1ecf1;
            border: 1px solid #bee5eb;
        }
        .membre-info-card {
            border-left: 4px solid #3498db;
            background-color: #f8f9fa;
            padding: 15px;
            border-radius: 5px;
            margin-bottom: 15px;
        }
        .membre-info-details {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 10px;
        }
        .membre-info-item {
            margin-bottom: 5px;
        }
        .membre-info-label {
            font-weight: bold;
            color: #495057;
        }
        .membre-info-value {
            color: #6c757d;
        }
        .loading-spinner {
            display: none;
        }
    </style>
</head>
<body>
    <div class="container-fluid">
        <div class="row">
            <!-- Sidebar -->
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
                                <a class="nav-link active" href="cotisations.php">
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
                <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
                    <h1 class="h2">
                        <i class="fas fa-coins me-2"></i>Gestion des Cotisations
                    </h1>
                    <div class="btn-toolbar mb-2 mb-md-0">
                        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modalAjouterCotisation">
                            <i class="fas fa-plus me-1"></i>Nouvelle Cotisation
                        </button>
                    </div>
                </div>

                <!-- Messages d'alerte -->
                <?php if ($succes): ?>
                    <div class="alert alert-success alert-dismissible fade show" role="alert">
                        <i class="fas fa-check-circle me-2"></i><?php echo $succes; ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <?php if ($erreur): ?>
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        <i class="fas fa-exclamation-triangle me-2"></i><?php echo $erreur; ?>
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
                                            Cotisations (30 jours)
                                        </div>
                                        <div class="h5 mb-0 font-weight-bold text-gray-800">
                                            <?php echo number_format($stats['total'] ?? 0); ?>
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
                        <div class="card stat-card border-left-success shadow h-100 py-2">
                            <div class="card-body">
                                <div class="row no-gutters align-items-center">
                                    <div class="col mr-2">
                                        <div class="text-xs font-weight-bold text-success text-uppercase mb-1">
                                            Montant Total
                                        </div>
                                        <div class="h5 mb-0 font-weight-bold text-gray-800">
                                            <?php echo number_format($stats['montant_total'] ?? 0, 0); ?> FBu
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
                                            Validées
                                        </div>
                                        <div class="h5 mb-0 font-weight-bold text-gray-800">
                                            <?php echo number_format($stats['valides'] ?? 0); ?>
                                        </div>
                                    </div>
                                    <div class="col-auto">
                                        <i class="fas fa-check-circle fa-2x text-gray-300"></i>
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
                </div>

                <!-- Filtres et recherche -->
                <div class="card shadow mb-4">
                    <div class="card-header bg-light">
                        <h6 class="m-0 font-weight-bold text-primary">
                            <i class="fas fa-filter me-2"></i>Filtres
                        </h6>
                    </div>
                    <div class="card-body">
                        <form method="GET" class="row g-3">
                            <div class="col-md-4">
                                <label for="search" class="form-label">Rechercher</label>
                                <input type="text" class="form-control" id="search" name="search" 
                                       value="<?php echo htmlspecialchars($search); ?>" placeholder="Code membre, nom...">
                            </div>
                            <div class="col-md-4">
                                <label for="statut" class="form-label">Statut</label>
                                <select class="form-select" id="statut" name="statut">
                                    <option value="">Tous les statuts</option>
                                    <option value="valide" <?php echo $statut_filter === 'valide' ? 'selected' : ''; ?>>Validé</option>
                                    <option value="expire" <?php echo $statut_filter === 'expire' ? 'selected' : ''; ?>>Expiré</option>
                                    <option value="en_attente" <?php echo $statut_filter === 'en_attente' ? 'selected' : ''; ?>>En attente</option>
                                    <option value="annule" <?php echo $statut_filter === 'annule' ? 'selected' : ''; ?>>Annulé</option>
                                </select>
                            </div>
                            <div class="col-md-4 d-flex align-items-end">
                                <button type="submit" class="btn btn-primary me-2">
                                    <i class="fas fa-search me-1"></i>Filtrer
                                </button>
                                <a href="cotisations.php" class="btn btn-outline-secondary">
                                    <i class="fas fa-redo me-1"></i>Réinitialiser
                                </a>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Tableau des cotisations -->
                <div class="card shadow">
                    <div class="card-header bg-light">
                        <h6 class="m-0 font-weight-bold text-primary">
                            <i class="fas fa-list me-2"></i>Liste des Cotisations
                        </h6>
                    </div>
                    <div class="card-body">
                        <?php if (!empty($cotisations)): ?>
                            <div class="table-responsive">
                                <table class="table table-striped table-hover">
                                    <thead>
                                        <tr>
                                            <th>Membre</th>
                                            <th>Catégorie</th>
                                            <th>Montant</th>
                                            <th>Période</th>
                                            <th>Méthode</th>
                                            <th>Date Paiement</th>
                                            <th>Statut</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($cotisations as $cotisation): ?>
                                            <tr>
                                                <td>
                                                    <div>
                                                        <strong><?php echo htmlspecialchars($cotisation['nom_complet']); ?></strong>
                                                        <br>
                                                        <small class="text-muted"><?php echo htmlspecialchars($cotisation['code_membre']); ?></small>
                                                        <br>
                                                        <small class="text-muted"><?php echo htmlspecialchars($cotisation['telephone']); ?></small>
                                                    </div>
                                                </td>
                                                <td><?php echo htmlspecialchars($cotisation['nom_categorie']); ?></td>
                                                <td>
                                                    <strong><?php echo number_format($cotisation['montant'], 0); ?> FBu</strong>
                                                    <?php if ($cotisation['montant'] != $cotisation['cotisation_mensuelle']): ?>
                                                        <br>
                                                        <small class="text-warning">
                                                            <i class="fas fa-exclamation-triangle"></i> 
                                                            Écart: <?php echo number_format($cotisation['montant'] - $cotisation['cotisation_mensuelle'], 0); ?> FBu
                                                        </small>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php echo date('d/m/Y', strtotime($cotisation['date_debut'])); ?> 
                                                    - 
                                                    <?php echo date('d/m/Y', strtotime($cotisation['date_fin'])); ?>
                                                    <br>
                                                    <small class="text-muted">(<?php echo $cotisation['periode_mois']; ?> mois)</small>
                                                </td>
                                                <td>
                                                    <?php 
                                                        $methodes = [
                                                            'espece' => 'Espèce',
                                                            'mobile_money' => 'Mobile Money',
                                                            'virement' => 'Virement',
                                                            'carte' => 'Carte',
                                                            'cheque' => 'Chèque'
                                                        ];
                                                        echo $methodes[$cotisation['methode_paiement']] ?? $cotisation['methode_paiement'];
                                                    ?>
                                                </td>
                                                <td><?php echo date('d/m/Y H:i', strtotime($cotisation['date_paiement'])); ?></td>
                                                <td>
                                                    <?php
                                                        $badge_class = [
                                                            'valide' => 'badge-valide',
                                                            'expire' => 'badge-expire',
                                                            'en_attente' => 'badge-attente',
                                                            'annule' => 'badge-annule'
                                                        ];
                                                        $statut_text = [
                                                            'valide' => 'Validé',
                                                            'expire' => 'Expiré',
                                                            'en_attente' => 'En attente',
                                                            'annule' => 'Annulé'
                                                        ];
                                                    ?>
                                                    <span class="badge <?php echo $badge_class[$cotisation['statut']] ?? 'badge-secondary'; ?>">
                                                        <?php echo $statut_text[$cotisation['statut']] ?? $cotisation['statut']; ?>
                                                    </span>
                                                </td>
                                                <td class="table-actions">
                                                    <a href="?action=voir&id=<?php echo $cotisation['id']; ?>" 
                                                       class="btn btn-sm btn-outline-primary" 
                                                       data-bs-toggle="tooltip" 
                                                       title="Voir les détails">
                                                        <i class="fas fa-eye"></i>
                                                    </a>
                                                    <?php if (a_la_permission('admin') || a_la_permission('gestionnaire')): ?>
                                                        <a href="?action=modifier&id=<?php echo $cotisation['id']; ?>" 
                                                           class="btn btn-sm btn-outline-warning"
                                                           data-bs-toggle="tooltip"
                                                           title="Modifier">
                                                            <i class="fas fa-edit"></i>
                                                        </a>
                                                        <?php if ($cotisation['statut'] == 'en_attente'): ?>
                                                            <form method="POST" style="display: inline;" onsubmit="return confirm('Êtes-vous sûr de vouloir valider cette cotisation?')">
                                                                <input type="hidden" name="cotisation_id" value="<?php echo $cotisation['id']; ?>">
                                                                <button type="submit" name="valider_cotisation" class="btn btn-sm btn-outline-success"
                                                                        data-bs-toggle="tooltip"
                                                                        title="Valider">
                                                                    <i class="fas fa-check"></i>
                                                                </button>
                                                            </form>
                                                        <?php endif; ?>
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
                                            <a class="page-link" href="?page=<?php echo $page - 1; ?>&search=<?php echo urlencode($search); ?>&statut=<?php echo urlencode($statut_filter); ?>">
                                                Précédent
                                            </a>
                                        </li>
                                        
                                        <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                                            <li class="page-item <?php echo $i == $page ? 'active' : ''; ?>">
                                                <a class="page-link" href="?page=<?php echo $i; ?>&search=<?php echo urlencode($search); ?>&statut=<?php echo urlencode($statut_filter); ?>">
                                                    <?php echo $i; ?>
                                                </a>
                                            </li>
                                        <?php endfor; ?>
                                        
                                        <li class="page-item <?php echo $page >= $total_pages ? 'disabled' : ''; ?>">
                                            <a class="page-link" href="?page=<?php echo $page + 1; ?>&search=<?php echo urlencode($search); ?>&statut=<?php echo urlencode($statut_filter); ?>">
                                                Suivant
                                            </a>
                                        </li>
                                    </ul>
                                </nav>
                            <?php endif; ?>

                        <?php else: ?>
                            <div class="text-center py-4">
                                <i class="fas fa-coins fa-3x text-muted mb-3"></i>
                                <h5 class="text-muted">Aucune cotisation trouvée</h5>
                                <p class="text-muted">Utilisez les filtres pour affiner votre recherche ou ajoutez une nouvelle cotisation.</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </main>
        </div>
    </div>

    <!-- Modal Ajouter Cotisation -->
    <div class="modal fade" id="modalAjouterCotisation" tabindex="-1" aria-labelledby="modalAjouterCotisationLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <form method="POST" id="formAjouterCotisation">
                    <div class="modal-header bg-primary text-white">
                        <h5 class="modal-title" id="modalAjouterCotisationLabel">
                            <i class="fas fa-plus-circle me-2"></i>Nouvelle Cotisation
                        </h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <?php if ($erreur_ajout): ?>
                            <div class="alert alert-danger">
                                <i class="fas fa-exclamation-triangle me-2"></i><?php echo $erreur_ajout; ?>
                            </div>
                        <?php endif; ?>

                        <div class="row g-3">
                            <div class="col-md-6">
                                <label for="code_membre" class="form-label">Code Membre *</label>
                                <input type="text" class="form-control" id="code_membre" name="code_membre" required
                                       placeholder="Entrez le code du membre">
                                <div class="form-text">Entrez le code unique du membre</div>
                            </div>
                            
                            <div class="col-md-6">
                                <label for="montant" class="form-label">Montant de la cotisation *</label>
                                <input type="number" class="form-control montant-fixe" id="montant" name="montant" 
                                       step="100" required readonly>
                                <div class="form-text">Montant automatique basé sur la catégorie</div>
                            </div>

                            <div class="col-md-6">
                                <label for="date_debut" class="form-label">Date de début *</label>
                                <input type="date" class="form-control" id="date_debut" name="date_debut" required>
                            </div>

                            <div class="col-md-6">
                                <label for="date_fin" class="form-label">Date de fin *</label>
                                <input type="date" class="form-control" id="date_fin" name="date_fin" required>
                            </div>

                            <div class="col-md-6">
                                <label for="methode_paiement" class="form-label">Méthode de paiement *</label>
                                <select class="form-select" id="methode_paiement" name="methode_paiement" required>
                                    <option value="">Choisir une méthode</option>
                                    <option value="espece">Espèce</option>
                                    
                                    <option value="virement">Virement bancaire</option>
                                    
                                    <option value="cheque">Chèque</option>
                                </select>
                            </div>

                            <div class="col-12">
                                <label for="notes" class="form-label">Notes</label>
                                <textarea class="form-control" id="notes" name="notes" rows="3" 
                                          placeholder="Notes optionnelles sur la cotisation..."></textarea>
                            </div>

                            <!-- Informations du membre (sera rempli dynamiquement) -->
                            <div class="col-12" id="membre-info-container" style="display: none;">
                                <div class="membre-info-card">
                                    <h6 class="mb-3"><i class="fas fa-user me-2"></i>Informations du membre</h6>
                                    <div class="membre-info-details" id="membre-info-details">
                                        <!-- Les informations seront injectées ici par JavaScript -->
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                            <i class="fas fa-times me-1"></i>Annuler
                        </button>
                        <button type="submit" name="ajouter_cotisation" class="btn btn-primary">
                            <i class="fas fa-save me-1"></i>Enregistrer la cotisation
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Modal Voir Cotisation -->
    <?php if ($action === 'voir' && $cotisation_details): ?>
        <div class="modal fade show" id="modalVoirCotisation" tabindex="-1" aria-labelledby="modalVoirCotisationLabel" aria-hidden="false" style="display: block; background-color: rgba(0,0,0,0.5);">
            <div class="modal-dialog modal-lg">
                <div class="modal-content">
                    <div class="modal-header bg-info text-white">
                        <h5 class="modal-title" id="modalVoirCotisationLabel">
                            <i class="fas fa-eye me-2"></i>Détails de la Cotisation
                        </h5>
                        <a href="cotisations.php" class="btn-close btn-close-white" aria-label="Close"></a>
                    </div>
                    <div class="modal-body">
                        <div class="row">
                            <div class="col-md-6">
                                <h6>Informations du membre</h6>
                                <table class="table table-sm">
                                    <tr>
                                        <td><strong>Nom complet:</strong></td>
                                        <td><?php echo htmlspecialchars($cotisation_details['nom_complet']); ?></td>
                                    </tr>
                                    <tr>
                                        <td><strong>Code membre:</strong></td>
                                        <td><?php echo htmlspecialchars($cotisation_details['code_membre']); ?></td>
                                    </tr>
                                    <tr>
                                        <td><strong>Téléphone:</strong></td>
                                        <td><?php echo htmlspecialchars($cotisation_details['telephone']); ?></td>
                                    </tr>
                                    <tr>
                                        <td><strong>Catégorie:</strong></td>
                                        <td><?php echo htmlspecialchars($cotisation_details['nom_categorie']); ?></td>
                                    </tr>
                                </table>
                            </div>
                            <div class="col-md-6">
                                <h6>Détails de la cotisation</h6>
                                <table class="table table-sm">
                                    <tr>
                                        <td><strong>Montant:</strong></td>
                                        <td><?php echo number_format($cotisation_details['montant'], 0); ?> FBu</td>
                                    </tr>
                                    <tr>
                                        <td><strong>Période:</strong></td>
                                        <td><?php echo $cotisation_details['periode_mois']; ?> mois</td>
                                    </tr>
                                    <tr>
                                        <td><strong>Date début:</strong></td>
                                        <td><?php echo date('d/m/Y', strtotime($cotisation_details['date_debut'])); ?></td>
                                    </tr>
                                    <tr>
                                        <td><strong>Date fin:</strong></td>
                                        <td><?php echo date('d/m/Y', strtotime($cotisation_details['date_fin'])); ?></td>
                                    </tr>
                                    <tr>
                                        <td><strong>Méthode paiement:</strong></td>
                                        <td>
                                            <?php 
                                                $methodes = [
                                                    'espece' => 'Espèce',
                                                    'mobile_money' => 'Mobile Money',
                                                    'virement' => 'Virement',
                                                    'carte' => 'Carte',
                                                    'cheque' => 'Chèque'
                                                ];
                                                echo $methodes[$cotisation_details['methode_paiement']] ?? $cotisation_details['methode_paiement'];
                                            ?>
                                        </td>
                                    </tr>
                                    <tr>
                                        <td><strong>Statut:</strong></td>
                                        <td>
                                            <?php
                                                $badge_class = [
                                                    'valide' => 'badge-valide',
                                                    'expire' => 'badge-expire',
                                                    'en_attente' => 'badge-attente',
                                                    'annule' => 'badge-annule'
                                                ];
                                                $statut_text = [
                                                    'valide' => 'Validé',
                                                    'expire' => 'Expiré',
                                                    'en_attente' => 'En attente',
                                                    'annule' => 'Annulé'
                                                ];
                                            ?>
                                            <span class="badge <?php echo $badge_class[$cotisation_details['statut']] ?? 'badge-secondary'; ?>">
                                                <?php echo $statut_text[$cotisation_details['statut']] ?? $cotisation_details['statut']; ?>
                                            </span>
                                        </td>
                                    </tr>
                                </table>
                            </div>
                        </div>
                        
                        <?php if (!empty($cotisation_details['notes'])): ?>
                            <div class="mt-3">
                                <h6>Notes</h6>
                                <div class="border rounded p-3 bg-light">
                                    <?php echo nl2br(htmlspecialchars($cotisation_details['notes'])); ?>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                    <div class="modal-footer">
                        <a href="cotisations.php" class="btn btn-secondary">
                            <i class="fas fa-times me-1"></i>Fermer
                        </a>
                        <?php if (a_la_permission('admin') || a_la_permission('gestionnaire')): ?>
                            <a href="?action=modifier&id=<?php echo $cotisation_details['id']; ?>" class="btn btn-warning">
                                <i class="fas fa-edit me-1"></i>Modifier
                            </a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <!-- Modal Modifier Cotisation -->
    <?php if ($action === 'modifier' && $cotisation_details): ?>
        <div class="modal fade show" id="modalModifierCotisation" tabindex="-1" aria-labelledby="modalModifierCotisationLabel" aria-hidden="false" style="display: block; background-color: rgba(0,0,0,0.5);">
            <div class="modal-dialog modal-lg">
                <div class="modal-content">
                    <form method="POST">
                        <input type="hidden" name="cotisation_id" value="<?php echo $cotisation_details['id']; ?>">
                        <div class="modal-header bg-warning text-dark">
                            <h5 class="modal-title" id="modalModifierCotisationLabel">
                                <i class="fas fa-edit me-2"></i>Modifier la Cotisation
                            </h5>
                            <a href="cotisations.php" class="btn-close" aria-label="Close"></a>
                        </div>
                        <div class="modal-body">
                            <?php if ($erreur_ajout): ?>
                                <div class="alert alert-danger">
                                    <i class="fas fa-exclamation-triangle me-2"></i><?php echo $erreur_ajout; ?>
                                </div>
                            <?php endif; ?>

                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label class="form-label">Membre</label>
                                    <input type="text" class="form-control" value="<?php echo htmlspecialchars($cotisation_details['nom_complet']); ?>" readonly>
                                    <div class="form-text">Code: <?php echo htmlspecialchars($cotisation_details['code_membre']); ?></div>
                                </div>
                                
                                <div class="col-md-6">
                                    <label class="form-label">Catégorie</label>
                                    <input type="text" class="form-control" value="<?php echo htmlspecialchars($cotisation_details['nom_categorie']); ?>" readonly>
                                    <div class="form-text">Cotisation: <?php echo number_format($cotisation_details['cotisation_mensuelle'], 0); ?> FBu/mois</div>
                                </div>

                                <div class="col-md-6">
                                    <label for="montant" class="form-label">Montant *</label>
                                    <input type="number" class="form-control montant-fixe" id="montant" name="montant" 
                                           value="<?php echo $cotisation_details['montant']; ?>" step="100" required>
                                    <div class="form-text">Doit correspondre à <?php echo number_format($cotisation_details['cotisation_mensuelle'], 0); ?> FBu</div>
                                </div>

                                <div class="col-md-6">
                                    <label for="statut" class="form-label">Statut *</label>
                                    <select class="form-select" id="statut" name="statut" required>
                                        <option value="valide" <?php echo $cotisation_details['statut'] == 'valide' ? 'selected' : ''; ?>>Validé</option>
                                        <option value="expire" <?php echo $cotisation_details['statut'] == 'expire' ? 'selected' : ''; ?>>Expiré</option>
                                        <option value="en_attente" <?php echo $cotisation_details['statut'] == 'en_attente' ? 'selected' : ''; ?>>En attente</option>
                                        <option value="annule" <?php echo $cotisation_details['statut'] == 'annule' ? 'selected' : ''; ?>>Annulé</option>
                                    </select>
                                </div>

                                <div class="col-md-6">
                                    <label for="date_debut" class="form-label">Date de début *</label>
                                    <input type="date" class="form-control" id="date_debut" name="date_debut" 
                                           value="<?php echo $cotisation_details['date_debut']; ?>" required>
                                </div>

                                <div class="col-md-6">
                                    <label for="date_fin" class="form-label">Date de fin *</label>
                                    <input type="date" class="form-control" id="date_fin" name="date_fin" 
                                           value="<?php echo $cotisation_details['date_fin']; ?>" required>
                                </div>

                                <div class="col-md-6">
                                    <label for="methode_paiement" class="form-label">Méthode de paiement *</label>
                                    <select class="form-select" id="methode_paiement" name="methode_paiement" required>
                                        <option value="espece" <?php echo $cotisation_details['methode_paiement'] == 'espece' ? 'selected' : ''; ?>>Espèce</option>
                                        <option value="mobile_money" <?php echo $cotisation_details['methode_paiement'] == 'mobile_money' ? 'selected' : ''; ?>>Mobile Money</option>
                                        <option value="virement" <?php echo $cotisation_details['methode_paiement'] == 'virement' ? 'selected' : ''; ?>>Virement bancaire</option>
                                        <option value="carte" <?php echo $cotisation_details['methode_paiement'] == 'carte' ? 'selected' : ''; ?>>Carte bancaire</option>
                                        <option value="cheque" <?php echo $cotisation_details['methode_paiement'] == 'cheque' ? 'selected' : ''; ?>>Chèque</option>
                                    </select>
                                </div>

                                <div class="col-12">
                                    <label for="notes" class="form-label">Notes</label>
                                    <textarea class="form-control" id="notes" name="notes" rows="3"><?php echo htmlspecialchars($cotisation_details['notes']); ?></textarea>
                                </div>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <a href="cotisations.php" class="btn btn-secondary">
                                <i class="fas fa-times me-1"></i>Annuler
                            </a>
                            <button type="submit" name="modifier_cotisation" class="btn btn-warning">
                                <i class="fas fa-save me-1"></i>Modifier la cotisation
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Initialiser les tooltips Bootstrap
        var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'))
        var tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
            return new bootstrap.Tooltip(tooltipTriggerEl)
        });

        // Fonction pour récupérer les informations du membre
        document.getElementById('code_membre').addEventListener('blur', function() {
            const codeMembre = this.value.trim();
            const montantInput = document.getElementById('montant');
            const membreInfoContainer = document.getElementById('membre-info-container');
            const membreInfoDetails = document.getElementById('membre-info-details');
            
            if (codeMembre) {
                // Afficher le spinner de chargement
                montantInput.value = 'Chargement...';
                membreInfoContainer.style.display = 'none';
                
                // Faire une requête AJAX pour récupérer les informations du membre
                fetch('api_get_membre_info.php?code_membre=' + encodeURIComponent(codeMembre))
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            montantInput.value = data.cotisation_mensuelle;
                            montantInput.classList.add('montant-auto');
                            
                            // Afficher les informations du membre
                            membreInfoDetails.innerHTML = `
                                <div class="membre-info-item">
                                    <span class="membre-info-label">Nom complet:</span>
                                    <span class="membre-info-value">${data.nom_complet}</span>
                                </div>
                                <div class="membre-info-item">
                                    <span class="membre-info-label">Catégorie:</span>
                                    <span class="membre-info-value">${data.nom_categorie}</span>
                                </div>
                                <div class="membre-info-item">
                                    <span class="membre-info-label">Téléphone:</span>
                                    <span class="membre-info-value">${data.telephone || 'Non renseigné'}</span>
                                </div>
                                <div class="membre-info-item">
                                    <span class="membre-info-label">Email:</span>
                                    <span class="membre-info-value">${data.email || 'Non renseigné'}</span>
                                </div>
                            `;
                            membreInfoContainer.style.display = 'block';
                        } else {
                            montantInput.value = '';
                            montantInput.classList.remove('montant-auto');
                            membreInfoContainer.style.display = 'none';
                            alert('Membre non trouvé ou inactif');
                        }
                    })
                    .catch(error => {
                        console.error('Erreur:', error);
                        montantInput.value = '';
                        montantInput.classList.remove('montant-auto');
                        membreInfoContainer.style.display = 'none';
                        alert('Erreur lors de la récupération des informations du membre');
                    });
            } else {
                montantInput.value = '';
                montantInput.classList.remove('montant-auto');
                membreInfoContainer.style.display = 'none';
            }
        });

        // Calcul automatique de la date de fin basée sur la date de début et la période
        document.getElementById('date_debut').addEventListener('change', function() {
            const dateDebut = new Date(this.value);
            const dateFinInput = document.getElementById('date_fin');
            
            if (this.value && !dateFinInput.value) {
                // Par défaut, mettre fin à 1 mois après
                const dateFin = new Date(dateDebut);
                dateFin.setMonth(dateFin.getMonth() + 1);
                dateFinInput.valueAsDate = dateFin;
            }
        });

        // Validation du formulaire d'ajout
        document.getElementById('formAjouterCotisation').addEventListener('submit', function(e) {
            const montant = document.getElementById('montant').value;
            const codeMembre = document.getElementById('code_membre').value;
            
            if (!codeMembre) {
                e.preventDefault();
                alert('Veuillez saisir un code membre');
                return;
            }
            
            if (!montant || montant === 'Chargement...') {
                e.preventDefault();
                alert('Veuillez attendre que le montant soit chargé');
                return;
            }
        });

        // Fermer les modaux avec la touche Échap
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                if (document.querySelector('.modal.show')) {
                    window.location.href = 'cotisations.php';
                }
            }
        });

        // Fermer les modaux en cliquant à l'extérieur
        document.addEventListener('click', function(e) {
            if (e.target.classList.contains('modal') || e.target.classList.contains('modal-backdrop')) {
                window.location.href = 'cotisations.php';
            }
        });
    </script>
</body>
</html>