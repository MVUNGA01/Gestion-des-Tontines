<?php
// documents.php
require_once 'config_database.php';

// Vérifier l'authentification
if (!est_connecte()) {
    rediriger('login.php');
}

// Vérifier les permissions (seuls admin et gestionnaire peuvent gérer les documents)
if (!a_la_permission('admin') && !a_la_permission('gestionnaire')) {
    $_SESSION['erreur'] = "Vous n'avez pas la permission d'accéder à cette page.";
    rediriger('dashboard.php');
}

$action = $_GET['action'] ?? 'liste';
$id = $_GET['id'] ?? null;
$code_membre = $_GET['code_membre'] ?? null;

// Configuration pour l'upload de documents
$dossier_upload = 'documents/membres/';
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

// Fonction pour formater la taille des fichiers
function formater_taille_fichier($taille) {
    if ($taille < 1024) {
        return $taille . ' octets';
    } elseif ($taille < 1048576) {
        return round($taille / 1024, 2) . ' Ko';
    } else {
        return round($taille / 1048576, 2) . ' Mo';
    }
}

try {
    // Fonction pour uploader un document
    function uploader_document($file, $code_membre, $type_document) {
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
        
        // Créer le sous-dossier pour le membre s'il n'existe pas
        $dossier_membre = $dossier_upload . $code_membre . '/';
        if (!file_exists($dossier_membre)) {
            mkdir($dossier_membre, 0777, true);
        }
        
        // Générer un nom de fichier unique
        $nom_fichier = $type_document . '_' . $code_membre . '_' . time() . '.' . $extension;
        $chemin_complet = $dossier_membre . $nom_fichier;
        
        // Déplacer le fichier
        if (!move_uploaded_file($file['tmp_name'], $chemin_complet)) {
            throw new Exception("Erreur lors du déplacement du fichier.");
        }
        
        return [
            'chemin_fichier' => $chemin_complet,
            'nom_fichier' => $nom_fichier,
            'taille_fichier' => $file['size']
        ];
    }

    // Actions CRUD pour les documents
    switch ($action) {
        case 'ajouter':
            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                $code_membre = securiser($_POST['code_membre']);
                $type_document = securiser($_POST['type_document']);
                $notes = securiser($_POST['notes']);
                
                // Vérifier que le membre existe
                $stmt_membre = $pdo->prepare("SELECT nom, prenom FROM membres WHERE code_membre = ?");
                $stmt_membre->execute([$code_membre]);
                $membre = $stmt_membre->fetch();
                
                if (!$membre) {
                    throw new Exception("Le membre spécifié n'existe pas.");
                }
                
                // Gérer l'upload de document
                if (isset($_FILES['document']) && $_FILES['document']['error'] === UPLOAD_ERR_OK) {
                    $upload_result = uploader_document($_FILES['document'], $code_membre, $type_document);
                    
                    // Insérer le document dans la base de données
                    $stmt = $pdo->prepare("
                        INSERT INTO documents_membres 
                        (code_membre, type_document, nom_fichier, chemin_fichier, taille_fichier, notes, statut)
                        VALUES (?, ?, ?, ?, ?, ?, 'en_attente')
                    ");
                    
                    $stmt->execute([
                        $code_membre,
                        $type_document,
                        $upload_result['nom_fichier'],
                        $upload_result['chemin_fichier'],
                        $upload_result['taille_fichier'],
                        $notes
                    ]);
                    
                    $_SESSION['succes'] = "Document ajouté avec succès et en attente d'approbation.";
                    rediriger('documents.php?action=details&id=' . $pdo->lastInsertId());
                } else {
                    throw new Exception("Aucun document n'a été uploadé.");
                }
            }
            break;
            
        case 'modifier':
            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                $type_document = securiser($_POST['type_document']);
                $notes = securiser($_POST['notes']);
                $statut = securiser($_POST['statut']);
                
                // Récupérer le document actuel
                $stmt_actuel = $pdo->prepare("SELECT chemin_fichier, code_membre FROM documents_membres WHERE id = ?");
                $stmt_actuel->execute([$id]);
                $document_actuel = $stmt_actuel->fetch();
                
                if (!$document_actuel) {
                    throw new Exception("Document non trouvé.");
                }
                
                $chemin_fichier = $document_actuel['chemin_fichier'];
                $nom_fichier = basename($chemin_fichier);
                $taille_fichier = filesize($chemin_fichier);
                
                // Gérer le nouvel upload de document
                if (isset($_FILES['document']) && $_FILES['document']['error'] === UPLOAD_ERR_OK) {
                    // Supprimer l'ancien document s'il existe
                    if (!empty($chemin_fichier) && file_exists($chemin_fichier)) {
                        unlink($chemin_fichier);
                    }
                    
                    $upload_result = uploader_document($_FILES['document'], $document_actuel['code_membre'], $type_document);
                    $chemin_fichier = $upload_result['chemin_fichier'];
                    $nom_fichier = $upload_result['nom_fichier'];
                    $taille_fichier = $upload_result['taille_fichier'];
                }
                
                $stmt = $pdo->prepare("
                    UPDATE documents_membres 
                    SET type_document = ?, nom_fichier = ?, chemin_fichier = ?, 
                        taille_fichier = ?, notes = ?, statut = ?,
                        date_upload = NOW()
                    WHERE id = ?
                ");
                
                $stmt->execute([
                    $type_document,
                    $nom_fichier,
                    $chemin_fichier,
                    $taille_fichier,
                    $notes,
                    $statut,
                    $id
                ]);
                
                $_SESSION['succes'] = "Document modifié avec succès.";
                rediriger('documents.php?action=details&id=' . $id);
            }
            break;
            
        case 'changer_statut':
            if ($id && isset($_POST['nouveau_statut'])) {
                $nouveau_statut = securiser($_POST['nouveau_statut']);
                
                $stmt = $pdo->prepare("
                    UPDATE documents_membres 
                    SET statut = ?, date_approbation = NOW(), approuve_par = ?
                    WHERE id = ?
                ");
                
                $stmt->execute([$nouveau_statut, $_SESSION['user_id'], $id]);
                
                $_SESSION['succes'] = "Statut du document mis à jour.";
                rediriger('documents.php?action=details&id=' . $id);
            }
            break;
            
        case 'telecharger':
            if ($id) {
                $stmt = $pdo->prepare("SELECT nom_fichier, chemin_fichier FROM documents_membres WHERE id = ?");
                $stmt->execute([$id]);
                $document = $stmt->fetch();
                
                if ($document && !empty($document['chemin_fichier']) && file_exists($document['chemin_fichier'])) {
                    $chemin_document = $document['chemin_fichier'];
                    $nom_fichier = $document['nom_fichier'];
                    
                    header('Content-Type: application/octet-stream');
                    header('Content-Disposition: attachment; filename="' . $nom_fichier . '"');
                    header('Content-Length: ' . filesize($chemin_document));
                    readfile($chemin_document);
                    exit;
                } else {
                    $_SESSION['erreur'] = "Document non trouvé.";
                    rediriger('documents.php?action=details&id=' . $id);
                }
            }
            break;
            
        case 'visualiser':
            if ($id) {
                $stmt = $pdo->prepare("SELECT nom_fichier, chemin_fichier FROM documents_membres WHERE id = ?");
                $stmt->execute([$id]);
                $document = $stmt->fetch();
                
                if ($document && !empty($document['chemin_fichier']) && file_exists($document['chemin_fichier'])) {
                    $chemin_document = $document['chemin_fichier'];
                    $extension = strtolower(pathinfo($document['nom_fichier'], PATHINFO_EXTENSION));
                    
                    // Déterminer le type MIME
                    $mime_types = [
                        'pdf' => 'application/pdf',
                        'jpg' => 'image/jpeg',
                        'jpeg' => 'image/jpeg',
                        'png' => 'image/png',
                        'gif' => 'image/gif'
                    ];
                    
                    if (isset($mime_types[$extension])) {
                        header('Content-Type: ' . $mime_types[$extension]);
                        header('Content-Disposition: inline; filename="' . $document['nom_fichier'] . '"');
                        readfile($chemin_document);
                        exit;
                    } else {
                        // Pour les types non visualisables, forcer le téléchargement
                        header('Content-Type: application/octet-stream');
                        header('Content-Disposition: attachment; filename="' . $document['nom_fichier'] . '"');
                        readfile($chemin_document);
                        exit;
                    }
                } else {
                    $_SESSION['erreur'] = "Document non trouvé.";
                    rediriger('documents.php?action=details&id=' . $id);
                }
            }
            break;
            
        case 'supprimer':
            if ($id) {
                // Vérifier que le document existe
                $stmt = $pdo->prepare("SELECT chemin_fichier FROM documents_membres WHERE id = ?");
                $stmt->execute([$id]);
                $document = $stmt->fetch();
                
                if ($document) {
                    // Supprimer le fichier physique s'il existe
                    if (!empty($document['chemin_fichier']) && file_exists($document['chemin_fichier'])) {
                        unlink($document['chemin_fichier']);
                    }
                    
                    $stmt = $pdo->prepare("DELETE FROM documents_membres WHERE id = ?");
                    $stmt->execute([$id]);
                    
                    $_SESSION['succes'] = "Document supprimé avec succès.";
                } else {
                    $_SESSION['erreur'] = "Document non trouvé.";
                }
                
                rediriger('documents.php');
            }
            break;
    }

    // Récupérer les données selon l'action
    switch ($action) {
        case 'details':
            // Détails d'un document spécifique
            $stmt = $pdo->prepare("
                SELECT d.*, m.nom, m.prenom, m.code_membre,
                       u.nom_complet as approbateur_nom
                FROM documents_membres d
                LEFT JOIN membres m ON d.code_membre = m.code_membre
                LEFT JOIN utilisateurs u ON d.approuve_par = u.id
                WHERE d.id = ?
            ");
            $stmt->execute([$id]);
            $document = $stmt->fetch();
            
            if (!$document) {
                $_SESSION['erreur'] = "Document non trouvé.";
                rediriger('documents.php');
            }
            break;
            
        case 'ajouter':
            // Pour l'ajout, récupérer la liste des membres
            $stmt_membres = $pdo->query("
                SELECT code_membre, nom, prenom 
                FROM membres 
                WHERE statut = 'actif'
                ORDER BY nom, prenom
            ");
            $membres = $stmt_membres->fetchAll();
            break;
            
        case 'modifier':
            // Pour la modification, récupérer le document et les membres
            $stmt = $pdo->prepare("SELECT * FROM documents_membres WHERE id = ?");
            $stmt->execute([$id]);
            $document = $stmt->fetch();
            
            if (!$document) {
                $_SESSION['erreur'] = "Document non trouvé.";
                rediriger('documents.php');
            }
            
            $stmt_membres = $pdo->query("
                SELECT code_membre, nom, prenom 
                FROM membres 
                WHERE statut = 'actif'
                ORDER BY nom, prenom
            ");
            $membres = $stmt_membres->fetchAll();
            break;
            
        default:
            // Liste des documents avec pagination et filtres
            $filtre_statut = $_GET['statut'] ?? '';
            $filtre_type = $_GET['type'] ?? '';
            $filtre_membre = $_GET['membre'] ?? '';
            
            $where_conditions = [];
            $params = [];
            
            if ($filtre_statut) {
                $where_conditions[] = "d.statut = ?";
                $params[] = $filtre_statut;
            }
            
            if ($filtre_type) {
                $where_conditions[] = "d.type_document = ?";
                $params[] = $filtre_type;
            }
            
            if ($filtre_membre) {
                $where_conditions[] = "m.code_membre LIKE ?";
                $params[] = "%$filtre_membre%";
            }
            
            $where_sql = '';
            if (!empty($where_conditions)) {
                $where_sql = 'WHERE ' . implode(' AND ', $where_conditions);
            }
            
            // Compter le total pour la pagination
            $stmt_count = $pdo->prepare("
                SELECT COUNT(*) 
                FROM documents_membres d
                LEFT JOIN membres m ON d.code_membre = m.code_membre
                $where_sql
            ");
            $stmt_count->execute($params);
            $total_documents = $stmt_count->fetchColumn();
            
            // Pagination
            $page = $_GET['page'] ?? 1;
            $limit = 20;
            $offset = ($page - 1) * $limit;
            $total_pages = ceil($total_documents / $limit);
            
            // Récupérer les documents
            $stmt_documents = $pdo->prepare("
                SELECT d.*, m.nom, m.prenom, m.code_membre,
                       u.nom_complet as approbateur_nom
                FROM documents_membres d
                LEFT JOIN membres m ON d.code_membre = m.code_membre
                LEFT JOIN utilisateurs u ON d.approuve_par = u.id
                $where_sql
                ORDER BY d.date_upload DESC
                LIMIT ? OFFSET ?
            ");
            
            $params_pagination = array_merge($params, [$limit, $offset]);
            $stmt_documents->execute($params_pagination);
            $documents = $stmt_documents->fetchAll();
            
            // Statistiques pour le dashboard des documents
            $stmt_stats = $pdo->query("
                SELECT 
                    COUNT(*) as total,
                    SUM(CASE WHEN statut = 'approuve' THEN 1 ELSE 0 END) as approuves,
                    SUM(CASE WHEN statut = 'en_attente' THEN 1 ELSE 0 END) as en_attente,
                    SUM(CASE WHEN statut = 'rejete' THEN 1 ELSE 0 END) as rejetes
                FROM documents_membres
            ");
            $stats_documents = $stmt_stats->fetch();
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
    <title>Gestion des Documents - <?php echo APP_NAME; ?></title>
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
        .file-icon {
            font-size: 2rem;
            margin-bottom: 10px;
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
                                <a class="nav-link active" href="documents.php">
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
                        <i class="fas fa-file-contract me-2"></i>Gestion des Documents
                    </h1>
                    <div class="btn-toolbar mb-2 mb-md-0">
                        <?php if ($action === 'liste'): ?>
                            <a href="documents.php?action=ajouter" class="btn btn-success">
                                <i class="fas fa-plus me-1"></i>Nouveau Document
                            </a>
                        <?php else: ?>
                            <a href="documents.php" class="btn btn-primary">
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
                    <!-- Statistiques des documents -->
                    <div class="row mb-4">
                        <div class="col-xl-3 col-md-6 mb-4">
                            <div class="card stat-card border-left-primary shadow h-100 py-2">
                                <div class="card-body">
                                    <div class="row no-gutters align-items-center">
                                        <div class="col mr-2">
                                            <div class="text-xs font-weight-bold text-primary text-uppercase mb-1">
                                                Total Documents
                                            </div>
                                            <div class="h5 mb-0 font-weight-bold text-gray-800">
                                                <?php echo number_format($stats_documents['total']); ?>
                                            </div>
                                        </div>
                                        <div class="col-auto">
                                            <i class="fas fa-file-contract fa-2x text-gray-300"></i>
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
                                                Documents Approuvés
                                            </div>
                                            <div class="h5 mb-0 font-weight-bold text-gray-800">
                                                <?php echo number_format($stats_documents['approuves']); ?>
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
                                                <?php echo number_format($stats_documents['en_attente']); ?>
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
                            <div class="card stat-card border-left-danger shadow h-100 py-2">
                                <div class="card-body">
                                    <div class="row no-gutters align-items-center">
                                        <div class="col mr-2">
                                            <div class="text-xs font-weight-bold text-danger text-uppercase mb-1">
                                                Documents Rejetés
                                            </div>
                                            <div class="h5 mb-0 font-weight-bold text-gray-800">
                                                <?php echo number_format($stats_documents['rejetes']); ?>
                                            </div>
                                        </div>
                                        <div class="col-auto">
                                            <i class="fas fa-times-circle fa-2x text-gray-300"></i>
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
                                        <option value="approuve" <?php echo $filtre_statut === 'approuve' ? 'selected' : ''; ?>>Approuvé</option>
                                        <option value="en_attente" <?php echo $filtre_statut === 'en_attente' ? 'selected' : ''; ?>>En attente</option>
                                        <option value="rejete" <?php echo $filtre_statut === 'rejete' ? 'selected' : ''; ?>>Rejeté</option>
                                    </select>
                                </div>
                                <div class="col-md-3">
                                    <label for="type" class="form-label">Type</label>
                                    <select name="type" id="type" class="form-select">
                                        <option value="">Tous les types</option>
                                        <option value="identite" <?php echo $filtre_type === 'identite' ? 'selected' : ''; ?>>Identité</option>
                                        <option value="justificatif_domicile" <?php echo $filtre_type === 'justificatif_domicile' ? 'selected' : ''; ?>>Justificatif de domicile</option>
                                        <option value="photo" <?php echo $filtre_type === 'photo' ? 'selected' : ''; ?>>Photo</option>
                                        <option value="contrat" <?php echo $filtre_type === 'contrat' ? 'selected' : ''; ?>>Contrat</option>
                                        <option value="bulletins_salaire" <?php echo $filtre_type === 'bulletins_salaire' ? 'selected' : ''; ?>>Bulletins de salaire</option>
                                        <option value="autre" <?php echo $filtre_type === 'autre' ? 'selected' : ''; ?>>Autre</option>
                                    </select>
                                </div>
                                <div class="col-md-3">
                                    <label for="membre" class="form-label">Code Membre</label>
                                    <input type="text" name="membre" id="membre" class="form-control" 
                                           value="<?php echo $filtre_membre; ?>" placeholder="Rechercher par code membre...">
                                </div>
                                <div class="col-md-3 d-flex align-items-end">
                                    <button type="submit" class="btn btn-primary me-2">
                                        <i class="fas fa-search me-1"></i>Filtrer
                                    </button>
                                    <a href="documents.php" class="btn btn-secondary">
                                        <i class="fas fa-times me-1"></i>Effacer
                                    </a>
                                </div>
                            </form>
                        </div>
                    </div>

                    <!-- Liste des documents -->
                    <div class="card shadow">
                        <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
                            <h6 class="m-0 font-weight-bold">
                                <i class="fas fa-list me-2"></i>Liste des Documents
                            </h6>
                            <span class="badge bg-light text-dark">
                                <?php echo number_format($total_documents); ?> document(s)
                            </span>
                        </div>
                        <div class="card-body">
                            <?php if (!empty($documents)): ?>
                                <div class="table-responsive">
                                    <table class="table table-striped table-hover">
                                        <thead>
                                            <tr>
                                                <th>ID</th>
                                                <th>Membre</th>
                                                <th>Type</th>
                                                <th>Nom du Fichier</th>
                                                <th>Taille</th>
                                                <th>Statut</th>
                                                <th>Date Upload</th>
                                                <th>Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($documents as $doc): ?>
                                                <tr>
                                                    <td><?php echo $doc['id']; ?></td>
                                                    <td>
                                                        <?php echo securiser($doc['nom'] . ' ' . $doc['prenom']); ?>
                                                        <br><small class="text-muted"><?php echo securiser($doc['code_membre']); ?></small>
                                                    </td>
                                                    <td>
                                                        <?php 
                                                        $type_icons = [
                                                            'identite' => 'fas fa-id-card',
                                                            'justificatif_domicile' => 'fas fa-home',
                                                            'photo' => 'fas fa-camera',
                                                            'contrat' => 'fas fa-file-signature',
                                                            'bulletins_salaire' => 'fas fa-file-invoice-dollar',
                                                            'autre' => 'fas fa-file'
                                                        ];
                                                        $type_labels = [
                                                            'identite' => 'Identité',
                                                            'justificatif_domicile' => 'Justificatif domicile',
                                                            'photo' => 'Photo',
                                                            'contrat' => 'Contrat',
                                                            'bulletins_salaire' => 'Bulletins salaire',
                                                            'autre' => 'Autre'
                                                        ];
                                                        ?>
                                                        <i class="<?php echo $type_icons[$doc['type_document']] ?? 'fas fa-file'; ?> me-1"></i>
                                                        <?php echo $type_labels[$doc['type_document']] ?? $doc['type_document']; ?>
                                                    </td>
                                                    <td>
                                                        <small><?php echo securiser($doc['nom_fichier']); ?></small>
                                                    </td>
                                                    <td><?php echo formater_taille_fichier($doc['taille_fichier']); ?></td>
                                                    <td>
                                                        <?php 
                                                        $badge_classes = [
                                                            'approuve' => 'bg-success',
                                                            'en_attente' => 'bg-warning',
                                                            'rejete' => 'bg-danger'
                                                        ];
                                                        $statut_labels = [
                                                            'approuve' => 'Approuvé',
                                                            'en_attente' => 'En attente',
                                                            'rejete' => 'Rejeté'
                                                        ];
                                                        ?>
                                                        <span class="badge <?php echo $badge_classes[$doc['statut']] ?? 'bg-secondary'; ?> badge-statut">
                                                            <?php echo $statut_labels[$doc['statut']] ?? $doc['statut']; ?>
                                                        </span>
                                                    </td>
                                                    <td><?php echo formater_date($doc['date_upload']); ?></td>
                                                    <td>
                                                        <div class="btn-group btn-group-sm">
                                                            <a href="documents.php?action=details&id=<?php echo $doc['id']; ?>" 
                                                               class="btn btn-info" title="Détails">
                                                                <i class="fas fa-eye"></i>
                                                            </a>
                                                            <a href="documents.php?action=visualiser&id=<?php echo $doc['id']; ?>" 
                                                               target="_blank" class="btn btn-primary" title="Visualiser">
                                                                <i class="fas fa-external-link-alt"></i>
                                                            </a>
                                                            <a href="documents.php?action=telecharger&id=<?php echo $doc['id']; ?>" 
                                                               class="btn btn-secondary" title="Télécharger">
                                                                <i class="fas fa-download"></i>
                                                            </a>
                                                            <?php if (a_la_permission('admin')): ?>
                                                                <a href="documents.php?action=modifier&id=<?php echo $doc['id']; ?>" 
                                                                   class="btn btn-warning" title="Modifier">
                                                                    <i class="fas fa-edit"></i>
                                                                </a>
                                                                <a href="documents.php?action=supprimer&id=<?php echo $doc['id']; ?>" 
                                                                   class="btn btn-danger" title="Supprimer"
                                                                   onclick="return confirm('Êtes-vous sûr de vouloir supprimer ce document ?')">
                                                                    <i class="fas fa-trash"></i>
                                                                </a>
                                                            <?php endif; ?>
                                                        </div>
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
                                            <?php if ($page > 1): ?>
                                                <li class="page-item">
                                                    <a class="page-link" href="?page=<?php echo $page - 1; ?>&statut=<?php echo $filtre_statut; ?>&type=<?php echo $filtre_type; ?>&membre=<?php echo $filtre_membre; ?>">
                                                        Précédent
                                                    </a>
                                                </li>
                                            <?php endif; ?>

                                            <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                                                <li class="page-item <?php echo $i == $page ? 'active' : ''; ?>">
                                                    <a class="page-link" href="?page=<?php echo $i; ?>&statut=<?php echo $filtre_statut; ?>&type=<?php echo $filtre_type; ?>&membre=<?php echo $filtre_membre; ?>">
                                                        <?php echo $i; ?>
                                                    </a>
                                                </li>
                                            <?php endfor; ?>

                                            <?php if ($page < $total_pages): ?>
                                                <li class="page-item">
                                                    <a class="page-link" href="?page=<?php echo $page + 1; ?>&statut=<?php echo $filtre_statut; ?>&type=<?php echo $filtre_type; ?>&membre=<?php echo $filtre_membre; ?>">
                                                        Suivant
                                                    </a>
                                                </li>
                                            <?php endif; ?>
                                        </ul>
                                    </nav>
                                <?php endif; ?>

                            <?php else: ?>
                                <div class="text-center py-5">
                                    <i class="fas fa-file-contract fa-4x text-muted mb-3"></i>
                                    <h5 class="text-muted">Aucun document trouvé</h5>
                                    <p class="text-muted">Commencez par ajouter votre premier document.</p>
                                    <a href="documents.php?action=ajouter" class="btn btn-primary">
                                        <i class="fas fa-plus me-1"></i>Ajouter un document
                                    </a>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                <?php elseif ($action === 'details' && isset($document)): ?>
                    <!-- Détails d'un document -->
                    <div class="row">
                        <div class="col-md-8">
                            <div class="card shadow mb-4">
                                <div class="card-header bg-info text-white">
                                    <h6 class="m-0 font-weight-bold">
                                        <i class="fas fa-info-circle me-2"></i>Détails du Document
                                    </h6>
                                </div>
                                <div class="card-body">
                                    <div class="row">
                                        <div class="col-md-6">
                                            <h6>Informations du Document</h6>
                                            <table class="table table-sm">
                                                <tr>
                                                    <th>ID:</th>
                                                    <td><?php echo $document['id']; ?></td>
                                                </tr>
                                                <tr>
                                                    <th>Type:</th>
                                                    <td>
                                                        <?php 
                                                        $type_labels = [
                                                            'identite' => 'Identité',
                                                            'justificatif_domicile' => 'Justificatif de domicile',
                                                            'photo' => 'Photo',
                                                            'contrat' => 'Contrat',
                                                            'bulletins_salaire' => 'Bulletins de salaire',
                                                            'autre' => 'Autre'
                                                        ];
                                                        echo $type_labels[$document['type_document']] ?? $document['type_document'];
                                                        ?>
                                                    </td>
                                                </tr>
                                                <tr>
                                                    <th>Nom du fichier:</th>
                                                    <td><?php echo securiser($document['nom_fichier']); ?></td>
                                                </tr>
                                                <tr>
                                                    <th>Taille:</th>
                                                    <td><?php echo formater_taille_fichier($document['taille_fichier']); ?></td>
                                                </tr>
                                                <tr>
                                                    <th>Statut:</th>
                                                    <td>
                                                        <?php 
                                                        $badge_classes = [
                                                            'approuve' => 'bg-success',
                                                            'en_attente' => 'bg-warning',
                                                            'rejete' => 'bg-danger'
                                                        ];
                                                        $statut_labels = [
                                                            'approuve' => 'Approuvé',
                                                            'en_attente' => 'En attente',
                                                            'rejete' => 'Rejeté'
                                                        ];
                                                        ?>
                                                        <span class="badge <?php echo $badge_classes[$document['statut']] ?? 'bg-secondary'; ?>">
                                                            <?php echo $statut_labels[$document['statut']] ?? $document['statut']; ?>
                                                        </span>
                                                    </td>
                                                </tr>
                                            </table>
                                        </div>
                                        <div class="col-md-6">
                                            <h6>Informations du Membre</h6>
                                            <table class="table table-sm">
                                                <tr>
                                                    <th>Code Membre:</th>
                                                    <td><?php echo securiser($document['code_membre']); ?></td>
                                                </tr>
                                                <tr>
                                                    <th>Nom:</th>
                                                    <td><?php echo securiser($document['nom'] . ' ' . $document['prenom']); ?></td>
                                                </tr>
                                                <tr>
                                                    <th>Date d'upload:</th>
                                                    <td><?php echo formater_date($document['date_upload']); ?></td>
                                                </tr>
                                                <?php if ($document['statut'] === 'approuve'): ?>
                                                    <tr>
                                                        <th>Approuvé par:</th>
                                                        <td><?php echo securiser($document['approbateur_nom']); ?></td>
                                                    </tr>
                                                    <tr>
                                                        <th>Date d'approbation:</th>
                                                        <td><?php echo formater_date($document['date_approbation']); ?></td>
                                                    </tr>
                                                <?php endif; ?>
                                            </table>
                                        </div>
                                    </div>

                                    <?php if (!empty($document['notes'])): ?>
                                        <div class="mt-3">
                                            <h6>Notes</h6>
                                            <div class="alert alert-light border">
                                                <?php echo nl2br(securiser($document['notes'])); ?>
                                            </div>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>

                        <div class="col-md-4">
                            <!-- Actions rapides -->
                            <div class="card shadow mb-4">
                                <div class="card-header bg-primary text-white">
                                    <h6 class="m-0 font-weight-bold">
                                        <i class="fas fa-cogs me-2"></i>Actions
                                    </h6>
                                </div>
                                <div class="card-body text-center">
                                    <div class="file-icon text-primary">
                                        <i class="fas fa-file-contract"></i>
                                    </div>
                                    <h5><?php echo securiser($document['nom_fichier']); ?></h5>
                                    
                                    <div class="d-grid gap-2 mt-4">
                                        <a href="documents.php?action=visualiser&id=<?php echo $document['id']; ?>" 
                                           target="_blank" class="btn btn-primary">
                                            <i class="fas fa-eye me-1"></i>Visualiser
                                        </a>
                                        <a href="documents.php?action=telecharger&id=<?php echo $document['id']; ?>" 
                                           class="btn btn-secondary">
                                            <i class="fas fa-download me-1"></i>Télécharger
                                        </a>
                                        
                                        <?php if (a_la_permission('admin')): ?>
                                            <a href="documents.php?action=modifier&id=<?php echo $document['id']; ?>" 
                                               class="btn btn-warning">
                                                <i class="fas fa-edit me-1"></i>Modifier
                                            </a>
                                            
                                            <!-- Changement de statut -->
                                            <div class="dropdown">
                                                <button class="btn btn-info dropdown-toggle w-100" type="button" 
                                                        data-bs-toggle="dropdown" aria-expanded="false">
                                                    <i class="fas fa-sync-alt me-1"></i>Changer Statut
                                                </button>
                                                <ul class="dropdown-menu w-100">
                                                    <li>
                                                        <form method="POST" action="documents.php?action=changer_statut&id=<?php echo $document['id']; ?>">
                                                            <input type="hidden" name="nouveau_statut" value="approuve">
                                                            <button type="submit" class="dropdown-item text-success">
                                                                <i class="fas fa-check me-1"></i>Approuver
                                                            </button>
                                                        </form>
                                                    </li>
                                                    <li>
                                                        <form method="POST" action="documents.php?action=changer_statut&id=<?php echo $document['id']; ?>">
                                                            <input type="hidden" name="nouveau_statut" value="rejete">
                                                            <button type="submit" class="dropdown-item text-danger">
                                                                <i class="fas fa-times me-1"></i>Rejeter
                                                            </button>
                                                        </form>
                                                    </li>
                                                    <li>
                                                        <form method="POST" action="documents.php?action=changer_statut&id=<?php echo $document['id']; ?>">
                                                            <input type="hidden" name="nouveau_statut" value="en_attente">
                                                            <button type="submit" class="dropdown-item text-warning">
                                                                <i class="fas fa-clock me-1"></i>Mettre en attente
                                                            </button>
                                                        </form>
                                                    </li>
                                                </ul>
                                            </div>
                                            
                                            <a href="documents.php?action=supprimer&id=<?php echo $document['id']; ?>" 
                                               class="btn btn-danger"
                                               onclick="return confirm('Êtes-vous sûr de vouloir supprimer ce document ?')">
                                                <i class="fas fa-trash me-1"></i>Supprimer
                                            </a>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                <?php elseif ($action === 'ajouter' || $action === 'modifier'): ?>
                    <!-- Formulaire d'ajout/modification -->
                    <div class="row justify-content-center">
                        <div class="col-md-8">
                            <div class="card shadow">
                                <div class="card-header <?php echo $action === 'ajouter' ? 'bg-success' : 'bg-warning'; ?> text-white">
                                    <h6 class="m-0 font-weight-bold">
                                        <i class="fas <?php echo $action === 'ajouter' ? 'fa-plus' : 'fa-edit'; ?> me-2"></i>
                                        <?php echo $action === 'ajouter' ? 'Ajouter un Document' : 'Modifier le Document'; ?>
                                    </h6>
                                </div>
                                <div class="card-body">
                                    <form method="POST" enctype="multipart/form-data">
                                        <div class="row">
                                            <div class="col-md-6">
                                                <div class="mb-3">
                                                    <label for="code_membre" class="form-label">Membre *</label>
                                                    <select name="code_membre" id="code_membre" class="form-select" required
                                                            <?php echo $action === 'modifier' ? 'disabled' : ''; ?>>
                                                        <option value="">Sélectionner un membre</option>
                                                        <?php foreach ($membres as $membre): ?>
                                                            <option value="<?php echo $membre['code_membre']; ?>"
                                                                <?php echo (isset($document) && $document['code_membre'] === $membre['code_membre']) ? 'selected' : ''; ?>>
                                                                <?php echo securiser($membre['nom'] . ' ' . $membre['prenom'] . ' (' . $membre['code_membre'] . ')'); ?>
                                                            </option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                    <?php if ($action === 'modifier'): ?>
                                                        <input type="hidden" name="code_membre" value="<?php echo $document['code_membre']; ?>">
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                            <div class="col-md-6">
                                                <div class="mb-3">
                                                    <label for="type_document" class="form-label">Type de Document *</label>
                                                    <select name="type_document" id="type_document" class="form-select" required>
                                                        <option value="">Sélectionner un type</option>
                                                        <option value="identite" <?php echo (isset($document) && $document['type_document'] === 'identite') ? 'selected' : ''; ?>>Identité</option>
                                                        <option value="justificatif_domicile" <?php echo (isset($document) && $document['type_document'] === 'justificatif_domicile') ? 'selected' : ''; ?>>Justificatif de domicile</option>
                                                        <option value="photo" <?php echo (isset($document) && $document['type_document'] === 'photo') ? 'selected' : ''; ?>>Photo</option>
                                                        <option value="contrat" <?php echo (isset($document) && $document['type_document'] === 'contrat') ? 'selected' : ''; ?>>Contrat</option>
                                                        <option value="bulletins_salaire" <?php echo (isset($document) && $document['type_document'] === 'bulletins_salaire') ? 'selected' : ''; ?>>Bulletins de salaire</option>
                                                        <option value="autre" <?php echo (isset($document) && $document['type_document'] === 'autre') ? 'selected' : ''; ?>>Autre</option>
                                                    </select>
                                                </div>
                                            </div>
                                        </div>

                                        <div class="mb-3">
                                            <label for="document" class="form-label">
                                                Document * 
                                                <small class="text-muted">(PDF, DOC, DOCX, JPG, PNG, GIF - Max 10MB)</small>
                                            </label>
                                            <input type="file" name="document" id="document" class="form-control" 
                                                   accept=".pdf,.doc,.docx,.jpg,.jpeg,.png,.gif"
                                                   <?php echo $action === 'ajouter' ? 'required' : ''; ?>>
                                            <?php if ($action === 'modifier' && !empty($document['nom_fichier'])): ?>
                                                <div class="form-text">
                                                    Document actuel: <?php echo securiser($document['nom_fichier']); ?>
                                                    <br>Laisser vide pour conserver le document actuel.
                                                </div>
                                            <?php endif; ?>
                                        </div>

                                        <div class="mb-3">
                                            <label for="notes" class="form-label">Notes</label>
                                            <textarea name="notes" id="notes" class="form-control" rows="4" 
                                                      placeholder="Notes optionnelles sur le document..."><?php echo isset($document['notes']) ? securiser($document['notes']) : ''; ?></textarea>
                                        </div>

                                        <?php if ($action === 'modifier' && a_la_permission('admin')): ?>
                                            <div class="mb-3">
                                                <label for="statut" class="form-label">Statut</label>
                                                <select name="statut" id="statut" class="form-select">
                                                    <option value="en_attente" <?php echo $document['statut'] === 'en_attente' ? 'selected' : ''; ?>>En attente</option>
                                                    <option value="approuve" <?php echo $document['statut'] === 'approuve' ? 'selected' : ''; ?>>Approuvé</option>
                                                    <option value="rejete" <?php echo $document['statut'] === 'rejete' ? 'selected' : ''; ?>>Rejeté</option>
                                                </select>
                                            </div>
                                        <?php endif; ?>

                                        <div class="d-flex justify-content-between">
                                            <a href="documents.php" class="btn btn-secondary">
                                                <i class="fas fa-arrow-left me-1"></i>Retour
                                            </a>
                                            <button type="submit" class="btn <?php echo $action === 'ajouter' ? 'btn-success' : 'btn-warning'; ?>">
                                                <i class="fas <?php echo $action === 'ajouter' ? 'fa-save' : 'fa-edit'; ?> me-1"></i>
                                                <?php echo $action === 'ajouter' ? 'Ajouter le Document' : 'Modifier le Document'; ?>
                                            </button>
                                        </div>
                                    </form>
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
        // Script pour afficher un aperçu du nom du fichier sélectionné
        document.getElementById('document').addEventListener('change', function(e) {
            const fileName = e.target.files[0]?.name;
            if (fileName) {
                const fileInfo = document.createElement('div');
                fileInfo.className = 'form-text text-success';
                fileInfo.innerHTML = `<i class="fas fa-file me-1"></i>Fichier sélectionné: ${fileName}`;
                
                // Supprimer l'ancien message s'il existe
                const oldFileInfo = this.parentNode.querySelector('.text-success');
                if (oldFileInfo) {
                    oldFileInfo.remove();
                }
                
                this.parentNode.appendChild(fileInfo);
            }
        });

        // Auto-sélection du texte dans les champs de recherche au clic
        document.querySelectorAll('input[type="text"]').forEach(input => {
            input.addEventListener('click', function() {
                this.select();
            });
        });
    </script>
</body>
</html>