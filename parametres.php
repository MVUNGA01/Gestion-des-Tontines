<?php
require_once 'config_database.php';

// Vérifier l'authentification et les permissions admin
if (!est_connecte()) {
    rediriger('login.php');
}

if (!a_la_permission('admin')) {
    $_SESSION['erreur'] = "Accès refusé. Permissions administrateur requises.";
    rediriger('dashboard.php');
}

// Variables pour le traitement des formulaires
$message_succes = '';
$message_erreur = '';

// Traitement de la modification d'un paramètre
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['modifier_parametre'])) {
    try {
        $parametre_id = $_POST['parametre_id'];
        $nouvelle_valeur = $_POST['valeur'];
        
        // Vérifier et formater la valeur selon le type
        $stmt = $pdo->prepare("SELECT type_valeur FROM parametres_systeme WHERE id = ?");
        $stmt->execute([$parametre_id]);
        $parametre = $stmt->fetch();
        
        if ($parametre) {
            switch ($parametre['type_valeur']) {
                case 'nombre':
                    $nouvelle_valeur = intval($nouvelle_valeur);
                    break;
                case 'decimal':
                    $nouvelle_valeur = floatval($nouvelle_valeur);
                    break;
                case 'booleen':
                    $nouvelle_valeur = ($nouvelle_valeur === 'true' || $nouvelle_valeur === '1') ? 'true' : 'false';
                    break;
            }
            
            // Mettre à jour le paramètre
            $stmt = $pdo->prepare("UPDATE parametres_systeme SET valeur = ?, date_mise_a_jour = NOW() WHERE id = ?");
            $stmt->execute([$nouvelle_valeur, $parametre_id]);
            
            $message_succes = "Paramètre mis à jour avec succès.";
        }
    } catch (PDOException $e) {
        $message_erreur = "Erreur lors de la mise à jour: " . $e->getMessage();
    }
}

// Traitement de l'ajout d'un nouveau paramètre
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajouter_parametre'])) {
    try {
        $cle = $_POST['cle'];
        $valeur = $_POST['valeur'];
        $type_valeur = $_POST['type_valeur'];
        $description = $_POST['description'];
        $categorie = $_POST['categorie'];
        $modifiable = isset($_POST['modifiable']) ? 1 : 0;
        
        // Vérifier si la clé existe déjà
        $stmt = $pdo->prepare("SELECT id FROM parametres_systeme WHERE cle = ?");
        $stmt->execute([$cle]);
        
        if ($stmt->fetch()) {
            $message_erreur = "Cette clé de paramètre existe déjà.";
        } else {
            // Insérer le nouveau paramètre
            $stmt = $pdo->prepare("INSERT INTO parametres_systeme (cle, valeur, type_valeur, description, categorie, modifiable) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->execute([$cle, $valeur, $type_valeur, $description, $categorie, $modifiable]);
            
            $message_succes = "Nouveau paramètre ajouté avec succès.";
        }
    } catch (PDOException $e) {
        $message_erreur = "Erreur lors de l'ajout: " . $e->getMessage();
    }
}

// Récupérer tous les paramètres groupés par catégorie
try {
    $stmt = $pdo->query("
        SELECT * FROM parametres_systeme 
        ORDER BY categorie, cle
    ");
    $parametres = $stmt->fetchAll();
    
    // Grouper par catégorie
    $parametres_par_categorie = [];
    foreach ($parametres as $param) {
        $parametres_par_categorie[$param['categorie']][] = $param;
    }
    
} catch (PDOException $e) {
    $message_erreur = "Erreur lors du chargement des paramètres: " . $e->getMessage();
}
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Paramètres Système - <?php echo APP_NAME; ?></title>
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
        .param-card {
            border-left: 4px solid #3498db;
            transition: all 0.3s ease;
        }
        .param-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0,0,0,0.1);
        }
        .categorie-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 1rem;
            border-radius: 8px;
            margin-bottom: 1rem;
        }
        .badge-categorie {
            font-size: 0.8rem;
            padding: 0.4rem 0.8rem;
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
                                <a class="nav-link active" href="parametres.php">
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
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <div>
                        <h1><i class="fas fa-cogs me-2"></i>Paramètres Système</h1>
                        <p class="text-muted">Gérez les paramètres de configuration du système</p>
                    </div>
                    <span class="badge bg-primary fs-6">
                        <i class="fas fa-user-shield me-1"></i><?php echo ucfirst($_SESSION['user_role']); ?>
                    </span>
                </div>

                <!-- Messages d'alerte -->
                <?php if ($message_succes): ?>
                    <div class="alert alert-success alert-dismissible fade show" role="alert">
                        <i class="fas fa-check-circle me-2"></i><?php echo $message_succes; ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <?php if ($message_erreur): ?>
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        <i class="fas fa-exclamation-triangle me-2"></i><?php echo $message_erreur; ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <div class="row">
                    <div class="col-12">
                        <!-- Carte d'ajout de paramètre -->
                        <div class="card shadow mb-4">
                            <div class="card-header bg-primary text-white">
                                <h5 class="mb-0">
                                    <i class="fas fa-plus-circle me-2"></i>Ajouter un Nouveau Paramètre
                                </h5>
                            </div>
                            <div class="card-body">
                                <form method="POST" class="row g-3">
                                    <div class="col-md-3">
                                        <label class="form-label">Clé du paramètre *</label>
                                        <input type="text" class="form-control" name="cle" required 
                                               placeholder="ex: taux_interet_max">
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label">Valeur *</label>
                                        <input type="text" class="form-control" name="valeur" required 
                                               placeholder="ex: 15.5">
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label">Type *</label>
                                        <select class="form-select" name="type_valeur" required>
                                            <option value="texte">Texte</option>
                                            <option value="nombre">Nombre</option>
                                            <option value="decimal">Décimal</option>
                                            <option value="booleen">Booléen</option>
                                            <option value="json">JSON</option>
                                        </select>
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label">Catégorie *</label>
                                        <input type="text" class="form-control" name="categorie" required 
                                               placeholder="ex: prets">
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label">Description</label>
                                        <input type="text" class="form-control" name="description" 
                                               placeholder="Description du paramètre">
                                    </div>
                                    <div class="col-12">
                                        <div class="form-check">
                                            <input class="form-check-input" type="checkbox" name="modifiable" value="1" checked>
                                            <label class="form-check-label">Paramètre modifiable</label>
                                        </div>
                                    </div>
                                    <div class="col-12">
                                        <button type="submit" name="ajouter_parametre" class="btn btn-success">
                                            <i class="fas fa-save me-2"></i>Ajouter le Paramètre
                                        </button>
                                    </div>
                                </form>
                            </div>
                        </div>

                        <!-- Paramètres existants par catégorie -->
                        <?php if (!empty($parametres_par_categorie)): ?>
                            <?php foreach ($parametres_par_categorie as $categorie => $params): ?>
                                <div class="card shadow mb-4">
                                    <div class="categorie-header">
                                        <h5 class="mb-0">
                                            <i class="fas fa-folder me-2"></i><?php echo ucfirst($categorie); ?>
                                            <span class="badge bg-light text-dark ms-2"><?php echo count($params); ?> paramètre(s)</span>
                                        </h5>
                                    </div>
                                    <div class="card-body">
                                        <div class="row g-3">
                                            <?php foreach ($params as $param): ?>
                                                <div class="col-lg-6">
                                                    <div class="card param-card h-100">
                                                        <div class="card-body">
                                                            <div class="d-flex justify-content-between align-items-start mb-2">
                                                                <h6 class="card-title text-primary"><?php echo securiser($param['cle']); ?></h6>
                                                                <span class="badge bg-secondary badge-categorie">
                                                                    <?php echo $param['type_valeur']; ?>
                                                                </span>
                                                            </div>
                                                            
                                                            <?php if ($param['description']): ?>
                                                                <p class="card-text small text-muted mb-3">
                                                                    <?php echo securiser($param['description']); ?>
                                                                </p>
                                                            <?php endif; ?>
                                                            
                                                            <form method="POST" class="row g-2">
                                                                <input type="hidden" name="parametre_id" value="<?php echo $param['id']; ?>">
                                                                
                                                                <div class="col-8">
                                                                    <?php if ($param['type_valeur'] === 'booleen'): ?>
                                                                        <select class="form-select form-select-sm" name="valeur" <?php echo $param['modifiable'] ? '' : 'disabled'; ?>>
                                                                            <option value="true" <?php echo $param['valeur'] === 'true' ? 'selected' : ''; ?>>True</option>
                                                                            <option value="false" <?php echo $param['valeur'] === 'false' ? 'selected' : ''; ?>>False</option>
                                                                        </select>
                                                                    <?php else: ?>
                                                                        <input type="text" class="form-control form-control-sm" name="valeur" 
                                                                               value="<?php echo securiser($param['valeur']); ?>" 
                                                                               <?php echo $param['modifiable'] ? '' : 'readonly'; ?>>
                                                                    <?php endif; ?>
                                                                </div>
                                                                
                                                                <div class="col-4">
                                                                    <?php if ($param['modifiable']): ?>
                                                                        <button type="submit" name="modifier_parametre" class="btn btn-primary btn-sm w-100">
                                                                            <i class="fas fa-save me-1"></i>Modifier
                                                                        </button>
                                                                    <?php else: ?>
                                                                        <span class="badge bg-warning text-dark w-100 py-2">Non modifiable</span>
                                                                    <?php endif; ?>
                                                                </div>
                                                            </form>
                                                            
                                                            <div class="mt-2 small text-muted">
                                                                <i class="fas fa-clock me-1"></i>
                                                                Dernière modification: 
                                                                <?php echo $param['date_mise_a_jour'] ? date('d/m/Y H:i', strtotime($param['date_mise_a_jour'])) : 'Jamais'; ?>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="card shadow">
                                <div class="card-body text-center py-5">
                                    <i class="fas fa-cogs fa-3x text-muted mb-3"></i>
                                    <h5 class="text-muted">Aucun paramètre configuré</h5>
                                    <p class="text-muted">Utilisez le formulaire ci-dessus pour ajouter vos premiers paramètres.</p>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Section d'actions système -->
                <div class="row mt-4">
                    <div class="col-12">
                        <div class="card shadow">
                            <div class="card-header bg-warning text-dark">
                                <h5 class="mb-0">
                                    <i class="fas fa-tools me-2"></i>Actions Système
                                </h5>
                            </div>
                            <div class="card-body">
                                <div class="row g-3">
                                    <div class="col-md-4">
                                        <button class="btn btn-outline-primary w-100 py-3">
                                            <i class="fas fa-sync-alt me-2"></i>Recharger la Configuration
                                        </button>
                                    </div>
                                    <div class="col-md-4">
                                        <button class="btn btn-outline-success w-100 py-3">
                                            <i class="fas fa-download me-2"></i>Exporter les Paramètres
                                        </button>
                                    </div>
                                    <div class="col-md-4">
                                        <button class="btn btn-outline-danger w-100 py-3">
                                            <i class="fas fa-trash-alt me-2"></i>Restaurer les Défauts
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </main>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Confirmation pour les actions critiques
        document.addEventListener('DOMContentLoaded', function() {
            const btnRestaurer = document.querySelector('.btn-outline-danger');
            if (btnRestaurer) {
                btnRestaurer.addEventListener('click', function(e) {
                    if (!confirm('Êtes-vous sûr de vouloir restaurer tous les paramètres par défaut ? Cette action est irréversible.')) {
                        e.preventDefault();
                    }
                });
            }

            // Auto-focus sur le premier champ du formulaire d'ajout
            const premierChamp = document.querySelector('input[name="cle"]');
            if (premierChamp) {
                premierChamp.focus();
            }
        });
    </script>
</body>
</html>