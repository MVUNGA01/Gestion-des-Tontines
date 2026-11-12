<?php
// garanties.php
require_once 'config_database.php';

// Vérifier l'authentification
if (!est_connecte()) {
    rediriger('login.php');
}

// Vérifier les permissions (seuls admin et gestionnaire peuvent gérer les garanties)
if (!a_la_permission('admin') && !a_la_permission('gestionnaire')) {
    $_SESSION['erreur'] = "Vous n'avez pas la permission d'accéder à cette page.";
    rediriger('dashboard.php');
}

$action = $_GET['action'] ?? 'liste';
$id = $_GET['id'] ?? null;
$pret_id = $_GET['pret_id'] ?? null;

// Configuration pour l'upload de documents
$dossier_upload = 'documents/garanties/';
$types_autorises = ['pdf', 'doc', 'docx', 'jpg', 'jpeg', 'png', 'gif'];
$taille_max = 10 * 1024 * 1024; // 10MB

// Créer le dossier s'il n'existe pas
if (!file_exists($dossier_upload)) {
    mkdir($dossier_upload, 0777, true);
}

// Fonction pour formater les dates
function formater_date($date) {
    if (empty($date) || $date == '0000-00-00') {
        return 'Non définie';
    }
    return date('d/m/Y', strtotime($date));
}

try {
    // Fonction pour uploader un document
    function uploader_document($file, $garantie_id) {
        global $dossier_upload, $types_autorises, $taille_max;
        
        if ($file['error'] !== UPLOAD_ERR_OK) {
            throw new Exception("Erreur lors de l'upload du document.");
        }
        
        // Vérifier la taille
        if ($file['size'] > $taille_max) {
            throw new Exception("Le fichier est trop volumineux. Taille maximum: 10MB.");
        }
        
        // Vérifier l'extension
        $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($extension, $types_autorises)) {
            throw new Exception("Type de fichier non autorisé. Types acceptés: " . implode(', ', $types_autorises));
        }
        
        // Générer un nom de fichier unique
        $nom_fichier = 'garantie_' . $garantie_id . '_' . time() . '.' . $extension;
        $chemin_complet = $dossier_upload . $nom_fichier;
        
        // Déplacer le fichier
        if (!move_uploaded_file($file['tmp_name'], $chemin_complet)) {
            throw new Exception("Erreur lors du déplacement du fichier.");
        }
        
        return $chemin_complet;
    }

    // Actions CRUD pour les garanties
    switch ($action) {
        case 'ajouter':
            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                $pret_id = securiser($_POST['pret_id']);
                $type_garantie = securiser($_POST['type_garantie']);
                $description = securiser($_POST['description']);
                $valeur_estimee = securiser($_POST['valeur_estimee']);
                $date_evaluation = securiser($_POST['date_evaluation']);
                $notes = securiser($_POST['notes']);
                $documents_path = '';
                
                // Vérifier que le prêt existe
                $stmt_pret = $pdo->prepare("SELECT code_pret FROM prets WHERE id = ?");
                $stmt_pret->execute([$pret_id]);
                $pret = $stmt_pret->fetch();
                
                if (!$pret) {
                    throw new Exception("Le prêt spécifié n'existe pas.");
                }
                
                // Insérer la garantie
                $stmt = $pdo->prepare("
                    INSERT INTO garanties 
                    (pret_id, type_garantie, description, valeur_estimee, date_evaluation, documents_path, notes, statut, evaluateur)
                    VALUES (?, ?, ?, ?, ?, ?, ?, 'a_verifier', ?)
                ");
                
                $stmt->execute([
                    $pret_id,
                    $type_garantie,
                    $description,
                    $valeur_estimee,
                    $date_evaluation,
                    $documents_path,
                    $notes,
                    $_SESSION['user_id']
                ]);
                
                $nouvelle_garantie_id = $pdo->lastInsertId();
                
                // Gérer l'upload de document si fourni
                if (isset($_FILES['document']) && $_FILES['document']['error'] === UPLOAD_ERR_OK) {
                    $chemin_document = uploader_document($_FILES['document'], $nouvelle_garantie_id);
                    
                    // Mettre à jour le chemin du document dans la base de données
                    $stmt_update = $pdo->prepare("UPDATE garanties SET documents_path = ? WHERE id = ?");
                    $stmt_update->execute([$chemin_document, $nouvelle_garantie_id]);
                }
                
                $_SESSION['succes'] = "Garantie ajoutée avec succès.";
                rediriger('garanties.php?action=details&id=' . $nouvelle_garantie_id);
            }
            break;
            
        case 'modifier':
            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                $type_garantie = securiser($_POST['type_garantie']);
                $description = securiser($_POST['description']);
                $valeur_estimee = securiser($_POST['valeur_estimee']);
                $date_evaluation = securiser($_POST['date_evaluation']);
                $notes = securiser($_POST['notes']);
                $statut = securiser($_POST['statut']);
                
                // Récupérer le chemin actuel du document
                $stmt_actuel = $pdo->prepare("SELECT documents_path FROM garanties WHERE id = ?");
                $stmt_actuel->execute([$id]);
                $garantie_actuelle = $stmt_actuel->fetch();
                $documents_path = $garantie_actuelle['documents_path'];
                
                // Gérer le nouvel upload de document
                if (isset($_FILES['document']) && $_FILES['document']['error'] === UPLOAD_ERR_OK) {
                    // Supprimer l'ancien document s'il existe
                    if (!empty($documents_path) && file_exists($documents_path)) {
                        unlink($documents_path);
                    }
                    
                    $documents_path = uploader_document($_FILES['document'], $id);
                }
                
                $stmt = $pdo->prepare("
                    UPDATE garanties 
                    SET type_garantie = ?, description = ?, valeur_estimee = ?, 
                        date_evaluation = ?, documents_path = ?, notes = ?, statut = ?,
                        date_modification = NOW(), evaluateur = ?
                    WHERE id = ?
                ");
                
                $stmt->execute([
                    $type_garantie,
                    $description,
                    $valeur_estimee,
                    $date_evaluation,
                    $documents_path,
                    $notes,
                    $statut,
                    $_SESSION['user_id'],
                    $id
                ]);
                
                $_SESSION['succes'] = "Garantie modifiée avec succès.";
                rediriger('garanties.php?action=details&id=' . $id);
            }
            break;
            
        case 'changer_statut':
            if ($id && isset($_POST['nouveau_statut'])) {
                $nouveau_statut = securiser($_POST['nouveau_statut']);
                
                $stmt = $pdo->prepare("
                    UPDATE garanties 
                    SET statut = ?, date_modification = NOW(), evaluateur = ?
                    WHERE id = ?
                ");
                
                $stmt->execute([$nouveau_statut, $_SESSION['user_id'], $id]);
                
                $_SESSION['succes'] = "Statut de la garantie mis à jour.";
                rediriger('garanties.php?action=details&id=' . $id);
            }
            break;
            
        case 'telecharger_document':
            if ($id) {
                $stmt = $pdo->prepare("SELECT documents_path FROM garanties WHERE id = ?");
                $stmt->execute([$id]);
                $garantie = $stmt->fetch();
                
                if ($garantie && !empty($garantie['documents_path']) && file_exists($garantie['documents_path'])) {
                    $chemin_document = $garantie['documents_path'];
                    $nom_fichier = basename($chemin_document);
                    
                    header('Content-Type: application/octet-stream');
                    header('Content-Disposition: attachment; filename="' . $nom_fichier . '"');
                    header('Content-Length: ' . filesize($chemin_document));
                    readfile($chemin_document);
                    exit;
                } else {
                    $_SESSION['erreur'] = "Document non trouvé.";
                    rediriger('garanties.php?action=details&id=' . $id);
                }
            }
            break;
            
        case 'supprimer_document':
            if ($id) {
                $stmt = $pdo->prepare("SELECT documents_path FROM garanties WHERE id = ?");
                $stmt->execute([$id]);
                $garantie = $stmt->fetch();
                
                if ($garantie && !empty($garantie['documents_path'])) {
                    if (file_exists($garantie['documents_path'])) {
                        unlink($garantie['documents_path']);
                    }
                    
                    $stmt_update = $pdo->prepare("UPDATE garanties SET documents_path = '' WHERE id = ?");
                    $stmt_update->execute([$id]);
                    
                    $_SESSION['succes'] = "Document supprimé avec succès.";
                } else {
                    $_SESSION['erreur'] = "Document non trouvé.";
                }
                
                rediriger('garanties.php?action=details&id=' . $id);
            }
            break;
            
        case 'supprimer':
            if ($id) {
                // Vérifier que la garantie existe
                $stmt = $pdo->prepare("SELECT documents_path FROM garanties WHERE id = ?");
                $stmt->execute([$id]);
                $garantie = $stmt->fetch();
                
                if ($garantie) {
                    // Supprimer le document associé s'il existe
                    if (!empty($garantie['documents_path']) && file_exists($garantie['documents_path'])) {
                        unlink($garantie['documents_path']);
                    }
                    
                    $stmt = $pdo->prepare("DELETE FROM garanties WHERE id = ?");
                    $stmt->execute([$id]);
                    
                    $_SESSION['succes'] = "Garantie supprimée avec succès.";
                } else {
                    $_SESSION['erreur'] = "Garantie non trouvée.";
                }
                
                rediriger('garanties.php');
            }
            break;
    }

    // Récupérer les données selon l'action
    switch ($action) {
        case 'details':
            // Détails d'une garantie spécifique
            $stmt = $pdo->prepare("
                SELECT g.*, p.code_pret, m.nom, m.prenom, m.code_membre,
                       u.nom_complet as evaluateur_nom
                FROM garanties g
                LEFT JOIN prets p ON g.pret_id = p.id
                LEFT JOIN membres m ON p.code_membre = m.code_membre
                LEFT JOIN utilisateurs u ON g.evaluateur = u.id
                WHERE g.id = ?
            ");
            $stmt->execute([$id]);
            $garantie = $stmt->fetch();
            
            if (!$garantie) {
                $_SESSION['erreur'] = "Garantie non trouvée.";
                rediriger('garanties.php');
            }
            break;
            
        case 'ajouter':
            // Pour l'ajout, récupérer la liste des prêts éligibles
            $stmt_prets = $pdo->query("
                SELECT p.id, p.code_pret, m.nom, m.prenom, m.code_membre
                FROM prets p
                LEFT JOIN membres m ON p.code_membre = m.code_membre
                WHERE p.statut IN ('approuve', 'actif')
                ORDER BY p.date_creation DESC
            ");
            $prets = $stmt_prets->fetchAll();
            break;
            
        case 'modifier':
            // Pour la modification, récupérer la garantie et les prêts
            $stmt = $pdo->prepare("SELECT * FROM garanties WHERE id = ?");
            $stmt->execute([$id]);
            $garantie = $stmt->fetch();
            
            if (!$garantie) {
                $_SESSION['erreur'] = "Garantie non trouvée.";
                rediriger('garanties.php');
            }
            
            $stmt_prets = $pdo->query("
                SELECT p.id, p.code_pret, m.nom, m.prenom, m.code_membre
                FROM prets p
                LEFT JOIN membres m ON p.code_membre = m.code_membre
                WHERE p.statut IN ('approuve', 'actif')
                ORDER BY p.date_creation DESC
            ");
            $prets = $stmt_prets->fetchAll();
            break;
            
        default:
            // Liste des garanties avec pagination et filtres
            $filtre_statut = $_GET['statut'] ?? '';
            $filtre_type = $_GET['type'] ?? '';
            $filtre_pret = $_GET['pret'] ?? '';
            
            $where_conditions = [];
            $params = [];
            
            if ($filtre_statut) {
                $where_conditions[] = "g.statut = ?";
                $params[] = $filtre_statut;
            }
            
            if ($filtre_type) {
                $where_conditions[] = "g.type_garantie = ?";
                $params[] = $filtre_type;
            }
            
            if ($filtre_pret) {
                $where_conditions[] = "p.code_pret LIKE ?";
                $params[] = "%$filtre_pret%";
            }
            
            $where_sql = '';
            if (!empty($where_conditions)) {
                $where_sql = 'WHERE ' . implode(' AND ', $where_conditions);
            }
            
            // Compter le total pour la pagination
            $stmt_count = $pdo->prepare("
                SELECT COUNT(*) 
                FROM garanties g
                LEFT JOIN prets p ON g.pret_id = p.id
                $where_sql
            ");
            $stmt_count->execute($params);
            $total_garanties = $stmt_count->fetchColumn();
            
            // Pagination
            $page = $_GET['page'] ?? 1;
            $limit = 20;
            $offset = ($page - 1) * $limit;
            $total_pages = ceil($total_garanties / $limit);
            
            // Récupérer les garanties
            $stmt_garanties = $pdo->prepare("
                SELECT g.*, p.code_pret, m.nom, m.prenom, m.code_membre,
                       u.nom_complet as evaluateur_nom,
                       cm.nom_categorie
                FROM garanties g
                LEFT JOIN prets p ON g.pret_id = p.id
                LEFT JOIN membres m ON p.code_membre = m.code_membre
                LEFT JOIN categories_membres cm ON m.categorie_id = cm.id
                LEFT JOIN utilisateurs u ON g.evaluateur = u.id
                $where_sql
                ORDER BY g.date_creation DESC
                LIMIT ? OFFSET ?
            ");
            
            $params_pagination = array_merge($params, [$limit, $offset]);
            $stmt_garanties->execute($params_pagination);
            $garanties = $stmt_garanties->fetchAll();
            
            // Statistiques pour le dashboard des garanties
            $stmt_stats = $pdo->query("
                SELECT 
                    COUNT(*) as total,
                    SUM(CASE WHEN statut = 'valide' THEN 1 ELSE 0 END) as valides,
                    SUM(CASE WHEN statut = 'invalide' THEN 1 ELSE 0 END) as invalides,
                    SUM(CASE WHEN statut = 'a_verifier' THEN 1 ELSE 0 END) as a_verifier,
                    SUM(CASE WHEN statut = 'expiree' THEN 1 ELSE 0 END) as expirees,
                    SUM(valeur_estimee) as valeur_totale
                FROM garanties
            ");
            $stats_garanties = $stmt_stats->fetch();
            break;
    }

} catch (Exception $e) {
    $erreur = "Erreur: " . $e->getMessage();
}
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestion des Garanties - <?php echo APP_NAME; ?></title>
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
        .badge-statut {
            font-size: 0.8rem;
            padding: 5px 10px;
        }
        .document-card {
            border-left: 4px solid #007bff;
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
                                <a class="nav-link active" href="garanties.php">
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
                <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
                    <h1 class="h2">
                        <i class="fas fa-shield-alt me-2"></i>Gestion des Garanties
                    </h1>
                    <div class="btn-toolbar mb-2 mb-md-0">
                        <?php if ($action === 'liste'): ?>
                            <a href="garanties.php?action=ajouter" class="btn btn-success">
                                <i class="fas fa-plus me-1"></i>Nouvelle Garantie
                            </a>
                        <?php else: ?>
                            <a href="garanties.php" class="btn btn-primary">
                                <i class="fas fa-arrow-left me-1"></i>Retour à la liste
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

                <?php if (isset($erreur)): ?>
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        <?php echo $erreur; ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <!-- Contenu selon l'action -->
                <?php if ($action === 'liste'): ?>
                    <!-- Statistiques des garanties -->
                    <div class="row mb-4">
                        <div class="col-xl-3 col-md-6 mb-4">
                            <div class="card stat-card border-left-primary shadow h-100 py-2">
                                <div class="card-body">
                                    <div class="row no-gutters align-items-center">
                                        <div class="col mr-2">
                                            <div class="text-xs font-weight-bold text-primary text-uppercase mb-1">
                                                Total Garanties
                                            </div>
                                            <div class="h5 mb-0 font-weight-bold text-gray-800">
                                                <?php echo number_format($stats_garanties['total']); ?>
                                            </div>
                                        </div>
                                        <div class="col-auto">
                                            <i class="fas fa-shield-alt fa-2x text-gray-300"></i>
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
                                                Garanties Valides
                                            </div>
                                            <div class="h5 mb-0 font-weight-bold text-gray-800">
                                                <?php echo number_format($stats_garanties['valides']); ?>
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
                                                À Vérifier
                                            </div>
                                            <div class="h5 mb-0 font-weight-bold text-gray-800">
                                                <?php echo number_format($stats_garanties['a_verifier']); ?>
                                            </div>
                                        </div>
                                        <div class="col-auto">
                                            <i class="fas fa-clock fa-2x text-gray-300"></i>
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
                                                Valeur Totale
                                            </div>
                                            <div class="h5 mb-0 font-weight-bold text-gray-800">
                                                <?php echo number_format($stats_garanties['valeur_totale'] ?? 0, 0); ?> <span class="currency">FBu</span>
                                            </div>
                                        </div>
                                        <div class="col-auto">
                                            <i class="fas fa-money-bill-wave fa-2x text-gray-300"></i>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Filtres -->
                    <div class="card shadow mb-4">
                        <div class="card-header bg-light">
                            <h6 class="m-0 font-weight-bold text-primary">
                                <i class="fas fa-filter me-2"></i>Filtres
                            </h6>
                        </div>
                        <div class="card-body">
                            <form method="GET" class="row g-3">
                                <div class="col-md-3">
                                    <label for="statut" class="form-label">Statut</label>
                                    <select name="statut" id="statut" class="form-select">
                                        <option value="">Tous les statuts</option>
                                        <option value="valide" <?php echo $filtre_statut === 'valide' ? 'selected' : ''; ?>>Valide</option>
                                        <option value="invalide" <?php echo $filtre_statut === 'invalide' ? 'selected' : ''; ?>>Invalide</option>
                                        <option value="a_verifier" <?php echo $filtre_statut === 'a_verifier' ? 'selected' : ''; ?>>À vérifier</option>
                                        <option value="expiree" <?php echo $filtre_statut === 'expiree' ? 'selected' : ''; ?>>Expirée</option>
                                    </select>
                                </div>
                                <div class="col-md-3">
                                    <label for="type" class="form-label">Type</label>
                                    <select name="type" id="type" class="form-select">
                                        <option value="">Tous les types</option>
                                        <option value="immobiliere" <?php echo $filtre_type === 'immobiliere' ? 'selected' : ''; ?>>Immobilière</option>
                                        <option value="mobiliere" <?php echo $filtre_type === 'mobiliere' ? 'selected' : ''; ?>>Mobilière</option>
                                        <option value="personnelle" <?php echo $filtre_type === 'personnelle' ? 'selected' : ''; ?>>Personnelle</option>
                                        <option value="autre" <?php echo $filtre_type === 'autre' ? 'selected' : ''; ?>>Autre</option>
                                    </select>
                                </div>
                                <div class="col-md-3">
                                    <label for="pret" class="form-label">Code Prêt</label>
                                    <input type="text" name="pret" id="pret" class="form-control" 
                                           value="<?php echo $filtre_pret; ?>" placeholder="Rechercher par code prêt...">
                                </div>
                                <div class="col-md-3 d-flex align-items-end">
                                    <button type="submit" class="btn btn-primary me-2">
                                        <i class="fas fa-search me-1"></i>Filtrer
                                    </button>
                                    <a href="garanties.php" class="btn btn-secondary">
                                        <i class="fas fa-times me-1"></i>Effacer
                                    </a>
                                </div>
                            </form>
                        </div>
                    </div>

                    <!-- Liste des garanties -->
                    <div class="card shadow">
                        <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
                            <h6 class="m-0 font-weight-bold">
                                <i class="fas fa-list me-2"></i>Liste des Garanties
                            </h6>
                            <span class="badge bg-light text-dark">
                                <?php echo number_format($total_garanties); ?> garantie(s)
                            </span>
                        </div>
                        <div class="card-body">
                            <?php if (!empty($garanties)): ?>
                                <div class="table-responsive">
                                    <table class="table table-striped table-hover">
                                        <thead>
                                            <tr>
                                                <th>ID</th>
                                                <th>Prêt</th>
                                                <th>Membre</th>
                                                <th>Type</th>
                                                <th>Valeur Estimée</th>
                                                <th>Statut</th>
                                                <th>Date Évaluation</th>
                                                <th>Document</th>
                                                <th>Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($garanties as $garantie): ?>
                                                <tr>
                                                    <td><?php echo $garantie['id']; ?></td>
                                                    <td>
                                                        <strong><?php echo securiser($garantie['code_pret']); ?></strong>
                                                    </td>
                                                    <td>
                                                        <?php echo securiser($garantie['nom'] . ' ' . $garantie['prenom']); ?>
                                                        <br><small class="text-muted"><?php echo securiser($garantie['code_membre']); ?></small>
                                                    </td>
                                                    <td>
                                                        <?php 
                                                        $type_icons = [
                                                            'immobiliere' => 'fas fa-home',
                                                            'mobiliere' => 'fas fa-car',
                                                            'personnelle' => 'fas fa-user',
                                                            'autre' => 'fas fa-cube'
                                                        ];
                                                        $type_labels = [
                                                            'immobiliere' => 'Immobilière',
                                                            'mobiliere' => 'Mobilière',
                                                            'personnelle' => 'Personnelle',
                                                            'autre' => 'Autre'
                                                        ];
                                                        ?>
                                                        <i class="<?php echo $type_icons[$garantie['type_garantie']] ?? 'fas fa-cube'; ?> me-1"></i>
                                                        <?php echo $type_labels[$garantie['type_garantie']] ?? 'Autre'; ?>
                                                    </td>
                                                    <td>
                                                        <strong><?php echo number_format($garantie['valeur_estimee'], 0); ?> FBu</strong>
                                                    </td>
                                                    <td>
                                                        <?php
                                                        $statut_classes = [
                                                            'valide' => 'success',
                                                            'invalide' => 'danger',
                                                            'a_verifier' => 'warning',
                                                            'expiree' => 'secondary'
                                                        ];
                                                        $statut_labels = [
                                                            'valide' => 'Valide',
                                                            'invalide' => 'Invalide',
                                                            'a_verifier' => 'À vérifier',
                                                            'expiree' => 'Expirée'
                                                        ];
                                                        ?>
                                                        <span class="badge badge-statut bg-<?php echo $statut_classes[$garantie['statut']] ?? 'secondary'; ?>">
                                                            <?php echo $statut_labels[$garantie['statut']] ?? $garantie['statut']; ?>
                                                        </span>
                                                    </td>
                                                    <td>
                                                        <?php echo formater_date($garantie['date_evaluation']); ?>
                                                    </td>
                                                    <td>
                                                        <?php if (!empty($garantie['documents_path'])): ?>
                                                            <a href="garanties.php?action=telecharger_document&id=<?php echo $garantie['id']; ?>" 
                                                               class="btn btn-sm btn-outline-primary" title="Télécharger le document">
                                                                <i class="fas fa-download"></i>
                                                            </a>
                                                        <?php else: ?>
                                                            <span class="text-muted">Aucun</span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <div class="btn-group btn-group-sm">
                                                            <a href="garanties.php?action=details&id=<?php echo $garantie['id']; ?>" 
                                                               class="btn btn-info" title="Voir les détails">
                                                                <i class="fas fa-eye"></i>
                                                            </a>
                                                            <a href="garanties.php?action=modifier&id=<?php echo $garantie['id']; ?>" 
                                                               class="btn btn-warning" title="Modifier">
                                                                <i class="fas fa-edit"></i>
                                                            </a>
                                                            <a href="garanties.php?action=supprimer&id=<?php echo $garantie['id']; ?>" 
                                                               class="btn btn-danger" 
                                                               onclick="return confirm('Êtes-vous sûr de vouloir supprimer cette garantie ?')"
                                                               title="Supprimer">
                                                                <i class="fas fa-trash"></i>
                                                            </a>
                                                        </div>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>

                                <!-- Pagination -->
                                <?php if ($total_pages > 1): ?>
                                    <nav aria-label="Page navigation">
                                        <ul class="pagination justify-content-center">
                                            <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                                                <li class="page-item <?php echo $i == $page ? 'active' : ''; ?>">
                                                    <a class="page-link" href="garanties.php?page=<?php echo $i; ?>&statut=<?php echo $filtre_statut; ?>&type=<?php echo $filtre_type; ?>&pret=<?php echo $filtre_pret; ?>">
                                                        <?php echo $i; ?>
                                                    </a>
                                                </li>
                                            <?php endfor; ?>
                                        </ul>
                                    </nav>
                                <?php endif; ?>

                            <?php else: ?>
                                <div class="text-center py-5">
                                    <i class="fas fa-shield-alt fa-4x text-muted mb-3"></i>
                                    <h4 class="text-muted">Aucune garantie trouvée</h4>
                                    <p class="text-muted">Commencez par ajouter votre première garantie.</p>
                                    <a href="garanties.php?action=ajouter" class="btn btn-primary">
                                        <i class="fas fa-plus me-1"></i>Ajouter une garantie
                                    </a>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                <?php elseif ($action === 'ajouter' || $action === 'modifier'): ?>
                    <!-- Formulaire d'ajout/modification -->
                    <div class="card shadow">
                        <div class="card-header bg-<?php echo $action === 'ajouter' ? 'success' : 'warning'; ?> text-white">
                            <h6 class="m-0 font-weight-bold">
                                <i class="fas fa-<?php echo $action === 'ajouter' ? 'plus' : 'edit'; ?> me-2"></i>
                                <?php echo $action === 'ajouter' ? 'Ajouter une Garantie' : 'Modifier la Garantie'; ?>
                            </h6>
                        </div>
                        <div class="card-body">
                            <form method="POST" enctype="multipart/form-data">
                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label for="pret_id" class="form-label">Prêt Associé *</label>
                                            <select name="pret_id" id="pret_id" class="form-select" required 
                                                <?php echo $action === 'modifier' ? 'disabled' : ''; ?>>
                                                <option value="">Sélectionner un prêt</option>
                                                <?php foreach ($prets as $pret): ?>
                                                    <option value="<?php echo $pret['id']; ?>" 
                                                        <?php echo (isset($garantie) && $garantie['pret_id'] == $pret['id']) ? 'selected' : ''; ?>>
                                                        <?php echo securiser($pret['code_pret']); ?> - 
                                                        <?php echo securiser($pret['nom'] . ' ' . $pret['prenom']); ?>
                                                        (<?php echo securiser($pret['code_membre']); ?>)
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                            <?php if ($action === 'modifier'): ?>
                                                <input type="hidden" name="pret_id" value="<?php echo $garantie['pret_id']; ?>">
                                            <?php endif; ?>
                                        </div>

                                        <div class="mb-3">
                                            <label for="type_garantie" class="form-label">Type de Garantie *</label>
                                            <select name="type_garantie" id="type_garantie" class="form-select" required>
                                                <option value="">Sélectionner un type</option>
                                                <option value="immobiliere" <?php echo (isset($garantie) && $garantie['type_garantie'] === 'immobiliere') ? 'selected' : ''; ?>>Immobilière</option>
                                                <option value="mobiliere" <?php echo (isset($garantie) && $garantie['type_garantie'] === 'mobiliere') ? 'selected' : ''; ?>>Mobilière</option>
                                                <option value="personnelle" <?php echo (isset($garantie) && $garantie['type_garantie'] === 'personnelle') ? 'selected' : ''; ?>>Personnelle</option>
                                                <option value="autre" <?php echo (isset($garantie) && $garantie['type_garantie'] === 'autre') ? 'selected' : ''; ?>>Autre</option>
                                            </select>
                                        </div>

                                        <div class="mb-3">
                                            <label for="valeur_estimee" class="form-label">Valeur Estimée (FBu) *</label>
                                            <input type="number" name="valeur_estimee" id="valeur_estimee" 
                                                   class="form-control" min="0" step="0.01" required
                                                   value="<?php echo isset($garantie) ? $garantie['valeur_estimee'] : ''; ?>">
                                        </div>

                                        <div class="mb-3">
                                            <label for="date_evaluation" class="form-label">Date d'Évaluation *</label>
                                            <input type="date" name="date_evaluation" id="date_evaluation" 
                                                   class="form-control" required
                                                   value="<?php echo isset($garantie) ? $garantie['date_evaluation'] : date('Y-m-d'); ?>">
                                        </div>
                                    </div>

                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label for="description" class="form-label">Description *</label>
                                            <textarea name="description" id="description" class="form-control" 
                                                      rows="3" required><?php echo isset($garantie) ? $garantie['description'] : ''; ?></textarea>
                                        </div>

                                        <div class="mb-3">
                                            <label for="document" class="form-label">Document</label>
                                            <input type="file" name="document" id="document" class="form-control" 
                                                   accept=".pdf,.doc,.docx,.jpg,.jpeg,.png,.gif">
                                            <small class="form-text text-muted">
                                                Formats acceptés: PDF, DOC, DOCX, JPG, JPEG, PNG, GIF (max 10MB)
                                            </small>
                                        </div>

                                        <?php if ($action === 'modifier'): ?>
                                            <div class="mb-3">
                                                <label for="statut" class="form-label">Statut *</label>
                                                <select name="statut" id="statut" class="form-select" required>
                                                    <option value="a_verifier" <?php echo (isset($garantie) && $garantie['statut'] === 'a_verifier') ? 'selected' : ''; ?>>À vérifier</option>
                                                    <option value="valide" <?php echo (isset($garantie) && $garantie['statut'] === 'valide') ? 'selected' : ''; ?>>Valide</option>
                                                    <option value="invalide" <?php echo (isset($garantie) && $garantie['statut'] === 'invalide') ? 'selected' : ''; ?>>Invalide</option>
                                                    <option value="expiree" <?php echo (isset($garantie) && $garantie['statut'] === 'expiree') ? 'selected' : ''; ?>>Expirée</option>
                                                </select>
                                            </div>
                                        <?php endif; ?>

                                        <div class="mb-3">
                                            <label for="notes" class="form-label">Notes</label>
                                            <textarea name="notes" id="notes" class="form-control" 
                                                      rows="2"><?php echo isset($garantie) ? $garantie['notes'] : ''; ?></textarea>
                                        </div>
                                    </div>
                                </div>

                                <div class="row mt-3">
                                    <div class="col-12">
                                        <div class="d-flex justify-content-between">
                                            <a href="garanties.php" class="btn btn-secondary">
                                                <i class="fas fa-arrow-left me-1"></i>Annuler
                                            </a>
                                            <button type="submit" class="btn btn-<?php echo $action === 'ajouter' ? 'success' : 'warning'; ?>">
                                                <i class="fas fa-<?php echo $action === 'ajouter' ? 'save' : 'edit'; ?> me-1"></i>
                                                <?php echo $action === 'ajouter' ? 'Ajouter la Garantie' : 'Modifier la Garantie'; ?>
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </form>
                        </div>
                    </div>

                <?php elseif ($action === 'details'): ?>
                    <!-- Détails d'une garantie -->
                    <div class="row">
                        <div class="col-lg-8">
                            <div class="card shadow mb-4">
                                <div class="card-header bg-info text-white">
                                    <h6 class="m-0 font-weight-bold">
                                        <i class="fas fa-info-circle me-2"></i>Détails de la Garantie #<?php echo $garantie['id']; ?>
                                    </h6>
                                </div>
                                <div class="card-body">
                                    <div class="row">
                                        <div class="col-md-6">
                                            <h6>Informations Générales</h6>
                                            <table class="table table-sm table-borderless">
                                                <tr>
                                                    <td><strong>Prêt:</strong></td>
                                                    <td><?php echo securiser($garantie['code_pret']); ?></td>
                                                </tr>
                                                <tr>
                                                    <td><strong>Membre:</strong></td>
                                                    <td>
                                                        <?php echo securiser($garantie['nom'] . ' ' . $garantie['prenom']); ?>
                                                        <br><small class="text-muted"><?php echo securiser($garantie['code_membre']); ?></small>
                                                    </td>
                                                </tr>
                                                <tr>
                                                    <td><strong>Type:</strong></td>
                                                    <td>
                                                        <?php 
                                                        $type_labels = [
                                                            'immobiliere' => 'Immobilière',
                                                            'mobiliere' => 'Mobilière',
                                                            'personnelle' => 'Personnelle',
                                                            'autre' => 'Autre'
                                                        ];
                                                        echo $type_labels[$garantie['type_garantie']] ?? 'Autre';
                                                        ?>
                                                    </td>
                                                </tr>
                                                <tr>
                                                    <td><strong>Statut:</strong></td>
                                                    <td>
                                                        <?php
                                                        $statut_classes = [
                                                            'valide' => 'success',
                                                            'invalide' => 'danger',
                                                            'a_verifier' => 'warning',
                                                            'expiree' => 'secondary'
                                                        ];
                                                        $statut_labels = [
                                                            'valide' => 'Valide',
                                                            'invalide' => 'Invalide',
                                                            'a_verifier' => 'À vérifier',
                                                            'expiree' => 'Expirée'
                                                        ];
                                                        ?>
                                                        <span class="badge bg-<?php echo $statut_classes[$garantie['statut']] ?? 'secondary'; ?>">
                                                            <?php echo $statut_labels[$garantie['statut']] ?? $garantie['statut']; ?>
                                                        </span>
                                                    </td>
                                                </tr>
                                            </table>
                                        </div>
                                        <div class="col-md-6">
                                            <h6>Évaluation</h6>
                                            <table class="table table-sm table-borderless">
                                                <tr>
                                                    <td><strong>Valeur Estimée:</strong></td>
                                                    <td><span class="text-success fw-bold"><?php echo number_format($garantie['valeur_estimee'], 0); ?> FBu</span></td>
                                                </tr>
                                                <tr>
                                                    <td><strong>Date d'Évaluation:</strong></td>
                                                    <td><?php echo formater_date($garantie['date_evaluation']); ?></td>
                                                </tr>
                                                <tr>
                                                    <td><strong>Évaluateur:</strong></td>
                                                    <td><?php echo securiser($garantie['evaluateur_nom']); ?></td>
                                                </tr>
                                                <tr>
                                                    <td><strong>Date de Création:</strong></td>
                                                    <td><?php echo formater_date($garantie['date_creation']); ?></td>
                                                </tr>
                                                <?php if (!empty($garantie['date_modification'])): ?>
                                                    <tr>
                                                        <td><strong>Dernière Modification:</strong></td>
                                                        <td><?php echo formater_date($garantie['date_modification']); ?></td>
                                                    </tr>
                                                <?php endif; ?>
                                            </table>
                                        </div>
                                    </div>

                                    <div class="row mt-3">
                                        <div class="col-12">
                                            <h6>Description</h6>
                                            <div class="border rounded p-3 bg-light">
                                                <?php echo nl2br(securiser($garantie['description'])); ?>
                                            </div>
                                        </div>
                                    </div>

                                    <?php if (!empty($garantie['notes'])): ?>
                                        <div class="row mt-3">
                                            <div class="col-12">
                                                <h6>Notes</h6>
                                                <div class="border rounded p-3 bg-light">
                                                    <?php echo nl2br(securiser($garantie['notes'])); ?>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>

                        <div class="col-lg-4">
                            <!-- Document -->
                            <div class="card shadow mb-4 document-card">
                                <div class="card-header">
                                    <h6 class="m-0 font-weight-bold text-primary">
                                        <i class="fas fa-file me-2"></i>Document
                                    </h6>
                                </div>
                                <div class="card-body text-center">
                                    <?php if (!empty($garantie['documents_path'])): ?>
                                        <i class="fas fa-file-pdf fa-3x text-danger mb-3"></i>
                                        <p class="mb-3">Document disponible</p>
                                        <div class="d-grid gap-2">
                                            <a href="garanties.php?action=telecharger_document&id=<?php echo $garantie['id']; ?>" 
                                               class="btn btn-primary">
                                                <i class="fas fa-download me-1"></i>Télécharger
                                            </a>
                                            <a href="garanties.php?action=supprimer_document&id=<?php echo $garantie['id']; ?>" 
                                               class="btn btn-outline-danger"
                                               onclick="return confirm('Êtes-vous sûr de vouloir supprimer ce document ?')">
                                                <i class="fas fa-trash me-1"></i>Supprimer
                                            </a>
                                        </div>
                                    <?php else: ?>
                                        <i class="fas fa-file fa-3x text-muted mb-3"></i>
                                        <p class="text-muted mb-3">Aucun document attaché</p>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <!-- Actions -->
                            <div class="card shadow">
                                <div class="card-header">
                                    <h6 class="m-0 font-weight-bold text-primary">
                                        <i class="fas fa-cogs me-2"></i>Actions
                                    </h6>
                                </div>
                                <div class="card-body">
                                    <div class="d-grid gap-2">
                                        <a href="garanties.php?action=modifier&id=<?php echo $garantie['id']; ?>" 
                                           class="btn btn-warning">
                                            <i class="fas fa-edit me-1"></i>Modifier
                                        </a>
                                        
                                        <!-- Formulaire de changement de statut -->
                                        <form method="POST" action="garanties.php?action=changer_statut&id=<?php echo $garantie['id']; ?>" class="d-grid">
                                            <div class="input-group">
                                                <select name="nouveau_statut" class="form-select" required>
                                                    <option value="">Changer statut</option>
                                                    <option value="valide" <?php echo $garantie['statut'] === 'valide' ? 'selected' : ''; ?>>Valide</option>
                                                    <option value="invalide" <?php echo $garantie['statut'] === 'invalide' ? 'selected' : ''; ?>>Invalide</option>
                                                    <option value="a_verifier" <?php echo $garantie['statut'] === 'a_verifier' ? 'selected' : ''; ?>>À vérifier</option>
                                                    <option value="expiree" <?php echo $garantie['statut'] === 'expiree' ? 'selected' : ''; ?>>Expirée</option>
                                                </select>
                                                <button type="submit" class="btn btn-outline-primary">
                                                    <i class="fas fa-sync"></i>
                                                </button>
                                            </div>
                                        </form>
                                        
                                        <a href="garanties.php?action=supprimer&id=<?php echo $garantie['id']; ?>" 
                                           class="btn btn-danger"
                                           onclick="return confirm('Êtes-vous sûr de vouloir supprimer cette garantie ? Cette action est irréversible.')">
                                            <i class="fas fa-trash me-1"></i>Supprimer
                                        </a>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
            </main>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Script pour améliorer l'interface utilisateur
        document.addEventListener('DOMContentLoaded', function() {
            // Auto-dismiss alerts after 5 seconds
            setTimeout(function() {
                var alerts = document.querySelectorAll('.alert');
                alerts.forEach(function(alert) {
                    var bsAlert = new bootstrap.Alert(alert);
                    bsAlert.close();
                });
            }, 5000);

            // Confirmation pour les actions critiques
            var deleteButtons = document.querySelectorAll('a[onclick*="confirm"]');
            deleteButtons.forEach(function(button) {
                button.addEventListener('click', function(e) {
                    if (!confirm(this.getAttribute('onclick').match(/confirm\('([^']+)'/)[1])) {
                        e.preventDefault();
                    }
                });
            });
        });
    </script>
</body>
</html>