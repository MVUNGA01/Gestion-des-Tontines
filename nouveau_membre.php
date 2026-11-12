<?php
require_once 'config_database.php';

// Vérifier l'authentification
if (!est_connecte()) {
    rediriger('login.php');
}

// Vérifier les permissions
if (!a_la_permission('admin') && !a_la_permission('gestionnaire')) {
    $_SESSION['erreur'] = "Vous n'avez pas la permission de créer des membres.";
    rediriger('dashboard.php');
}

// Fonction pour calculer l'âge à partir d'une date
function calculer_age($date_naissance) {
    if (empty($date_naissance)) return 0;
    
    $date_naissance = new DateTime($date_naissance);
    $aujourdhui = new DateTime();
    $difference = $aujourdhui->diff($date_naissance);
    return $difference->y;
}

// Fonction pour valider et télécharger la photo
function uploader_photo_profil($file) {
    // Vérifier s'il y a une erreur
    if ($file['error'] !== UPLOAD_ERR_OK) {
        if ($file['error'] === UPLOAD_ERR_NO_FILE) {
            return ['success' => true, 'path' => null]; // Pas de fichier
        }
        return ['success' => false, 'error' => 'Erreur lors du téléchargement: ' . $file['error']];
    }
    
    // Vérifier la taille (max 5MB)
    $max_size = 5 * 1024 * 1024; // 5MB
    if ($file['size'] > $max_size) {
        return ['success' => false, 'error' => 'La photo est trop volumineuse (max 5MB)'];
    }
    
    // Vérifier le type MIME
    $allowed_types = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif'];
    $file_info = finfo_open(FILEINFO_MIME_TYPE);
    $mime_type = finfo_file($file_info, $file['tmp_name']);
    finfo_close($file_info);
    
    if (!in_array($mime_type, $allowed_types)) {
        return ['success' => false, 'error' => 'Type de fichier non autorisé. Types acceptés: JPEG, PNG, GIF'];
    }
    
    // Créer le dossier de destination s'il n'existe pas
    $upload_dir = 'uploads/profils/';
    if (!file_exists($upload_dir)) {
        mkdir($upload_dir, 0777, true);
    }
    
    // Générer un nom de fichier unique
    $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
    $filename = uniqid('profil_') . '_' . time() . '.' . $extension;
    $destination = $upload_dir . $filename;
    
    // Déplacer le fichier
    if (move_uploaded_file($file['tmp_name'], $destination)) {
        return ['success' => true, 'path' => $destination];
    } else {
        return ['success' => false, 'error' => 'Échec du téléchargement de la photo'];
    }
}

// Initialiser les variables
$erreurs = [];
$succes = '';
$categories = [];
$donnees_formulaire = [
    'nom' => '',
    'prenom' => '',
    'email' => '',
    'telephone' => '',
    'adresse' => '',
    'categorie_id' => '',
    'date_naissance' => '',
    'lieu_naissance' => '',
    'profession' => '',
    'employeur' => '',
    'salaire_mensuel' => '',
    'nom_contact_urgence' => '',
    'tel_contact_urgence' => '',
    'relation_contact_urgence' => ''
];

// Récupérer la liste des catégories actives
try {
    $sql_categories = "SELECT id, code_categorie, nom_categorie, cotisation_mensuelle 
                      FROM categories_membres 
                      WHERE statut = 'actif'
                      ORDER BY cotisation_mensuelle";
    $stmt_categories = $pdo->query($sql_categories);
    $categories = $stmt_categories->fetchAll();
} catch (PDOException $e) {
    $erreurs[] = "Erreur lors du chargement des catégories: " . $e->getMessage();
}

// Traitement du formulaire
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Récupérer et valider les données
    $donnees_formulaire['nom'] = trim($_POST['nom'] ?? '');
    $donnees_formulaire['prenom'] = trim($_POST['prenom'] ?? '');
    $donnees_formulaire['email'] = trim($_POST['email'] ?? '');
    $donnees_formulaire['telephone'] = trim($_POST['telephone'] ?? '');
    $donnees_formulaire['adresse'] = trim($_POST['adresse'] ?? '');
    $donnees_formulaire['categorie_id'] = intval($_POST['categorie_id'] ?? 0);
    $donnees_formulaire['date_naissance'] = $_POST['date_naissance'] ?? '';
    $donnees_formulaire['lieu_naissance'] = trim($_POST['lieu_naissance'] ?? '');
    $donnees_formulaire['profession'] = trim($_POST['profession'] ?? '');
    $donnees_formulaire['employeur'] = trim($_POST['employeur'] ?? '');
    $donnees_formulaire['salaire_mensuel'] = floatval($_POST['salaire_mensuel'] ?? 0);
    $donnees_formulaire['nom_contact_urgence'] = trim($_POST['nom_contact_urgence'] ?? '');
    $donnees_formulaire['tel_contact_urgence'] = trim($_POST['tel_contact_urgence'] ?? '');
    $donnees_formulaire['relation_contact_urgence'] = trim($_POST['relation_contact_urgence'] ?? '');

    // Validation
    if (empty($donnees_formulaire['nom']) || empty($donnees_formulaire['prenom'])) {
        $erreurs[] = "Le nom et le prénom sont obligatoires.";
    }

    if (empty($donnees_formulaire['email']) || !filter_var($donnees_formulaire['email'], FILTER_VALIDATE_EMAIL)) {
        $erreurs[] = "Veuillez saisir une adresse email valide.";
    }

    if (empty($donnees_formulaire['categorie_id'])) {
        $erreurs[] = "Veuillez sélectionner une catégorie.";
    }

    // Vérifier la date de naissance - CONDITION D'ÂGE
    if (!empty($donnees_formulaire['date_naissance'])) {
        $age = calculer_age($donnees_formulaire['date_naissance']);
        if ($age < 18) {
            $erreurs[] = "Le membre doit avoir au moins 18 ans. Âge calculé: " . $age . " ans.";
        }
        
        // Vérifier que la date de naissance n'est pas dans le futur
        $date_naissance_obj = new DateTime($donnees_formulaire['date_naissance']);
        $aujourdhui = new DateTime();
        if ($date_naissance_obj > $aujourdhui) {
            $erreurs[] = "La date de naissance ne peut pas être dans le futur.";
        }
        
        // Vérifier que la date est raisonnable (pas avant 1900)
        $date_min = new DateTime('1900-01-01');
        if ($date_naissance_obj < $date_min) {
            $erreurs[] = "La date de naissance doit être après 1900.";
        }
    }

    // Vérifier si l'email existe déjà
    if (empty($erreurs)) {
        try {
            $stmt_verif_email = $pdo->prepare("SELECT COUNT(*) as count FROM membres WHERE email = ?");
            $stmt_verif_email->execute([$donnees_formulaire['email']]);
            $result = $stmt_verif_email->fetch();
            
            if ($result['count'] > 0) {
                $erreurs[] = "Cet email est déjà utilisé par un autre membre.";
            }
        } catch (PDOException $e) {
            $erreurs[] = "Erreur lors de la vérification de l'email: " . $e->getMessage();
        }
    }

    // Traiter la photo de profil
    $photo_profil_path = null;
    if (empty($erreurs) && isset($_FILES['photo_profil']) && $_FILES['photo_profil']['error'] !== UPLOAD_ERR_NO_FILE) {
        $upload_result = uploader_photo_profil($_FILES['photo_profil']);
        if (!$upload_result['success']) {
            $erreurs[] = $upload_result['error'];
        } else {
            $photo_profil_path = $upload_result['path'];
        }
    }

    // Si pas d'erreurs, créer le membre
    if (empty($erreurs)) {
        try {
            $pdo->beginTransaction();

            // Récupérer les informations de la catégorie
            $stmt_categorie = $pdo->prepare("SELECT code_categorie, nom_categorie, cotisation_mensuelle FROM categories_membres WHERE id = ?");
            $stmt_categorie->execute([$donnees_formulaire['categorie_id']]);
            $categorie = $stmt_categorie->fetch();

            if (!$categorie) {
                $erreurs[] = "Catégorie sélectionnée invalide.";
            } else {
                // Le code membre sera généré automatiquement par le trigger
                $sql_insert = "INSERT INTO membres (
                    code_membre, nom, prenom, email, telephone, adresse, 
                    categorie_id, date_naissance, lieu_naissance, profession, 
                    employeur, salaire_mensuel, nom_contact_urgence, 
                    tel_contact_urgence, relation_contact_urgence, 
                    photo_profil, statut, date_inscription
                ) VALUES (
                    NULL, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'actif', NOW()
                )";

                $stmt_insert = $pdo->prepare($sql_insert);
                $stmt_insert->execute([
                    $donnees_formulaire['nom'],
                    $donnees_formulaire['prenom'],
                    $donnees_formulaire['email'],
                    $donnees_formulaire['telephone'],
                    $donnees_formulaire['adresse'],
                    $donnees_formulaire['categorie_id'],
                    $donnees_formulaire['date_naissance'] ?: NULL,
                    $donnees_formulaire['lieu_naissance'],
                    $donnees_formulaire['profession'],
                    $donnees_formulaire['employeur'],
                    $donnees_formulaire['salaire_mensuel'],
                    $donnees_formulaire['nom_contact_urgence'],
                    $donnees_formulaire['tel_contact_urgence'],
                    $donnees_formulaire['relation_contact_urgence'],
                    $photo_profil_path
                ]);

                $membre_id = $pdo->lastInsertId();

                // Récupérer le code membre généré
                $stmt_code = $pdo->prepare("SELECT code_membre FROM membres WHERE id = ?");
                $stmt_code->execute([$membre_id]);
                $membre = $stmt_code->fetch();
                $code_membre = $membre['code_membre'];

                $pdo->commit();

                $_SESSION['succes'] = "Membre créé avec succès! Code membre: " . $code_membre;
                rediriger('profil_membre.php?code=' . $code_membre);

            }
        } catch (PDOException $e) {
            $pdo->rollBack();
            // Supprimer la photo téléchargée si l'insertion a échoué
            if ($photo_profil_path && file_exists($photo_profil_path)) {
                unlink($photo_profil_path);
            }
            $erreurs[] = "Erreur lors de la création du membre: " . $e->getMessage();
            error_log("Erreur création membre: " . $e->getMessage());
        }
    }
}
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Nouveau Membre - <?php echo APP_NAME; ?></title>
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
        .card-categorie {
            border-left: 4px solid #007bff;
            transition: all 0.3s;
            cursor: pointer;
        }
        .card-categorie:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0,0,0,0.1);
        }
        .categorie-selected {
            border-color: #28a745;
            background-color: #f8f9fa;
        }
        .required:after {
            content: " *";
            color: #dc3545;
        }
        .section-title {
            border-bottom: 2px solid #dee2e6;
            padding-bottom: 10px;
            margin-bottom: 20px;
        }
        .form-section {
            background: #f8f9fa;
            border-radius: 5px;
            padding: 20px;
            margin-bottom: 20px;
        }
        .age-indicator {
            font-size: 0.85rem;
            padding: 3px 8px;
            border-radius: 3px;
            margin-left: 5px;
        }
        .age-valid {
            background-color: #d4edda;
            color: #155724;
        }
        .age-invalid {
            background-color: #f8d7da;
            color: #721c24;
        }
        .photo-preview-container {
            width: 150px;
            height: 150px;
            border: 2px dashed #dee2e6;
            border-radius: 50%;
            overflow: hidden;
            margin: 0 auto 20px;
            position: relative;
            cursor: pointer;
            background-color: #f8f9fa;
        }
        .photo-preview {
            width: 100%;
            height: 100%;
            object-fit: cover;
            display: none;
        }
        .photo-placeholder {
            width: 100%;
            height: 100%;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            color: #6c757d;
        }
        .photo-placeholder i {
            font-size: 48px;
            margin-bottom: 10px;
        }
        .photo-input {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            opacity: 0;
            cursor: pointer;
        }
        .file-info {
            font-size: 0.8rem;
            color: #6c757d;
            margin-top: 5px;
        }
        .remove-photo {
            position: absolute;
            top: 5px;
            right: 5px;
            background: rgba(220, 53, 69, 0.8);
            color: white;
            border: none;
            border-radius: 50%;
            width: 25px;
            height: 25px;
            display: none;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            z-index: 10;
        }
        .remove-photo:hover {
            background: #dc3545;
        }
        .photo-instructions {
            font-size: 0.85rem;
            color: #6c757d;
        }
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

                        <!-- Menu principal pour tous les utilisateurs -->
                        <div class="menu-section">
                            <div class="menu-section-title">Navigation Principale</div>
                            
                            <li class="nav-item">
                                <a class="nav-link active" href="membres.php">
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
                <!-- En-tête de page -->
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <div>
                        <h1 class="h3 mb-0">
                            <i class="fas fa-user-plus me-2"></i>Nouveau Membre
                        </h1>
                        <p class="text-muted mb-0">Ajouter un nouveau membre au système</p>
                    </div>
                    <div>
                        <a href="membres.php" class="btn btn-secondary">
                            <i class="fas fa-arrow-left me-2"></i>Retour aux membres
                        </a>
                    </div>
                </div>

                <!-- Messages d'alerte -->
                <?php if (!empty($erreurs)): ?>
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        <h5 class="alert-heading"><i class="fas fa-exclamation-triangle me-2"></i>Erreurs</h5>
                        <ul class="mb-0">
                            <?php foreach ($erreurs as $erreur): ?>
                                <li><?php echo htmlspecialchars($erreur); ?></li>
                            <?php endforeach; ?>
                        </ul>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <?php if (isset($_SESSION['succes'])): ?>
                    <div class="alert alert-success alert-dismissible fade show" role="alert">
                        <?php echo htmlspecialchars($_SESSION['succes']); unset($_SESSION['succes']); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <form method="POST" id="formMembre" enctype="multipart/form-data">
                    <div class="row">
                        <div class="col-lg-8">
                            <!-- Photo de profil -->
                            <div class="card shadow mb-4">
                                <div class="card-header bg-secondary text-white">
                                    <h6 class="m-0 font-weight-bold">
                                        <i class="fas fa-camera me-2"></i>Photo de Profil
                                    </h6>
                                </div>
                                <div class="card-body text-center">
                                    <div class="photo-preview-container" id="photoContainer">
                                        <div class="photo-placeholder" id="photoPlaceholder">
                                            <i class="fas fa-user-circle"></i>
                                            <span>Cliquer pour ajouter une photo</span>
                                        </div>
                                        <img src="" alt="Aperçu photo" class="photo-preview" id="photoPreview">
                                        <button type="button" class="remove-photo" id="removePhoto" title="Supprimer la photo">
                                            <i class="fas fa-times"></i>
                                        </button>
                                        <input type="file" class="photo-input" id="photo_profil" name="photo_profil" accept="image/*">
                                    </div>
                                    <div class="file-info" id="fileInfo">
                                        Aucun fichier sélectionné
                                    </div>
                                    <div class="photo-instructions mt-2">
                                        <small>
                                            <i class="fas fa-info-circle me-1"></i>
                                            Formats acceptés: JPEG, PNG, GIF • Taille max: 5MB
                                        </small>
                                    </div>
                                </div>
                            </div>

                            <!-- Informations personnelles -->
                            <div class="card shadow mb-4">
                                <div class="card-header bg-primary text-white">
                                    <h6 class="m-0 font-weight-bold">
                                        <i class="fas fa-user me-2"></i>Informations Personnelles
                                    </h6>
                                </div>
                                <div class="card-body">
                                    <div class="row">
                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label for="nom" class="form-label required">Nom</label>
                                                <input type="text" class="form-control" id="nom" name="nom" 
                                                       value="<?php echo htmlspecialchars($donnees_formulaire['nom']); ?>" 
                                                       required maxlength="100">
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label for="prenom" class="form-label required">Prénom</label>
                                                <input type="text" class="form-control" id="prenom" name="prenom" 
                                                       value="<?php echo htmlspecialchars($donnees_formulaire['prenom']); ?>" 
                                                       required maxlength="100">
                                            </div>
                                        </div>
                                    </div>

                                    <div class="row">
                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label for="email" class="form-label required">Email</label>
                                                <input type="email" class="form-control" id="email" name="email" 
                                                       value="<?php echo htmlspecialchars($donnees_formulaire['email']); ?>" 
                                                       required maxlength="150">
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label for="telephone" class="form-label">Téléphone</label>
                                                <input type="text" class="form-control" id="telephone" name="telephone" 
                                                       value="<?php echo htmlspecialchars($donnees_formulaire['telephone']); ?>" 
                                                       maxlength="20" placeholder="+257 XX XX XX XX">
                                            </div>
                                        </div>
                                    </div>

                                    <div class="mb-3">
                                        <label for="adresse" class="form-label">Adresse</label>
                                        <textarea class="form-control" id="adresse" name="adresse" rows="2" 
                                                  maxlength="500"><?php echo htmlspecialchars($donnees_formulaire['adresse']); ?></textarea>
                                    </div>

                                    <div class="row">
                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label for="date_naissance" class="form-label">Date de naissance</label>
                                                <div class="input-group">
                                                    <input type="date" class="form-control" id="date_naissance" name="date_naissance" 
                                                           value="<?php echo htmlspecialchars($donnees_formulaire['date_naissance']); ?>"
                                                           max="<?php echo date('Y-m-d'); ?>"
                                                           onchange="calculerAge()">
                                                    <span id="age_indicator" class="input-group-text age-indicator" style="display: none;"></span>
                                                </div>
                                                <div class="form-text">
                                                    <span id="age_message">Le membre doit avoir au moins 18 ans</span>
                                                    <span id="age_calcul" class="ms-2 fw-bold"></span>
                                                </div>
                                                <div class="form-text small">
                                                    Date maximum: <?php echo date('d/m/Y'); ?>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label for="lieu_naissance" class="form-label">Lieu de naissance</label>
                                                <input type="text" class="form-control" id="lieu_naissance" name="lieu_naissance" 
                                                       value="<?php echo htmlspecialchars($donnees_formulaire['lieu_naissance']); ?>" 
                                                       maxlength="100">
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Informations professionnelles -->
                            <div class="card shadow mb-4">
                                <div class="card-header bg-info text-white">
                                    <h6 class="m-0 font-weight-bold">
                                        <i class="fas fa-briefcase me-2"></i>Informations Professionnelles
                                    </h6>
                                </div>
                                <div class="card-body">
                                    <div class="row">
                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label for="profession" class="form-label">Profession</label>
                                                <input type="text" class="form-control" id="profession" name="profession" 
                                                       value="<?php echo htmlspecialchars($donnees_formulaire['profession']); ?>" 
                                                       maxlength="100">
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label for="employeur" class="form-label">Employeur</label>
                                                <input type="text" class="form-control" id="employeur" name="employeur" 
                                                       value="<?php echo htmlspecialchars($donnees_formulaire['employeur']); ?>" 
                                                       maxlength="150">
                                            </div>
                                        </div>
                                    </div>

                                    <div class="mb-3">
                                        <label for="salaire_mensuel" class="form-label">Salaire Mensuel (FBu)</label>
                                        <div class="input-group">
                                            <input type="number" class="form-control" id="salaire_mensuel" name="salaire_mensuel" 
                                                   value="<?php echo htmlspecialchars($donnees_formulaire['salaire_mensuel']); ?>" 
                                                   min="0" step="1000">
                                            <span class="input-group-text">FBu</span>
                                        </div>
                                        <div class="form-text">Montant en Francs Burundais</div>
                                    </div>
                                </div>
                            </div>

                            <!-- Contact d'urgence -->
                            <div class="card shadow mb-4">
                                <div class="card-header bg-warning text-dark">
                                    <h6 class="m-0 font-weight-bold">
                                        <i class="fas fa-phone-alt me-2"></i>Contact d'Urgence
                                    </h6>
                                </div>
                                <div class="card-body">
                                    <div class="row">
                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label for="nom_contact_urgence" class="form-label">Nom du contact</label>
                                                <input type="text" class="form-control" id="nom_contact_urgence" name="nom_contact_urgence" 
                                                       value="<?php echo htmlspecialchars($donnees_formulaire['nom_contact_urgence']); ?>" 
                                                       maxlength="100">
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label for="tel_contact_urgence" class="form-label">Téléphone du contact</label>
                                                <input type="text" class="form-control" id="tel_contact_urgence" name="tel_contact_urgence" 
                                                       value="<?php echo htmlspecialchars($donnees_formulaire['tel_contact_urgence']); ?>" 
                                                       maxlength="20">
                                            </div>
                                        </div>
                                    </div>

                                    <div class="mb-3">
                                        <label for="relation_contact_urgence" class="form-label">Relation avec le membre</label>
                                        <select class="form-select" id="relation_contact_urgence" name="relation_contact_urgence">
                                            <option value="">Sélectionner...</option>
                                            <option value="Conjoint(e)" <?php echo $donnees_formulaire['relation_contact_urgence'] == 'Conjoint(e)' ? 'selected' : ''; ?>>Conjoint(e)</option>
                                            <option value="Parent" <?php echo $donnees_formulaire['relation_contact_urgence'] == 'Parent' ? 'selected' : ''; ?>>Parent</option>
                                            <option value="Frère/Sœur" <?php echo $donnees_formulaire['relation_contact_urgence'] == 'Frère/Sœur' ? 'selected' : ''; ?>>Frère/Sœur</option>
                                            <option value="Enfant" <?php echo $donnees_formulaire['relation_contact_urgence'] == 'Enfant' ? 'selected' : ''; ?>>Enfant</option>
                                            <option value="Ami(e)" <?php echo $donnees_formulaire['relation_contact_urgence'] == 'Ami(e)' ? 'selected' : ''; ?>>Ami(e)</option>
                                            <option value="Collègue" <?php echo $donnees_formulaire['relation_contact_urgence'] == 'Collègue' ? 'selected' : ''; ?>>Collègue</option>
                                            <option value="Autre" <?php echo $donnees_formulaire['relation_contact_urgence'] == 'Autre' ? 'selected' : ''; ?>>Autre</option>
                                        </select>
                                        <div class="form-text">Ex: Conjoint(e), Parent, Frère/Sœur, Ami</div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="col-lg-4">
                            <!-- Sélection de la catégorie -->
                            <div class="card shadow mb-4">
                                <div class="card-header bg-success text-white">
                                    <h6 class="m-0 font-weight-bold">
                                        <i class="fas fa-tags me-2"></i>Catégorie de Membre
                                    </h6>
                                </div>
                                <div class="card-body">
                                    <div class="mb-3">
                                        <label for="categorie_id" class="form-label required">Sélectionnez une catégorie</label>
                                        <select class="form-select" id="categorie_id" name="categorie_id" required>
                                            <option value="">Choisir une catégorie...</option>
                                            <?php if (!empty($categories)): ?>
                                                <?php foreach ($categories as $categorie): ?>
                                                    <option value="<?php echo $categorie['id']; ?>" 
                                                            data-cotisation="<?php echo $categorie['cotisation_mensuelle']; ?>"
                                                            <?php echo $donnees_formulaire['categorie_id'] == $categorie['id'] ? 'selected' : ''; ?>>
                                                        <?php echo htmlspecialchars($categorie['nom_categorie'] . ' (' . number_format($categorie['cotisation_mensuelle']) . ' FBu/mois)'); ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            <?php else: ?>
                                                <option value="">Aucune catégorie disponible</option>
                                            <?php endif; ?>
                                        </select>
                                    </div>

                                    <!-- Détails de la catégorie sélectionnée -->
                                    <div id="details_categorie" class="form-section" style="display: none;">
                                        <h6>Détails de la catégorie</h6>
                                        <div class="small">
                                            <div class="mb-2">
                                                <strong>Code:</strong> <span id="categorie_code"></span>
                                            </div>
                                            <div class="mb-2">
                                                <strong>Cotisation mensuelle:</strong> 
                                                <span id="categorie_cotisation" class="fw-bold"></span> FBu
                                            </div>
                                            <div class="mb-2">
                                                <strong>Statut:</strong> <span class="badge bg-success">Actif</span>
                                            </div>
                                            <div class="alert alert-info mt-2 small">
                                                <i class="fas fa-info-circle me-2"></i>
                                                Le membre sera automatiquement ajouté comme emprunteur avec un score de crédit initial de 500.
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Informations système -->
                                    <div class="alert alert-secondary mt-3 small">
                                        <h6><i class="fas fa-info-circle me-2"></i>Conditions d'admission</h6>
                                        <ul class="mb-0">
                                            <li>Âge minimum: <strong>18 ans</strong></li>
                                            <li>Le code membre sera généré automatiquement</li>
                                            <li>Le statut initial sera "Actif"</li>
                                            <li>Un profil d'emprunteur sera créé automatiquement</li>
                                            <li>Le score de crédit initial est de 500 points</li>
                                            <li>Le statut emprunteur sera "en_attente"</li>
                                        </ul>
                                    </div>
                                </div>
                            </div>

                            <!-- Boutons de soumission -->
                            <div class="card shadow">
                                <div class="card-body">
                                    <div class="d-grid gap-2">
                                        <button type="submit" class="btn btn-primary btn-lg" id="submitBtn">
                                            <i class="fas fa-check me-2"></i>Créer le Membre
                                        </button>
                                        <a href="membres.php" class="btn btn-secondary">
                                            <i class="fas fa-times me-2"></i>Annuler
                                        </a>
                                    </div>
                                </div>
                            </div>

                            <!-- Aide -->
                            <div class="card shadow mt-4">
                                <div class="card-header bg-light">
                                    <h6 class="m-0 font-weight-bold">
                                        <i class="fas fa-question-circle me-2"></i>Aide
                                    </h6>
                                </div>
                                <div class="card-body small">
                                    <p><strong>Champs obligatoires:</strong> Nom, Prénom, Email et Catégorie</p>
                                    <p><strong>Âge minimum:</strong> 18 ans requis pour l'adhésion</p>
                                    <p><strong>Photo de profil:</strong> Formats acceptés: JPEG, PNG, GIF • Max 5MB</p>
                                    <p><strong>Code membre:</strong> Généré automatiquement (format: MEMAAAAMMNNNN)</p>
                                    <p><strong>Score de crédit:</strong> 500 points par défaut</p>
                                    <p><strong>Éligibilité aux prêts:</strong> Après 3 mois de cotisations</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </form>
            </main>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Données des catégories pour JavaScript
        const categoriesData = <?php echo json_encode($categories ?: []); ?>;
        
        // Fonction pour calculer l'âge à partir d'une date
        function calculerAge() {
            const dateInput = document.getElementById('date_naissance');
            const ageIndicator = document.getElementById('age_indicator');
            const ageCalcul = document.getElementById('age_calcul');
            const submitBtn = document.getElementById('submitBtn');
            
            if (dateInput.value) {
                const dateNaissance = new Date(dateInput.value);
                const aujourdhui = new Date();
                let age = aujourdhui.getFullYear() - dateNaissance.getFullYear();
                const moisDifference = aujourdhui.getMonth() - dateNaissance.getMonth();
                
                // Ajuster si l'anniversaire n'est pas encore passé cette année
                if (moisDifference < 0 || (moisDifference === 0 && aujourdhui.getDate() < dateNaissance.getDate())) {
                    age--;
                }
                
                // Afficher l'âge calculé
                ageCalcul.textContent = `Âge calculé: ${age} ans`;
                
                // Vérifier si l'âge est valide
                if (age >= 18) {
                    ageIndicator.textContent = `✓ ${age} ans`;
                    ageIndicator.className = "input-group-text age-indicator age-valid";
                    ageIndicator.style.display = 'inline-block';
                    submitBtn.disabled = false;
                    ageCalcul.className = "text-success fw-bold";
                } else {
                    ageIndicator.textContent = `✗ ${age} ans`;
                    ageIndicator.className = "input-group-text age-indicator age-invalid";
                    ageIndicator.style.display = 'inline-block';
                    submitBtn.disabled = true;
                    ageCalcul.className = "text-danger fw-bold";
                }
            } else {
                ageIndicator.style.display = 'none';
                ageCalcul.textContent = '';
                submitBtn.disabled = false;
            }
        }
        
        // Afficher les détails de la catégorie sélectionnée
        document.getElementById('categorie_id').addEventListener('change', function() {
            const categorieId = this.value;
            const detailsDiv = document.getElementById('details_categorie');
            
            if (categorieId) {
                // Trouver la catégorie dans les données
                const categorie = categoriesData.find(c => c.id == categorieId);
                if (categorie) {
                    document.getElementById('categorie_code').textContent = categorie.code_categorie;
                    document.getElementById('categorie_cotisation').textContent = 
                        parseFloat(categorie.cotisation_mensuelle).toLocaleString('fr-FR');
                    detailsDiv.style.display = 'block';
                }
            } else {
                detailsDiv.style.display = 'none';
            }
        });
        
        // Gestion de la photo de profil
        const photoInput = document.getElementById('photo_profil');
        const photoPreview = document.getElementById('photoPreview');
        const photoPlaceholder = document.getElementById('photoPlaceholder');
        const removePhotoBtn = document.getElementById('removePhoto');
        const fileInfo = document.getElementById('fileInfo');
        const photoContainer = document.getElementById('photoContainer');
        
        photoInput.addEventListener('change', function(e) {
            const file = this.files[0];
            if (file) {
                // Vérifier la taille
                const maxSize = 5 * 1024 * 1024; // 5MB
                if (file.size > maxSize) {
                    alert('La photo est trop volumineuse (max 5MB). Veuillez choisir une autre photo.');
                    this.value = '';
                    return;
                }
                
                // Vérifier le type
                const allowedTypes = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif'];
                if (!allowedTypes.includes(file.type)) {
                    alert('Type de fichier non autorisé. Veuillez choisir une image JPEG, PNG ou GIF.');
                    this.value = '';
                    return;
                }
                
                // Afficher l'aperçu
                const reader = new FileReader();
                reader.onload = function(e) {
                    photoPreview.src = e.target.result;
                    photoPreview.style.display = 'block';
                    photoPlaceholder.style.display = 'none';
                    removePhotoBtn.style.display = 'flex';
                    fileInfo.textContent = `${file.name} (${(file.size / 1024).toFixed(2)} KB)`;
                    photoContainer.style.borderColor = '#28a745';
                };
                reader.readAsDataURL(file);
            }
        });
        
        // Supprimer la photo
        removePhotoBtn.addEventListener('click', function(e) {
            e.preventDefault();
            e.stopPropagation();
            photoInput.value = '';
            photoPreview.style.display = 'none';
            photoPlaceholder.style.display = 'flex';
            removePhotoBtn.style.display = 'none';
            fileInfo.textContent = 'Aucun fichier sélectionné';
            photoContainer.style.borderColor = '#dee2e6';
        });
        
        // Validation du formulaire côté client
        document.getElementById('formMembre').addEventListener('submit', function(e) {
            const email = document.getElementById('email').value;
            const dateNaissance = document.getElementById('date_naissance').value;
            const photoFile = document.getElementById('photo_profil').files[0];
            
            // Validation de l'email
            if (email && !isValidEmail(email)) {
                e.preventDefault();
                alert('Veuillez saisir une adresse email valide.');
                document.getElementById('email').focus();
                return false;
            }
            
            // Validation de la date de naissance si fournie
            if (dateNaissance) {
                const dateNaissanceObj = new Date(dateNaissance);
                const aujourdhui = new Date();
                
                // Vérifier que la date n'est pas dans le futur
                if (dateNaissanceObj > aujourdhui) {
                    e.preventDefault();
                    alert('La date de naissance ne peut pas être dans le futur.');
                    document.getElementById('date_naissance').focus();
                    return false;
                }
                
                // Vérifier l'âge minimum
                let age = aujourdhui.getFullYear() - dateNaissanceObj.getFullYear();
                const moisDifference = aujourdhui.getMonth() - dateNaissanceObj.getMonth();
                
                if (moisDifference < 0 || (moisDifference === 0 && aujourdhui.getDate() < dateNaissanceObj.getDate())) {
                    age--;
                }
                
                if (age < 18) {
                    e.preventDefault();
                    alert(`Le membre doit avoir au moins 18 ans. Âge calculé: ${age} ans.`);
                    document.getElementById('date_naissance').focus();
                    return false;
                }
            }
            
            // Validation de la photo si fournie
            if (photoFile) {
                const maxSize = 5 * 1024 * 1024; // 5MB
                if (photoFile.size > maxSize) {
                    e.preventDefault();
                    alert('La photo est trop volumineuse (max 5MB). Veuillez choisir une autre photo.');
                    document.getElementById('photo_profil').focus();
                    return false;
                }
            }
            
            // Afficher un message de confirmation
            if (!confirm('Êtes-vous sûr de vouloir créer ce membre ?')) {
                e.preventDefault();
                return false;
            }
            
            return true;
        });
        
        // Initialiser l'affichage
        document.addEventListener('DOMContentLoaded', function() {
            // Initialiser la catégorie si déjà sélectionnée
            const categorieId = document.getElementById('categorie_id').value;
            if (categorieId) {
                document.getElementById('categorie_id').dispatchEvent(new Event('change'));
            }
            
            // Initialiser le calcul d'âge si date déjà remplie
            const dateNaissance = document.getElementById('date_naissance').value;
            if (dateNaissance) {
                calculerAge();
            }
            
            // Définir la date maximum comme aujourd'hui
            const today = new Date().toISOString().split('T')[0];
            document.getElementById('date_naissance').max = today;
            
            // Initialiser le placeholder pour le téléphone
            const telInput = document.getElementById('telephone');
            if (!telInput.value) {
                telInput.value = '+257 ';
            }
        });
        
        function isValidEmail(email) {
            const re = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
            return re.test(email);
        }
        
        // Empêcher le clic sur le container de déclencher l'input file
        photoContainer.addEventListener('click', function(e) {
            if (e.target !== removePhotoBtn && e.target !== photoInput) {
                photoInput.click();
            }
        });
    </script>
</body>
</html>