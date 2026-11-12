<?php
// detail_membre.php
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

// Vérifier si un code membre est fourni
if (!isset($_GET['code']) || empty($_GET['code'])) {
    $_SESSION['erreur'] = "Aucun membre spécifié.";
    rediriger('membres.php');
}

$code_membre = securiser($_GET['code']);

// Initialiser les variables
$membre = null;
$emprunteur = null;
$categorie = null;
$prets = [];
$cotisations = [];
$paiements_recent = [];
$documents = [];
$statistiques = [];
$erreur = '';

try {
    // Récupérer les informations du membre avec jointures
    $sql_membre = "SELECT m.*, cm.nom_categorie, cm.code_categorie, cm.cotisation_mensuelle,
                          cm.duree_min_pret_mois, cm.duree_max_pret_mois, cm.taux_interet_categorie,
                          cm.duree_min_cotisation_mois, cm.couleur_theme, cm.icone
                   FROM membres m
                   INNER JOIN categories_membres cm ON m.categorie_id = cm.id
                   WHERE m.code_membre = ?";
    
    $stmt_membre = $pdo->prepare($sql_membre);
    $stmt_membre->execute([$code_membre]);
    $membre = $stmt_membre->fetch();
    
    if (!$membre) {
        throw new Exception("Membre non trouvé.");
    }
    
    // Récupérer les informations de l'emprunteur
    $sql_emprunteur = "SELECT * FROM emprunteurs WHERE code_membre = ?";
    $stmt_emprunteur = $pdo->prepare($sql_emprunteur);
    $stmt_emprunteur->execute([$code_membre]);
    $emprunteur = $stmt_emprunteur->fetch();
    
    // Récupérer les prêts du membre
    $sql_prets = "SELECT p.*, pp.nom_pret, pp.code_plan, tp.nom_type as type_pret,
                         DATEDIFF(p.date_fin, CURDATE()) as jours_restants,
                         CASE 
                             WHEN p.statut = 'actif' AND DATEDIFF(p.date_fin, CURDATE()) < 0 THEN 'en_retard'
                             ELSE p.statut
                         END as statut_etendu
                  FROM prets p
                  LEFT JOIN plans_pret pp ON p.plan_pret_id = pp.id
                  LEFT JOIN types_pret tp ON pp.type_pret_id = tp.id
                  WHERE p.code_membre = ?
                  ORDER BY p.date_creation DESC";
    
    $stmt_prets = $pdo->prepare($sql_prets);
    $stmt_prets->execute([$code_membre]);
    $prets = $stmt_prets->fetchAll();
    
    // Récupérer les cotisations du membre
    $sql_cotisations = "SELECT c.*, cm.cotisation_mensuelle as cotisation_theorique
                       FROM cotisations c
                       LEFT JOIN categories_membres cm ON c.categorie_id = cm.id
                       WHERE c.code_membre = ?
                       ORDER BY c.date_fin DESC, c.date_paiement DESC";
    
    $stmt_cotisations = $pdo->prepare($sql_cotisations);
    $stmt_cotisations->execute([$code_membre]);
    $cotisations = $stmt_cotisations->fetchAll();
    
    // Récupérer les paiements récents (30 derniers jours)
    $sql_paiements = "SELECT pmt.*, p.code_pret, p.montant as montant_pret,
                             DATE_FORMAT(pmt.date_paiement, '%d/%m/%Y %H:%i') as date_formatee
                      FROM paiements pmt
                      LEFT JOIN prets p ON pmt.pret_id = p.id
                      WHERE p.code_membre = ?
                      AND pmt.date_paiement >= DATE_SUB(NOW(), INTERVAL 30 DAY)
                      ORDER BY pmt.date_paiement DESC
                      LIMIT 10";
    
    $stmt_paiements = $pdo->prepare($sql_paiements);
    $stmt_paiements->execute([$code_membre]);
    $paiements_recent = $stmt_paiements->fetchAll();
    
    // Récupérer les documents du membre
    $sql_documents = "SELECT d.*, u.nom_complet as approbateur_nom
                      FROM documents_membres d
                      LEFT JOIN utilisateurs u ON d.approuve_par = u.id
                      WHERE d.code_membre = ?
                      ORDER BY d.date_upload DESC";
    
    $stmt_documents = $pdo->prepare($sql_documents);
    $stmt_documents->execute([$code_membre]);
    $documents = $stmt_documents->fetchAll();
    
    // Calculer les statistiques
    $total_prets = count($prets);
    $prets_actifs = array_filter($prets, function($pret) {
        return in_array($pret['statut'], ['actif', 'en_retard']);
    });
    $prets_termines = array_filter($prets, function($pret) {
        return in_array($pret['statut'], ['complet', 'annule']);
    });
    
    $dette_totale = 0;
    foreach ($prets_actifs as $pret) {
        $dette_totale += $pret['montant_restant'];
    }
    
    // Calculer l'âge du membre
    $age = 0;
    if (!empty($membre['date_naissance'])) {
        $date_naissance = new DateTime($membre['date_naissance']);
        $aujourdhui = new DateTime();
        $difference = $aujourdhui->diff($date_naissance);
        $age = $difference->y;
    }
    
    // Calculer l'ancienneté
    $date_inscription = new DateTime($membre['date_inscription']);
    $anciennete = $aujourdhui->diff($date_inscription);
    $mois_anciennete = ($anciennete->y * 12) + $anciennete->m;
    
    // Vérifier si la cotisation est à jour
    $cotisation_a_jour = false;
    $date_expiration_cotisation = null;
    
    if (!empty($cotisations)) {
        $derniere_cotisation = $cotisations[0];
        if ($derniere_cotisation['statut'] == 'valide') {
            $date_expiration = new DateTime($derniere_cotisation['date_fin']);
            $cotisation_a_jour = ($date_expiration >= new DateTime());
            $date_expiration_cotisation = $derniere_cotisation['date_fin'];
        }
    }
    
    // Statistiques des cotisations
    $total_cotisations = array_sum(array_column($cotisations, 'montant'));
    $cotisations_valides = array_filter($cotisations, function($cot) {
        return $cot['statut'] == 'valide';
    });
    $mois_cotises = array_sum(array_column($cotisations_valides, 'periode_mois'));
    
} catch (Exception $e) {
    $erreur = "Erreur lors du chargement des données: " . $e->getMessage();
    error_log("Erreur detail_membre.php: " . $e->getMessage());
}

// Traitement des actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    try {
        switch ($_POST['action']) {
            case 'changer_statut_emprunteur':
                if (a_la_permission('admin') || a_la_permission('gestionnaire')) {
                    $nouveau_statut = securiser($_POST['nouveau_statut']);
                    
                    $stmt = $pdo->prepare("UPDATE emprunteurs SET statut = ? WHERE code_membre = ?");
                    $stmt->execute([$nouveau_statut, $code_membre]);
                    
                    $_SESSION['succes'] = "Statut de l'emprunteur mis à jour avec succès.";
                    rediriger('detail_membre.php?code=' . $code_membre);
                }
                break;
                
            case 'mettre_a_jour_score':
                if (a_la_permission('admin')) {
                    $nouveau_score = (int)$_POST['nouveau_score'];
                    $raison = securiser($_POST['raison_score']);
                    $ancien_score = $emprunteur['score_credit'] ?? 500;
                    
                    $pdo->beginTransaction();
                    
                    // Mettre à jour le score
                    $stmt_update = $pdo->prepare("UPDATE emprunteurs SET score_credit = ? WHERE code_membre = ?");
                    $stmt_update->execute([$nouveau_score, $code_membre]);
                    
                    // Enregistrer dans l'historique
                    $stmt_historique = $pdo->prepare("
                        INSERT INTO historique_scores (code_membre, ancien_score, nouveau_score, type_modification, raison, modifie_par)
                        VALUES (?, ?, ?, 'manuel', ?, ?)
                    ");
                    $stmt_historique->execute([
                        $code_membre,
                        $ancien_score,
                        $nouveau_score,
                        $raison,
                        $_SESSION['nom_complet'] ?? 'Système'
                    ]);
                    
                    $pdo->commit();
                    
                    $_SESSION['succes'] = "Score de crédit mis à jour de $ancien_score à $nouveau_score.";
                    rediriger('detail_membre.php?code=' . $code_membre);
                }
                break;
        }
    } catch (Exception $e) {
        $_SESSION['erreur'] = "Erreur lors de l'opération: " . $e->getMessage();
        error_log("Erreur POST detail_membre.php: " . $e->getMessage());
    }
}
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Profil Membre - <?php echo APP_NAME; ?></title>
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
        .profile-header {
            background: linear-gradient(135deg, <?php echo $membre['couleur_theme'] ?? '#667eea'; ?> 0%, #764ba2 100%);
            color: white;
            padding: 2rem;
            border-radius: 10px;
            margin-bottom: 2rem;
        }
        .avatar-large {
            width: 100px;
            height: 100px;
            border-radius: 50%;
            object-fit: cover;
            border: 4px solid white;
        }
        .avatar-placeholder-large {
            width: 100px;
            height: 100px;
            border-radius: 50%;
            background-color: #6c757d;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 2.5rem;
            border: 4px solid white;
        }
        .stat-card {
            border-radius: 10px;
            transition: transform 0.3s;
            height: 100%;
        }
        .stat-card:hover {
            transform: translateY(-5px);
        }
        .badge-score {
            font-size: 0.9rem;
            padding: 5px 10px;
        }
        .timeline {
            position: relative;
            padding-left: 30px;
        }
        .timeline:before {
            content: '';
            position: absolute;
            left: 15px;
            top: 0;
            bottom: 0;
            width: 2px;
            background-color: #dee2e6;
        }
        .timeline-item {
            position: relative;
            margin-bottom: 20px;
        }
        .timeline-item:before {
            content: '';
            position: absolute;
            left: -26px;
            top: 5px;
            width: 12px;
            height: 12px;
            border-radius: 50%;
            background-color: #007bff;
        }
        .timeline-success:before {
            background-color: #28a745;
        }
        .timeline-warning:before {
            background-color: #ffc107;
        }
        .timeline-danger:before {
            background-color: #dc3545;
        }
        .card-header-action {
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .action-buttons {
            display: flex;
            gap: 10px;
        }
        .info-row {
            padding: 8px 0;
            border-bottom: 1px solid #f0f0f0;
        }
        .info-row:last-child {
            border-bottom: none;
        }
        .info-label {
            font-weight: 600;
            color: #6c757d;
        }
        .info-value {
            color: #212529;
        }
        .document-icon {
            width: 40px;
            height: 40px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 5px;
            margin-right: 10px;
        }
        .document-icon.pdf {
            background-color: #f8d7da;
            color: #721c24;
        }
        .document-icon.image {
            background-color: #d1ecf1;
            color: #0c5460;
        }
        .document-icon.word {
            background-color: #d4edda;
            color: #155724;
        }
        .document-icon.other {
            background-color: #e2e3e5;
            color: #383d41;
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
                <?php if (!empty($erreur)): ?>
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        <?php echo securiser($erreur); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

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

                <?php if ($membre): ?>
                    <!-- En-tête du profil -->
                    <div class="profile-header">
                        <div class="row align-items-center">
                            <div class="col-auto">
                                <?php if (!empty($membre['photo_profil'])): ?>
                                    <img src="<?php echo securiser($membre['photo_profil']); ?>" alt="Photo profil" class="avatar-large">
                                <?php else: ?>
                                    <div class="avatar-placeholder-large">
                                        <i class="fas fa-user"></i>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <div class="col">
                                <h1 class="h3 mb-1"><?php echo securiser($membre['nom'] . ' ' . $membre['prenom']); ?></h1>
                                <p class="mb-2">
                                    <span class="badge bg-light text-dark me-2"><?php echo securiser($membre['code_membre']); ?></span>
                                    <span class="badge bg-info"><?php echo securiser($membre['nom_categorie']); ?></span>
                                </p>
                                <div class="d-flex align-items-center">
                                    <span class="me-3"><i class="fas fa-envelope me-1"></i> <?php echo securiser($membre['email']); ?></span>
                                    <span class="me-3"><i class="fas fa-phone me-1"></i> <?php echo securiser($membre['telephone'] ?? 'Non renseigné'); ?></span>
                                    <?php if ($age > 0): ?>
                                        <span><i class="fas fa-birthday-cake me-1"></i> <?php echo $age; ?> ans</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="col-auto action-buttons">
                                <a href="modifier_membre.php?code=<?php echo securiser($membre['code_membre']); ?>" class="btn btn-light">
                                    <i class="fas fa-edit me-2"></i>Modifier
                                </a>
                                <a href="nouveau_pret.php?membre=<?php echo securiser($membre['code_membre']); ?>" class="btn btn-primary">
                                    <i class="fas fa-plus me-2"></i>Nouveau Prêt
                                </a>
                            </div>
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
                                                Score de Crédit
                                            </div>
                                            <div class="h5 mb-0 font-weight-bold text-gray-800">
                                                <?php echo $emprunteur['score_credit'] ?? 500; ?>
                                            </div>
                                            <div class="mt-2">
                                                <?php if ($emprunteur): ?>
                                                    <span class="badge bg-<?php 
                                                        $score = $emprunteur['score_credit'];
                                                        echo $score >= 700 ? 'success' : ($score >= 500 ? 'warning' : 'danger');
                                                    ?> badge-score">
                                                        <?php 
                                                        if ($score >= 700) echo 'Excellent';
                                                        elseif ($score >= 500) echo 'Moyen';
                                                        else echo 'Faible';
                                                        ?>
                                                    </span>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                        <div class="col-auto">
                                            <i class="fas fa-chart-line fa-2x text-gray-300"></i>
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
                                                Dette Actuelle
                                            </div>
                                            <div class="h5 mb-0 font-weight-bold text-gray-800">
                                                <?php echo number_format($dette_totale, 0, ',', ' '); ?> FBu
                                            </div>
                                            <div class="mt-2">
                                                <span class="text-xs text-<?php echo $dette_totale > 0 ? 'danger' : 'success'; ?>">
                                                    <i class="fas fa-<?php echo $dette_totale > 0 ? 'exclamation-triangle' : 'check-circle'; ?> me-1"></i>
                                                    <?php echo $dette_totale > 0 ? 'Dette en cours' : 'Aucune dette'; ?>
                                                </span>
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
                                                Prêts Actifs
                                            </div>
                                            <div class="h5 mb-0 font-weight-bold text-gray-800">
                                                <?php echo count($prets_actifs); ?>
                                            </div>
                                            <div class="mt-2">
                                                <span class="text-xs">
                                                    <?php echo $total_prets; ?> prêts au total
                                                </span>
                                            </div>
                                        </div>
                                        <div class="col-auto">
                                            <i class="fas fa-file-invoice-dollar fa-2x text-gray-300"></i>
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
                                                Cotisations
                                            </div>
                                            <div class="h5 mb-0 font-weight-bold text-gray-800">
                                                <?php echo number_format($total_cotisations, 0, ',', ' '); ?> FBu
                                            </div>
                                            <div class="mt-2">
                                                <span class="text-xs text-<?php echo $cotisation_a_jour ? 'success' : 'danger'; ?>">
                                                    <i class="fas fa-<?php echo $cotisation_a_jour ? 'check-circle' : 'times-circle'; ?> me-1"></i>
                                                    <?php echo $cotisation_a_jour ? 'À jour' : 'En retard'; ?>
                                                </span>
                                            </div>
                                        </div>
                                        <div class="col-auto">
                                            <i class="fas fa-coins fa-2x text-gray-300"></i>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="row">
                        <!-- Colonne gauche : Informations détaillées -->
                        <div class="col-lg-4">
                            <!-- Informations personnelles -->
                            <div class="card shadow mb-4">
                                <div class="card-header card-header-action">
                                    <h6 class="m-0 font-weight-bold text-primary">
                                        <i class="fas fa-user-circle me-2"></i>Informations Personnelles
                                    </h6>
                                </div>
                                <div class="card-body">
                                    <div class="info-row">
                                        <div class="info-label">Nom complet</div>
                                        <div class="info-value"><?php echo securiser($membre['nom'] . ' ' . $membre['prenom']); ?></div>
                                    </div>
                                    <div class="info-row">
                                        <div class="info-label">Date de naissance</div>
                                        <div class="info-value">
                                            <?php 
                                            if (!empty($membre['date_naissance'])) {
                                                echo date('d/m/Y', strtotime($membre['date_naissance'])) . " ($age ans)";
                                            } else {
                                                echo 'Non renseignée';
                                            }
                                            ?>
                                        </div>
                                    </div>
                                    <div class="info-row">
                                        <div class="info-label">Lieu de naissance</div>
                                        <div class="info-value"><?php echo securiser($membre['lieu_naissance'] ?: 'Non renseigné'); ?></div>
                                    </div>
                                    <div class="info-row">
                                        <div class="info-label">Adresse</div>
                                        <div class="info-value"><?php echo securiser($membre['adresse'] ?: 'Non renseignée'); ?></div>
                                    </div>
                                    <div class="info-row">
                                        <div class="info-label">Date d'inscription</div>
                                        <div class="info-value">
                                            <?php 
                                            echo date('d/m/Y', strtotime($membre['date_inscription'])) . 
                                                 " ($mois_anciennete mois)";
                                            ?>
                                        </div>
                                    </div>
                                    <div class="info-row">
                                        <div class="info-label">Statut du membre</div>
                                        <div class="info-value">
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
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Informations professionnelles -->
                            <div class="card shadow mb-4">
                                <div class="card-header">
                                    <h6 class="m-0 font-weight-bold text-info">
                                        <i class="fas fa-briefcase me-2"></i>Informations Professionnelles
                                    </h6>
                                </div>
                                <div class="card-body">
                                    <div class="info-row">
                                        <div class="info-label">Profession</div>
                                        <div class="info-value"><?php echo securiser($membre['profession'] ?: 'Non renseignée'); ?></div>
                                    </div>
                                    <div class="info-row">
                                        <div class="info-label">Employeur</div>
                                        <div class="info-value"><?php echo securiser($membre['employeur'] ?: 'Non renseigné'); ?></div>
                                    </div>
                                    <div class="info-row">
                                        <div class="info-label">Salaire mensuel</div>
                                        <div class="info-value">
                                            <?php 
                                            if (!empty($membre['salaire_mensuel'])) {
                                                echo number_format($membre['salaire_mensuel'], 0, ',', ' ') . ' FBu';
                                            } else {
                                                echo 'Non renseigné';
                                            }
                                            ?>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Contact d'urgence -->
                            <div class="card shadow mb-4">
                                <div class="card-header">
                                    <h6 class="m-0 font-weight-bold text-warning">
                                        <i class="fas fa-phone-alt me-2"></i>Contact d'Urgence
                                    </h6>
                                </div>
                                <div class="card-body">
                                    <div class="info-row">
                                        <div class="info-label">Nom du contact</div>
                                        <div class="info-value"><?php echo securiser($membre['nom_contact_urgence'] ?: 'Non renseigné'); ?></div>
                                    </div>
                                    <div class="info-row">
                                        <div class="info-label">Téléphone</div>
                                        <div class="info-value"><?php echo securiser($membre['tel_contact_urgence'] ?: 'Non renseigné'); ?></div>
                                    </div>
                                    <div class="info-row">
                                        <div class="info-label">Relation</div>
                                        <div class="info-value"><?php echo securiser($membre['relation_contact_urgence'] ?: 'Non renseignée'); ?></div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Colonne droite : Prêts et activités -->
                        <div class="col-lg-8">
                            <!-- Statut emprunteur et actions -->
                            <div class="card shadow mb-4">
                                <div class="card-header card-header-action">
                                    <h6 class="m-0 font-weight-bold text-success">
                                        <i class="fas fa-user-check me-2"></i>Statut Emprunteur
                                    </h6>
                                    <?php if ($emprunteur && (a_la_permission('admin') || a_la_permission('gestionnaire'))): ?>
                                        <div class="dropdown">
                                            <button class="btn btn-sm btn-outline-secondary dropdown-toggle" type="button" data-bs-toggle="dropdown">
                                                <i class="fas fa-cog me-1"></i>Actions
                                            </button>
                                            <ul class="dropdown-menu">
                                                <li><h6 class="dropdown-header">Modifier le statut</h6></li>
                                                <li>
                                                    <form method="POST" class="d-inline">
                                                        <input type="hidden" name="action" value="changer_statut_emprunteur">
                                                        <input type="hidden" name="code_membre" value="<?php echo securiser($code_membre); ?>">
                                                        <input type="hidden" name="nouveau_statut" value="eligible">
                                                        <button type="submit" class="dropdown-item <?php echo ($emprunteur['statut'] ?? '') === 'eligible' ? 'active' : ''; ?>">
                                                            <i class="fas fa-check-circle me-2 text-success"></i>Éligible
                                                        </button>
                                                    </form>
                                                </li>
                                                <li>
                                                    <form method="POST" class="d-inline">
                                                        <input type="hidden" name="action" value="changer_statut_emprunteur">
                                                        <input type="hidden" name="code_membre" value="<?php echo securiser($code_membre); ?>">
                                                        <input type="hidden" name="nouveau_statut" value="en_attente">
                                                        <button type="submit" class="dropdown-item <?php echo ($emprunteur['statut'] ?? '') === 'en_attente' ? 'active' : ''; ?>">
                                                            <i class="fas fa-clock me-2 text-warning"></i>En attente
                                                        </button>
                                                    </form>
                                                </li>
                                                <li>
                                                    <form method="POST" class="d-inline">
                                                        <input type="hidden" name="action" value="changer_statut_emprunteur">
                                                        <input type="hidden" name="code_membre" value="<?php echo securiser($code_membre); ?>">
                                                        <input type="hidden" name="nouveau_statut" value="suspendu">
                                                        <button type="submit" class="dropdown-item <?php echo ($emprunteur['statut'] ?? '') === 'suspendu' ? 'active' : ''; ?>">
                                                            <i class="fas fa-pause-circle me-2 text-danger"></i>Suspendu
                                                        </button>
                                                    </form>
                                                </li>
                                                <li>
                                                    <form method="POST" class="d-inline">
                                                        <input type="hidden" name="action" value="changer_statut_emprunteur">
                                                        <input type="hidden" name="code_membre" value="<?php echo securiser($code_membre); ?>">
                                                        <input type="hidden" name="nouveau_statut" value="blackliste">
                                                        <button type="submit" class="dropdown-item <?php echo ($emprunteur['statut'] ?? '') === 'blackliste' ? 'active' : ''; ?>">
                                                            <i class="fas fa-ban me-2 text-dark"></i>Blacklisté
                                                        </button>
                                                    </form>
                                                </li>
                                            </ul>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                <div class="card-body">
                                    <div class="row">
                                        <div class="col-md-6">
                                            <div class="info-row">
                                                <div class="info-label">Statut emprunteur</div>
                                                <div class="info-value">
                                                    <?php if ($emprunteur): ?>
                                                        <?php
                                                        $statut_colors = [
                                                            'eligible' => 'success',
                                                            'en_attente' => 'warning',
                                                            'suspendu' => 'danger',
                                                            'blackliste' => 'dark'
                                                        ];
                                                        $statut_color = $statut_colors[$emprunteur['statut']] ?? 'secondary';
                                                        ?>
                                                        <span class="badge bg-<?php echo $statut_color; ?>">
                                                            <?php echo ucfirst(securiser($emprunteur['statut'])); ?>
                                                        </span>
                                                    <?php else: ?>
                                                        <span class="badge bg-secondary">Non défini</span>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                            <div class="info-row">
                                                <div class="info-label">Cotisation à jour</div>
                                                <div class="info-value">
                                                    <?php if ($emprunteur && $emprunteur['cotisation_a_jour']): ?>
                                                        <span class="badge bg-success">Oui</span>
                                                    <?php else: ?>
                                                        <span class="badge bg-danger">Non</span>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                            <div class="info-row">
                                                <div class="info-label">Mois cotisés consécutifs</div>
                                                <div class="info-value"><?php echo $emprunteur['mois_cotises_consecutifs'] ?? 0; ?> mois</div>
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="info-row">
                                                <div class="info-label">Score de crédit</div>
                                                <div class="info-value">
                                                    <?php if ($emprunteur): ?>
                                                        <span class="badge bg-<?php 
                                                            $score = $emprunteur['score_credit'];
                                                            echo $score >= 700 ? 'success' : ($score >= 500 ? 'warning' : 'danger');
                                                        ?>">
                                                            <?php echo $score; ?>
                                                        </span>
                                                        <?php if (a_la_permission('admin')): ?>
                                                            <button type="button" class="btn btn-sm btn-outline-primary ms-2" 
                                                                    data-bs-toggle="modal" data-bs-target="#modalScore">
                                                                <i class="fas fa-edit"></i>
                                                            </button>
                                                        <?php endif; ?>
                                                    <?php else: ?>
                                                        <span class="badge bg-secondary">500 (par défaut)</span>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                            <div class="info-row">
                                                <div class="info-label">Total cotisations payées</div>
                                                <div class="info-value">
                                                    <?php echo number_format($emprunteur['total_cotisations_payees'] ?? 0, 0, ',', ' '); ?> FBu
                                                </div>
                                            </div>
                                            <div class="info-row">
                                                <div class="info-label">Total emprunté</div>
                                                <div class="info-value">
                                                    <?php echo number_format($emprunteur['total_emprunte'] ?? 0, 0, ',', ' '); ?> FBu
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Prêts du membre -->
                            <div class="card shadow mb-4">
                                <div class="card-header card-header-action">
                                    <h6 class="m-0 font-weight-bold text-primary">
                                        <i class="fas fa-file-invoice-dollar me-2"></i>Prêts du Membre
                                    </h6>
                                    <a href="nouveau_pret.php?membre=<?php echo securiser($code_membre); ?>" class="btn btn-sm btn-primary">
                                        <i class="fas fa-plus me-1"></i>Nouveau Prêt
                                    </a>
                                </div>
                                <div class="card-body">
                                    <?php if (!empty($prets)): ?>
                                        <div class="table-responsive">
                                            <table class="table table-sm table-hover">
                                                <thead class="table-light">
                                                    <tr>
                                                        <th>Code</th>
                                                        <th>Type</th>
                                                        <th>Montant</th>
                                                        <th>Reste</th>
                                                        <th>Statut</th>
                                                        <th>Date</th>
                                                        <th>Actions</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php foreach ($prets as $pret): ?>
                                                        <tr>
                                                            <td>
                                                                <small class="text-muted"><?php echo securiser($pret['code_pret']); ?></small>
                                                            </td>
                                                            <td>
                                                                <small><?php echo securiser($pret['type_pret'] ?? 'N/A'); ?></small>
                                                            </td>
                                                            <td>
                                                                <strong><?php echo number_format($pret['montant'], 0, ',', ' '); ?> FBu</strong>
                                                            </td>
                                                            <td>
                                                                <?php if ($pret['montant_restant'] > 0): ?>
                                                                    <span class="text-danger"><?php echo number_format($pret['montant_restant'], 0, ',', ' '); ?> FBu</span>
                                                                <?php else: ?>
                                                                    <span class="text-success">Remboursé</span>
                                                                <?php endif; ?>
                                                            </td>
                                                            <td>
                                                                <?php
                                                                $statut_colors = [
                                                                    'actif' => 'success',
                                                                    'en_retard' => 'danger',
                                                                    'complet' => 'info',
                                                                    'en_attente' => 'warning',
                                                                    'brouillon' => 'secondary',
                                                                    'annule' => 'dark',
                                                                    'default' => 'danger'
                                                                ];
                                                                $statut = $pret['statut_etendu'] ?? $pret['statut'];
                                                                $statut_color = $statut_colors[$statut] ?? 'secondary';
                                                                ?>
                                                                <span class="badge bg-<?php echo $statut_color; ?>">
                                                                    <?php echo ucfirst(securiser($statut)); ?>
                                                                </span>
                                                            </td>
                                                            <td>
                                                                <small><?php echo date('d/m/Y', strtotime($pret['date_creation'])); ?></small>
                                                            </td>
                                                            <td>
                                                                <div class="btn-group btn-group-sm">
                                                                    <a href="detail_pret.php?code=<?php echo securiser($pret['code_pret']); ?>" class="btn btn-outline-primary" title="Voir">
                                                                        <i class="fas fa-eye"></i>
                                                                    </a>
                                                                    <a href="modifier_pret.php?code=<?php echo securiser($pret['code_pret']); ?>" class="btn btn-outline-warning" title="Modifier">
                                                                        <i class="fas fa-edit"></i>
                                                                    </a>
                                                                </div>
                                                            </td>
                                                        </tr>
                                                    <?php endforeach; ?>
                                                </tbody>
                                            </table>
                                        </div>
                                        <div class="text-center mt-3">
                                            <a href="prets.php?membre=<?php echo securiser($code_membre); ?>" class="btn btn-sm btn-outline-primary">
                                                <i class="fas fa-list me-1"></i>Voir tous les prêts
                                            </a>
                                        </div>
                                    <?php else: ?>
                                        <div class="text-center py-4">
                                            <i class="fas fa-file-invoice-dollar fa-3x text-muted mb-3"></i>
                                            <h5 class="text-muted">Aucun prêt</h5>
                                            <p class="text-muted">Ce membre n'a pas encore de prêt.</p>
                                            <a href="nouveau_pret.php?membre=<?php echo securiser($code_membre); ?>" class="btn btn-primary">
                                                <i class="fas fa-plus me-2"></i>Créer le premier prêt
                                            </a>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <!-- Cotisations -->
                            <div class="card shadow">
                                <div class="card-header card-header-action">
                                    <h6 class="m-0 font-weight-bold text-info">
                                        <i class="fas fa-coins me-2"></i>Cotisations
                                    </h6>
                                    <a href="nouvelle_cotisation.php?membre=<?php echo securiser($code_membre); ?>" class="btn btn-sm btn-info">
                                        <i class="fas fa-plus me-1"></i>Nouvelle Cotisation
                                    </a>
                                </div>
                                <div class="card-body">
                                    <?php if (!empty($cotisations)): ?>
                                        <div class="timeline">
                                            <?php foreach ($cotisations as $cotisation): ?>
                                                <div class="timeline-item timeline-<?php 
                                                    switch($cotisation['statut']) {
                                                        case 'valide': echo 'success'; break;
                                                        case 'expire': echo 'warning'; break;
                                                        case 'annule': echo 'danger'; break;
                                                        default: echo 'info';
                                                    }
                                                ?>">
                                                    <div class="card mb-2">
                                                        <div class="card-body p-3">
                                                            <div class="d-flex justify-content-between">
                                                                <div>
                                                                    <h6 class="mb-1">
                                                                        <?php echo number_format($cotisation['montant'], 0, ',', ' '); ?> FBu
                                                                        <?php if ($cotisation['montant'] != $cotisation['cotisation_theorique']): ?>
                                                                            <span class="badge bg-warning ms-2">Écart: <?php echo number_format($cotisation['montant'] - $cotisation['cotisation_theorique'], 0, ',', ' '); ?> FBu</span>
                                                                        <?php endif; ?>
                                                                    </h6>
                                                                    <p class="mb-0 small">
                                                                        <?php echo date('d/m/Y', strtotime($cotisation['date_debut'])); ?> - 
                                                                        <?php echo date('d/m/Y', strtotime($cotisation['date_fin'])); ?>
                                                                        (<?php echo $cotisation['periode_mois']; ?> mois)
                                                                    </p>
                                                                    <p class="mb-0 small text-muted">
                                                                        Payé le <?php echo date('d/m/Y', strtotime($cotisation['date_paiement'])); ?> 
                                                                        par <?php echo securiser($cotisation['methode_paiement'] ?? 'N/A'); ?>
                                                                    </p>
                                                                </div>
                                                                <div class="text-end">
                                                                    <span class="badge bg-<?php 
                                                                        switch($cotisation['statut']) {
                                                                            case 'valide': echo 'success'; break;
                                                                            case 'expire': echo 'warning'; break;
                                                                            case 'annule': echo 'danger'; break;
                                                                            default: echo 'secondary';
                                                                        }
                                                                    ?>">
                                                                        <?php echo ucfirst(securiser($cotisation['statut'])); ?>
                                                                    </span>
                                                                    <?php if (!empty($cotisation['notes'])): ?>
                                                                        <p class="mb-0 small text-muted mt-1">
                                                                            <i class="fas fa-sticky-note me-1"></i><?php echo substr(securiser($cotisation['notes']), 0, 50); ?>...
                                                                        </p>
                                                                    <?php endif; ?>
                                                                </div>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                        <div class="text-center mt-3">
                                            <a href="cotisations.php?membre=<?php echo securiser($code_membre); ?>" class="btn btn-sm btn-outline-info">
                                                <i class="fas fa-list me-1"></i>Voir toutes les cotisations
                                            </a>
                                        </div>
                                    <?php else: ?>
                                        <div class="text-center py-4">
                                            <i class="fas fa-coins fa-3x text-muted mb-3"></i>
                                            <h5 class="text-muted">Aucune cotisation</h5>
                                            <p class="text-muted">Ce membre n'a pas encore de cotisation enregistrée.</p>
                                            <a href="nouvelle_cotisation.php?membre=<?php echo securiser($code_membre); ?>" class="btn btn-info">
                                                <i class="fas fa-plus me-2"></i>Ajouter une cotisation
                                            </a>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="text-center py-5">
                        <i class="fas fa-user-slash fa-4x text-muted mb-3"></i>
                        <h3 class="text-muted">Membre non trouvé</h3>
                        <p class="text-muted">Le membre avec le code "<?php echo securiser($code_membre); ?>" n'existe pas.</p>
                        <a href="membres.php" class="btn btn-primary">Retour à la liste des membres</a>
                    </div>
                <?php endif; ?>
            </main>
        </div>
    </div>

    <!-- Modal pour modifier le score de crédit -->
    <?php if (a_la_permission('admin') && $emprunteur): ?>
    <div class="modal fade" id="modalScore" tabindex="-1" aria-labelledby="modalScoreLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST">
                    <input type="hidden" name="action" value="mettre_a_jour_score">
                    <input type="hidden" name="code_membre" value="<?php echo securiser($code_membre); ?>">
                    
                    <div class="modal-header">
                        <h5 class="modal-title" id="modalScoreLabel">
                            <i class="fas fa-chart-line me-2"></i>Modifier le score de crédit
                        </h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <label for="nouveau_score" class="form-label">Nouveau score</label>
                            <input type="number" class="form-control" id="nouveau_score" name="nouveau_score" 
                                   value="<?php echo $emprunteur['score_credit']; ?>" 
                                   min="0" max="1000" required>
                            <div class="form-text">
                                Score actuel: <?php echo $emprunteur['score_credit']; ?> 
                                | 
                                <span class="badge bg-<?php 
                                    $score = $emprunteur['score_credit'];
                                    echo $score >= 700 ? 'success' : ($score >= 500 ? 'warning' : 'danger');
                                ?>">
                                    <?php 
                                    if ($score >= 700) echo 'Excellent';
                                    elseif ($score >= 500) echo 'Moyen';
                                    else echo 'Faible';
                                    ?>
                                </span>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label for="raison_score" class="form-label">Raison de la modification</label>
                            <textarea class="form-control" id="raison_score" name="raison_score" rows="3" 
                                      placeholder="Ex: Paiement ponctuel des prêts, amélioration des cotisations..." 
                                      required></textarea>
                            <div class="form-text">Cette raison sera enregistrée dans l'historique des scores.</div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annuler</button>
                        <button type="submit" class="btn btn-primary">Mettre à jour</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Afficher le modal si erreur dans le formulaire de score
        <?php if (isset($_POST['action']) && $_POST['action'] == 'mettre_a_jour_score' && isset($erreur)): ?>
            document.addEventListener('DOMContentLoaded', function() {
                var modal = new bootstrap.Modal(document.getElementById('modalScore'));
                modal.show();
            });
        <?php endif; ?>
    </script>
</body>
</html>