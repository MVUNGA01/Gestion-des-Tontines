<?php
require_once 'config_database.php';

// Vérifier l'authentification
if (!est_connecte()) {
    rediriger('login.php');
}

// Vérifier les permissions
if (!a_la_permission('admin') && !a_la_permission('gestionnaire')) {
    $_SESSION['erreur'] = "Accès non autorisé";
    rediriger('dashboard.php');
}

// Traitement du formulaire
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $code_membre = securiser($_POST['code_membre']);
        
        // Vérifier si le membre existe et est actif
        $stmt_membre = $pdo->prepare("
            SELECT m.*, cm.nom_categorie 
            FROM membres m 
            INNER JOIN categories_membres cm ON m.categorie_id = cm.id 
            WHERE m.code_membre = ? AND m.statut = 'actif'
        ");
        $stmt_membre->execute([$code_membre]);
        $membre = $stmt_membre->fetch();
        
        if (!$membre) {
            $_SESSION['erreur'] = "Membre non trouvé ou inactif";
            rediriger('qualifier_emprunteur.php');
        }
        
        // Vérifier si le membre n'est pas déjà qualifié comme emprunteur
        $stmt_existant = $pdo->prepare("SELECT * FROM emprunteurs WHERE code_membre = ?");
        $stmt_existant->execute([$code_membre]);
        $emprunteur_existant = $stmt_existant->fetch();
        
        if ($emprunteur_existant) {
            $_SESSION['erreur'] = "Ce membre est déjà qualifié comme emprunteur";
            rediriger('qualifier_emprunteur.php');
        }
        
        // Vérifier les cotisations du membre
        $stmt_cotisations = $pdo->prepare("
            SELECT COUNT(*) as total, 
                   SUM(CASE WHEN statut = 'valide' AND date_fin >= CURDATE() THEN 1 ELSE 0 END) as valides
            FROM cotisations 
            WHERE code_membre = ?
        ");
        $stmt_cotisations->execute([$code_membre]);
        $cotisations = $stmt_cotisations->fetch();
        
        // Calculer le score de crédit initial basé sur les cotisations
        $score_initial = 500; // Score de base
        
        if ($cotisations['valides'] > 0) {
            $score_initial += 100; // Bonus pour cotisation valide
        }
        
        if ($cotisations['total'] >= 3) {
            $score_initial += 50; // Bonus pour ancienneté
        }
        
        // Déterminer le statut initial
        $statut_initial = ($cotisations['valides'] > 0 && $score_initial >= 500) ? 'eligible' : 'en_attente';
        
        // Calculer le montant maximum d'emprunt basé sur le score et les cotisations
        $montant_max_emprunt = 0;
        if ($statut_initial === 'eligible') {
            // Base: 10x la cotisation mensuelle moyenne
            $stmt_cotisation_moyenne = $pdo->prepare("
                SELECT AVG(montant) as moyenne 
                FROM cotisations 
                WHERE code_membre = ? AND statut = 'valide'
            ");
            $stmt_cotisation_moyenne->execute([$code_membre]);
            $moyenne = $stmt_cotisation_moyenne->fetch()['moyenne'] ?? 0;
            $montant_max_emprunt = $moyenne * 10 * ($score_initial / 500);
        }
        
        // Récupérer les dates de cotisation
        $stmt_dates_cotisations = $pdo->prepare("
            SELECT MIN(date_debut) as premiere, MAX(date_fin) as derniere,
                   COUNT(*) as total_mois, SUM(montant) as total_paye
            FROM cotisations 
            WHERE code_membre = ? AND statut = 'valide'
        ");
        $stmt_dates_cotisations->execute([$code_membre]);
        $dates_cotisations = $stmt_dates_cotisations->fetch();
        
        $cotisation_a_jour = ($dates_cotisations['derniere'] && $dates_cotisations['derniere'] >= date('Y-m-d'));
        
        // Insérer le nouvel emprunteur
        $stmt_insert = $pdo->prepare("
            INSERT INTO emprunteurs (
                code_membre, score_credit, statut, montant_max_emprunt,
                date_premiere_cotisation, date_derniere_cotisation,
                mois_cotises_consecutifs, mois_cotises_total,
                total_cotisations_payees, cotisation_a_jour
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        
        $stmt_insert->execute([
            $code_membre,
            $score_initial,
            $statut_initial,
            $montant_max_emprunt,
            $dates_cotisations['premiere'],
            $dates_cotisations['derniere'],
            $cotisations['valides'], // Approximation des mois consécutifs
            $cotisations['total'],
            $dates_cotisations['total_paye'] ?? 0,
            $cotisation_a_jour
        ]);
        
        // Journaliser l'action
        $stmt_audit = $pdo->prepare("
            INSERT INTO audit_log (table_name, action, record_id, old_values, new_values, user_id, ip_address)
            VALUES ('emprunteurs', 'INSERT', ?, ?, ?, ?, ?)
        ");
        $stmt_audit->execute([
            $code_membre,
            json_encode([]),
            json_encode([
                'code_membre' => $code_membre,
                'score_credit' => $score_initial,
                'statut' => $statut_initial,
                'montant_max_emprunt' => $montant_max_emprunt
            ]),
            $_SESSION['user_id'],
            $_SERVER['REMOTE_ADDR']
        ]);
        
        $_SESSION['succes'] = "Membre qualifié avec succès comme emprunteur. Score initial: " . $score_initial . ", Statut: " . $statut_initial;
        rediriger('emprunteurs.php');
        
    } catch (PDOException $e) {
        $_SESSION['erreur'] = "Erreur lors de la qualification: " . $e->getMessage();
        rediriger('qualifier_emprunteur.php');
    }
}

// Récupérer la liste des membres non encore qualifiés pour l'autocomplétion
try {
    $stmt_membres_non_qualifies = $pdo->query("
        SELECT m.code_membre, m.nom, m.prenom, m.email, cm.nom_categorie, m.date_inscription
        FROM membres m
        INNER JOIN categories_membres cm ON m.categorie_id = cm.id
        WHERE m.statut = 'actif'
        AND m.code_membre NOT IN (SELECT code_membre FROM emprunteurs)
        ORDER BY m.nom, m.prenom
    ");
    $membres_non_qualifies = $stmt_membres_non_qualifies->fetchAll();
} catch (PDOException $e) {
    $erreur = "Erreur lors du chargement des membres: " . $e->getMessage();
}
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Qualifier un Emprunteur - <?php echo APP_NAME; ?></title>
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
        .info-card {
            border-left: 4px solid #3498db;
        }
        .warning-card {
            border-left: 4px solid #f39c12;
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
                <!-- Header -->
                <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
                    <h1 class="h2">
                        <i class="fas fa-user-plus me-2"></i>Qualifier un Nouvel Emprunteur
                    </h1>
                    <div class="btn-toolbar mb-2 mb-md-0">
                        <a href="emprunteurs.php" class="btn btn-sm btn-outline-secondary me-2">
                            <i class="fas fa-arrow-left me-1"></i>Retour aux Emprunteurs
                        </a>
                        <a href="dashboard.php" class="btn btn-sm btn-outline-primary">
                            <i class="fas fa-tachometer-alt me-1"></i>Tableau de bord
                        </a>
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

                <div class="row">
                    <!-- Formulaire de qualification -->
                    <div class="col-lg-6 mb-4">
                        <div class="card shadow">
                            <div class="card-header bg-primary text-white">
                                <h5 class="card-title mb-0">
                                    <i class="fas fa-user-check me-2"></i>Qualifier un Membre
                                </h5>
                            </div>
                            <div class="card-body">
                                <form method="POST" id="formQualifier">
                                    <div class="mb-3">
                                        <label for="code_membre" class="form-label">Sélectionner un Membre</label>
                                        <select class="form-select" id="code_membre" name="code_membre" required>
                                            <option value="">Choisir un membre...</option>
                                            <?php foreach ($membres_non_qualifies as $membre): ?>
                                                <option value="<?php echo securiser($membre['code_membre']); ?>">
                                                    <?php echo securiser($membre['code_membre'] . ' - ' . $membre['nom'] . ' ' . $membre['prenom'] . ' (' . $membre['nom_categorie'] . ')'); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <div class="form-text">
                                            Seuls les membres actifs non encore qualifiés comme emprunteurs sont affichés.
                                        </div>
                                    </div>

                                    <div class="mb-3">
                                        <label class="form-label">Informations sur la qualification</label>
                                        <div class="alert alert-info">
                                            <small>
                                                <i class="fas fa-info-circle me-1"></i>
                                                <strong>Processus automatique:</strong><br>
                                                • Score de crédit calculé automatiquement<br>
                                                • Statut déterminé selon les cotisations<br>
                                                • Montant maximum d'emprunt calculé
                                            </small>
                                        </div>
                                    </div>

                                    <div class="d-grid gap-2">
                                        <button type="submit" class="btn btn-success btn-lg">
                                            <i class="fas fa-user-check me-2"></i>Qualifier comme Emprunteur
                                        </button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>

                    <!-- Informations et statistiques -->
                    <div class="col-lg-6 mb-4">
                        <!-- Carte d'information -->
                        <div class="card shadow info-card mb-4">
                            <div class="card-header bg-info text-white">
                                <h6 class="card-title mb-0">
                                    <i class="fas fa-info-circle me-2"></i>Comment fonctionne la qualification
                                </h6>
                            </div>
                            <div class="card-body">
                                <h6>Critères de qualification:</h6>
                                <ul class="small">
                                    <li>Membre doit être <strong>actif</strong></li>
                                    <li>Doit avoir des <strong>cotisations valides</strong></li>
                                    <li>Ne doit pas être déjà qualifié comme emprunteur</li>
                                </ul>

                                <h6>Calcul du score initial:</h6>
                                <ul class="small">
                                    <li>Score de base: <strong>500 points</strong></li>
                                    <li>+100 points si cotisation valide</li>
                                    <li>+50 points si ancienneté ≥ 3 mois</li>
                                </ul>

                                <h6>Statuts possibles:</h6>
                                <ul class="small">
                                    <li><span class="badge bg-success">Éligible</span> - Score ≥ 500 et cotisation valide</li>
                                    <li><span class="badge bg-warning">En attente</span> - Conditions non remplies</li>
                                </ul>
                            </div>
                        </div>

                        <!-- Statistiques rapides -->
                        <div class="card shadow warning-card">
                            <div class="card-header bg-warning text-dark">
                                <h6 class="card-title mb-0">
                                    <i class="fas fa-chart-bar me-2"></i>Statistiques
                                </h6>
                            </div>
                            <div class="card-body">
                                <div class="row text-center">
                                    <div class="col-6">
                                        <div class="h4 text-primary"><?php echo count($membres_non_qualifies); ?></div>
                                        <small class="text-muted">Membres à qualifier</small>
                                    </div>
                                    <div class="col-6">
                                        <?php
                                        try {
                                            $stmt_total_emprunteurs = $pdo->query("SELECT COUNT(*) as total FROM emprunteurs");
                                            $total_emprunteurs = $stmt_total_emprunteurs->fetch()['total'];
                                        } catch (PDOException $e) {
                                            $total_emprunteurs = 0;
                                        }
                                        ?>
                                        <div class="h4 text-success"><?php echo $total_emprunteurs; ?></div>
                                        <small class="text-muted">Emprunteurs actifs</small>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Liste des membres disponibles pour qualification -->
                <div class="row mt-4">
                    <div class="col-12">
                        <div class="card shadow">
                            <div class="card-header bg-light">
                                <h5 class="card-title mb-0">
                                    <i class="fas fa-list me-2"></i>Membres Disponibles pour Qualification
                                    <span class="badge bg-primary ms-2"><?php echo count($membres_non_qualifies); ?></span>
                                </h5>
                            </div>
                            <div class="card-body">
                                <?php if (!empty($membres_non_qualifies)): ?>
                                    <div class="table-responsive">
                                        <table class="table table-sm table-hover">
                                            <thead>
                                                <tr>
                                                    <th>Code Membre</th>
                                                    <th>Nom Complet</th>
                                                    <th>Email</th>
                                                    <th>Catégorie</th>
                                                    <th>Date d'Inscription</th>
                                                    <th>Action</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($membres_non_qualifies as $membre): ?>
                                                    <tr>
                                                        <td>
                                                            <strong><?php echo securiser($membre['code_membre']); ?></strong>
                                                        </td>
                                                        <td><?php echo securiser($membre['nom'] . ' ' . $membre['prenom']); ?></td>
                                                        <td><?php echo securiser($membre['email']); ?></td>
                                                        <td>
                                                            <span class="badge bg-secondary"><?php echo securiser($membre['nom_categorie']); ?></span>
                                                        </td>
                                                        <td>
                                                            <?php 
                                                            if (isset($membre['date_inscription']) && !empty($membre['date_inscription'])) {
                                                                echo date('d/m/Y', strtotime($membre['date_inscription']));
                                                            } else {
                                                                echo 'N/A';
                                                            }
                                                            ?>
                                                        </td>
                                                        <td>
                                                            <button type="button" class="btn btn-sm btn-outline-primary" 
                                                                    onclick="document.getElementById('code_membre').value = '<?php echo $membre['code_membre']; ?>'">
                                                                <i class="fas fa-check me-1"></i>Sélectionner
                                                            </button>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                <?php else: ?>
                                    <div class="text-center py-4">
                                        <i class="fas fa-users fa-3x text-muted mb-3"></i>
                                        <h5 class="text-muted">Aucun membre disponible</h5>
                                        <p class="text-muted">Tous les membres actifs sont déjà qualifiés comme emprunteurs.</p>
                                        <a href="membres.php" class="btn btn-primary">
                                            <i class="fas fa-plus me-1"></i>Ajouter un Nouveau Membre
                                        </a>
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
        // Auto-dismiss alerts after 5 seconds
        setTimeout(function() {
            const alerts = document.querySelectorAll('.alert');
            alerts.forEach(alert => {
                const bsAlert = new bootstrap.Alert(alert);
                bsAlert.close();
            });
        }, 5000);

        // Confirmation avant soumission
        document.getElementById('formQualifier').addEventListener('submit', function(e) {
            const codeMembre = document.getElementById('code_membre').value;
            if (!codeMembre) {
                e.preventDefault();
                alert('Veuillez sélectionner un membre');
                return;
            }
            
            if (!confirm('Êtes-vous sûr de vouloir qualifier ce membre comme emprunteur ?')) {
                e.preventDefault();
            }
        });
    </script>
</body>
</html>