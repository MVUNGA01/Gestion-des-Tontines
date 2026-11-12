<?php
require_once 'config_database.php';

// Vérifier l'authentification et les permissions
if (!est_connecte()) {
    rediriger('login.php');
}

// Vérifier que l'utilisateur a les permissions nécessaires
if (!a_la_permission('admin') && !a_la_permission('gestionnaire')) {
    $_SESSION['erreur'] = "Vous n'avez pas les permissions nécessaires pour accéder à cette page.";
    rediriger('dashboard.php');
}

$action = $_GET['action'] ?? 'liste';
$id = $_GET['id'] ?? null;
$message = '';
$erreur = '';

try {
    // Traitement des différentes actions
    switch ($action) {
        case 'ajouter':
            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                $code_categorie = securiser($_POST['code_categorie']);
                $nom_categorie = securiser($_POST['nom_categorie']);
                $description = securiser($_POST['description']);
                $avantages = securiser($_POST['avantages']);
                $cotisation_mensuelle = floatval($_POST['cotisation_mensuelle']);
                
                $couleur_theme = securiser($_POST['couleur_theme']);
                $icone = securiser($_POST['icone']);
                $statut = securiser($_POST['statut']);

                // Vérifier si le code catégorie existe déjà
                $stmt = $pdo->prepare("SELECT id FROM categories_membres WHERE code_categorie = ?");
                $stmt->execute([$code_categorie]);
                
                if ($stmt->fetch()) {
                    $erreur = "Le code catégorie existe déjà.";
                } else {
                    $stmt = $pdo->prepare("INSERT INTO categories_membres 
                        (code_categorie, nom_categorie, description, avantages, cotisation_mensuelle, 
                         couleur_theme, icone, statut) 
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                    
                    if ($stmt->execute([
                        $code_categorie, $nom_categorie, $description, $avantages, 
                        $cotisation_mensuelle, $couleur_theme, $icone, $statut
                    ])) {
                        $_SESSION['succes'] = "Catégorie ajoutée avec succès.";
                        rediriger('categories_membres.php');
                    } else {
                        $erreur = "Erreur lors de l'ajout de la catégorie.";
                    }
                }
            }
            break;

        case 'modifier':
            if ($id) {
                if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                    $nom_categorie = securiser($_POST['nom_categorie']);
                    $description = securiser($_POST['description']);
                    $avantages = securiser($_POST['avantages']);
                    $cotisation_mensuelle = floatval($_POST['cotisation_mensuelle']);
                    
                    $couleur_theme = securiser($_POST['couleur_theme']);
                    $icone = securiser($_POST['icone']);
                    $statut = securiser($_POST['statut']);

                    $stmt = $pdo->prepare("UPDATE categories_membres 
                        SET nom_categorie = ?, description = ?, avantages = ?, cotisation_mensuelle = ?,
                            couleur_theme = ?, icone = ?, statut = ?
                        WHERE id = ?");
                    
                    if ($stmt->execute([
                        $nom_categorie, $description, $avantages, $cotisation_mensuelle,
                        $couleur_theme, $icone, $statut, $id
                    ])) {
                        $_SESSION['succes'] = "Catégorie modifiée avec succès.";
                        rediriger('categories_membres.php');
                    } else {
                        $erreur = "Erreur lors de la modification de la catégorie.";
                    }
                } else {
                    // Récupérer la catégorie pour affichage dans le formulaire
                    $stmt = $pdo->prepare("SELECT * FROM categories_membres WHERE id = ?");
                    $stmt->execute([$id]);
                    $categorie = $stmt->fetch();
                    
                    if (!$categorie) {
                        $_SESSION['erreur'] = "Catégorie non trouvée.";
                        rediriger('categories_membres.php');
                    }
                }
            }
            break;

        case 'supprimer':
            if ($id) {
                // Vérifier si la catégorie est utilisée par des membres
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM membres WHERE categorie_id = ?");
                $stmt->execute([$id]);
                $count = $stmt->fetchColumn();
                
                if ($count > 0) {
                    $_SESSION['erreur'] = "Impossible de supprimer cette catégorie car elle est utilisée par $count membre(s).";
                } else {
                    $stmt = $pdo->prepare("DELETE FROM categories_membres WHERE id = ?");
                    if ($stmt->execute([$id])) {
                        $_SESSION['succes'] = "Catégorie supprimée avec succès.";
                    } else {
                        $_SESSION['erreur'] = "Erreur lors de la suppression de la catégorie.";
                    }
                }
                rediriger('categories_membres.php');
            }
            break;

        case 'liste':
        default:
            // Récupérer toutes les catégories
            $stmt = $pdo->query("SELECT * FROM categories_membres ORDER BY date_creation DESC");
            $categories = $stmt->fetchAll();
            break;
    }

    // Récupérer les messages de session
    if (isset($_SESSION['succes'])) {
        $message = $_SESSION['succes'];
        unset($_SESSION['succes']);
    }
    if (isset($_SESSION['erreur'])) {
        $erreur = $_SESSION['erreur'];
        unset($_SESSION['erreur']);
    }

} catch (PDOException $e) {
    $erreur = "Erreur base de données: " . $e->getMessage();
}
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Catégories Membres - <?php echo APP_NAME; ?></title>
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
        .statut-badge {
            font-size: 0.8rem;
        }
        .categorie-card {
            border-left: 4px solid;
            transition: transform 0.2s;
        }
        .categorie-card:hover {
            transform: translateY(-2px);
        }
        .info-badge {
            font-size: 0.75rem;
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
                                <a class="nav-link active" href="categories_membres.php">
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
                            <!-- ... éléments d'administration ... -->
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
                <!-- En-tête de page -->
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <div>
                        <h1 class="h3 mb-0">
                            <i class="fas fa-tags me-2"></i>Catégories de Membres
                        </h1>
                        <p class="text-muted mb-0">Gestion des différentes catégories de membres</p>
                    </div>
                    <div>
                        <a href="categories_membres.php?action=ajouter" class="btn btn-primary">
                            <i class="fas fa-plus me-2"></i>Nouvelle Catégorie
                        </a>
                    </div>
                </div>

                <!-- Messages d'alerte -->
                <?php if ($message): ?>
                    <div class="alert alert-success alert-dismissible fade show" role="alert">
                        <i class="fas fa-check-circle me-2"></i><?php echo $message; ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <?php if ($erreur): ?>
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        <i class="fas fa-exclamation-triangle me-2"></i><?php echo $erreur; ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <!-- Contenu selon l'action -->
                <?php if ($action === 'ajouter' || $action === 'modifier'): ?>
                    <!-- Formulaire d'ajout/modification -->
                    <div class="card shadow">
                        <div class="card-header bg-primary text-white">
                            <h5 class="card-title mb-0">
                                <i class="fas <?php echo $action === 'ajouter' ? 'fa-plus' : 'fa-edit'; ?> me-2"></i>
                                <?php echo $action === 'ajouter' ? 'Ajouter une Catégorie' : 'Modifier la Catégorie'; ?>
                            </h5>
                        </div>
                        <div class="card-body">
                            <form method="POST" action="categories_membres.php?action=<?php echo $action; ?><?php echo $id ? '&id=' . $id : ''; ?>">
                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label for="code_categorie" class="form-label">Code Catégorie *</label>
                                            <input type="text" class="form-control" id="code_categorie" name="code_categorie" 
                                                   value="<?php echo $action === 'modifier' ? securiser($categorie['code_categorie']) : ''; ?>" 
                                                   <?php echo $action === 'modifier' ? 'readonly' : ''; ?> required>
                                            <div class="form-text">Code unique pour identifier la catégorie</div>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label for="nom_categorie" class="form-label">Nom de la Catégorie *</label>
                                            <input type="text" class="form-control" id="nom_categorie" name="nom_categorie" 
                                                   value="<?php echo $action === 'modifier' ? securiser($categorie['nom_categorie']) : ''; ?>" required>
                                        </div>
                                    </div>
                                </div>

                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label for="cotisation_mensuelle" class="form-label">Cotisation Mensuelle (FBu) *</label>
                                            <input type="number" step="1000" class="form-control" id="cotisation_mensuelle" name="cotisation_mensuelle" 
                                                   value="<?php echo $action === 'modifier' ? securiser($categorie['cotisation_mensuelle']) : ''; ?>" required>
                                        </div>
                                    </div>
                                </div>

                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label for="couleur_theme" class="form-label">Couleur du Thème</label>
                                            <input type="color" class="form-control" id="couleur_theme" name="couleur_theme" 
                                                   value="<?php echo $action === 'modifier' ? securiser($categorie['couleur_theme']) : '#3498db'; ?>">
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label for="icone" class="form-label">Icône FontAwesome</label>
                                            <input type="text" class="form-control" id="icone" name="icone" 
                                                   value="<?php echo $action === 'modifier' ? securiser($categorie['icone']) : 'fas fa-user'; ?>">
                                            <div class="form-text">Ex: fas fa-user, fas fa-crown, fas fa-gem</div>
                                        </div>
                                    </div>
                                </div>

                                <div class="mb-3">
                                    <label for="description" class="form-label">Description</label>
                                    <textarea class="form-control" id="description" name="description" rows="3"><?php echo $action === 'modifier' ? securiser($categorie['description']) : ''; ?></textarea>
                                </div>

                                <div class="mb-3">
                                    <label for="avantages" class="form-label">Avantages</label>
                                    <textarea class="form-control" id="avantages" name="avantages" rows="3"><?php echo $action === 'modifier' ? securiser($categorie['avantages']) : ''; ?></textarea>
                                    <div class="form-text">Liste des avantages spécifiques à cette catégorie</div>
                                </div>

                                <div class="mb-3">
                                    <label for="statut" class="form-label">Statut</label>
                                    <select class="form-select" id="statut" name="statut" required>
                                        <option value="actif" <?php echo ($action === 'modifier' && $categorie['statut'] === 'actif') ? 'selected' : ''; ?>>Actif</option>
                                        <option value="inactif" <?php echo ($action === 'modifier' && $categorie['statut'] === 'inactif') ? 'selected' : ''; ?>>Inactif</option>
                                    </select>
                                </div>

                                <div class="d-flex justify-content-between">
                                    <a href="categories_membres.php" class="btn btn-secondary">
                                        <i class="fas fa-arrow-left me-2"></i>Retour
                                    </a>
                                    <button type="submit" class="btn btn-primary">
                                        <i class="fas fa-save me-2"></i>
                                        <?php echo $action === 'ajouter' ? 'Ajouter' : 'Modifier'; ?>
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>

                <?php else: ?>
                    <!-- Liste des catégories -->
                    <div class="card shadow">
                        <div class="card-header bg-light">
                            <h5 class="card-title mb-0">
                                <i class="fas fa-list me-2"></i>Liste des Catégories
                            </h5>
                        </div>
                        <div class="card-body">
                            <?php if (empty($categories)): ?>
                                <div class="text-center py-4">
                                    <i class="fas fa-tags fa-3x text-muted mb-3"></i>
                                    <p class="text-muted">Aucune catégorie trouvée</p>
                                    <a href="categories_membres.php?action=ajouter" class="btn btn-primary">
                                        <i class="fas fa-plus me-2"></i>Créer la première catégorie
                                    </a>
                                </div>
                            <?php else: ?>
                                <div class="table-responsive">
                                    <table class="table table-striped table-hover">
                                        <thead>
                                            <tr>
                                                <th>Code</th>
                                                <th>Nom</th>
                                                <th>Cotisation</th>
                                                <th>Membres</th>
                                                <th>Statut</th>
                                                <th>Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($categories as $cat): ?>
                                                <tr>
                                                    <td>
                                                        <span class="badge" style="background-color: <?php echo securiser($cat['couleur_theme']); ?>; color: white;">
                                                            <i class="<?php echo securiser($cat['icone']); ?> me-1"></i>
                                                            <?php echo securiser($cat['code_categorie']); ?>
                                                        </span>
                                                    </td>
                                                    <td>
                                                        <strong><?php echo securiser($cat['nom_categorie']); ?></strong>
                                                        <?php if ($cat['description']): ?>
                                                            <br><small class="text-muted"><?php echo substr(securiser($cat['description']), 0, 50); ?>...</small>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <strong><?php echo number_format($cat['cotisation_mensuelle'], 0, ',', ' '); ?> FBu</strong>
                                                        <br><small class="text-muted">/mois</small>
                                                    </td>
                                                    <td>
                                                        <?php
                                                        // Compter les membres dans cette catégorie
                                                        $stmt = $pdo->prepare("SELECT COUNT(*) FROM membres WHERE categorie_id = ?");
                                                        $stmt->execute([$cat['id']]);
                                                        $nb_membres = $stmt->fetchColumn();
                                                        ?>
                                                        <span class="badge bg-primary info-badge"><?php echo $nb_membres; ?> membres</span>
                                                    </td>
                                                    <td>
                                                        <span class="badge <?php echo $cat['statut'] === 'actif' ? 'bg-success' : 'bg-secondary'; ?> statut-badge">
                                                            <?php echo securiser($cat['statut']); ?>
                                                        </span>
                                                    </td>
                                                    <td>
                                                        <div class="btn-group btn-group-sm">
                                                            <a href="categories_membres.php?action=modifier&id=<?php echo securiser($cat['id']); ?>" 
                                                               class="btn btn-outline-primary" title="Modifier">
                                                                <i class="fas fa-edit"></i>
                                                            </a>
                                                            <?php if ($nb_membres == 0): ?>
                                                                <a href="categories_membres.php?action=supprimer&id=<?php echo securiser($cat['id']); ?>" 
                                                                   class="btn btn-outline-danger" title="Supprimer"
                                                                   onclick="return confirm('Êtes-vous sûr de vouloir supprimer cette catégorie ?')">
                                                                    <i class="fas fa-trash"></i>
                                                                </a>
                                                            <?php else: ?>
                                                                <button class="btn btn-outline-secondary" title="Impossible de supprimer (utilisée par <?php echo $nb_membres; ?> membre(s))" disabled>
                                                                    <i class="fas fa-trash"></i>
                                                                </button>
                                                            <?php endif; ?>
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
                <?php endif; ?>
            </main>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>