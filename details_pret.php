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

// Récupérer l'ID du prêt
$pret_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($pret_id <= 0) {
    $_SESSION['erreur'] = "Prêt non spécifié.";
    rediriger('prets.php');
}

try {
    // Récupérer les informations détaillées du prêt
    $sql = "SELECT p.*, m.nom, m.prenom, m.code_membre, m.email, m.telephone,
                   pp.nom_pret, pp.code_plan, tp.nom_type, tp.couleur,
                   u.nom_complet as approbateur_nom,
                   COUNT(ep.id) as echeances_total,
                   SUM(CASE WHEN ep.statut = 'payee' THEN 1 ELSE 0 END) as echeances_payees,
                   SUM(CASE WHEN ep.statut IN ('due', 'en_retard') THEN 1 ELSE 0 END) as echeances_en_retard
            FROM prets p
            INNER JOIN membres m ON p.code_membre = m.code_membre
            INNER JOIN plans_pret pp ON p.plan_pret_id = pp.id
            INNER JOIN types_pret tp ON pp.type_pret_id = tp.id
            LEFT JOIN utilisateurs u ON p.approbation_par = u.id
            LEFT JOIN echeances_pret ep ON p.id = ep.pret_id
            WHERE p.id = ?
            GROUP BY p.id";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$pret_id]);
    $pret = $stmt->fetch();
    
    if (!$pret) {
        $_SESSION['erreur'] = "Prêt non trouvé.";
        rediriger('prets.php');
    }
    
    // Récupérer les échéances
    $sql_echeances = "SELECT * FROM echeances_pret 
                      WHERE pret_id = ? 
                      ORDER BY numero_echeance";
    $stmt_echeances = $pdo->prepare($sql_echeances);
    $stmt_echeances->execute([$pret_id]);
    $echeances = $stmt_echeances->fetchAll();
    
    // Récupérer les paiements
    $sql_paiements = "SELECT pa.*, u.nom_complet as enregistre_par_nom
                      FROM paiements pa
                      LEFT JOIN utilisateurs u ON pa.enregistre_par = u.id
                      WHERE pa.pret_id = ?
                      ORDER BY pa.date_paiement DESC";
    $stmt_paiements = $pdo->prepare($sql_paiements);
    $stmt_paiements->execute([$pret_id]);
    $paiements = $stmt_paiements->fetchAll();
    
    // Récupérer les garanties
    $sql_garanties = "SELECT g.*, u.nom_complet as evaluateur_nom
                      FROM garanties g
                      LEFT JOIN utilisateurs u ON g.evaluateur = u.id
                      WHERE g.pret_id = ?";
    $stmt_garanties = $pdo->prepare($sql_garanties);
    $stmt_garanties->execute([$pret_id]);
    $garanties = $stmt_garanties->fetchAll();
    
} catch (PDOException $e) {
    $_SESSION['erreur'] = "Erreur lors du chargement des données: " . $e->getMessage();
    rediriger('prets.php');
}

// Calculs pour l'affichage
$pourcentage_rembourse = $pret['montant'] > 0 ? (($pret['montant'] - $pret['montant_restant']) / $pret['montant']) * 100 : 0;
$pourcentage_echeances = $pret['echeances_total'] > 0 ? ($pret['echeances_payees'] / $pret['echeances_total']) * 100 : 0;
$jours_restants = floor((strtotime($pret['date_fin']) - time()) / (60 * 60 * 24));
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Détails du Prêt - <?php echo APP_NAME; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        .sidebar { background: #2c3e50; color: white; height: 100vh; position: fixed; padding-top: 20px; overflow-y: auto; }
        .sidebar .nav-link { color: #bdc3c7; padding: 12px 15px; margin: 3px 0; font-size: 0.9rem; }
        .sidebar .nav-link:hover, .sidebar .nav-link.active { color: white; background: #34495e; }
        .sidebar .nav-link i { width: 20px; margin-right: 8px; }
        .main-content { margin-left: 250px; padding: 20px; }
        .menu-section { margin: 15px 0; padding: 10px 0; border-bottom: 1px solid #34495e; }
        .menu-section-title { font-size: 0.8rem; text-transform: uppercase; color: #95a5a6; padding: 0 15px; margin-bottom: 10px; font-weight: bold; }
        .stat-card { border-radius: 10px; transition: transform 0.3s; }
        .stat-card:hover { transform: translateY(-5px); }
        .progress { height: 8px; }
        .table-actions { white-space: nowrap; }
        .info-card { border-left: 4px solid #007bff; }
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
                            <i class="fas fa-file-invoice-dollar me-2"></i>Détails du Prêt
                        </h1>
                        <p class="text-muted mb-0">Informations complètes sur le prêt <?php echo securiser($pret['code_pret']); ?></p>
                    </div>
                    <div>
                        <a href="prets.php" class="btn btn-secondary me-2">
                            <i class="fas fa-arrow-left me-2"></i>Retour aux prêts
                        </a>
                        <?php if (a_la_permission('admin') || a_la_permission('gestionnaire')): ?>
                        <a href="modifier_pret.php?id=<?php echo $pret_id; ?>" class="btn btn-warning">
                            <i class="fas fa-edit me-2"></i>Modifier
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

                <div class="row">
                    <!-- Informations principales -->
                    <div class="col-lg-8">
                        <!-- Carte d'information du prêt -->
                        <div class="card shadow mb-4">
                            <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
                                <h6 class="m-0 font-weight-bold">
                                    <i class="fas fa-info-circle me-2"></i>Informations du Prêt
                                </h6>
                                <span class="badge bg-light text-dark">
                                    <?php echo ucfirst(str_replace('_', ' ', $pret['statut'])); ?>
                                </span>
                            </div>
                            <div class="card-body">
                                <div class="row">
                                    <div class="col-md-6">
                                        <table class="table table-borderless">
                                            <tr>
                                                <th width="40%">Code Prêt:</th>
                                                <td><strong><?php echo securiser($pret['code_pret']); ?></strong></td>
                                            </tr>
                                            <tr>
                                                <th>Membre:</th>
                                                <td>
                                                    <strong><?php echo securiser($pret['nom'] . ' ' . $pret['prenom']); ?></strong>
                                                    <br>
                                                    <small class="text-muted"><?php echo securiser($pret['code_membre']); ?></small>
                                                </td>
                                            </tr>
                                            <tr>
                                                <th>Type de prêt:</th>
                                                <td>
                                                    <span class="badge bg-info"><?php echo securiser($pret['nom_type']); ?></span>
                                                    <br>
                                                    <small class="text-muted"><?php echo securiser($pret['nom_pret']); ?></small>
                                                </td>
                                            </tr>
                                            <tr>
                                                <th>Date création:</th>
                                                <td><?php echo date('d/m/Y H:i', strtotime($pret['date_creation'])); ?></td>
                                            </tr>
                                        </table>
                                    </div>
                                    <div class="col-md-6">
                                        <table class="table table-borderless">
                                            <tr>
                                                <th width="40%">Montant:</th>
                                                <td><strong><?php echo number_format($pret['montant'], 2); ?> FBu</strong></td>
                                            </tr>
                                            <tr>
                                                <th>Montant restant:</th>
                                                <td><strong class="<?php echo $pret['montant_restant'] > 0 ? 'text-warning' : 'text-success'; ?>">
                                                    <?php echo number_format($pret['montant_restant'], 2); ?> FBu
                                                </strong></td>
                                            </tr>
                                            <tr>
                                                <th>Mensualité:</th>
                                                <td><strong><?php echo number_format($pret['mensualite'], 2); ?> FBu</strong></td>
                                            </tr>
                                            <tr>
                                                <th>Taux d'intérêt:</th>
                                                <td><?php echo $pret['taux_interet']; ?>%</td>
                                            </tr>
                                        </table>
                                    </div>
                                </div>

                                <!-- Barre de progression -->
                                <div class="mt-4">
                                    <div class="d-flex justify-content-between mb-2">
                                        <span>Progression du remboursement</span>
                                        <span><?php echo round($pourcentage_rembourse); ?>%</span>
                                    </div>
                                    <div class="progress" style="height: 20px;">
                                        <div class="progress-bar bg-success" role="progressbar" 
                                             style="width: <?php echo $pourcentage_rembourse; ?>%"
                                             aria-valuenow="<?php echo $pourcentage_rembourse; ?>" 
                                             aria-valuemin="0" aria-valuemax="100">
                                            <?php echo round($pourcentage_rembourse); ?>%
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Informations de durée -->
                        <div class="card shadow mb-4">
                            <div class="card-header bg-info text-white">
                                <h6 class="m-0 font-weight-bold">
                                    <i class="fas fa-calendar-alt me-2"></i>Informations de Durée
                                </h6>
                            </div>
                            <div class="card-body">
                                <div class="row text-center">
                                    <div class="col-md-4">
                                        <div class="border rounded p-3">
                                            <h5 class="text-primary"><?php echo $pret['duree_mois']; ?></h5>
                                            <small class="text-muted">Durée (mois)</small>
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <div class="border rounded p-3">
                                            <h5 class="text-success"><?php echo date('d/m/Y', strtotime($pret['date_debut'])); ?></h5>
                                            <small class="text-muted">Date de début</small>
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <div class="border rounded p-3">
                                            <h5 class="text-warning"><?php echo date('d/m/Y', strtotime($pret['date_fin'])); ?></h5>
                                            <small class="text-muted">Date de fin</small>
                                        </div>
                                    </div>
                                </div>
                                <div class="text-center mt-3">
                                    <small class="text-muted">
                                        <?php if ($jours_restants > 0): ?>
                                            Il reste <strong><?php echo $jours_restants; ?> jours</strong> avant la fin du prêt
                                        <?php else: ?>
                                            Le prêt est <strong class="text-danger">échu depuis <?php echo abs($jours_restants); ?> jours</strong>
                                        <?php endif; ?>
                                    </small>
                                </div>
                            </div>
                        </div>

                        <!-- Échéances -->
                        <div class="card shadow mb-4">
                            <div class="card-header bg-warning text-dark">
                                <h6 class="m-0 font-weight-bold">
                                    <i class="fas fa-list-ol me-2"></i>Échéances (<?php echo $pret['echeances_payees']; ?>/<?php echo $pret['echeances_total']; ?> payées)
                                </h6>
                            </div>
                            <div class="card-body">
                                <?php if (!empty($echeances)): ?>
                                    <div class="table-responsive">
                                        <table class="table table-sm table-hover">
                                            <thead>
                                                <tr>
                                                    <th>#</th>
                                                    <th>Date</th>
                                                    <th>Montant</th>
                                                    <th>Capital</th>
                                                    <th>Intérêt</th>
                                                    <th>Capital Restant</th>
                                                    <th>Statut</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($echeances as $echeance): ?>
                                                    <tr>
                                                        <td><?php echo $echeance['numero_echeance']; ?></td>
                                                        <td><?php echo date('d/m/Y', strtotime($echeance['date_echeance'])); ?></td>
                                                        <td><?php echo number_format($echeance['montant_echeance'], 2); ?> FBu</td>
                                                        <td><?php echo number_format($echeance['capital'], 2); ?> FBu</td>
                                                        <td><?php echo number_format($echeance['interet'], 2); ?> FBu</td>
                                                        <td><?php echo number_format($echeance['capital_restant'], 2); ?> FBu</td>
                                                        <td>
                                                            <span class="badge bg-<?php 
                                                                switch($echeance['statut']) {
                                                                    case 'payee': echo 'success'; break;
                                                                    case 'due': echo 'warning'; break;
                                                                    case 'en_retard': echo 'danger'; break;
                                                                    case 'a_venir': echo 'info'; break;
                                                                    default: echo 'secondary';
                                                                }
                                                            ?>">
                                                                <?php echo ucfirst(str_replace('_', ' ', $echeance['statut'])); ?>
                                                            </span>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                <?php else: ?>
                                    <p class="text-muted text-center">Aucune échéance trouvée pour ce prêt.</p>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <!-- Informations secondaires -->
                    <div class="col-lg-4">
                        <!-- Statut et actions -->
                        <div class="card shadow mb-4">
                            <div class="card-header bg-secondary text-white">
                                <h6 class="m-0 font-weight-bold">
                                    <i class="fas fa-cogs me-2"></i>Statut et Actions
                                </h6>
                            </div>
                            <div class="card-body">
                                <div class="mb-3">
                                    <strong>Statut actuel:</strong>
                                    <br>
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
                                    ?> fs-6 mt-2 d-inline-block">
                                        <?php echo ucfirst(str_replace('_', ' ', $pret['statut'])); ?>
                                    </span>
                                </div>

                                <?php if ($pret['approbateur_nom']): ?>
                                <div class="mb-3">
                                    <strong>Approuvé par:</strong>
                                    <br>
                                    <?php echo securiser($pret['approbateur_nom']); ?>
                                    <br>
                                    <small class="text-muted"><?php echo date('d/m/Y H:i', strtotime($pret['date_approbation'])); ?></small>
                                </div>
                                <?php endif; ?>

                                <div class="d-grid gap-2">
                                    <!-- Bouton pour enregistrer un paiement pour ce prêt spécifique -->
                                    <a href="paiements.php?pret_id=<?php echo $pret_id; ?>" class="btn btn-success">
                                        <i class="fas fa-money-bill-wave me-2"></i>Enregistrer Paiement
                                    </a>
                                    
                                    <?php if (a_la_permission('admin') || a_la_permission('gestionnaire')): ?>
                                        <?php if ($pret['statut'] == 'en_attente'): ?>
                                            <a href="traiter_pret.php?id=<?php echo $pret_id; ?>" class="btn btn-primary">
                                                <i class="fas fa-cogs me-2"></i>Traiter le Prêt
                                            </a>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>

                        <!-- Informations du membre -->
                        <div class="card shadow mb-4">
                            <div class="card-header bg-info text-white">
                                <h6 class="m-0 font-weight-bold">
                                    <i class="fas fa-user me-2"></i>Informations du Membre
                                </h6>
                            </div>
                            <div class="card-body">
                                <h6><?php echo securiser($pret['nom'] . ' ' . $pret['prenom']); ?></h6>
                                <p class="mb-1">
                                    <i class="fas fa-id-card me-2 text-muted"></i>
                                    <?php echo securiser($pret['code_membre']); ?>
                                </p>
                                <p class="mb-1">
                                    <i class="fas fa-envelope me-2 text-muted"></i>
                                    <?php echo securiser($pret['email']); ?>
                                </p>
                                <p class="mb-0">
                                    <i class="fas fa-phone me-2 text-muted"></i>
                                    <?php echo securiser($pret['telephone']); ?>
                                </p>
                                <hr>
                                <div class="d-grid">
                                    <a href="details_membre.php?code=<?php echo $pret['code_membre']; ?>" class="btn btn-outline-primary btn-sm">
                                        Voir profil complet
                                    </a>
                                </div>
                            </div>
                        </div>

                        <!-- Garanties -->
                        <?php if (!empty($garanties)): ?>
                        <div class="card shadow mb-4">
                            <div class="card-header bg-warning text-dark">
                                <h6 class="m-0 font-weight-bold">
                                    <i class="fas fa-shield-alt me-2"></i>Garanties
                                </h6>
                            </div>
                            <div class="card-body">
                                <?php foreach ($garanties as $garantie): ?>
                                    <div class="border rounded p-3 mb-3">
                                        <h6 class="mb-2"><?php echo ucfirst($garantie['type_garantie']); ?></h6>
                                        <p class="mb-2 small"><?php echo securiser($garantie['description']); ?></p>
                                        <div class="d-flex justify-content-between">
                                            <span class="badge bg-<?php 
                                                switch($garantie['statut']) {
                                                    case 'valide': echo 'success'; break;
                                                    case 'invalide': echo 'danger'; break;
                                                    case 'a_verifier': echo 'warning'; break;
                                                    default: echo 'secondary';
                                                }
                                            ?>">
                                                <?php echo ucfirst($garantie['statut']); ?>
                                            </span>
                                            <strong><?php echo number_format($garantie['valeur_estimee'], 2); ?> FBu</strong>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Historique des paiements -->
                <div class="card shadow">
                    <div class="card-header bg-success text-white">
                        <h6 class="m-0 font-weight-bold">
                            <i class="fas fa-history me-2"></i>Historique des Paiements
                        </h6>
                    </div>
                    <div class="card-body">
                        <?php if (!empty($paiements)): ?>
                            <div class="table-responsive">
                                <table class="table table-sm table-hover">
                                    <thead>
                                        <tr>
                                            <th>Date</th>
                                            <th>Montant</th>
                                            <th>Méthode</th>
                                            <th>Type</th>
                                            <th>Référence</th>
                                            <th>Enregistré par</th>
                                            <th>Statut</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($paiements as $paiement): ?>
                                            <tr>
                                                <td><?php echo date('d/m/Y H:i', strtotime($paiement['date_paiement'])); ?></td>
                                                <td><strong><?php echo number_format($paiement['montant_paye'], 2); ?> FBu</strong></td>
                                                <td>
                                                    <span class="badge bg-info"><?php echo ucfirst($paiement['methode_paiement']); ?></span>
                                                </td>
                                                <td><?php echo ucfirst($paiement['type_paiement']); ?></td>
                                                <td><?php echo securiser($paiement['reference_paiement']); ?></td>
                                                <td><?php echo securiser($paiement['enregistre_par_nom']); ?></td>
                                                <td>
                                                    <span class="badge bg-<?php 
                                                        switch($paiement['statut']) {
                                                            case 'complet': echo 'success'; break;
                                                            case 'partiel': echo 'warning'; break;
                                                            case 'en_attente': echo 'info'; break;
                                                            default: echo 'secondary';
                                                        }
                                                    ?>">
                                                        <?php echo ucfirst($paiement['statut']); ?>
                                                    </span>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php else: ?>
                            <p class="text-muted text-center">Aucun paiement enregistré pour ce prêt.</p>
                        <?php endif; ?>
                    </div>
                </div>
            </main>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html> 