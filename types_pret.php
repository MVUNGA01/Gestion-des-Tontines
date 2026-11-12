<?php
require_once 'config_database.php';

// Vérifier l'authentification et les permissions
if (!est_connecte()) {
    rediriger('login.php');
}

if (!a_la_permission('admin') && !a_la_permission('gestionnaire')) {
    $_SESSION['erreur'] = "Accès non autorisé";
    rediriger('dashboard.php');
}

$action = $_GET['action'] ?? 'liste';
$id = $_GET['id'] ?? null;
$message = '';
$erreur = '';

try {
    // Traitement des formulaires
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $nom = securiser($_POST['nom'] ?? '');
        $description = securiser($_POST['description'] ?? '');
        $icone = securiser($_POST['icone'] ?? 'fas fa-hand-holding-usd');
        $couleur = securiser($_POST['couleur'] ?? '#27ae60');
        $statut = securiser($_POST['statut'] ?? 'actif');

        if (empty($nom)) {
            throw new Exception("Le nom du type de prêt est obligatoire");
        }

        if ($action === 'ajouter') {
            // Ajouter un nouveau type de prêt
            $stmt = $pdo->prepare("
                INSERT INTO types_pret (nom_type, description, icone, couleur, statut) 
                VALUES (?, ?, ?, ?, ?)
            ");
            $stmt->execute([$nom, $description, $icone, $couleur, $statut]);
            
            $message = "Type de prêt ajouté avec succès";
            $action = 'liste';
            
        } elseif ($action === 'modifier' && $id) {
            // Modifier un type de prêt existant
            $stmt = $pdo->prepare("
                UPDATE types_pret 
                SET nom_type = ?, description = ?, icone = ?, couleur = ?, statut = ?
                WHERE id = ?
            ");
            $stmt->execute([$nom, $description, $icone, $couleur, $statut, $id]);
            
            $message = "Type de prêt modifié avec succès";
            $action = 'liste';
        }
    }

    // Récupérer les données selon l'action
    if ($action === 'liste') {
        // Liste de tous les types de prêt
        $stmt = $pdo->query("
            SELECT * FROM types_pret 
            ORDER BY statut DESC, nom_type ASC
        ");
        $types_pret = $stmt->fetchAll();
        
    } elseif (($action === 'modifier' || $action === 'supprimer') && $id) {
        // Récupérer un type de prêt spécifique
        $stmt = $pdo->prepare("SELECT * FROM types_pret WHERE id = ?");
        $stmt->execute([$id]);
        $type_pret = $stmt->fetch();
        
        if (!$type_pret) {
            throw new Exception("Type de prêt non trouvé");
        }

        // Traitement de la suppression
        if ($action === 'supprimer' && $_SERVER['REQUEST_METHOD'] === 'POST') {
            // Vérifier s'il y a des plans de prêt associés
            $stmt_check = $pdo->prepare("SELECT COUNT(*) FROM plans_pret WHERE type_pret_id = ?");
            $stmt_check->execute([$id]);
            $count = $stmt_check->fetchColumn();
            
            if ($count > 0) {
                throw new Exception("Impossible de supprimer ce type de prêt : il est utilisé dans $count plan(s) de prêt");
            }
            
            $stmt = $pdo->prepare("DELETE FROM types_pret WHERE id = ?");
            $stmt->execute([$id]);
            
            $_SESSION['success'] = "Type de prêt supprimé avec succès";
            rediriger('types_pret.php');
        }
    }

} catch (Exception $e) {
    $erreur = $e->getMessage();
}

// Afficher les messages de session
if (isset($_SESSION['success'])) {
    $message = $_SESSION['success'];
    unset($_SESSION['success']);
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
    <title>Types de Prêt - <?php echo APP_NAME; ?></title>
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
        .statut-badge {
            font-size: 0.8rem;
        }
        .color-preview {
            width: 20px;
            height: 20px;
            border-radius: 50%;
            display: inline-block;
            margin-right: 8px;
            border: 2px solid #dee2e6;
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
                                <a class="nav-link active" href="types_pret.php">
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

                        <!-- Rapports et Administration (identique au dashboard) -->
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
                            <!-- ... liens administration ... -->
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
                <!-- En-tête -->
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <h1>
                        <i class="fas fa-list-alt me-2"></i>Types de Prêt
                    </h1>
                    <?php if ($action === 'liste'): ?>
                        <a href="types_pret.php?action=ajouter" class="btn btn-primary">
                            <i class="fas fa-plus me-2"></i>Nouveau Type
                        </a>
                    <?php else: ?>
                        <a href="types_pret.php" class="btn btn-secondary">
                            <i class="fas fa-arrow-left me-2"></i>Retour à la liste
                        </a>
                    <?php endif; ?>
                </div>

                <!-- Messages -->
                <?php if ($message): ?>
                    <div class="alert alert-success alert-dismissible fade show" role="alert">
                        <?php echo $message; ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <?php if ($erreur): ?>
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        <?php echo $erreur; ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <!-- Contenu selon l'action -->
                <?php if ($action === 'liste'): ?>
                    <!-- Liste des types de prêt -->
                    <div class="card shadow">
                        <div class="card-header bg-primary text-white">
                            <h5 class="mb-0">
                                <i class="fas fa-list me-2"></i>Liste des Types de Prêt
                            </h5>
                        </div>
                        <div class="card-body">
                            <?php if (empty($types_pret)): ?>
                                <div class="text-center py-4">
                                    <i class="fas fa-inbox fa-3x text-muted mb-3"></i>
                                    <p class="text-muted">Aucun type de prêt trouvé</p>
                                    <a href="types_pret.php?action=ajouter" class="btn btn-primary">
                                        <i class="fas fa-plus me-2"></i>Ajouter le premier type
                                    </a>
                                </div>
                            <?php else: ?>
                                <div class="table-responsive">
                                    <table class="table table-striped table-hover">
                                        <thead>
                                            <tr>
                                                <th>Icône</th>
                                                <th>Nom</th>
                                                <th>Couleur</th>
                                                <th>Description</th>
                                                <th>Statut</th>
                                                <th>Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($types_pret as $type): ?>
                                                <tr>
                                                    <td>
                                                        <i class="<?php echo securiser($type['icone']); ?> fa-lg" style="color: <?php echo securiser($type['couleur']); ?>"></i>
                                                    </td>
                                                    <td>
                                                        <strong><?php echo securiser($type['nom_type']); ?></strong>
                                                    </td>
                                                    <td>
                                                        <span class="color-preview" style="background-color: <?php echo securiser($type['couleur']); ?>"></span>
                                                        <?php echo securiser($type['couleur']); ?>
                                                    </td>
                                                    <td><?php echo securiser($type['description'] ?? 'Aucune description'); ?></td>
                                                    <td>
                                                        <span class="badge statut-badge bg-<?php echo $type['statut'] === 'actif' ? 'success' : 'secondary'; ?>">
                                                            <?php echo ucfirst($type['statut']); ?>
                                                        </span>
                                                    </td>
                                                    <td>
                                                        <div class="btn-group btn-group-sm">
                                                            <a href="types_pret.php?action=modifier&id=<?php echo $type['id']; ?>" 
                                                               class="btn btn-outline-primary" title="Modifier">
                                                                <i class="fas fa-edit"></i>
                                                            </a>
                                                            <a href="types_pret.php?action=supprimer&id=<?php echo $type['id']; ?>" 
                                                               class="btn btn-outline-danger" title="Supprimer"
                                                               onclick="return confirm('Êtes-vous sûr de vouloir supprimer ce type de prêt ?')">
                                                                <i class="fas fa-trash"></i>
                                                            </a>
                                                        </div>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                <?php elseif ($action === 'ajouter' || $action === 'modifier'): ?>
                    <!-- Formulaire d'ajout/modification -->
                    <div class="card shadow">
                        <div class="card-header bg-<?php echo $action === 'ajouter' ? 'success' : 'warning'; ?> text-white">
                            <h5 class="mb-0">
                                <i class="fas fa-<?php echo $action === 'ajouter' ? 'plus' : 'edit'; ?> me-2"></i>
                                <?php echo $action === 'ajouter' ? 'Ajouter un Type de Prêt' : 'Modifier le Type de Prêt'; ?>
                            </h5>
                        </div>
                        <div class="card-body">
                            <form method="POST">
                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label for="nom" class="form-label">Nom du type de prêt *</label>
                                            <input type="text" class="form-control" id="nom" name="nom" 
                                                   value="<?php echo isset($type_pret) ? securiser($type_pret['nom_type']) : ''; ?>" 
                                                   required>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label for="statut" class="form-label">Statut</label>
                                            <select class="form-select" id="statut" name="statut">
                                                <option value="actif" <?php echo (isset($type_pret) && $type_pret['statut'] === 'actif') ? 'selected' : ''; ?>>Actif</option>
                                                <option value="inactif" <?php echo (isset($type_pret) && $type_pret['statut'] === 'inactif') ? 'selected' : ''; ?>>Inactif</option>
                                            </select>
                                        </div>
                                    </div>
                                </div>

                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label for="icone" class="form-label">Icône FontAwesome</label>
                                            <input type="text" class="form-control" id="icone" name="icone" 
                                                   value="<?php echo isset($type_pret) ? securiser($type_pret['icone']) : 'fas fa-hand-holding-usd'; ?>"
                                                   placeholder="fas fa-hand-holding-usd">
                                            <div class="form-text">
                                                Exemples: fas fa-home, fas fa-car, fas fa-graduation-cap
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label for="couleur" class="form-label">Couleur</label>
                                            <input type="color" class="form-control" id="couleur" name="couleur" 
                                                   value="<?php echo isset($type_pret) ? securiser($type_pret['couleur']) : '#27ae60'; ?>">
                                        </div>
                                    </div>
                                </div>

                                <div class="mb-3">
                                    <label for="description" class="form-label">Description</label>
                                    <textarea class="form-control" id="description" name="description" rows="3"><?php echo isset($type_pret) ? securiser($type_pret['description']) : ''; ?></textarea>
                                </div>

                                <div class="d-flex justify-content-between">
                                    <a href="types_pret.php" class="btn btn-secondary">
                                        <i class="fas fa-arrow-left me-2"></i>Annuler
                                    </a>
                                    <button type="submit" class="btn btn-<?php echo $action === 'ajouter' ? 'success' : 'warning'; ?>">
                                        <i class="fas fa-<?php echo $action === 'ajouter' ? 'save' : 'edit'; ?> me-2"></i>
                                        <?php echo $action === 'ajouter' ? 'Ajouter' : 'Modifier'; ?>
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>

                <?php elseif ($action === 'supprimer' && isset($type_pret)): ?>
                    <!-- Confirmation de suppression -->
                    <div class="card shadow">
                        <div class="card-header bg-danger text-white">
                            <h5 class="mb-0">
                                <i class="fas fa-exclamation-triangle me-2"></i>Confirmation de suppression
                            </h5>
                        </div>
                        <div class="card-body">
                            <div class="alert alert-warning">
                                <h6 class="alert-heading">
                                    <i class="fas fa-exclamation-circle me-2"></i>Attention !
                                </h6>
                                Vous êtes sur le point de supprimer le type de prêt : 
                                <strong>"<?php echo securiser($type_pret['nom_type']); ?>"</strong>
                            </div>

                            <div class="mb-4">
                                <h6>Détails du type de prêt :</h6>
                                <ul class="list-group">
                                    <li class="list-group-item">
                                        <strong>Nom :</strong> <?php echo securiser($type_pret['nom_type']); ?>
                                    </li>
                                    <li class="list-group-item">
                                        <strong>Description :</strong> <?php echo securiser($type_pret['description'] ?? 'Aucune'); ?>
                                    </li>
                                    <li class="list-group-item">
                                        <strong>Statut :</strong> 
                                        <span class="badge bg-<?php echo $type_pret['statut'] === 'actif' ? 'success' : 'secondary'; ?>">
                                            <?php echo ucfirst($type_pret['statut']); ?>
                                        </span>
                                    </li>
                                </ul>
                            </div>

                            <form method="POST">
                                <div class="d-flex justify-content-between">
                                    <a href="types_pret.php" class="btn btn-secondary">
                                        <i class="fas fa-arrow-left me-2"></i>Annuler
                                    </a>
                                    <button type="submit" class="btn btn-danger" 
                                            onclick="return confirm('Êtes-vous définitivement sûr de vouloir supprimer ce type de prêt ? Cette action est irréversible.')">
                                        <i class="fas fa-trash me-2"></i>Confirmer la suppression
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                <?php endif; ?>
            </main>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>