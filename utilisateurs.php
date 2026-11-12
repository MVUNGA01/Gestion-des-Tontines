<?php
require_once 'config_database.php';

// Vérifier l'authentification et les permissions admin
if (!est_connecte()) {
    rediriger('login.php');
}

// Seuls les administrateurs peuvent accéder à cette page
if (!a_la_permission('admin')) {
    $_SESSION['erreur'] = "Accès refusé. Permissions administrateur requises.";
    rediriger('dashboard.php');
}

// Variables pour la gestion des utilisateurs
$erreur = '';
$succes = '';

// Traitement de la création d'un nouvel utilisateur
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['creer_utilisateur'])) {
    try {
        $username = securiser($_POST['username']);
        $nom_complet = securiser($_POST['nom_complet']);
        $email = securiser($_POST['email']);
        $role = securiser($_POST['role']);
        $statut = securiser($_POST['statut']);
        
        // Générer un mot de passe temporaire
        $mot_de_passe_temporaire = generer_mot_de_passe_temporaire();
        $password_hash = password_hash($mot_de_passe_temporaire, PASSWORD_DEFAULT);
        
        // Vérifier si l'username ou l'email existe déjà
        $stmt_verif = $pdo->prepare("SELECT id FROM utilisateurs WHERE username = ? OR email = ?");
        $stmt_verif->execute([$username, $email]);
        
        if ($stmt_verif->fetch()) {
            $erreur = "Un utilisateur avec ce nom d'utilisateur ou cet email existe déjà.";
        } else {
            // Insérer le nouvel utilisateur
            $stmt = $pdo->prepare("
                INSERT INTO utilisateurs (username, password_hash, nom_complet, email, role, statut, photo_profil)
                VALUES (?, ?, ?, ?, ?, ?, NULL)
            ");
            
            $stmt->execute([$username, $password_hash, $nom_complet, $email, $role, $statut]);
            
            $succes = "Utilisateur créé avec succès. Mot de passe temporaire: <strong>$mot_de_passe_temporaire</strong>";
        }
    } catch (PDOException $e) {
        $erreur = "Erreur lors de la création de l'utilisateur: " . $e->getMessage();
    }
}

// Traitement de la modification d'un utilisateur
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['modifier_utilisateur'])) {
    try {
        $user_id = securiser($_POST['user_id']);
        $nom_complet = securiser($_POST['nom_complet']);
        $email = securiser($_POST['email']);
        $role = securiser($_POST['role']);
        $statut = securiser($_POST['statut']);
        
        $stmt = $pdo->prepare("
            UPDATE utilisateurs 
            SET nom_complet = ?, email = ?, role = ?, statut = ?, date_modification = NOW()
            WHERE id = ?
        ");
        
        $stmt->execute([$nom_complet, $email, $role, $statut, $user_id]);
        
        $succes = "Utilisateur modifié avec succès.";
    } catch (PDOException $e) {
        $erreur = "Erreur lors de la modification de l'utilisateur: " . $e->getMessage();
    }
}

// Traitement de la réinitialisation du mot de passe
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['reinitialiser_mdp'])) {
    try {
        $user_id = securiser($_POST['user_id']);
        $nouveau_mot_de_passe = generer_mot_de_passe_temporaire();
        $password_hash = password_hash($nouveau_mot_de_passe, PASSWORD_DEFAULT);
        
        $stmt = $pdo->prepare("
            UPDATE utilisateurs 
            SET password_hash = ?, date_modification = NOW()
            WHERE id = ?
        ");
        
        $stmt->execute([$password_hash, $user_id]);
        
        $succes = "Mot de passe réinitialisé avec succès. Nouveau mot de passe: <strong>$nouveau_mot_de_passe</strong>";
    } catch (PDOException $e) {
        $erreur = "Erreur lors de la réinitialisation du mot de passe: " . $e->getMessage();
    }
}

// Traitement de la suppression d'un utilisateur
if (isset($_GET['supprimer'])) {
    try {
        $user_id = securiser($_GET['supprimer']);
        
        // Empêcher la suppression de l'utilisateur admin principal
        if ($user_id == 1) {
            $erreur = "Impossible de supprimer l'administrateur principal.";
        } else {
            $stmt = $pdo->prepare("DELETE FROM utilisateurs WHERE id = ?");
            $stmt->execute([$user_id]);
            
            $succes = "Utilisateur supprimé avec succès.";
        }
    } catch (PDOException $e) {
        $erreur = "Erreur lors de la suppression de l'utilisateur: " . $e->getMessage();
    }
}

// Récupérer la liste des utilisateurs
try {
    $stmt_utilisateurs = $pdo->query("
        SELECT id, username, nom_complet, email, role, statut, date_creation, derniere_connexion, photo_profil
        FROM utilisateurs 
        ORDER BY date_creation DESC
    ");
    $utilisateurs = $stmt_utilisateurs->fetchAll();
    
    // Statistiques des utilisateurs
    $stmt_stats = $pdo->query("
        SELECT 
            COUNT(*) as total,
            SUM(CASE WHEN statut = 'actif' THEN 1 ELSE 0 END) as actifs,
            SUM(CASE WHEN statut = 'inactif' THEN 1 ELSE 0 END) as inactifs,
            SUM(CASE WHEN statut = 'suspendu' THEN 1 ELSE 0 END) as suspendus,
            COUNT(DISTINCT role) as roles_diff
        FROM utilisateurs
    ");
    $stats_utilisateurs = $stmt_stats->fetch();
    
} catch (PDOException $e) {
    $erreur = "Erreur lors du chargement des utilisateurs: " . $e->getMessage();
}

// Fonction pour générer un mot de passe temporaire
function generer_mot_de_passe_temporaire($longueur = 10) {
    $caracteres = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
    $mot_de_passe = '';
    for ($i = 0; $i < $longueur; $i++) {
        $mot_de_passe .= $caracteres[rand(0, strlen($caracteres) - 1)];
    }
    return $mot_de_passe;
}
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestion des Utilisateurs - <?php echo APP_NAME; ?></title>
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
        .welcome-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 2rem;
            border-radius: 10px;
            margin-bottom: 2rem;
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
        .badge-role {
            font-size: 0.7rem;
            padding: 4px 8px;
        }
        .table-actions {
            white-space: nowrap;
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
                                <a class="nav-link active" href="utilisateurs.php">
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
                <div class="welcome-header">
                    <div class="row align-items-center">
                        <div class="col">
                            <h1>Gestion des Utilisateurs</h1>
                            <p class="mb-0">Administration des comptes utilisateurs du système</p>
                        </div>
                        <div class="col-auto">
                            <span class="badge bg-light text-dark">
                                <i class="fas fa-user-shield me-1"></i><?php echo ucfirst($_SESSION['user_role']); ?>
                            </span>
                        </div>
                    </div>
                </div>

                <!-- Messages d'alerte -->
                <?php if ($erreur): ?>
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        <i class="fas fa-exclamation-triangle me-2"></i><?php echo $erreur; ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <?php if ($succes): ?>
                    <div class="alert alert-success alert-dismissible fade show" role="alert">
                        <i class="fas fa-check-circle me-2"></i><?php echo $succes; ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <!-- Statistiques des utilisateurs -->
                <div class="row mb-4">
                    <div class="col-xl-3 col-md-6 mb-4">
                        <div class="card stat-card border-left-primary shadow h-100 py-2">
                            <div class="card-body">
                                <div class="row no-gutters align-items-center">
                                    <div class="col mr-2">
                                        <div class="text-xs font-weight-bold text-primary text-uppercase mb-1">
                                            Total Utilisateurs
                                        </div>
                                        <div class="h5 mb-0 font-weight-bold text-gray-800">
                                            <?php echo number_format($stats_utilisateurs['total']); ?>
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
                                            Utilisateurs Actifs
                                        </div>
                                        <div class="h5 mb-0 font-weight-bold text-gray-800">
                                            <?php echo number_format($stats_utilisateurs['actifs']); ?>
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
                        <div class="card stat-card border-left-warning shadow h-100 py-2">
                            <div class="card-body">
                                <div class="row no-gutters align-items-center">
                                    <div class="col mr-2">
                                        <div class="text-xs font-weight-bold text-warning text-uppercase mb-1">
                                            Rôles Différents
                                        </div>
                                        <div class="h5 mb-0 font-weight-bold text-gray-800">
                                            <?php echo number_format($stats_utilisateurs['roles_diff']); ?>
                                        </div>
                                    </div>
                                    <div class="col-auto">
                                        <i class="fas fa-user-tag fa-2x text-gray-300"></i>
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
                                            Inactifs/Suspendus
                                        </div>
                                        <div class="h5 mb-0 font-weight-bold text-gray-800">
                                            <?php echo number_format($stats_utilisateurs['inactifs'] + $stats_utilisateurs['suspendus']); ?>
                                        </div>
                                    </div>
                                    <div class="col-auto">
                                        <i class="fas fa-user-slash fa-2x text-gray-300"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="row">
                    <!-- Formulaire de création d'utilisateur -->
                    <div class="col-lg-4 mb-4">
                        <div class="card shadow h-100">
                            <div class="card-header bg-primary text-white">
                                <h6 class="m-0 font-weight-bold">
                                    <i class="fas fa-user-plus me-2"></i>Nouvel Utilisateur
                                </h6>
                            </div>
                            <div class="card-body">
                                <form method="POST" action="">
                                    <div class="mb-3">
                                        <label for="username" class="form-label">Nom d'utilisateur *</label>
                                        <input type="text" class="form-control" id="username" name="username" required>
                                    </div>
                                    <div class="mb-3">
                                        <label for="nom_complet" class="form-label">Nom complet *</label>
                                        <input type="text" class="form-control" id="nom_complet" name="nom_complet" required>
                                    </div>
                                    <div class="mb-3">
                                        <label for="email" class="form-label">Email *</label>
                                        <input type="email" class="form-control" id="email" name="email" required>
                                    </div>
                                    <div class="mb-3">
                                        <label for="role" class="form-label">Rôle *</label>
                                        <select class="form-control" id="role" name="role" required>
                                            <option value="admin">Administrateur</option>
                                            <option value="gestionnaire">Gestionnaire</option>
                                            <option value="caissier">Caissier</option>
                                            <option value="analyste">Analyste</option>
                                        </select>
                                    </div>
                                    <div class="mb-3">
                                        <label for="statut" class="form-label">Statut *</label>
                                        <select class="form-control" id="statut" name="statut" required>
                                            <option value="actif">Actif</option>
                                            <option value="inactif">Inactif</option>
                                            <option value="suspendu">Suspendu</option>
                                        </select>
                                    </div>
                                    <button type="submit" name="creer_utilisateur" class="btn btn-primary w-100">
                                        <i class="fas fa-plus me-2"></i>Créer l'utilisateur
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>

                    <!-- Liste des utilisateurs -->
                    <div class="col-lg-8 mb-4">
                        <div class="card shadow h-100">
                            <div class="card-header bg-success text-white d-flex justify-content-between align-items-center">
                                <h6 class="m-0 font-weight-bold">
                                    <i class="fas fa-list me-2"></i>Liste des Utilisateurs
                                </h6>
                                <span class="badge bg-light text-dark"><?php echo count($utilisateurs); ?> utilisateurs</span>
                            </div>
                            <div class="card-body">
                                <?php if (!empty($utilisateurs)): ?>
                                    <div class="table-responsive">
                                        <table class="table table-striped table-hover">
                                            <thead>
                                                <tr>
                                                    <th>Utilisateur</th>
                                                    <th>Rôle</th>
                                                    <th>Statut</th>
                                                    <th>Dernière connexion</th>
                                                    <th>Actions</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($utilisateurs as $utilisateur): ?>
                                                    <tr>
                                                        <td>
                                                            <div class="d-flex align-items-center">
                                                                <div class="flex-shrink-0">
                                                                    <i class="fas fa-user-circle fa-2x text-secondary"></i>
                                                                </div>
                                                                <div class="flex-grow-1 ms-3">
                                                                    <strong><?php echo securiser($utilisateur['nom_complet']); ?></strong>
                                                                    <div class="text-muted small">
                                                                        <?php echo securiser($utilisateur['username']); ?>
                                                                    </div>
                                                                    <div class="text-muted small">
                                                                        <?php echo securiser($utilisateur['email']); ?>
                                                                    </div>
                                                                </div>
                                                            </div>
                                                        </td>
                                                        <td>
                                                            <span class="badge badge-role bg-<?php 
                                                                switch($utilisateur['role']) {
                                                                    case 'admin': echo 'danger'; break;
                                                                    case 'gestionnaire': echo 'warning'; break;
                                                                    case 'caissier': echo 'info'; break;
                                                                    case 'analyste': echo 'success'; break;
                                                                    default: echo 'secondary';
                                                                }
                                                            ?>">
                                                                <?php echo ucfirst($utilisateur['role']); ?>
                                                            </span>
                                                        </td>
                                                        <td>
                                                            <span class="badge bg-<?php 
                                                                switch($utilisateur['statut']) {
                                                                    case 'actif': echo 'success'; break;
                                                                    case 'inactif': echo 'secondary'; break;
                                                                    case 'suspendu': echo 'danger'; break;
                                                                    default: echo 'secondary';
                                                                }
                                                            ?>">
                                                                <?php echo ucfirst($utilisateur['statut']); ?>
                                                            </span>
                                                        </td>
                                                        <td>
                                                            <?php if ($utilisateur['derniere_connexion']): ?>
                                                                <?php echo date('d/m/Y H:i', strtotime($utilisateur['derniere_connexion'])); ?>
                                                            <?php else: ?>
                                                                <span class="text-muted">Jamais</span>
                                                            <?php endif; ?>
                                                        </td>
                                                        <td class="table-actions">
                                                            <!-- Bouton Modifier -->
                                                            <button type="button" class="btn btn-sm btn-outline-primary" 
                                                                    data-bs-toggle="modal" 
                                                                    data-bs-target="#modalModifier<?php echo $utilisateur['id']; ?>">
                                                                <i class="fas fa-edit"></i>
                                                            </button>
                                                            
                                                            <!-- Bouton Réinitialiser MDP -->
                                                            <button type="button" class="btn btn-sm btn-outline-warning" 
                                                                    data-bs-toggle="modal" 
                                                                    data-bs-target="#modalResetMDP<?php echo $utilisateur['id']; ?>">
                                                                <i class="fas fa-key"></i>
                                                            </button>
                                                            
                                                            <!-- Bouton Supprimer (sauf pour admin principal) -->
                                                            <?php if ($utilisateur['id'] != 1): ?>
                                                                <a href="utilisateurs.php?supprimer=<?php echo $utilisateur['id']; ?>" 
                                                                   class="btn btn-sm btn-outline-danger"
                                                                   onclick="return confirm('Êtes-vous sûr de vouloir supprimer cet utilisateur ?')">
                                                                    <i class="fas fa-trash"></i>
                                                                </a>
                                                            <?php else: ?>
                                                                <button class="btn btn-sm btn-outline-secondary" disabled>
                                                                    <i class="fas fa-trash"></i>
                                                                </button>
                                                            <?php endif; ?>
                                                        </td>
                                                    </tr>

                                                    <!-- Modal de modification -->
                                                    <div class="modal fade" id="modalModifier<?php echo $utilisateur['id']; ?>" tabindex="-1">
                                                        <div class="modal-dialog">
                                                            <div class="modal-content">
                                                                <form method="POST" action="">
                                                                    <div class="modal-header">
                                                                        <h5 class="modal-title">Modifier l'utilisateur</h5>
                                                                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                                                    </div>
                                                                    <div class="modal-body">
                                                                        <input type="hidden" name="user_id" value="<?php echo $utilisateur['id']; ?>">
                                                                        
                                                                        <div class="mb-3">
                                                                            <label class="form-label">Nom complet</label>
                                                                            <input type="text" class="form-control" name="nom_complet" 
                                                                                   value="<?php echo securiser($utilisateur['nom_complet']); ?>" required>
                                                                        </div>
                                                                        
                                                                        <div class="mb-3">
                                                                            <label class="form-label">Email</label>
                                                                            <input type="email" class="form-control" name="email" 
                                                                                   value="<?php echo securiser($utilisateur['email']); ?>" required>
                                                                        </div>
                                                                        
                                                                        <div class="mb-3">
                                                                            <label class="form-label">Rôle</label>
                                                                            <select class="form-control" name="role" required>
                                                                                <option value="admin" <?php echo $utilisateur['role'] == 'admin' ? 'selected' : ''; ?>>Administrateur</option>
                                                                                <option value="gestionnaire" <?php echo $utilisateur['role'] == 'gestionnaire' ? 'selected' : ''; ?>>Gestionnaire</option>
                                                                                <option value="caissier" <?php echo $utilisateur['role'] == 'caissier' ? 'selected' : ''; ?>>Caissier</option>
                                                                                <option value="analyste" <?php echo $utilisateur['role'] == 'analyste' ? 'selected' : ''; ?>>Analyste</option>
                                                                            </select>
                                                                        </div>
                                                                        
                                                                        <div class="mb-3">
                                                                            <label class="form-label">Statut</label>
                                                                            <select class="form-control" name="statut" required>
                                                                                <option value="actif" <?php echo $utilisateur['statut'] == 'actif' ? 'selected' : ''; ?>>Actif</option>
                                                                                <option value="inactif" <?php echo $utilisateur['statut'] == 'inactif' ? 'selected' : ''; ?>>Inactif</option>
                                                                                <option value="suspendu" <?php echo $utilisateur['statut'] == 'suspendu' ? 'selected' : ''; ?>>Suspendu</option>
                                                                            </select>
                                                                        </div>
                                                                    </div>
                                                                    <div class="modal-footer">
                                                                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annuler</button>
                                                                        <button type="submit" name="modifier_utilisateur" class="btn btn-primary">Enregistrer</button>
                                                                    </div>
                                                                </form>
                                                            </div>
                                                        </div>
                                                    </div>

                                                    <!-- Modal de réinitialisation de mot de passe -->
                                                    <div class="modal fade" id="modalResetMDP<?php echo $utilisateur['id']; ?>" tabindex="-1">
                                                        <div class="modal-dialog">
                                                            <div class="modal-content">
                                                                <form method="POST" action="">
                                                                    <div class="modal-header">
                                                                        <h5 class="modal-title">Réinitialiser le mot de passe</h5>
                                                                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                                                    </div>
                                                                    <div class="modal-body">
                                                                        <input type="hidden" name="user_id" value="<?php echo $utilisateur['id']; ?>">
                                                                        <p>Êtes-vous sûr de vouloir réinitialiser le mot de passe de <strong><?php echo securiser($utilisateur['nom_complet']); ?></strong> ?</p>
                                                                        <p class="text-warning"><i class="fas fa-exclamation-triangle me-2"></i>Un nouveau mot de passe temporaire sera généré.</p>
                                                                    </div>
                                                                    <div class="modal-footer">
                                                                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annuler</button>
                                                                        <button type="submit" name="reinitialiser_mdp" class="btn btn-warning">Réinitialiser</button>
                                                                    </div>
                                                                </form>
                                                            </div>
                                                        </div>
                                                    </div>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                <?php else: ?>
                                    <p class="text-muted text-center my-3">Aucun utilisateur trouvé</p>
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
        // Auto-actualisation toutes les 10 minutes
        setTimeout(function() {
            window.location.reload();
        }, 600000);

        // Animation des cartes statistiques
        document.addEventListener('DOMContentLoaded', function() {
            const statCards = document.querySelectorAll('.stat-card');
            statCards.forEach((card, index) => {
                setTimeout(() => {
                    card.style.opacity = '0';
                    card.style.transform = 'translateY(20px)';
                    card.style.transition = 'all 0.5s ease';
                    
                    setTimeout(() => {
                        card.style.opacity = '1';
                        card.style.transform = 'translateY(0)';
                    }, 100);
                }, index * 100);
            });
        });
    </script>
</body>
</html>