<?php
require_once 'config_database.php';

// Vérifier l'authentification
if (!est_connecte()) {
    rediriger('login.php');
}

// Vérifier les permissions
if (!a_la_permission('admin') && !a_la_permission('gestionnaire')) {
    $_SESSION['erreur'] = "Vous n'avez pas la permission de modifier des membres.";
    rediriger('membres.php');
}

// Vérifier que le code membre est fourni
if (!isset($_GET['code']) || empty($_GET['code'])) {
    $_SESSION['erreur'] = "Code membre non spécifié.";
    rediriger('membres.php');
}

$code_membre = securiser($_GET['code']);

try {
    // Récupérer les informations du membre
    $stmt = $pdo->prepare("
        SELECT m.*, cm.nom_categorie, e.score_credit, e.statut as statut_emprunteur
        FROM membres m
        INNER JOIN categories_membres cm ON m.categorie_id = cm.id
        LEFT JOIN emprunteurs e ON m.code_membre = e.code_membre
        WHERE m.code_membre = ?
    ");
    $stmt->execute([$code_membre]);
    $membre = $stmt->fetch();

    if (!$membre) {
        $_SESSION['erreur'] = "Membre non trouvé.";
        rediriger('membres.php');
    }

    // Récupérer les catégories pour le formulaire
    $stmt_categories = $pdo->query("SELECT id, nom_categorie FROM categories_membres WHERE statut = 'actif'");
    $categories = $stmt_categories->fetchAll();

} catch (PDOException $e) {
    $erreur = "Erreur lors du chargement des données: " . $e->getMessage();
}

// Traitement du formulaire de modification
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // Récupérer et valider les données
        $nom = securiser($_POST['nom']);
        $prenom = securiser($_POST['prenom']);
        $email = securiser($_POST['email']);
        $telephone = securiser($_POST['telephone']);
        $adresse = securiser($_POST['adresse']);
        $categorie_id = (int)$_POST['categorie_id'];
        $date_naissance = !empty($_POST['date_naissance']) ? $_POST['date_naissance'] : null;
        $lieu_naissance = securiser($_POST['lieu_naissance']);
        $profession = securiser($_POST['profession']);
        $employeur = securiser($_POST['employeur']);
        $salaire_mensuel = !empty($_POST['salaire_mensuel']) ? (float)$_POST['salaire_mensuel'] : null;
        $nom_contact_urgence = securiser($_POST['nom_contact_urgence']);
        $tel_contact_urgence = securiser($_POST['tel_contact_urgence']);
        $relation_contact_urgence = securiser($_POST['relation_contact_urgence']);
        $statut = securiser($_POST['statut']);

        // Vérifier que l'email n'est pas déjà utilisé par un autre membre
        $stmt_check_email = $pdo->prepare("SELECT code_membre FROM membres WHERE email = ? AND code_membre != ?");
        $stmt_check_email->execute([$email, $code_membre]);
        
        if ($stmt_check_email->fetch()) {
            $_SESSION['erreur'] = "Cet email est déjà utilisé par un autre membre.";
        } else {
            // Mettre à jour le membre
            $stmt_update = $pdo->prepare("
                UPDATE membres SET 
                    nom = ?, prenom = ?, email = ?, telephone = ?, adresse = ?,
                    categorie_id = ?, date_naissance = ?, lieu_naissance = ?,
                    profession = ?, employeur = ?, salaire_mensuel = ?,
                    nom_contact_urgence = ?, tel_contact_urgence = ?, relation_contact_urgence = ?,
                    statut = ?
                WHERE code_membre = ?
            ");

            $stmt_update->execute([
                $nom, $prenom, $email, $telephone, $adresse,
                $categorie_id, $date_naissance, $lieu_naissance,
                $profession, $employeur, $salaire_mensuel,
                $nom_contact_urgence, $tel_contact_urgence, $relation_contact_urgence,
                $statut, $code_membre
            ]);

            $_SESSION['succes'] = "Membre modifié avec succès.";
            rediriger('profil_membre.php?code=' . $code_membre);
        }

    } catch (PDOException $e) {
        $_SESSION['erreur'] = "Erreur lors de la modification: " . $e->getMessage();
    }
}
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Modifier <?php echo securiser($membre['nom'] . ' ' . $membre['prenom']); ?> - <?php echo APP_NAME; ?></title>
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
        .page-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 2rem;
            border-radius: 10px;
            margin-bottom: 2rem;
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
                <div class="page-header">
                    <div class="row align-items-center">
                        <div class="col">
                            <h1 class="h2 mb-1">
                                <i class="fas fa-user-edit me-2"></i>Modifier le Membre
                            </h1>
                            <p class="mb-0"><?php echo securiser($membre['nom'] . ' ' . $membre['prenom']); ?> (<?php echo securiser($membre['code_membre']); ?>)</p>
                        </div>
                        <div class="col-auto">
                            <a href="profil_membre.php?code=<?php echo $membre['code_membre']; ?>" class="btn btn-light">
                                <i class="fas fa-arrow-left me-2"></i>Retour au profil
                            </a>
                        </div>
                    </div>
                </div>

                <!-- Messages d'alerte -->
                <?php if (isset($_SESSION['erreur'])): ?>
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        <?php echo $_SESSION['erreur']; unset($_SESSION['erreur']); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <!-- Formulaire de modification -->
                <div class="card shadow">
                    <div class="card-header bg-light">
                        <h6 class="m-0 font-weight-bold text-primary">
                            <i class="fas fa-user me-2"></i>Informations du Membre
                        </h6>
                    </div>
                    <div class="card-body">
                        <form method="POST" id="formModifierMembre">
                            <div class="row">
                                <div class="col-md-6">
                                    <h6 class="border-bottom pb-2 mb-3">Informations Personnelles</h6>
                                    
                                    <div class="mb-3">
                                        <label for="nom" class="form-label">Nom *</label>
                                        <input type="text" class="form-control" id="nom" name="nom" value="<?php echo securiser($membre['nom']); ?>" required>
                                    </div>

                                    <div class="mb-3">
                                        <label for="prenom" class="form-label">Prénom *</label>
                                        <input type="text" class="form-control" id="prenom" name="prenom" value="<?php echo securiser($membre['prenom']); ?>" required>
                                    </div>

                                    <div class="mb-3">
                                        <label for="email" class="form-label">Email *</label>
                                        <input type="email" class="form-control" id="email" name="email" value="<?php echo securiser($membre['email']); ?>" required>
                                    </div>

                                    <div class="mb-3">
                                        <label for="telephone" class="form-label">Téléphone</label>
                                        <input type="text" class="form-control" id="telephone" name="telephone" value="<?php echo securiser($membre['telephone'] ?? ''); ?>">
                                    </div>

                                    <div class="mb-3">
                                        <label for="adresse" class="form-label">Adresse</label>
                                        <textarea class="form-control" id="adresse" name="adresse" rows="3"><?php echo securiser($membre['adresse'] ?? ''); ?></textarea>
                                    </div>

                                    <div class="mb-3">
                                        <label for="categorie_id" class="form-label">Catégorie *</label>
                                        <select class="form-select" id="categorie_id" name="categorie_id" required>
                                            <option value="">Sélectionnez une catégorie</option>
                                            <?php foreach ($categories as $categorie): ?>
                                                <option value="<?php echo $categorie['id']; ?>" <?php echo $membre['categorie_id'] == $categorie['id'] ? 'selected' : ''; ?>>
                                                    <?php echo securiser($categorie['nom_categorie']); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>

                                <div class="col-md-6">
                                    <h6 class="border-bottom pb-2 mb-3">Informations Complémentaires</h6>
                                    
                                    <div class="row">
                                        <div class="col-md-6 mb-3">
                                            <label for="date_naissance" class="form-label">Date de Naissance</label>
                                            <input type="date" class="form-control" id="date_naissance" name="date_naissance" value="<?php echo $membre['date_naissance'] ?? ''; ?>">
                                        </div>
                                        <div class="col-md-6 mb-3">
                                            <label for="lieu_naissance" class="form-label">Lieu de Naissance</label>
                                            <input type="text" class="form-control" id="lieu_naissance" name="lieu_naissance" value="<?php echo securiser($membre['lieu_naissance'] ?? ''); ?>">
                                        </div>
                                    </div>

                                    <div class="mb-3">
                                        <label for="profession" class="form-label">Profession</label>
                                        <input type="text" class="form-control" id="profession" name="profession" value="<?php echo securiser($membre['profession'] ?? ''); ?>">
                                    </div>

                                    <div class="mb-3">
                                        <label for="employeur" class="form-label">Employeur</label>
                                        <input type="text" class="form-control" id="employeur" name="employeur" value="<?php echo securiser($membre['employeur'] ?? ''); ?>">
                                    </div>

                                    <div class="mb-3">
                                        <label for="salaire_mensuel" class="form-label">Salaire Mensuel (FBu)</label>
                                        <input type="number" step="0.01" class="form-control" id="salaire_mensuel" name="salaire_mensuel" value="<?php echo $membre['salaire_mensuel'] ?? ''; ?>">
                                    </div>

                                    <h6 class="border-bottom pb-2 mb-3 mt-4">Contact d'Urgence</h6>
                                    
                                    <div class="mb-3">
                                        <label for="nom_contact_urgence" class="form-label">Nom du Contact</label>
                                        <input type="text" class="form-control" id="nom_contact_urgence" name="nom_contact_urgence" value="<?php echo securiser($membre['nom_contact_urgence'] ?? ''); ?>">
                                    </div>

                                    <div class="mb-3">
                                        <label for="tel_contact_urgence" class="form-label">Téléphone du Contact</label>
                                        <input type="text" class="form-control" id="tel_contact_urgence" name="tel_contact_urgence" value="<?php echo securiser($membre['tel_contact_urgence'] ?? ''); ?>">
                                    </div>

                                    <div class="mb-3">
                                        <label for="relation_contact_urgence" class="form-label">Relation</label>
                                        <input type="text" class="form-control" id="relation_contact_urgence" name="relation_contact_urgence" value="<?php echo securiser($membre['relation_contact_urgence'] ?? ''); ?>">
                                    </div>

                                    <div class="mb-3">
                                        <label for="statut" class="form-label">Statut *</label>
                                        <select class="form-select" id="statut" name="statut" required>
                                            <option value="actif" <?php echo $membre['statut'] === 'actif' ? 'selected' : ''; ?>>Actif</option>
                                            <option value="inactif" <?php echo $membre['statut'] === 'inactif' ? 'selected' : ''; ?>>Inactif</option>
                                            <option value="suspendu" <?php echo $membre['statut'] === 'suspendu' ? 'selected' : ''; ?>>Suspendu</option>
                                        </select>
                                    </div>
                                </div>
                            </div>

                            <div class="row mt-4">
                                <div class="col-12">
                                    <div class="d-flex justify-content-between">
                                        <a href="profil_membre.php?code=<?php echo $membre['code_membre']; ?>" class="btn btn-secondary">
                                            <i class="fas fa-times me-2"></i>Annuler
                                        </a>
                                        <button type="submit" class="btn btn-primary">
                                            <i class="fas fa-save me-2"></i>Enregistrer les modifications
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Informations de suivi -->
                <div class="row mt-4">
                    <div class="col-md-6">
                        <div class="card shadow">
                            <div class="card-header bg-light">
                                <h6 class="m-0 font-weight-bold text-primary">
                                    <i class="fas fa-chart-line me-2"></i>Informations de Suivi
                                </h6>
                            </div>
                            <div class="card-body">
                                <div class="row">
                                    <div class="col-6">
                                        <small class="text-muted">Score de crédit</small>
                                        <div class="h5"><?php echo $membre['score_credit'] ?? 'N/A'; ?></div>
                                    </div>
                                    <div class="col-6">
                                        <small class="text-muted">Statut emprunteur</small>
                                        <div>
                                            <span class="badge bg-<?php 
                                                switch($membre['statut_emprunteur']) {
                                                    case 'eligible': echo 'success'; break;
                                                    case 'en_attente': echo 'warning'; break;
                                                    case 'suspendu': echo 'danger'; break;
                                                    default: echo 'secondary';
                                                }
                                            ?>">
                                                <?php echo ucfirst($membre['statut_emprunteur'] ?? 'Non défini'); ?>
                                            </span>
                                        </div>
                                    </div>
                                </div>
                                <div class="row mt-3">
                                    <div class="col-12">
                                        <small class="text-muted">Date d'inscription</small>
                                        <div><?php echo date('d/m/Y à H:i', strtotime($membre['date_inscription'])); ?></div>
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
        // Validation du formulaire
        document.getElementById('formModifierMembre').addEventListener('submit', function(e) {
            const email = document.getElementById('email').value;
            const telephone = document.getElementById('telephone').value;
            
            // Validation basique de l'email
            if (!email.match(/^[^\s@]+@[^\s@]+\.[^\s@]+$/)) {
                e.preventDefault();
                alert('Veuillez entrer une adresse email valide.');
                return;
            }
            
            // Validation du téléphone (optionnel)
            if (telephone && !telephone.match(/^[\d\s\-\+\(\)]{8,}$/)) {
                e.preventDefault();
                alert('Veuillez entrer un numéro de téléphone valide.');
                return;
            }
        });
    </script>
</body>
</html>