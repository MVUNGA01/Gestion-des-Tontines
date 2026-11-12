<?php
require_once 'config_database.php';

// Vérifier l'authentification
if (!est_connecte()) {
    rediriger('login.php');
}

// Vérifier les permissions
if (!a_la_permission('admin') && !a_la_permission('gestionnaire')) {
    $_SESSION['erreur'] = "Vous n'avez pas la permission d'accéder à cette page.";
    rediriger('dashboard.php');
}

// Récupérer l'ID du prêt
$pret_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($pret_id <= 0) {
    $_SESSION['erreur'] = "Prêt non spécifié.";
    rediriger('prets.php');
}

try {
    // Récupérer les informations du prêt
    $sql = "SELECT p.*, m.nom, m.prenom, m.code_membre, pp.nom_pret, tp.nom_type,
                   pp.id as plan_pret_id, pp.type_pret_id
            FROM prets p
            INNER JOIN membres m ON p.code_membre = m.code_membre
            INNER JOIN plans_pret pp ON p.plan_pret_id = pp.id
            INNER JOIN types_pret tp ON pp.type_pret_id = tp.id
            WHERE p.id = ?";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$pret_id]);
    $pret = $stmt->fetch();
    
    if (!$pret) {
        $_SESSION['erreur'] = "Prêt non trouvé.";
        rediriger('prets.php');
    }
    
    // Récupérer les types de prêt et plans disponibles
    $stmt_types = $pdo->query("SELECT id, nom_type FROM types_pret WHERE statut = 'actif'");
    $types_pret = $stmt_types->fetchAll();
    
    $stmt_plans = $pdo->query("SELECT id, nom_pret, type_pret_id FROM plans_pret WHERE statut = 'actif'");
    $plans_pret = $stmt_plans->fetchAll();
    
} catch (PDOException $e) {
    $_SESSION['erreur'] = "Erreur lors du chargement des données: " . $e->getMessage();
    rediriger('prets.php');
}

// Traitement du formulaire de modification
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $montant = (float)$_POST['montant'];
        $duree_mois = (int)$_POST['duree_mois'];
        $taux_interet = (float)$_POST['taux_interet'];
        $plan_pret_id = (int)$_POST['plan_pret_id'];
        $date_debut = securiser($_POST['date_debut']);
        $motif_pret = securiser($_POST['motif_pret']);
        $garanties_provisees = securiser($_POST['garanties_provisees']);
        
        // Recalculer la mensualité
        $taux_mensuel = $taux_interet / 12 / 100;
        $mensualite = $montant * ($taux_mensuel * pow(1 + $taux_mensuel, $duree_mois)) / 
                     (pow(1 + $taux_mensuel, $duree_mois) - 1);
        
        // Calculer la date de fin
        $date_fin = date('Y-m-d', strtotime($date_debut . " + $duree_mois months"));
        
        // Mettre à jour le prêt
        $sql_update = "UPDATE prets 
                      SET montant = ?, 
                          duree_mois = ?, 
                          taux_interet = ?,
                          plan_pret_id = ?,
                          date_debut = ?,
                          date_fin = ?,
                          mensualite = ?,
                          motif_pret = ?,
                          garanties_provisees = ?,
                          conditions_remplies = FALSE  -- Réinitialiser la vérification
                      WHERE id = ?";
        
        $stmt_update = $pdo->prepare($sql_update);
        $stmt_update->execute([
            $montant, $duree_mois, $taux_interet, $plan_pret_id,
            $date_debut, $date_fin, $mensualite, $motif_pret,
            $garanties_provisees, $pret_id
        ]);
        
        $_SESSION['succes'] = "Prêt modifié avec succès. Les conditions doivent être revérifiées.";
        rediriger('prets.php');
        
    } catch (PDOException $e) {
        $_SESSION['erreur'] = "Erreur lors de la modification: " . $e->getMessage();
        rediriger('modifier_pret.php?id=' . $pret_id);
    }
}
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Modifier le Prêt - <?php echo APP_NAME; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        .sidebar { background: #2c3e50; color: white; height: 100vh; position: fixed; padding-top: 20px; overflow-y: auto; }
        .sidebar .nav-link { color: #bdc3c7; padding: 12px 15px; margin: 3px 0; font-size: 0.9rem; }
        .sidebar .nav-link:hover, .sidebar .nav-link.active { color: white; background: #34495e; }
        .sidebar .nav-link i { width: 20px; margin-right: 8px; }
        .main-content { margin-left: 250px; padding: 20px; }
        .menu-section { margin: 15px 0; padding: 10px 0; border-bottom: 1px solid #34495e; }
        .menu-section-title { font-size: 0.8rem; text-transform: uppercase; color: #95a5a6; padding: 0 15px; margin-bottom: 10px; font-weight: bold; }
        .info-card { border-left: 4px solid #007bff; }
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
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <div>
                        <h1 class="h3 mb-0">
                            <i class="fas fa-edit me-2"></i>Modifier le Prêt
                        </h1>
                        <p class="text-muted mb-0">Modification des informations du prêt <?php echo securiser($pret['code_pret']); ?></p>
                    </div>
                    <div>
                        <a href="prets.php" class="btn btn-secondary me-2">
                            <i class="fas fa-arrow-left me-2"></i>Retour aux prêts
                        </a>
                        <a href="details_pret.php?id=<?php echo $pret_id; ?>" class="btn btn-primary">
                            <i class="fas fa-eye me-2"></i>Voir détails
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

                <div class="row">
                    <!-- Informations du prêt -->
                    <div class="col-lg-4">
                        <div class="card shadow mb-4 info-card">
                            <div class="card-header bg-primary text-white">
                                <h6 class="m-0 font-weight-bold">
                                    <i class="fas fa-info-circle me-2"></i>Informations Actuelles
                                </h6>
                            </div>
                            <div class="card-body">
                                <table class="table table-borderless">
                                    <tr>
                                        <th width="40%">Code Prêt:</th>
                                        <td><strong><?php echo securiser($pret['code_pret']); ?></strong></td>
                                    </tr>
                                    <tr>
                                        <th>Membre:</th>
                                        <td><?php echo securiser($pret['nom'] . ' ' . $pret['prenom']); ?></td>
                                    </tr>
                                    <tr>
                                        <th>Type:</th>
                                        <td><?php echo securiser($pret['nom_type']); ?></td>
                                    </tr>
                                    <tr>
                                        <th>Plan:</th>
                                        <td><?php echo securiser($pret['nom_pret']); ?></td>
                                    </tr>
                                    <tr>
                                        <th>Statut:</th>
                                        <td>
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
                                            ?>">
                                                <?php echo ucfirst(str_replace('_', ' ', $pret['statut'])); ?>
                                            </span>
                                        </td>
                                    </tr>
                                </table>
                            </div>
                        </div>
                    </div>

                    <!-- Formulaire de modification -->
                    <div class="col-lg-8">
                        <div class="card shadow">
                            <div class="card-header bg-warning text-white">
                                <h6 class="m-0 font-weight-bold">
                                    <i class="fas fa-edit me-2"></i>Modifier les Informations
                                </h6>
                            </div>
                            <div class="card-body">
                                <form method="POST" id="formModificationPret">
                                    <div class="row">
                                        <div class="col-md-6 mb-3">
                                            <label for="montant" class="form-label">Montant du prêt (FBu)</label>
                                            <input type="number" class="form-control" id="montant" name="montant" 
                                                   value="<?php echo $pret['montant']; ?>" step="0.01" min="1000" required>
                                        </div>
                                        
                                        <div class="col-md-6 mb-3">
                                            <label for="duree_mois" class="form-label">Durée (mois)</label>
                                            <input type="number" class="form-control" id="duree_mois" name="duree_mois" 
                                                   value="<?php echo $pret['duree_mois']; ?>" min="1" max="120" required>
                                        </div>
                                    </div>

                                    <div class="row">
                                        <div class="col-md-6 mb-3">
                                            <label for="taux_interet" class="form-label">Taux d'intérêt annuel (%)</label>
                                            <input type="number" class="form-control" id="taux_interet" name="taux_interet" 
                                                   value="<?php echo $pret['taux_interet']; ?>" step="0.01" min="0.1" max="100" required>
                                        </div>
                                        
                                        <div class="col-md-6 mb-3">
                                            <label for="plan_pret_id" class="form-label">Plan de prêt</label>
                                            <select class="form-select" id="plan_pret_id" name="plan_pret_id" required>
                                                <option value="">Sélectionner un plan</option>
                                                <?php foreach ($plans_pret as $plan): ?>
                                                    <option value="<?php echo $plan['id']; ?>" 
                                                            <?php echo $plan['id'] == $pret['plan_pret_id'] ? 'selected' : ''; ?>>
                                                        <?php echo securiser($plan['nom_pret']); ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                    </div>

                                    <div class="row">
                                        <div class="col-md-6 mb-3">
                                            <label for="date_debut" class="form-label">Date de début</label>
                                            <input type="date" class="form-control" id="date_debut" name="date_debut" 
                                                   value="<?php echo $pret['date_debut']; ?>" required>
                                        </div>
                                        
                                        <div class="col-md-6 mb-3">
                                            <label class="form-label">Mensualité calculée</label>
                                            <div class="input-group">
                                                <input type="text" class="form-control" id="mensualite_calculee" 
                                                       value="<?php echo number_format($pret['mensualite'], 2); ?>" readonly>
                                                <span class="input-group-text">FBu</span>
                                            </div>
                                            <small class="form-text text-muted">Recalculée automatiquement</small>
                                        </div>
                                    </div>

                                    <div class="mb-3">
                                        <label for="motif_pret" class="form-label">Motif du prêt</label>
                                        <textarea class="form-control" id="motif_pret" name="motif_pret" 
                                                  rows="3"><?php echo securiser($pret['motif_pret']); ?></textarea>
                                    </div>

                                    <div class="mb-3">
                                        <label for="garanties_provisees" class="form-label">Garanties fournies</label>
                                        <textarea class="form-control" id="garanties_provisees" name="garanties_provisees" 
                                                  rows="3"><?php echo securiser($pret['garanties_provisees']); ?></textarea>
                                    </div>

                                    <div class="alert alert-info">
                                        <i class="fas fa-info-circle me-2"></i>
                                        <strong>Note importante:</strong> Après modification, les conditions d'éligibilité 
                                        devront être revérifiées avant toute approbation.
                                    </div>

                                    <div class="d-flex justify-content-between">
                                        <a href="details_pret.php?id=<?php echo $pret_id; ?>" class="btn btn-secondary">
                                            <i class="fas fa-times me-2"></i>Annuler
                                        </a>
                                        <button type="submit" class="btn btn-warning">
                                            <i class="fas fa-save me-2"></i>Enregistrer les modifications
                                        </button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Aperçu des nouvelles conditions -->
                <div class="card shadow mt-4">
                    <div class="card-header bg-success text-white">
                        <h6 class="m-0 font-weight-bold">
                            <i class="fas fa-calculator me-2"></i>Aperçu des Nouvelles Conditions
                        </h6>
                    </div>
                    <div class="card-body">
                        <div class="row text-center">
                            <div class="col-md-3">
                                <div class="border rounded p-3">
                                    <h6 class="text-muted">Nouvelle Mensualité</h6>
                                    <h4 id="apercu_mensualite" class="text-success"><?php echo number_format($pret['mensualite'], 2); ?> FBu</h4>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="border rounded p-3">
                                    <h6 class="text-muted">Intérêt Total</h6>
                                    <h4 id="apercu_interet_total" class="text-info"><?php echo number_format($pret['montant'] * $pret['taux_interet']/100 * $pret['duree_mois']/12, 2); ?> FBu</h4>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="border rounded p-3">
                                    <h6 class="text-muted">Montant Total</h6>
                                    <h4 id="apercu_montant_total" class="text-primary"><?php echo number_format($pret['montant'] + ($pret['montant'] * $pret['taux_interet']/100 * $pret['duree_mois']/12), 2); ?> FBu</h4>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="border rounded p-3">
                                    <h6 class="text-muted">Date de Fin</h6>
                                    <h4 id="apercu_date_fin" class="text-dark"><?php echo date('d/m/Y', strtotime($pret['date_fin'])); ?></h4>
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
        // Fonction pour recalculer la mensualité
        function recalculerMensualite() {
            const montant = parseFloat(document.getElementById('montant').value) || 0;
            const tauxInteret = parseFloat(document.getElementById('taux_interet').value) || 0;
            const dureeMois = parseInt(document.getElementById('duree_mois').value) || 0;
            const dateDebut = document.getElementById('date_debut').value;
            
            if (montant > 0 && tauxInteret > 0 && dureeMois > 0) {
                // Calcul de la mensualité
                const tauxMensuel = tauxInteret / 12 / 100;
                const mensualite = montant * (tauxMensuel * Math.pow(1 + tauxMensuel, dureeMois)) / 
                                 (Math.pow(1 + tauxMensuel, dureeMois) - 1);
                
                document.getElementById('mensualite_calculee').value = mensualite.toFixed(2);
                
                // Mise à jour de l'aperçu
                const interetTotal = (mensualite * dureeMois) - montant;
                const montantTotal = mensualite * dureeMois;
                
                document.getElementById('apercu_mensualite').textContent = mensualite.toFixed(2) + ' FBu';
                document.getElementById('apercu_interet_total').textContent = interetTotal.toFixed(2) + ' FBu';
                document.getElementById('apercu_montant_total').textContent = montantTotal.toFixed(2) + ' FBu';
                
                // Calcul de la date de fin
                if (dateDebut) {
                    const dateDebutObj = new Date(dateDebut);
                    const dateFinObj = new Date(dateDebutObj);
                    dateFinObj.setMonth(dateFinObj.getMonth() + dureeMois);
                    
                    const options = { day: '2-digit', month: '2-digit', year: 'numeric' };
                    document.getElementById('apercu_date_fin').textContent = dateFinObj.toLocaleDateString('fr-FR', options);
                }
            }
        }
        
        // Écouteurs d'événements pour le recalcul automatique
        document.getElementById('montant').addEventListener('input', recalculerMensualite);
        document.getElementById('taux_interet').addEventListener('input', recalculerMensualite);
        document.getElementById('duree_mois').addEventListener('input', recalculerMensualite);
        document.getElementById('date_debut').addEventListener('change', recalculerMensualite);
        
        // Recalcul initial
        recalculerMensualite();
    </script>
</body>
</html>