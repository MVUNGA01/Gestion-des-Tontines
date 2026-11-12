<?php
require_once 'config_database.php';

// Vérifier l'authentification
if (!est_connecte()) {
    rediriger('login.php');
}

// Vérifier les permissions
if (!a_la_permission('admin') && !a_la_permission('gestionnaire')) {
    $_SESSION['erreur'] = "Vous n'avez pas la permission de traiter les prêts.";
    rediriger('dashboard.php');
}

// Récupérer l'ID du prêt
$pret_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($pret_id <= 0) {
    $_SESSION['erreur'] = "ID de prêt invalide.";
    rediriger('prets.php');
}

// Récupérer les informations du prêt
try {
    $sql = "SELECT p.*, 
                   m.nom, m.prenom, m.code_membre, m.email, m.telephone,
                   cm.nom_categorie, cm.code_categorie,
                   pp.nom_pret as nom_plan, pp.code_plan,
                   tp.nom_type as type_pret,
                   u.nom_complet as approbateur_nom,
                   e.score_credit, e.cotisation_a_jour, e.dette_actuelle
            FROM prets p
            INNER JOIN membres m ON p.code_membre = m.code_membre
            INNER JOIN categories_membres cm ON m.categorie_id = cm.id
            INNER JOIN plans_pret pp ON p.plan_pret_id = pp.id
            INNER JOIN types_pret tp ON pp.type_pret_id = tp.id
            LEFT JOIN utilisateurs u ON p.approbation_par = u.id
            LEFT JOIN emprunteurs e ON m.code_membre = e.code_membre
            WHERE p.id = ?";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$pret_id]);
    $pret = $stmt->fetch();
    
    if (!$pret) {
        $_SESSION['erreur'] = "Prêt non trouvé.";
        rediriger('prets.php');
    }
    
    // Récupérer les échéances du prêt
    $sql_echeances = "SELECT * FROM echeances_pret WHERE pret_id = ? ORDER BY numero_echeance";
    $stmt_echeances = $pdo->prepare($sql_echeances);
    $stmt_echeances->execute([$pret_id]);
    $echeances = $stmt_echeances->fetchAll();
    
    // Récupérer les paiements du prêt
    $sql_paiements = "SELECT * FROM paiements WHERE pret_id = ? ORDER BY date_paiement DESC";
    $stmt_paiements = $pdo->prepare($sql_paiements);
    $stmt_paiements->execute([$pret_id]);
    $paiements = $stmt_paiements->fetchAll();
    
    // Récupérer les garanties du prêt
    $sql_garanties = "SELECT g.*, u.nom_complet as evaluateur_nom 
                      FROM garanties g
                      LEFT JOIN utilisateurs u ON g.evaluateur = u.id
                      WHERE g.pret_id = ?";
    $stmt_garanties = $pdo->prepare($sql_garanties);
    $stmt_garanties->execute([$pret_id]);
    $garanties = $stmt_garanties->fetchAll();
    
    // Récupérer les documents du membre
    $sql_documents = "SELECT * FROM documents_membres 
                      WHERE code_membre = ? AND statut = 'approuve'
                      ORDER BY date_upload DESC";
    $stmt_documents = $pdo->prepare($sql_documents);
    $stmt_documents->execute([$pret['code_membre']]);
    $documents = $stmt_documents->fetchAll();
    
} catch (PDOException $e) {
    $_SESSION['erreur'] = "Erreur lors de la récupération des données: " . $e->getMessage();
    rediriger('prets.php');
}

// Traitement des actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $pdo->beginTransaction();
        
        $action = securiser($_POST['action'] ?? '');
        
        switch ($action) {
            case 'approuver':
                // Vérifier si l'utilisateur a la permission
                if (!a_la_permission('admin') && !a_la_permission('gestionnaire')) {
                    throw new Exception("Permission insuffisante pour approuver les prêts.");
                }
                
                // Vérifier les conditions avant approbation
                if (!$pret['conditions_remplies']) {
                    throw new Exception("Les conditions du prêt ne sont pas remplies.");
                }
                
                // Mettre à jour le statut du prêt
                $sql_update = "UPDATE prets 
                               SET statut = 'approuve', 
                                   approbation_par = ?, 
                                   date_approbation = NOW(),
                                   date_activation = NOW()
                               WHERE id = ?";
                $stmt_update = $pdo->prepare($sql_update);
                $stmt_update->execute([$_SESSION['user_id'], $pret_id]);
                
                // Mettre à jour les échéances
                $sql_update_echeances = "UPDATE echeances_pret SET statut = 'a_venir' WHERE pret_id = ?";
                $stmt_update_echeances = $pdo->prepare($sql_update_echeances);
                $stmt_update_echeances->execute([$pret_id]);
                
                // Mettre à jour l'emprunteur
                $sql_update_emprunteur = "UPDATE emprunteurs 
                                          SET dette_actuelle = dette_actuelle + ?,
                                              total_emprunte = total_emprunte + ?,
                                              nombre_prets_actifs = nombre_prets_actifs + 1,
                                              nombre_prets_total = nombre_prets_total + 1
                                          WHERE code_membre = ?";
                $stmt_update_emprunteur = $pdo->prepare($sql_update_emprunteur);
                $stmt_update_emprunteur->execute([
                    $pret['montant'],
                    $pret['montant'],
                    $pret['code_membre']
                ]);
                
                // Ajouter un paiement pour les frais de dossier
                if ($pret['frais_dossier'] > 0) {
                    $sql_paiement_frais = "INSERT INTO paiements (
                        pret_id, montant_paye, methode_paiement, type_paiement,
                        statut, montant_capital, montant_interet, enregistre_par, date_paiement
                    ) VALUES (?, ?, 'espece', 'frais', 'complet', ?, ?, ?, NOW())";
                    
                    $stmt_paiement_frais = $pdo->prepare($sql_paiement_frais);
                    $stmt_paiement_frais->execute([
                        $pret_id,
                        $pret['frais_dossier'],
                        $pret['frais_dossier'],
                        0,
                        $_SESSION['user_id']
                    ]);
                }
                
                // Créer une alerte de succès
                $sql_alerte = "INSERT INTO alertes (
                    type_alerte, severite, destinataire_type, destinataire_id,
                    titre, message, lien_action
                ) VALUES (
                    'systeme', 'faible', 'membre', ?,
                    'Prêt approuvé',
                    CONCAT('Votre prêt ', ?, ' a été approuvé. Montant: ', FORMAT(?, 0), ' FBu'),
                    CONCAT('detail_pret.php?id=', ?)
                )";
                
                $stmt_alerte = $pdo->prepare($sql_alerte);
                $stmt_alerte->execute([
                    $pret['code_membre'],
                    $pret['code_pret'],
                    $pret['montant'],
                    $pret_id
                ]);
                
                $_SESSION['succes'] = "Prêt approuvé avec succès.";
                break;
                
            case 'rejeter':
                $motif_refus = securiser($_POST['motif_refus'] ?? '');
                
                if (empty($motif_refus)) {
                    throw new Exception("Veuillez spécifier un motif de refus.");
                }
                
                // Mettre à jour le prêt
                $sql_update = "UPDATE prets 
                               SET statut = 'annule', 
                                   motif_refus = ?,
                                   date_modification = NOW()
                               WHERE id = ?";
                $stmt_update = $pdo->prepare($sql_update);
                $stmt_update->execute([$motif_refus, $pret_id]);
                
                // Créer une alerte de refus
                $sql_alerte = "INSERT INTO alertes (
                    type_alerte, severite, destinataire_type, destinataire_id,
                    titre, message, lien_action
                ) VALUES (
                    'systeme', 'moyenne', 'membre', ?,
                    'Prêt rejeté',
                    CONCAT('Votre prêt ', ?, ' a été rejeté. Motif: ', ?),
                    'dashboard.php'
                )";
                
                $stmt_alerte = $pdo->prepare($sql_alerte);
                $stmt_alerte->execute([
                    $pret['code_membre'],
                    $pret['code_pret'],
                    $motif_refus
                ]);
                
                $_SESSION['succes'] = "Prêt rejeté avec succès.";
                break;
                
            case 'activer':
                if ($pret['statut'] !== 'approuve') {
                    throw new Exception("Seuls les prêts approuvés peuvent être activés.");
                }
                
                $sql_update = "UPDATE prets 
                               SET statut = 'actif', 
                                   date_activation = NOW()
                               WHERE id = ?";
                $stmt_update = $pdo->prepare($sql_update);
                $stmt_update->execute([$pret_id]);
                
                $_SESSION['succes'] = "Prêt activé avec succès.";
                break;
                
            case 'modifier_conditions':
                $conditions_remplies = isset($_POST['conditions_remplies']) ? 1 : 0;
                $verification_cotisations = isset($_POST['verification_cotisations']) ? 1 : 0;
                $verification_dette = isset($_POST['verification_dette']) ? 1 : 0;
                $notes = securiser($_POST['notes'] ?? '');
                
                $sql_update = "UPDATE prets 
                               SET conditions_remplies = ?,
                                   verification_cotisations = ?,
                                   verification_dette = ?,
                                   notes_verification = ?
                               WHERE id = ?";
                $stmt_update = $pdo->prepare($sql_update);
                $stmt_update->execute([
                    $conditions_remplies,
                    $verification_cotisations,
                    $verification_dette,
                    $notes,
                    $pret_id
                ]);
                
                $_SESSION['succes'] = "Conditions mises à jour avec succès.";
                break;
                
            case 'ajouter_garantie':
                $type_garantie = securiser($_POST['type_garantie'] ?? '');
                $description = securiser($_POST['description'] ?? '');
                $valeur_estimee = floatval($_POST['valeur_estimee'] ?? 0);
                
                if (empty($type_garantie) || empty($description) || $valeur_estimee <= 0) {
                    throw new Exception("Veuillez remplir tous les champs de la garantie.");
                }
                
                $sql_insert = "INSERT INTO garanties (
                    pret_id, type_garantie, description, valeur_estimee,
                    date_evaluation, statut, evaluateur
                ) VALUES (?, ?, ?, ?, CURDATE(), 'valide', ?)";
                
                $stmt_insert = $pdo->prepare($sql_insert);
                $stmt_insert->execute([
                    $pret_id,
                    $type_garantie,
                    $description,
                    $valeur_estimee,
                    $_SESSION['user_id']
                ]);
                
                $_SESSION['succes'] = "Garantie ajoutée avec succès.";
                break;
        }
        
        $pdo->commit();
        
        // Recharger les données après modification
        rediriger('traiter_pret.php?id=' . $pret_id);
        
    } catch (Exception $e) {
        $pdo->rollBack();
        $_SESSION['erreur'] = "Erreur: " . $e->getMessage();
    }
}

// Fonction pour formater les montants
function format_montant($montant) {
    return number_format($montant, 0, ',', ' ') . ' FBu';
}

// Fonction pour calculer le progrès du remboursement
function calculer_progression($montant, $montant_restant) {
    if ($montant <= 0) return 0;
    $rembourse = $montant - $montant_restant;
    return ($rembourse / $montant) * 100;
}

$progression = calculer_progression($pret['montant'], $pret['montant_restant']);
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Traitement du Prêt - <?php echo APP_NAME; ?></title>
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
        .pret-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 20px;
        }
        .stat-card {
            border-radius: 10px;
            transition: transform 0.3s;
            margin-bottom: 15px;
        }
        .stat-card:hover {
            transform: translateY(-5px);
        }
        .progress {
            height: 10px;
        }
        .tab-content {
            border: 1px solid #dee2e6;
            border-top: none;
            padding: 20px;
            border-radius: 0 0 5px 5px;
        }
        .nav-tabs .nav-link.active {
            border-bottom: 3px solid #007bff;
            font-weight: bold;
        }
        .condition-item {
            padding: 10px;
            margin-bottom: 10px;
            border-radius: 5px;
            background: #f8f9fa;
        }
        .condition-ok {
            border-left: 4px solid #28a745;
            background: #d4edda;
        }
        .condition-ko {
            border-left: 4px solid #dc3545;
            background: #f8d7da;
        }
        .modal-backdrop {
            z-index: 1040;
        }
        .modal {
            z-index: 1050;
        }
    </style>
</head>
<body>
    <div class="container-fluid">
        <div class="row">
            <!-- Sidebar -->
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
                                <a class="nav-link active" href="prets.php">
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
                <!-- En-tête du prêt -->
                <div class="pret-header">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h1 class="h3 mb-2">
                                <i class="fas fa-file-invoice-dollar me-2"></i>
                                Traitement du Prêt: <?php echo securiser($pret['code_pret']); ?>
                            </h1>
                            <p class="mb-0">
                                <i class="fas fa-user me-1"></i>
                                <?php echo securiser($pret['nom'] . ' ' . $pret['prenom']); ?>
                                (<?php echo securiser($pret['code_membre']); ?>)
                            </p>
                        </div>
                        <div class="text-end">
                            <span class="badge bg-<?php 
                                switch($pret['statut']) {
                                    case 'actif': echo 'success'; break;
                                    case 'en_attente': echo 'warning'; break;
                                    case 'approuve': echo 'info'; break;
                                    case 'en_retard': echo 'danger'; break;
                                    case 'complet': echo 'secondary'; break;
                                    case 'annule': echo 'dark'; break;
                                    default: echo 'secondary';
                                }
                            ?> fs-6 px-3 py-2">
                                <?php echo strtoupper(str_replace('_', ' ', $pret['statut'])); ?>
                            </span>
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
                    <div class="col-xl-3 col-md-6">
                        <div class="card bg-primary text-white stat-card">
                            <div class="card-body">
                                <div class="d-flex justify-content-between">
                                    <div>
                                        <div class="text-xs font-weight-bold text-uppercase mb-1">Montant du prêt</div>
                                        <div class="h5 mb-0"><?php echo format_montant($pret['montant']); ?></div>
                                    </div>
                                    <div class="col-auto">
                                        <i class="fas fa-money-bill-wave fa-2x"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-xl-3 col-md-6">
                        <div class="card bg-success text-white stat-card">
                            <div class="card-body">
                                <div class="d-flex justify-content-between">
                                    <div>
                                        <div class="text-xs font-weight-bold text-uppercase mb-1">Montant restant</div>
                                        <div class="h5 mb-0"><?php echo format_montant($pret['montant_restant']); ?></div>
                                    </div>
                                    <div class="col-auto">
                                        <i class="fas fa-balance-scale fa-2x"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-xl-3 col-md-6">
                        <div class="card bg-info text-white stat-card">
                            <div class="card-body">
                                <div class="d-flex justify-content-between">
                                    <div>
                                        <div class="text-xs font-weight-bold text-uppercase mb-1">Mensualité</div>
                                        <div class="h5 mb-0"><?php echo format_montant($pret['mensualite']); ?></div>
                                    </div>
                                    <div class="col-auto">
                                        <i class="fas fa-calendar-alt fa-2x"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-xl-3 col-md-6">
                        <div class="card bg-warning text-white stat-card">
                            <div class="card-body">
                                <div class="d-flex justify-content-between">
                                    <div>
                                        <div class="text-xs font-weight-bold text-uppercase mb-1">Progression</div>
                                        <div class="h5 mb-0"><?php echo round($progression, 1); ?>%</div>
                                    </div>
                                    <div class="col-auto">
                                        <i class="fas fa-chart-line fa-2x"></i>
                                    </div>
                                </div>
                                <div class="progress bg-white" style="height: 5px;">
                                    <div class="progress-bar bg-white" style="width: <?php echo $progression; ?>%"></div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Onglets -->
                <ul class="nav nav-tabs" id="pretTabs" role="tablist">
                    <li class="nav-item" role="presentation">
                        <button class="nav-link active" id="info-tab" data-bs-toggle="tab" data-bs-target="#info" type="button" role="tab">
                            <i class="fas fa-info-circle me-2"></i>Informations
                        </button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" id="conditions-tab" data-bs-toggle="tab" data-bs-target="#conditions" type="button" role="tab">
                            <i class="fas fa-clipboard-check me-2"></i>Conditions
                        </button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" id="echeances-tab" data-bs-toggle="tab" data-bs-target="#echeances" type="button" role="tab">
                            <i class="fas fa-calendar-alt me-2"></i>Échéances
                        </button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" id="paiements-tab" data-bs-toggle="tab" data-bs-target="#paiements" type="button" role="tab">
                            <i class="fas fa-money-bill-wave me-2"></i>Paiements
                        </button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" id="garanties-tab" data-bs-toggle="tab" data-bs-target="#garanties" type="button" role="tab">
                            <i class="fas fa-shield-alt me-2"></i>Garanties
                        </button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" id="actions-tab" data-bs-toggle="tab" data-bs-target="#actions" type="button" role="tab">
                            <i class="fas fa-cogs me-2"></i>Actions
                        </button>
                    </li>
                </ul>

                <div class="tab-content" id="pretTabsContent">
                    <!-- Onglet Informations -->
                    <div class="tab-pane fade show active" id="info" role="tabpanel">
                        <div class="row">
                            <div class="col-md-6">
                                <div class="card mb-3">
                                    <div class="card-header bg-light">
                                        <h6 class="mb-0"><i class="fas fa-user me-2"></i>Informations du membre</h6>
                                    </div>
                                    <div class="card-body">
                                        <table class="table table-sm">
                                            <tr>
                                                <th>Nom complet:</th>
                                                <td><?php echo securiser($pret['nom'] . ' ' . $pret['prenom']); ?></td>
                                            </tr>
                                            <tr>
                                                <th>Code membre:</th>
                                                <td><?php echo securiser($pret['code_membre']); ?></td>
                                            </tr>
                                            <tr>
                                                <th>Catégorie:</th>
                                                <td><?php echo securiser($pret['nom_categorie'] . ' (' . $pret['code_categorie'] . ')'); ?></td>
                                            </tr>
                                            <tr>
                                                <th>Téléphone:</th>
                                                <td><?php echo securiser($pret['telephone']); ?></td>
                                            </tr>
                                            <tr>
                                                <th>Email:</th>
                                                <td><?php echo securiser($pret['email']); ?></td>
                                            </tr>
                                            <tr>
                                                <th>Score crédit:</th>
                                                <td><span class="badge bg-info"><?php echo $pret['score_credit']; ?></span></td>
                                            </tr>
                                            <tr>
                                                <th>Cotisation à jour:</th>
                                                <td>
                                                    <span class="badge bg-<?php echo $pret['cotisation_a_jour'] ? 'success' : 'danger'; ?>">
                                                        <?php echo $pret['cotisation_a_jour'] ? 'Oui' : 'Non'; ?>
                                                    </span>
                                                </td>
                                            </tr>
                                        </table>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="card mb-3">
                                    <div class="card-header bg-light">
                                        <h6 class="mb-0"><i class="fas fa-file-invoice-dollar me-2"></i>Détails du prêt</h6>
                                    </div>
                                    <div class="card-body">
                                        <table class="table table-sm">
                                            <tr>
                                                <th>Type de prêt:</th>
                                                <td><?php echo securiser($pret['type_pret']); ?></td>
                                            </tr>
                                            <tr>
                                                <th>Plan de prêt:</th>
                                                <td><?php echo securiser($pret['nom_plan'] . ' (' . $pret['code_plan'] . ')'); ?></td>
                                            </tr>
                                            <tr>
                                                <th>Taux d'intérêt:</th>
                                                <td><?php echo $pret['taux_interet']; ?>%</td>
                                            </tr>
                                            <tr>
                                                <th>Durée:</th>
                                                <td><?php echo $pret['duree_mois']; ?> mois</td>
                                            </tr>
                                            <tr>
                                                <th>Date début:</th>
                                                <td><?php echo date('d/m/Y', strtotime($pret['date_debut'])); ?></td>
                                            </tr>
                                            <tr>
                                                <th>Date fin:</th>
                                                <td><?php echo date('d/m/Y', strtotime($pret['date_fin'])); ?></td>
                                            </tr>
                                            <tr>
                                                <th>Date création:</th>
                                                <td><?php echo date('d/m/Y H:i', strtotime($pret['date_creation'])); ?></td>
                                            </tr>
                                            <?php if ($pret['date_approbation']): ?>
                                            <tr>
                                                <th>Approuvé par:</th>
                                                <td><?php echo securiser($pret['approbateur_nom']); ?> le <?php echo date('d/m/Y H:i', strtotime($pret['date_approbation'])); ?></td>
                                            </tr>
                                            <?php endif; ?>
                                        </table>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Documents du membre -->
                        <?php if (!empty($documents)): ?>
                        <div class="card">
                            <div class="card-header bg-light">
                                <h6 class="mb-0"><i class="fas fa-file-contract me-2"></i>Documents du membre</h6>
                            </div>
                            <div class="card-body">
                                <div class="row">
                                    <?php foreach ($documents as $doc): ?>
                                    <div class="col-md-4 mb-3">
                                        <div class="card border">
                                            <div class="card-body text-center">
                                                <i class="fas fa-file-pdf fa-3x text-danger mb-2"></i>
                                                <h6><?php echo securiser($doc['type_document']); ?></h6>
                                                <small class="text-muted"><?php echo date('d/m/Y', strtotime($doc['date_upload'])); ?></small>
                                                <div class="mt-2">
                                                    <a href="#" class="btn btn-sm btn-outline-primary">
                                                        <i class="fas fa-eye"></i> Voir
                                                    </a>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>

                    <!-- Onglet Conditions -->
                    <div class="tab-pane fade" id="conditions" role="tabpanel">
                        <div class="card">
                            <div class="card-header bg-light">
                                <h6 class="mb-0"><i class="fas fa-clipboard-check me-2"></i>Vérification des conditions</h6>
                            </div>
                            <div class="card-body">
                                <form method="POST">
                                    <input type="hidden" name="action" value="modifier_conditions">
                                    
                                    <div class="row">
                                        <div class="col-md-6">
                                            <div class="condition-item <?php echo $pret['conditions_remplies'] ? 'condition-ok' : 'condition-ko'; ?>">
                                                <div class="form-check form-switch">
                                                    <input class="form-check-input" type="checkbox" id="conditions_remplies" 
                                                           name="conditions_remplies" value="1" 
                                                           <?php echo $pret['conditions_remplies'] ? 'checked' : ''; ?>>
                                                    <label class="form-check-label fw-bold" for="conditions_remplies">
                                                        Conditions générales remplies
                                                    </label>
                                                    <p class="mb-0 small text-muted">
                                                        Toutes les conditions générales du prêt sont vérifiées et acceptées.
                                                    </p>
                                                </div>
                                            </div>
                                            
                                            <div class="condition-item <?php echo $pret['verification_cotisations'] ? 'condition-ok' : 'condition-ko'; ?>">
                                                <div class="form-check form-switch">
                                                    <input class="form-check-input" type="checkbox" id="verification_cotisations" 
                                                           name="verification_cotisations" value="1"
                                                           <?php echo $pret['verification_cotisations'] ? 'checked' : ''; ?>>
                                                    <label class="form-check-label fw-bold" for="verification_cotisations">
                                                        Cotisations vérifiées
                                                    </label>
                                                    <p class="mb-0 small text-muted">
                                                        Les cotisations du membre sont à jour et suffisantes.
                                                    </p>
                                                </div>
                                            </div>
                                            
                                            <div class="condition-item <?php echo $pret['verification_dette'] ? 'condition-ok' : 'condition-ko'; ?>">
                                                <div class="form-check form-switch">
                                                    <input class="form-check-input" type="checkbox" id="verification_dette" 
                                                           name="verification_dette" value="1"
                                                           <?php echo $pret['verification_dette'] ? 'checked' : ''; ?>>
                                                    <label class="form-check-label fw-bold" for="verification_dette">
                                                        Dette vérifiée
                                                    </label>
                                                    <p class="mb-0 small text-muted">
                                                        La dette actuelle du membre est dans les limites autorisées.
                                                    </p>
                                                </div>
                                            </div>
                                        </div>
                                        
                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label for="notes" class="form-label">Notes et commentaires</label>
                                                <textarea class="form-control" id="notes" name="notes" rows="8" 
                                                          placeholder="Ajoutez des notes ou commentaires sur la vérification..."></textarea>
                                            </div>
                                            
                                            <div class="d-grid">
                                                <button type="submit" class="btn btn-primary">
                                                    <i class="fas fa-save me-2"></i>Enregistrer les modifications
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>

                    <!-- Onglet Échéances -->
                    <div class="tab-pane fade" id="echeances" role="tabpanel">
                        <div class="card">
                            <div class="card-header bg-light d-flex justify-content-between align-items-center">
                                <h6 class="mb-0"><i class="fas fa-calendar-alt me-2"></i>Échéances du prêt</h6>
                                <span class="badge bg-info"><?php echo count($echeances); ?> échéances</span>
                            </div>
                            <div class="card-body">
                                <div class="table-responsive">
                                    <table class="table table-hover">
                                        <thead>
                                            <tr>
                                                <th>#</th>
                                                <th>Date échéance</th>
                                                <th>Montant</th>
                                                <th>Capital</th>
                                                <th>Intérêt</th>
                                                <th>Capital restant</th>
                                                <th>Statut</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($echeances as $echeance): ?>
                                            <tr class="<?php 
                                                switch($echeance['statut']) {
                                                    case 'payee': echo 'table-success'; break;
                                                    case 'en_retard': echo 'table-danger'; break;
                                                    case 'due': echo 'table-warning'; break;
                                                    default: echo '';
                                                }
                                            ?>">
                                                <td><?php echo $echeance['numero_echeance']; ?></td>
                                                <td><?php echo date('d/m/Y', strtotime($echeance['date_echeance'])); ?></td>
                                                <td class="text-end"><?php echo format_montant($echeance['montant_echeance']); ?></td>
                                                <td class="text-end"><?php echo format_montant($echeance['capital']); ?></td>
                                                <td class="text-end"><?php echo format_montant($echeance['interet']); ?></td>
                                                <td class="text-end"><?php echo format_montant($echeance['capital_restant']); ?></td>
                                                <td>
                                                    <span class="badge bg-<?php 
                                                        switch($echeance['statut']) {
                                                            case 'payee': echo 'success'; break;
                                                            case 'en_retard': echo 'danger'; break;
                                                            case 'due': echo 'warning'; break;
                                                            case 'a_venir': echo 'info'; break;
                                                            default: echo 'secondary';
                                                        }
                                                    ?>">
                                                        <?php echo str_replace('_', ' ', $echeance['statut']); ?>
                                                    </span>
                                                </td>
                                            </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Onglet Paiements -->
                    <div class="tab-pane fade" id="paiements" role="tabpanel">
                        <div class="card">
                            <div class="card-header bg-light">
                                <h6 class="mb-0"><i class="fas fa-money-bill-wave me-2"></i>Historique des paiements</h6>
                            </div>
                            <div class="card-body">
                                <?php if (empty($paiements)): ?>
                                    <div class="text-center py-5">
                                        <i class="fas fa-money-bill-wave fa-3x text-muted mb-3"></i>
                                        <h5 class="text-muted">Aucun paiement enregistré</h5>
                                        <p class="text-muted">Aucun paiement n'a encore été effectué pour ce prêt.</p>
                                    </div>
                                <?php else: ?>
                                    <div class="table-responsive">
                                        <table class="table table-hover">
                                            <thead>
                                                <tr>
                                                    <th>Date</th>
                                                    <th>Montant</th>
                                                    <th>Méthode</th>
                                                    <th>Type</th>
                                                    <th>Capital</th>
                                                    <th>Intérêt</th>
                                                    <th>Pénalité</th>
                                                    <th>Statut</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($paiements as $paiement): ?>
                                                <tr>
                                                    <td><?php echo date('d/m/Y H:i', strtotime($paiement['date_paiement'])); ?></td>
                                                    <td class="text-end"><?php echo format_montant($paiement['montant_paye']); ?></td>
                                                    <td><?php echo securiser($paiement['methode_paiement']); ?></td>
                                                    <td><?php echo securiser($paiement['type_paiement']); ?></td>
                                                    <td class="text-end"><?php echo format_montant($paiement['montant_capital']); ?></td>
                                                    <td class="text-end"><?php echo format_montant($paiement['montant_interet']); ?></td>
                                                    <td class="text-end"><?php echo format_montant($paiement['montant_penalite']); ?></td>
                                                    <td>
                                                        <span class="badge bg-<?php 
                                                            switch($paiement['statut']) {
                                                                case 'complet': echo 'success'; break;
                                                                case 'partiel': echo 'warning'; break;
                                                                case 'en_attente': echo 'info'; break;
                                                                default: echo 'secondary';
                                                            }
                                                        ?>">
                                                            <?php echo securiser($paiement['statut']); ?>
                                                        </span>
                                                    </td>
                                                </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <!-- Onglet Garanties -->
                    <div class="tab-pane fade" id="garanties" role="tabpanel">
                        <div class="card">
                            <div class="card-header bg-light d-flex justify-content-between align-items-center">
                                <h6 class="mb-0"><i class="fas fa-shield-alt me-2"></i>Garanties du prêt</h6>
                                <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#modalGarantie">
                                    <i class="fas fa-plus me-2"></i>Ajouter une garantie
                                </button>
                            </div>
                            <div class="card-body">
                                <?php if (empty($garanties)): ?>
                                    <div class="text-center py-5">
                                        <i class="fas fa-shield-alt fa-3x text-muted mb-3"></i>
                                        <h5 class="text-muted">Aucune garantie enregistrée</h5>
                                        <p class="text-muted">Aucune garantie n'a été fournie pour ce prêt.</p>
                                        <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modalGarantie">
                                            <i class="fas fa-plus me-2"></i>Ajouter la première garantie
                                        </button>
                                    </div>
                                <?php else: ?>
                                    <div class="row">
                                        <?php foreach ($garanties as $garantie): ?>
                                        <div class="col-md-6 mb-3">
                                            <div class="card border-<?php 
                                                switch($garantie['statut']) {
                                                    case 'valide': echo 'success'; break;
                                                    case 'invalide': echo 'danger'; break;
                                                    case 'a_verifier': echo 'warning'; break;
                                                    default: echo 'secondary';
                                                }
                                            ?>">
                                                <div class="card-header bg-light">
                                                    <div class="d-flex justify-content-between align-items-center">
                                                        <h6 class="mb-0"><?php echo securiser(ucfirst($garantie['type_garantie'])); ?></h6>
                                                        <span class="badge bg-<?php 
                                                            switch($garantie['statut']) {
                                                                case 'valide': echo 'success'; break;
                                                                case 'invalide': echo 'danger'; break;
                                                                case 'a_verifier': echo 'warning'; break;
                                                                default: echo 'secondary';
                                                            }
                                                        ?>">
                                                            <?php echo securiser($garantie['statut']); ?>
                                                        </span>
                                                    </div>
                                                </div>
                                                <div class="card-body">
                                                    <p><strong>Description:</strong> <?php echo securiser($garantie['description']); ?></p>
                                                    <p><strong>Valeur estimée:</strong> <?php echo format_montant($garantie['valeur_estimee']); ?></p>
                                                    <p><strong>Date évaluation:</strong> <?php echo date('d/m/Y', strtotime($garantie['date_evaluation'])); ?></p>
                                                    <?php if ($garantie['evaluateur_nom']): ?>
                                                        <p><strong>Évaluateur:</strong> <?php echo securiser($garantie['evaluateur_nom']); ?></p>
                                                    <?php endif; ?>
                                                    <?php if ($garantie['notes']): ?>
                                                        <p><strong>Notes:</strong> <?php echo securiser($garantie['notes']); ?></p>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <!-- Onglet Actions -->
                    <div class="tab-pane fade" id="actions" role="tabpanel">
                        <div class="row">
                            <div class="col-md-6 mb-4">
                                <div class="card border-success">
                                    <div class="card-header bg-success text-white">
                                        <h6 class="mb-0"><i class="fas fa-check-circle me-2"></i>Actions d'approbation</h6>
                                    </div>
                                    <div class="card-body">
                                        <?php if ($pret['statut'] == 'en_attente'): ?>
                                            <!-- Formulaire d'approbation -->
                                            <form method="POST">
                                                <input type="hidden" name="action" value="approuver">
                                                <div class="mb-3">
                                                    <p>Voulez-vous approuver ce prêt? Cette action :</p>
                                                    <ul>
                                                        <li>Activera le prêt</li>
                                                        <li>Créera les échéances</li>
                                                        <li>Informera le membre par notification</li>
                                                        <li>Ajoutera les frais de dossier</li>
                                                    </ul>
                                                </div>
                                                <div class="d-grid">
                                                    <button type="submit" class="btn btn-success btn-lg">
                                                        <i class="fas fa-check-circle me-2"></i>Approuver le prêt
                                                    </button>
                                                </div>
                                            </form>
                                        <?php elseif ($pret['statut'] == 'approuve'): ?>
                                            <!-- Formulaire d'activation -->
                                            <form method="POST">
                                                <input type="hidden" name="action" value="activer">
                                                <div class="mb-3">
                                                    <p>Ce prêt a été approuvé. Voulez-vous l'activer maintenant?</p>
                                                </div>
                                                <div class="d-grid">
                                                    <button type="submit" class="btn btn-primary btn-lg">
                                                        <i class="fas fa-play-circle me-2"></i>Activer le prêt
                                                    </button>
                                                </div>
                                            </form>
                                        <?php else: ?>
                                            <div class="text-center py-4">
                                                <i class="fas fa-check-circle fa-3x text-success mb-3"></i>
                                                <h5 class="text-success">Prêt déjà traité</h5>
                                                <p class="text-muted">Ce prêt a déjà été traité et se trouve dans l'état "<?php echo $pret['statut']; ?>"</p>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="col-md-6 mb-4">
                                <div class="card border-danger">
                                    <div class="card-header bg-danger text-white">
                                        <h6 class="mb-0"><i class="fas fa-times-circle me-2"></i>Actions de refus</h6>
                                    </div>
                                    <div class="card-body">
                                        <?php if ($pret['statut'] == 'en_attente'): ?>
                                            <!-- Formulaire de refus -->
                                            <form method="POST" id="formRefus">
                                                <input type="hidden" name="action" value="rejeter">
                                                <div class="mb-3">
                                                    <label for="motif_refus" class="form-label">Motif du refus *</label>
                                                    <textarea class="form-control" id="motif_refus" name="motif_refus" 
                                                              rows="4" required placeholder="Détailler les raisons du refus..."></textarea>
                                                </div>
                                                <div class="d-grid">
                                                    <button type="submit" class="btn btn-danger btn-lg">
                                                        <i class="fas fa-times-circle me-2"></i>Refuser le prêt
                                                    </button>
                                                </div>
                                            </form>
                                        <?php else: ?>
                                            <div class="text-center py-4">
                                                <i class="fas fa-ban fa-3x text-muted mb-3"></i>
                                                <h5 class="text-muted">Action non disponible</h5>
                                                <p class="text-muted">Le refus n'est possible que pour les prêts en attente.</p>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Actions supplémentaires -->
                        <div class="card">
                            <div class="card-header bg-info text-white">
                                <h6 class="mb-0"><i class="fas fa-tools me-2"></i>Actions supplémentaires</h6>
                            </div>
                            <div class="card-body">
                                <div class="row">
                                    <div class="col-md-4 mb-3">
                                        <a href="details_pret.php?id=<?php echo $pret_id; ?>" class="btn btn-outline-info w-100">
                                            <i class="fas fa-eye me-2"></i>Voir les détails
                                        </a>
                                    </div>
                                    <div class="col-md-4 mb-3">
                                        <a href="modifier_pret.php?id=<?php echo $pret_id; ?>" class="btn btn-outline-warning w-100">
                                            <i class="fas fa-edit me-2"></i>Modifier
                                        </a>
                                    </div>
                                    <div class="col-md-4 mb-3">
                                        <a href="nouveau_paiement.php?pret_id=<?php echo $pret_id; ?>" class="btn btn-outline-success w-100">
                                            <i class="fas fa-money-bill-wave me-2"></i>Nouveau paiement
                                        </a>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </main>
        </div>
    </div>

    <!-- Modal pour ajouter une garantie -->
    <div class="modal fade" id="modalGarantie" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST">
                    <input type="hidden" name="action" value="ajouter_garantie">
                    
                    <div class="modal-header bg-primary text-white">
                        <h5 class="modal-title"><i class="fas fa-shield-alt me-2"></i>Ajouter une garantie</h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <label for="type_garantie" class="form-label">Type de garantie *</label>
                            <select class="form-select" id="type_garantie" name="type_garantie" required>
                                <option value="">Sélectionner...</option>
                                <option value="immobiliere">Immobilière</option>
                                <option value="mobiliere">Mobilière</option>
                                <option value="personnelle">Personnelle</option>
                                <option value="autre">Autre</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label for="description" class="form-label">Description *</label>
                            <textarea class="form-control" id="description" name="description" 
                                      rows="3" required placeholder="Décrire la garantie..."></textarea>
                        </div>
                        <div class="mb-3">
                            <label for="valeur_estimee" class="form-label">Valeur estimée (FBu) *</label>
                            <input type="number" class="form-control" id="valeur_estimee" name="valeur_estimee" 
                                   min="0" step="1000" required>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annuler</button>
                        <button type="submit" class="btn btn-primary">Ajouter la garantie</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Activation des onglets
            const triggerTabList = [].slice.call(document.querySelectorAll('#pretTabs button'));
            triggerTabList.forEach(function (triggerEl) {
                const tabTrigger = new bootstrap.Tab(triggerEl);
                triggerEl.addEventListener('click', function (event) {
                    event.preventDefault();
                    tabTrigger.show();
                });
            });

            // Confirmation pour le refus
            const formRefus = document.getElementById('formRefus');
            if (formRefus) {
                formRefus.onsubmit = function(e) {
                    const motif = document.getElementById('motif_refus').value.trim();
                    if (!motif) {
                        e.preventDefault();
                        alert('Veuillez spécifier un motif de refus.');
                        return false;
                    }
                    if (!confirm('Êtes-vous sûr de vouloir refuser ce prêt? Cette action est irréversible.')) {
                        e.preventDefault();
                        return false;
                    }
                };
            }

            // Confirmation pour l'approbation
            const formApprobation = document.querySelector('form[action*="approuver"]');
            if (formApprobation) {
                formApprobation.onsubmit = function(e) {
                    if (!confirm('Êtes-vous sûr de vouloir approuver ce prêt?')) {
                        e.preventDefault();
                        return false;
                    }
                };
            }

            // Auto-sélection de l'onglet depuis l'URL
            const urlParams = new URLSearchParams(window.location.search);
            const tab = urlParams.get('tab');
            if (tab) {
                const tabElement = document.querySelector(`button[data-bs-target="#${tab}"]`);
                if (tabElement) {
                    new bootstrap.Tab(tabElement).show();
                }
            }
        });
    </script>
</body>
</html>