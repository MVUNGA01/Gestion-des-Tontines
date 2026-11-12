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

// Vérifier que le code membre est fourni
if (!isset($_GET['code']) || empty($_GET['code'])) {
    $_SESSION['erreur'] = "Code membre non spécifié.";
    rediriger('membres.php');
}

$code_membre = securiser($_GET['code']);

try {
    // Récupérer les informations détaillées du membre
    $stmt = $pdo->prepare("
        SELECT m.*, cm.nom_categorie, cm.code_categorie, cm.cotisation_mensuelle,
               e.score_credit, e.statut as statut_emprunteur, 
               -- Calcul de la dette actuelle : somme des montants restants de tous les prêts actifs
               COALESCE(SUM(p.montant_restant), 0) as dette_actuelle,
               e.cotisation_a_jour, e.mois_cotises_consecutifs, e.total_cotisations_payees,
               e.total_emprunte, e.total_rembourse,
               e.nombre_prets_actifs, e.nombre_prets_total,
               e.date_premiere_cotisation, e.date_derniere_cotisation,
               -- Calcul du total des mois cotisés (somme des périodes_mois de toutes les cotisations)
               (SELECT COALESCE(SUM(periode_mois), 0) 
                FROM cotisations c 
                WHERE c.code_membre = m.code_membre AND c.statut = 'valide') as total_mois_cotises
        FROM membres m
        INNER JOIN categories_membres cm ON m.categorie_id = cm.id
        LEFT JOIN emprunteurs e ON m.code_membre = e.code_membre
        LEFT JOIN prets p ON m.code_membre = p.code_membre AND p.statut IN ('actif', 'en_retard', 'approuve')
        WHERE m.code_membre = ?
        GROUP BY m.code_membre, cm.nom_categorie, cm.code_categorie, cm.cotisation_mensuelle,
                 e.score_credit, e.statut, e.cotisation_a_jour, e.mois_cotises_consecutifs, 
                 e.total_cotisations_payees, e.total_emprunte, e.total_rembourse, 
                 e.nombre_prets_actifs, e.nombre_prets_total,
                 e.date_premiere_cotisation, e.date_derniere_cotisation
    ");
    $stmt->execute([$code_membre]);
    $membre = $stmt->fetch();

    if (!$membre) {
        $_SESSION['erreur'] = "Membre non trouvé.";
        rediriger('membres.php');
    }

    // Récupérer les cotisations du membre
    $stmt_cotisations = $pdo->prepare("
        SELECT * FROM cotisations 
        WHERE code_membre = ? 
        ORDER BY date_debut DESC 
        LIMIT 12
    ");
    $stmt_cotisations->execute([$code_membre]);
    $cotisations = $stmt_cotisations->fetchAll();

    // Récupérer les prêts du membre
    $stmt_prets = $pdo->prepare("
        SELECT p.*, pp.nom_pret, tp.nom_type,
               DATEDIFF(p.date_fin, CURDATE()) as jours_restants
        FROM prets p
        INNER JOIN plans_pret pp ON p.plan_pret_id = pp.id
        INNER JOIN types_pret tp ON pp.type_pret_id = tp.id
        WHERE p.code_membre = ?
        ORDER BY p.date_creation DESC
    ");
    $stmt_prets->execute([$code_membre]);
    $prets = $stmt_prets->fetchAll();

    // Récupérer les documents du membre
    $stmt_documents = $pdo->prepare("
        SELECT * FROM documents_membres 
        WHERE code_membre = ? 
        ORDER BY date_upload DESC
    ");
    $stmt_documents->execute([$code_membre]);
    $documents = $stmt_documents->fetchAll();

    // Récupérer l'historique des scores
    $stmt_scores = $pdo->prepare("
        SELECT * FROM historique_scores 
        WHERE code_membre = ? 
        ORDER BY date_modification DESC 
        LIMIT 10
    ");
    $stmt_scores->execute([$code_membre]);
    $historique_scores = $stmt_scores->fetchAll();

} catch (PDOException $e) {
    $erreur = "Erreur lors du chargement du profil: " . $e->getMessage();
}
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Profil de <?php echo securiser($membre['nom'] . ' ' . $membre['prenom']); ?> - <?php echo APP_NAME; ?></title>
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
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 2rem;
            border-radius: 10px;
            margin-bottom: 2rem;
        }
        .profile-avatar {
            width: 120px;
            height: 120px;
            border-radius: 50%;
            object-fit: cover;
            border: 4px solid white;
        }
        .stat-card {
            border-radius: 10px;
            transition: transform 0.3s;
        }
        .stat-card:hover {
            transform: translateY(-5px);
        }
        .nav-tabs .nav-link.active {
            font-weight: bold;
            border-bottom: 3px solid #007bff;
        }
        .avatar-placeholder {
            width: 120px;
            height: 120px;
            border-radius: 50%;
            background: white;
            display: flex;
            align-items: center;
            justify-content: center;
            border: 4px solid white;
        }
        .avatar-placeholder i {
            font-size: 3rem;
            color: #667eea;
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
                <!-- En-tête du profil -->
                <div class="profile-header">
                    <div class="row align-items-center">
                        <div class="col-auto">
                            <?php if (!empty($membre['photo_profil'])): ?>
                                <img src="<?php echo securiser($membre['photo_profil']); ?>" alt="Photo profil" class="profile-avatar">
                            <?php else: ?>
                                <div class="avatar-placeholder">
                                    <i class="fas fa-user"></i>
                                </div>
                            <?php endif; ?>
                        </div>
                        <div class="col">
                            <h1 class="h2 mb-1"><?php echo securiser($membre['nom'] . ' ' . $membre['prenom']); ?></h1>
                            <p class="mb-1">Code: <?php echo securiser($membre['code_membre']); ?></p>
                            <p class="mb-0">
                                <span class="badge bg-light text-dark me-2">
                                    <i class="fas fa-tag me-1"></i><?php echo securiser($membre['nom_categorie']); ?>
                                </span>
                                <span class="badge bg-<?php echo $membre['statut'] === 'actif' ? 'success' : ($membre['statut'] === 'inactif' ? 'secondary' : 'danger'); ?>">
                                    <?php echo ucfirst($membre['statut']); ?>
                                </span>
                                <span class="badge bg-<?php echo $membre['statut_emprunteur'] === 'eligible' ? 'success' : ($membre['statut_emprunteur'] === 'en_attente' ? 'warning' : ($membre['statut_emprunteur'] === 'suspendu' ? 'danger' : ($membre['statut_emprunteur'] === 'blackliste' ? 'dark' : 'secondary'))); ?> ms-2">
                                    <?php echo ucfirst($membre['statut_emprunteur'] ?? 'non défini'); ?>
                                </span>
                            </p>
                        </div>
                        <div class="col-auto">
                            <div class="btn-group">
                                <a href="membres.php" class="btn btn-light">
                                    <i class="fas fa-arrow-left me-2"></i>Retour
                                </a>
                                <a href="modifier_membre.php?code=<?php echo $membre['code_membre']; ?>" class="btn btn-warning">
                                    <i class="fas fa-edit me-2"></i>Modifier
                                </a>
                                <?php if (a_la_permission('admin') || a_la_permission('gestionnaire')): ?>
                                <a href="nouveau_pret.php?membre=<?php echo $membre['code_membre']; ?>" class="btn btn-primary">
                                    <i class="fas fa-plus me-2"></i>Nouveau Prêt
                                </a>
                                <?php endif; ?>
                            </div>
                        </div>
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
                    <div class="col-xl-2 col-md-4 mb-3">
                        <div class="card stat-card border-left-primary shadow h-100">
                            <div class="card-body text-center">
                                <div class="text-primary mb-2">
                                    <i class="fas fa-star fa-2x"></i>
                                </div>
                                <div class="h5 mb-1 font-weight-bold"><?php echo $membre['score_credit'] ?? '500'; ?></div>
                                <small class="text-muted">Score Crédit</small>
                            </div>
                        </div>
                    </div>

                    <div class="col-xl-2 col-md-4 mb-3">
                        <div class="card stat-card border-left-success shadow h-100">
                            <div class="card-body text-center">
                                <div class="text-success mb-2">
                                    <i class="fas fa-coins fa-2x"></i>
                                </div>
                                <div class="h5 mb-1 font-weight-bold"><?php echo $membre['cotisation_a_jour'] ? 'À jour' : 'En retard'; ?></div>
                                <small class="text-muted">Cotisation</small>
                            </div>
                        </div>
                    </div>

                    <div class="col-xl-2 col-md-4 mb-3">
                        <div class="card stat-card border-left-info shadow h-100">
                            <div class="card-body text-center">
                                <div class="text-info mb-2">
                                    <i class="fas fa-money-bill-wave fa-2x"></i>
                                </div>
                                <div class="h5 mb-1 font-weight-bold"><?php echo number_format($membre['dette_actuelle'] ?? 0, 0, ',', ' '); ?> FBu</div>
                                <small class="text-muted">Dette Actuelle</small>
                            </div>
                        </div>
                    </div>

                    <div class="col-xl-2 col-md-4 mb-3">
                        <div class="card stat-card border-left-warning shadow h-100">
                            <div class="card-body text-center">
                                <div class="text-warning mb-2">
                                    <i class="fas fa-file-invoice-dollar fa-2x"></i>
                                </div>
                                <div class="h5 mb-1 font-weight-bold"><?php echo $membre['nombre_prets_actifs'] ?? 0; ?></div>
                                <small class="text-muted">Prêts Actifs</small>
                            </div>
                        </div>
                    </div>

                    <div class="col-xl-2 col-md-4 mb-3">
                        <div class="card stat-card border-left-secondary shadow h-100">
                            <div class="card-body text-center">
                                <div class="text-secondary mb-2">
                                    <i class="fas fa-calendar-alt fa-2x"></i>
                                </div>
                                <div class="h5 mb-1 font-weight-bold"><?php echo $membre['total_mois_cotises'] ?? 0; ?></div>
                                <small class="text-muted">Total Mois Cotisés</small>
                            </div>
                        </div>
                    </div>

                    <div class="col-xl-2 col-md-4 mb-3">
                        <div class="card stat-card border-left-dark shadow h-100">
                            <div class="card-body text-center">
                                <div class="text-dark mb-2">
                                    <i class="fas fa-history fa-2x"></i>
                                </div>
                                <div class="h5 mb-1 font-weight-bold"><?php echo $membre['mois_cotises_consecutifs'] ?? 0; ?></div>
                                <small class="text-muted">Mois Consécutifs</small>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Navigation par onglets -->
                <ul class="nav nav-tabs mb-4" id="profileTabs" role="tablist">
                    <li class="nav-item" role="presentation">
                        <button class="nav-link active" id="info-tab" data-bs-toggle="tab" data-bs-target="#info" type="button" role="tab">
                            <i class="fas fa-info-circle me-2"></i>Informations
                        </button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" id="prets-tab" data-bs-toggle="tab" data-bs-target="#prets" type="button" role="tab">
                            <i class="fas fa-file-invoice-dollar me-2"></i>Prêts (<?php echo count($prets); ?>)
                        </button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" id="cotisations-tab" data-bs-toggle="tab" data-bs-target="#cotisations" type="button" role="tab">
                            <i class="fas fa-coins me-2"></i>Cotisations (<?php echo count($cotisations); ?>)
                        </button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" id="documents-tab" data-bs-toggle="tab" data-bs-target="#documents" type="button" role="tab">
                            <i class="fas fa-file-contract me-2"></i>Documents (<?php echo count($documents); ?>)
                        </button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" id="historique-tab" data-bs-toggle="tab" data-bs-target="#historique" type="button" role="tab">
                            <i class="fas fa-chart-line me-2"></i>Historique Score
                        </button>
                    </li>
                </ul>

                <div class="tab-content" id="profileTabsContent">
                    <!-- Onglet Informations -->
                    <div class="tab-pane fade show active" id="info" role="tabpanel">
                        <div class="row">
                            <div class="col-lg-6">
                                <div class="card shadow mb-4">
                                    <div class="card-header bg-light">
                                        <h6 class="m-0 font-weight-bold text-primary">
                                            <i class="fas fa-user me-2"></i>Informations Personnelles
                                        </h6>
                                    </div>
                                    <div class="card-body">
                                        <div class="row mb-3">
                                            <div class="col-sm-4 fw-bold">Nom complet:</div>
                                            <div class="col-sm-8"><?php echo securiser($membre['nom'] . ' ' . $membre['prenom']); ?></div>
                                        </div>
                                        <div class="row mb-3">
                                            <div class="col-sm-4 fw-bold">Email:</div>
                                            <div class="col-sm-8"><?php echo securiser($membre['email']); ?></div>
                                        </div>
                                        <div class="row mb-3">
                                            <div class="col-sm-4 fw-bold">Téléphone:</div>
                                            <div class="col-sm-8"><?php echo securiser($membre['telephone'] ?? 'Non renseigné'); ?></div>
                                        </div>
                                        <div class="row mb-3">
                                            <div class="col-sm-4 fw-bold">Date naissance:</div>
                                            <div class="col-sm-8"><?php echo $membre['date_naissance'] ? date('d/m/Y', strtotime($membre['date_naissance'])) : 'Non renseignée'; ?></div>
                                        </div>
                                        <div class="row mb-3">
                                            <div class="col-sm-4 fw-bold">Lieu naissance:</div>
                                            <div class="col-sm-8"><?php echo securiser($membre['lieu_naissance'] ?? 'Non renseigné'); ?></div>
                                        </div>
                                        <div class="row mb-3">
                                            <div class="col-sm-4 fw-bold">Date d'inscription:</div>
                                            <div class="col-sm-8"><?php echo date('d/m/Y', strtotime($membre['date_inscription'])); ?></div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="col-lg-6">
                                <div class="card shadow mb-4">
                                    <div class="card-header bg-light">
                                        <h6 class="m-0 font-weight-bold text-primary">
                                            <i class="fas fa-briefcase me-2"></i>Informations Professionnelles
                                        </h6>
                                    </div>
                                    <div class="card-body">
                                        <div class="row mb-3">
                                            <div class="col-sm-4 fw-bold">Profession:</div>
                                            <div class="col-sm-8"><?php echo securiser($membre['profession'] ?? 'Non renseignée'); ?></div>
                                        </div>
                                        <div class="row mb-3">
                                            <div class="col-sm-4 fw-bold">Employeur:</div>
                                            <div class="col-sm-8"><?php echo securiser($membre['employeur'] ?? 'Non renseigné'); ?></div>
                                        </div>
                                        <div class="row mb-3">
                                            <div class="col-sm-4 fw-bold">Salaire mensuel:</div>
                                            <div class="col-sm-8"><?php echo $membre['salaire_mensuel'] ? number_format($membre['salaire_mensuel'], 0, ',', ' ') . ' FBu' : 'Non renseigné'; ?></div>
                                        </div>
                                        <div class="row mb-3">
                                            <div class="col-sm-4 fw-bold">Adresse:</div>
                                            <div class="col-sm-8"><?php echo securiser($membre['adresse'] ?? 'Non renseignée'); ?></div>
                                        </div>
                                        <div class="row mb-3">
                                            <div class="col-sm-4 fw-bold">Cotisation mensuelle:</div>
                                            <div class="col-sm-8"><?php echo number_format($membre['cotisation_mensuelle'], 0, ',', ' ') . ' FBu'; ?></div>
                                        </div>
                                        <div class="row mb-3">
                                            <div class="col-sm-4 fw-bold">Total cotisations:</div>
                                            <div class="col-sm-8"><?php echo number_format($membre['total_cotisations_payees'] ?? 0, 0, ',', ' ') . ' FBu'; ?></div>
                                        </div>
                                    </div>
                                </div>

                                <div class="card shadow">
                                    <div class="card-header bg-light">
                                        <h6 class="m-0 font-weight-bold text-primary">
                                            <i class="fas fa-phone-alt me-2"></i>Contact d'Urgence
                                        </h6>
                                    </div>
                                    <div class="card-body">
                                        <div class="row mb-3">
                                            <div class="col-sm-4 fw-bold">Nom:</div>
                                            <div class="col-sm-8"><?php echo securiser($membre['nom_contact_urgence'] ?? 'Non renseigné'); ?></div>
                                        </div>
                                        <div class="row mb-3">
                                            <div class="col-sm-4 fw-bold">Téléphone:</div>
                                            <div class="col-sm-8"><?php echo securiser($membre['tel_contact_urgence'] ?? 'Non renseigné'); ?></div>
                                        </div>
                                        <div class="row mb-3">
                                            <div class="col-sm-4 fw-bold">Relation:</div>
                                            <div class="col-sm-8"><?php echo securiser($membre['relation_contact_urgence'] ?? 'Non renseignée'); ?></div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Onglet Prêts -->
                    <div class="tab-pane fade" id="prets" role="tabpanel">
                        <div class="card shadow">
                            <div class="card-header bg-light d-flex justify-content-between align-items-center">
                                <h6 class="m-0 font-weight-bold text-primary">
                                    <i class="fas fa-file-invoice-dollar me-2"></i>Historique des Prêts
                                </h6>
                                <?php if (a_la_permission('admin') || a_la_permission('gestionnaire')): ?>
                                <a href="nouveau_pret.php?membre=<?php echo $membre['code_membre']; ?>" class="btn btn-primary btn-sm">
                                    <i class="fas fa-plus me-2"></i>Nouveau Prêt
                                </a>
                                <?php endif; ?>
                            </div>
                            <div class="card-body">
                                <?php if (!empty($prets)): ?>
                                    <div class="table-responsive">
                                        <table class="table table-hover">
                                            <thead class="table-light">
                                                <tr>
                                                    <th>Code Prêt</th>
                                                    <th>Type</th>
                                                    <th>Montant</th>
                                                    <th>Reste</th>
                                                    <th>Date Début</th>
                                                    <th>Date Fin</th>
                                                    <th>Statut</th>
                                                    <th>Actions</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($prets as $pret): ?>
                                                    <tr>
                                                        <td><?php echo securiser($pret['code_pret']); ?></td>
                                                        <td><?php echo securiser($pret['nom_type']); ?></td>
                                                        <td><?php echo number_format($pret['montant'], 0, ',', ' '); ?> FBu</td>
                                                        <td><?php echo number_format($pret['montant_restant'], 0, ',', ' '); ?> FBu</td>
                                                        <td><?php echo date('d/m/Y', strtotime($pret['date_debut'])); ?></td>
                                                        <td><?php echo date('d/m/Y', strtotime($pret['date_fin'])); ?></td>
                                                        <td>
                                                            <span class="badge bg-<?php 
                                                                switch($pret['statut']) {
                                                                    case 'actif': echo 'success'; break;
                                                                    case 'en_retard': echo 'danger'; break;
                                                                    case 'approuve': echo 'info'; break;
                                                                    case 'complet': echo 'secondary'; break;
                                                                    case 'brouillon': echo 'light text-dark'; break;
                                                                    default: echo 'warning';
                                                                }
                                                            ?>">
                                                                <?php echo ucfirst($pret['statut']); ?>
                                                            </span>
                                                        </td>
                                                        <td>
                                                            <a href="details_pret.php?code=<?php echo $pret['code_pret']; ?>" class="btn btn-sm btn-outline-primary">
                                                                <i class="fas fa-eye"></i>
                                                            </a>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                <?php else: ?>
                                    <div class="text-center py-4">
                                        <i class="fas fa-file-invoice-dollar fa-3x text-muted mb-3"></i>
                                        <h5 class="text-muted">Aucun prêt</h5>
                                        <p class="text-muted">Ce membre n'a pas encore souscrit de prêt.</p>
                                        <?php if (a_la_permission('admin') || a_la_permission('gestionnaire')): ?>
                                        <a href="nouveau_pret.php?membre=<?php echo $membre['code_membre']; ?>" class="btn btn-primary">
                                            <i class="fas fa-plus me-2"></i>Créer un premier prêt
                                        </a>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <!-- Onglet Cotisations -->
                    <div class="tab-pane fade" id="cotisations" role="tabpanel">
                        <div class="card shadow">
                            <div class="card-header bg-light">
                                <h6 class="m-0 font-weight-bold text-primary">
                                    <i class="fas fa-coins me-2"></i>Historique des Cotisations
                                </h6>
                            </div>
                            <div class="card-body">
                                <?php if (!empty($cotisations)): ?>
                                    <div class="table-responsive">
                                        <table class="table table-hover">
                                            <thead class="table-light">
                                                <tr>
                                                    <th>Période</th>
                                                    <th>Mois</th>
                                                    <th>Montant</th>
                                                    <th>Date Paiement</th>
                                                    <th>Méthode</th>
                                                    <th>Statut</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($cotisations as $cotisation): ?>
                                                    <tr>
                                                        <td>
                                                            <?php echo date('d/m/Y', strtotime($cotisation['date_debut'])); ?> - 
                                                            <?php echo date('d/m/Y', strtotime($cotisation['date_fin'])); ?>
                                                        </td>
                                                        <td>
                                                            <span class="badge bg-primary"><?php echo $cotisation['periode_mois']; ?> mois</span>
                                                        </td>
                                                        <td><?php echo number_format($cotisation['montant'], 0, ',', ' '); ?> FBu</td>
                                                        <td><?php echo date('d/m/Y', strtotime($cotisation['date_paiement'])); ?></td>
                                                        <td>
                                                            <span class="badge bg-info"><?php echo ucfirst($cotisation['methode_paiement']); ?></span>
                                                        </td>
                                                        <td>
                                                            <span class="badge bg-<?php 
                                                                switch($cotisation['statut']) {
                                                                    case 'valide': echo 'success'; break;
                                                                    case 'expire': echo 'warning'; break;
                                                                    case 'en_attente': echo 'info'; break;
                                                                    case 'annule': echo 'danger'; break;
                                                                    default: echo 'secondary';
                                                                }
                                                            ?>">
                                                                <?php echo ucfirst($cotisation['statut']); ?>
                                                            </span>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                <?php else: ?>
                                    <div class="text-center py-4">
                                        <i class="fas fa-coins fa-3x text-muted mb-3"></i>
                                        <h5 class="text-muted">Aucune cotisation</h5>
                                        <p class="text-muted">Aucune cotisation enregistrée pour ce membre.</p>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <!-- Onglet Documents -->
                    <div class="tab-pane fade" id="documents" role="tabpanel">
                        <div class="card shadow">
                            <div class="card-header bg-light">
                                <h6 class="m-0 font-weight-bold text-primary">
                                    <i class="fas fa-file-contract me-2"></i>Documents du Membre
                                </h6>
                            </div>
                            <div class="card-body">
                                <?php if (!empty($documents)): ?>
                                    <div class="row">
                                        <?php foreach ($documents as $document): ?>
                                            <div class="col-md-6 mb-3">
                                                <div class="card">
                                                    <div class="card-body">
                                                        <div class="d-flex justify-content-between align-items-start">
                                                            <div>
                                                                <h6 class="card-title">
                                                                    <i class="fas fa-file me-2 text-primary"></i>
                                                                    <?php echo securiser($document['nom_fichier']); ?>
                                                                </h6>
                                                                <p class="card-text text-muted small mb-1">
                                                                    Type: <?php echo ucfirst(str_replace('_', ' ', $document['type_document'])); ?>
                                                                </p>
                                                                <p class="card-text text-muted small mb-0">
                                                                    Uploadé le: <?php echo date('d/m/Y H:i', strtotime($document['date_upload'])); ?>
                                                                </p>
                                                            </div>
                                                            <div class="btn-group">
                                                                <a href="<?php echo securiser($document['chemin_fichier']); ?>" target="_blank" class="btn btn-sm btn-outline-primary">
                                                                    <i class="fas fa-download"></i>
                                                                </a>
                                                                <?php if (a_la_permission('admin')): ?>
                                                                <button class="btn btn-sm btn-outline-danger" onclick="if(confirm('Supprimer ce document ?')) deleteDocument(<?php echo $document['id']; ?>)">
                                                                    <i class="fas fa-trash"></i>
                                                                </button>
                                                                <?php endif; ?>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php else: ?>
                                    <div class="text-center py-4">
                                        <i class="fas fa-file-contract fa-3x text-muted mb-3"></i>
                                        <h5 class="text-muted">Aucun document</h5>
                                        <p class="text-muted">Aucun document uploadé pour ce membre.</p>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <!-- Onglet Historique Score -->
                    <div class="tab-pane fade" id="historique" role="tabpanel">
                        <div class="card shadow">
                            <div class="card-header bg-light">
                                <h6 class="m-0 font-weight-bold text-primary">
                                    <i class="fas fa-chart-line me-2"></i>Historique du Score de Crédit
                                </h6>
                            </div>
                            <div class="card-body">
                                <?php if (!empty($historique_scores)): ?>
                                    <div class="table-responsive">
                                        <table class="table table-hover">
                                            <thead class="table-light">
                                                <tr>
                                                    <th>Date</th>
                                                    <th>Ancien Score</th>
                                                    <th>Nouveau Score</th>
                                                    <th>Variation</th>
                                                    <th>Type</th>
                                                    <th>Raison</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($historique_scores as $score): ?>
                                                    <?php 
                                                    $variation = $score['nouveau_score'] - $score['ancien_score'];
                                                    $variation_class = $variation > 0 ? 'text-success' : ($variation < 0 ? 'text-danger' : 'text-muted');
                                                    $variation_icon = $variation > 0 ? 'fa-arrow-up' : ($variation < 0 ? 'fa-arrow-down' : 'fa-minus');
                                                    ?>
                                                    <tr>
                                                        <td><?php echo date('d/m/Y H:i', strtotime($score['date_modification'])); ?></td>
                                                        <td><?php echo $score['ancien_score']; ?></td>
                                                        <td>
                                                            <span class="fw-bold"><?php echo $score['nouveau_score']; ?></span>
                                                        </td>
                                                        <td>
                                                            <span class="<?php echo $variation_class; ?>">
                                                                <i class="fas <?php echo $variation_icon; ?> me-1"></i>
                                                                <?php echo abs($variation); ?>
                                                            </span>
                                                        </td>
                                                        <td>
                                                            <span class="badge bg-<?php 
                                                                switch($score['type_modification']) {
                                                                    case 'automatique': echo 'info'; break;
                                                                    case 'manuel': echo 'warning'; break;
                                                                    case 'correction': echo 'secondary'; break;
                                                                    default: echo 'light text-dark';
                                                                }
                                                            ?>">
                                                                <?php echo ucfirst($score['type_modification']); ?>
                                                            </span>
                                                        </td>
                                                        <td><?php echo securiser($score['raison'] ?? 'Non spécifiée'); ?></td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                <?php else: ?>
                                    <div class="text-center py-4">
                                        <i class="fas fa-chart-line fa-3x text-muted mb-3"></i>
                                        <h5 class="text-muted">Aucun historique</h5>
                                        <p class="text-muted">Aucune modification de score enregistrée.</p>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </main>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function deleteDocument(documentId) {
            // Fonction pour supprimer un document
            if (confirm('Êtes-vous sûr de vouloir supprimer ce document ?')) {
                // Envoyer une requête AJAX pour supprimer le document
                fetch('delete_document.php?id=' + documentId, {
                    method: 'DELETE',
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        alert('Document supprimé avec succès');
                        location.reload();
                    } else {
                        alert('Erreur: ' + data.message);
                    }
                })
                .catch(error => {
                    console.error('Erreur:', error);
                    alert('Erreur lors de la suppression du document');
                });
            }
        }
    </script>
</body>
</html>