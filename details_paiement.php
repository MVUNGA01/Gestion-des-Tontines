<?php
require_once 'config_database.php';

// Vérifier l'authentification
if (!est_connecte()) {
    rediriger('login.php');
}

// Vérifier les permissions (au moins caissier)
if (!a_la_permission('caissier')) {
    $_SESSION['erreur'] = "Vous n'avez pas les permissions nécessaires pour accéder à cette page.";
    rediriger('dashboard.php');
}

// Vérifier que l'ID du paiement est fourni
if (!isset($_GET['id']) || empty($_GET['id'])) {
    $_SESSION['erreur'] = "Aucun paiement spécifié.";
    rediriger('paiements.php');
}

$paiement_id = (int)$_GET['id'];

try {
    // Récupérer les détails complets du paiement
    $stmt = $pdo->prepare("
        SELECT 
            p.*,
            pr.code_pret,
            pr.montant as montant_pret,
            pr.montant_restant,
            pr.taux_interet,
            pr.duree_mois,
            pr.date_debut,
            pr.date_fin,
            pr.mensualite,
            pr.statut as statut_pret,
            m.nom,
            m.prenom,
            m.code_membre,
            m.telephone,
            m.email,
            cm.nom_categorie,
            pp.nom_pret as nom_plan_pret,
            pp.code_plan,
            tp.nom_type as type_pret,
            u.nom_complet as enregistre_par_nom,
            ep.numero_echeance,
            ep.date_echeance,
            ep.montant_echeance,
            ep.capital,
            ep.interet,
            ep.assurance,
            ep.capital_restant,
            ep.statut as statut_echeance,
            ep.penalite_retard as penalite_echeance
        FROM paiements p
        INNER JOIN prets pr ON p.pret_id = pr.id
        INNER JOIN membres m ON pr.code_membre = m.code_membre
        INNER JOIN categories_membres cm ON m.categorie_id = cm.id
        INNER JOIN plans_pret pp ON pr.plan_pret_id = pp.id
        INNER JOIN types_pret tp ON pp.type_pret_id = tp.id
        LEFT JOIN echeances_pret ep ON p.echeance_id = ep.id
        LEFT JOIN utilisateurs u ON p.enregistre_par = u.id
        WHERE p.id = ?
    ");
    
    $stmt->execute([$paiement_id]);
    $paiement = $stmt->fetch();
    
    if (!$paiement) {
        $_SESSION['erreur'] = "Paiement non trouvé.";
        rediriger('paiements.php');
    }
    
    // Récupérer l'historique des paiements pour ce prêt
    $stmt_historique = $pdo->prepare("
        SELECT 
            p.*,
            ep.numero_echeance,
            u.nom_complet as enregistre_par_nom
        FROM paiements p
        LEFT JOIN echeances_pret ep ON p.echeance_id = ep.id
        LEFT JOIN utilisateurs u ON p.enregistre_par = u.id
        WHERE p.pret_id = ?
        ORDER BY p.date_paiement DESC
        LIMIT 10
    ");
    $stmt_historique->execute([$paiement['pret_id']]);
    $historique_paiements = $stmt_historique->fetchAll();
    
    // Récupérer les échéances du prêt
    $stmt_echeances = $pdo->prepare("
        SELECT *
        FROM echeances_pret
        WHERE pret_id = ?
        ORDER BY numero_echeance
    ");
    $stmt_echeances->execute([$paiement['pret_id']]);
    $echeances = $stmt_echeances->fetchAll();
    
} catch (PDOException $e) {
    $erreur = "Erreur lors du chargement des données: " . $e->getMessage();
}
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Détails du Paiement - <?php echo APP_NAME; ?></title>
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
        .info-card { border-left: 4px solid #007bff; }
        .info-card.success { border-left-color: #28a745; }
        .info-card.warning { border-left-color: #ffc107; }
        .info-card.danger { border-left-color: #dc3545; }
        .badge-statut { font-size: 0.8rem; }
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
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <div>
                        <h1 class="h3 mb-0">
                            <i class="fas fa-receipt me-2"></i>Détails du Paiement
                        </h1>
                        <p class="text-muted mb-0">Informations complètes sur le paiement</p>
                    </div>
                    <div>
                        <a href="paiements.php" class="btn btn-secondary">
                            <i class="fas fa-arrow-left me-2"></i>Retour aux paiements
                        </a>
                        <?php if (a_la_permission('admin') || a_la_permission('gestionnaire')): ?>
                            <button class="btn btn-outline-danger" onclick="confirmerAnnulation(<?php echo $paiement['id']; ?>)">
                                <i class="fas fa-times me-2"></i>Annuler le paiement
                            </button>
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

                <?php if (isset($erreur)): ?>
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        <?php echo $erreur; ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <div class="row">
                    <!-- Informations principales du paiement -->
                    <div class="col-lg-8">
                        <div class="card shadow mb-4">
                            <div class="card-header bg-primary text-white">
                                <h6 class="m-0 font-weight-bold">
                                    <i class="fas fa-info-circle me-2"></i>Informations du Paiement
                                </h6>
                            </div>
                            <div class="card-body">
                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="info-card p-3 mb-3 bg-light">
                                            <small class="text-muted">Référence</small>
                                            <h5 class="mb-0"><?php echo securiser($paiement['reference_paiement']); ?></h5>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="info-card p-3 mb-3 bg-light">
                                            <small class="text-muted">Statut</small>
                                            <div>
                                                <?php
                                                $badge_class = [
                                                    'complet' => 'bg-success',
                                                    'partiel' => 'bg-warning',
                                                    'en_attente' => 'bg-secondary',
                                                    'annule' => 'bg-danger'
                                                ][$paiement['statut']] ?? 'bg-secondary';
                                                ?>
                                                <span class="badge <?php echo $badge_class; ?> badge-statut">
                                                    <?php echo ucfirst($paiement['statut']); ?>
                                                </span>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="info-card p-3 mb-3 bg-light">
                                            <small class="text-muted">Montant Payé</small>
                                            <h4 class="text-primary mb-0">
                                                <?php echo number_format($paiement['montant_paye'], 2); ?> FBu
                                            </h4>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="info-card p-3 mb-3 bg-light">
                                            <small class="text-muted">Méthode de Paiement</small>
                                            <h5 class="mb-0">
                                                <span class="badge bg-info">
                                                    <?php echo ucfirst(str_replace('_', ' ', $paiement['methode_paiement'])); ?>
                                                </span>
                                            </h5>
                                        </div>
                                    </div>
                                </div>

                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="info-card p-3 mb-3 bg-light">
                                            <small class="text-muted">Type de Paiement</small>
                                            <h5 class="mb-0"><?php echo ucfirst($paiement['type_paiement']); ?></h5>
                                            <?php if ($paiement['numero_echeance']): ?>
                                                <small class="text-muted">
                                                    Échéance #<?php echo $paiement['numero_echeance']; ?>
                                                </small>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="info-card p-3 mb-3 bg-light">
                                            <small class="text-muted">Date du Paiement</small>
                                            <h5 class="mb-0">
                                                <?php echo date('d/m/Y H:i', strtotime($paiement['date_paiement'])); ?>
                                            </h5>
                                        </div>
                                    </div>
                                </div>

                                <!-- Répartition du paiement -->
                                <div class="card mt-4">
                                    <div class="card-header bg-light">
                                        <h6 class="m-0 font-weight-bold">Répartition du Paiement</h6>
                                    </div>
                                    <div class="card-body">
                                        <div class="row text-center">
                                            <div class="col-md-3">
                                                <div class="info-card success p-3">
                                                    <small class="text-muted">Capital</small>
                                                    <h5 class="text-success mb-0">
                                                        <?php echo number_format($paiement['montant_capital'], 2); ?> FBu
                                                    </h5>
                                                </div>
                                            </div>
                                            <div class="col-md-3">
                                                <div class="info-card warning p-3">
                                                    <small class="text-muted">Intérêt</small>
                                                    <h5 class="text-warning mb-0">
                                                        <?php echo number_format($paiement['montant_interet'], 2); ?> FBu
                                                    </h5>
                                                </div>
                                            </div>
                                            <div class="col-md-3">
                                                <div class="info-card p-3">
                                                    <small class="text-muted">Assurance</small>
                                                    <h5 class="text-info mb-0">
                                                        <?php echo number_format($paiement['montant_assurance'], 2); ?> FBu
                                                    </h5>
                                                </div>
                                            </div>
                                            <div class="col-md-3">
                                                <div class="info-card danger p-3">
                                                    <small class="text-muted">Pénalité</small>
                                                    <h5 class="text-danger mb-0">
                                                        <?php echo number_format($paiement['montant_penalite'], 2); ?> FBu
                                                    </h5>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <!-- Notes -->
                                <?php if (!empty($paiement['notes'])): ?>
                                <div class="card mt-4">
                                    <div class="card-header bg-light">
                                        <h6 class="m-0 font-weight-bold">Notes</h6>
                                    </div>
                                    <div class="card-body">
                                        <p class="mb-0"><?php echo nl2br(securiser($paiement['notes'])); ?></p>
                                    </div>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- Historique des paiements du prêt -->
                        <div class="card shadow">
                            <div class="card-header bg-light">
                                <h6 class="m-0 font-weight-bold">
                                    <i class="fas fa-history me-2"></i>Historique Récent des Paiements
                                </h6>
                            </div>
                            <div class="card-body">
                                <?php if (!empty($historique_paiements)): ?>
                                    <div class="table-responsive">
                                        <table class="table table-sm table-hover">
                                            <thead>
                                                <tr>
                                                    <th>Référence</th>
                                                    <th>Date</th>
                                                    <th>Montant</th>
                                                    <th>Type</th>
                                                    <th>Statut</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($historique_paiements as $histo): ?>
                                                    <tr class="<?php echo $histo['id'] == $paiement['id'] ? 'table-active' : ''; ?>">
                                                        <td>
                                                            <?php if ($histo['id'] == $paiement['id']): ?>
                                                                <strong><?php echo securiser($histo['reference_paiement']); ?></strong>
                                                            <?php else: ?>
                                                                <a href="details_paiement.php?id=<?php echo $histo['id']; ?>">
                                                                    <?php echo securiser($histo['reference_paiement']); ?>
                                                                </a>
                                                            <?php endif; ?>
                                                        </td>
                                                        <td><?php echo date('d/m/Y H:i', strtotime($histo['date_paiement'])); ?></td>
                                                        <td><?php echo number_format($histo['montant_paye'], 2); ?> FBu</td>
                                                        <td><?php echo ucfirst($histo['type_paiement']); ?></td>
                                                        <td>
                                                            <?php
                                                            $badge_class = [
                                                                'complet' => 'bg-success',
                                                                'partiel' => 'bg-warning',
                                                                'en_attente' => 'bg-secondary',
                                                                'annule' => 'bg-danger'
                                                            ][$histo['statut']] ?? 'bg-secondary';
                                                            ?>
                                                            <span class="badge <?php echo $badge_class; ?> badge-statut">
                                                                <?php echo ucfirst($histo['statut']); ?>
                                                            </span>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                <?php else: ?>
                                    <p class="text-muted text-center">Aucun autre paiement trouvé pour ce prêt.</p>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <!-- Informations du prêt et du membre -->
                    <div class="col-lg-4">
                        <!-- Informations du prêt -->
                        <div class="card shadow mb-4">
                            <div class="card-header bg-info text-white">
                                <h6 class="m-0 font-weight-bold">
                                    <i class="fas fa-file-invoice-dollar me-2"></i>Informations du Prêt
                                </h6>
                            </div>
                            <div class="card-body">
                                <div class="mb-3">
                                    <small class="text-muted">Code Prêt</small>
                                    <h6 class="mb-0"><?php echo securiser($paiement['code_pret']); ?></h6>
                                </div>
                                
                                <div class="mb-3">
                                    <small class="text-muted">Plan de Prêt</small>
                                    <h6 class="mb-0"><?php echo securiser($paiement['nom_plan_pret']); ?></h6>
                                    <small class="text-muted"><?php echo securiser($paiement['type_pret']); ?></small>
                                </div>
                                
                                <div class="row mb-3">
                                    <div class="col-6">
                                        <small class="text-muted">Montant Initial</small>
                                        <h6 class="mb-0"><?php echo number_format($paiement['montant_pret'], 2); ?> FBu</h6>
                                    </div>
                                    <div class="col-6">
                                        <small class="text-muted">Montant Restant</small>
                                        <h6 class="mb-0"><?php echo number_format($paiement['montant_restant'], 2); ?> FBu</h6>
                                    </div>
                                </div>
                                
                                <div class="row mb-3">
                                    <div class="col-6">
                                        <small class="text-muted">Taux d'intérêt</small>
                                        <h6 class="mb-0"><?php echo $paiement['taux_interet']; ?>%</h6>
                                    </div>
                                    <div class="col-6">
                                        <small class="text-muted">Mensualité</small>
                                        <h6 class="mb-0"><?php echo number_format($paiement['mensualite'], 2); ?> FBu</h6>
                                    </div>
                                </div>
                                
                                <div class="row">
                                    <div class="col-6">
                                        <small class="text-muted">Date début</small>
                                        <h6 class="mb-0"><?php echo date('d/m/Y', strtotime($paiement['date_debut'])); ?></h6>
                                    </div>
                                    <div class="col-6">
                                        <small class="text-muted">Date fin</small>
                                        <h6 class="mb-0"><?php echo date('d/m/Y', strtotime($paiement['date_fin'])); ?></h6>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Informations du membre -->
                        <div class="card shadow mb-4">
                            <div class="card-header bg-success text-white">
                                <h6 class="m-0 font-weight-bold">
                                    <i class="fas fa-user me-2"></i>Informations du Membre
                                </h6>
                            </div>
                            <div class="card-body">
                                <div class="mb-3">
                                    <small class="text-muted">Nom Complet</small>
                                    <h6 class="mb-0"><?php echo securiser($paiement['nom'] . ' ' . $paiement['prenom']); ?></h6>
                                </div>
                                
                                <div class="mb-3">
                                    <small class="text-muted">Code Membre</small>
                                    <h6 class="mb-0"><?php echo securiser($paiement['code_membre']); ?></h6>
                                </div>
                                
                                <div class="mb-3">
                                    <small class="text-muted">Catégorie</small>
                                    <h6 class="mb-0"><?php echo securiser($paiement['nom_categorie']); ?></h6>
                                </div>
                                
                                <div class="mb-3">
                                    <small class="text-muted">Téléphone</small>
                                    <h6 class="mb-0"><?php echo securiser($paiement['telephone']); ?></h6>
                                </div>
                                
                                <div class="mb-3">
                                    <small class="text-muted">Email</small>
                                    <h6 class="mb-0"><?php echo securiser($paiement['email']); ?></h6>
                                </div>
                                
                                <div class="text-center mt-3">
                                    <a href="membres.php?recherche=<?php echo urlencode($paiement['code_membre']); ?>" 
                                       class="btn btn-sm btn-outline-success">
                                        <i class="fas fa-external-link-alt me-1"></i>Voir le membre
                                    </a>
                                </div>
                            </div>
                        </div>

                        <!-- Informations de l'échéance (si applicable) -->
                        <?php if ($paiement['echeance_id']): ?>
                        <div class="card shadow">
                            <div class="card-header bg-warning text-dark">
                                <h6 class="m-0 font-weight-bold">
                                    <i class="fas fa-calendar-alt me-2"></i>Échéance Concernée
                                </h6>
                            </div>
                            <div class="card-body">
                                <div class="mb-3">
                                    <small class="text-muted">Numéro</small>
                                    <h6 class="mb-0">Échéance #<?php echo $paiement['numero_echeance']; ?></h6>
                                </div>
                                
                                <div class="mb-3">
                                    <small class="text-muted">Date d'échéance</small>
                                    <h6 class="mb-0"><?php echo date('d/m/Y', strtotime($paiement['date_echeance'])); ?></h6>
                                </div>
                                
                                <div class="mb-3">
                                    <small class="text-muted">Montant de l'échéance</small>
                                    <h6 class="mb-0"><?php echo number_format($paiement['montant_echeance'], 2); ?> FBu</h6>
                                </div>
                                
                                <div class="mb-3">
                                    <small class="text-muted">Statut</small>
                                    <div>
                                        <?php
                                        $badge_class_echeance = [
                                            'payee' => 'bg-success',
                                            'due' => 'bg-warning',
                                            'en_retard' => 'bg-danger',
                                            'a_venir' => 'bg-secondary'
                                        ][$paiement['statut_echeance']] ?? 'bg-secondary';
                                        ?>
                                        <span class="badge <?php echo $badge_class_echeance; ?> badge-statut">
                                            <?php echo ucfirst(str_replace('_', ' ', $paiement['statut_echeance'])); ?>
                                        </span>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </main>
        </div>
    </div>

    <!-- Scripts -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function confirmerAnnulation(paiementId) {
            if (confirm('Êtes-vous sûr de vouloir annuler ce paiement ? Cette action est irréversible.')) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.action = 'paiements.php';
                form.innerHTML = `
                    <input type="hidden" name="action" value="annuler_paiement">
                    <input type="hidden" name="paiement_id" value="${paiementId}">
                `;
                document.body.appendChild(form);
                form.submit();
            }
        }

        // Impression du reçu
        function imprimerReçu() {
            window.print();
        }
    </script>
</body>
</html>