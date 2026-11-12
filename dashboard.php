<?php
require_once 'config_database.php';

// Vérifier l'authentification
if (!est_connecte()) {
	rediriger('login.php');
}

// Récupérer les statistiques selon le rôle de l'utilisateur
try {
	// Statistiques de base pour tous les utilisateurs
	if (a_la_permission('admin') || a_la_permission('gestionnaire')) {
		// Statistiques complètes pour admin et gestionnaire
		$stmt_membres = $pdo->query("SELECT COUNT(*) as total FROM membres WHERE statut = 'actif'");
		$total_membres = $stmt_membres->fetch()['total'];
		
		$stmt_emprunteurs = $pdo->query("SELECT COUNT(*) as total FROM emprunteurs WHERE statut = 'eligible'");
		$total_emprunteurs = $stmt_emprunteurs->fetch()['total'];
		
		$stmt_prets = $pdo->query("SELECT COUNT(*) as total FROM prets WHERE statut IN ('actif', 'en_retard')");
		$total_prets_actifs = $stmt_prets->fetch()['total'];
		
		$stmt_dette = $pdo->query("SELECT SUM(montant_restant) as total FROM prets WHERE statut IN ('actif', 'en_retard')");
		$total_dette = $stmt_dette->fetch()['total'] ?? 0;
		
		$stmt_retard = $pdo->query("SELECT COUNT(*) as total FROM prets WHERE statut = 'en_retard'");
		$prets_retard = $stmt_retard->fetch()['total'];
		
		// Derniers prêts approuvés
		$stmt_derniers_prets = $pdo->query("
			SELECT p.code_pret, m.nom, m.prenom, p.montant, p.date_creation 
			FROM prets p 
			INNER JOIN membres m ON p.code_membre = m.code_membre 
			WHERE p.statut = 'approuve' 
			ORDER BY p.date_creation DESC 
			LIMIT 5
		");
		$derniers_prets = $stmt_derniers_prets->fetchAll();
		
	} else {
		// Statistiques limitées pour les autres rôles
		$total_membres = 0;
		$total_emprunteurs = 0;
		$total_prets_actifs = 0;
		$total_dette = 0;
		$prets_retard = 0;
		$derniers_prets = [];
	}

	// Alertes récentes (pour tous les utilisateurs)
	$stmt_alertes = $pdo->query("
		SELECT type_alerte, titre, message, date_creation 
		FROM alertes 
		WHERE lue = FALSE 
		ORDER BY date_creation DESC 
		LIMIT 5
	");
	$alertes = $stmt_alertes->fetchAll();
	
} catch (PDOException $e) {
	$erreur = "Erreur lors du chargement des données: " . $e->getMessage();
}
?>

<!DOCTYPE html>
<html lang="fr">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<title>Tableau de bord - <?php echo APP_NAME; ?></title>
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
		.welcome-header {
			background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
			color: white;
			padding: 2rem;
			border-radius: 10px;
			margin-bottom: 2rem;
		}
		.currency {
			font-weight: bold;
			color: #2c3e50;
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
		.card-header {
			border-bottom: 1px solid #e3e6f0;
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
							<a class="nav-link active" href="dashboard.php">
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
				<div class="welcome-header">
					<div class="row align-items-center">
						<div class="col">
							<h1>Bonjour, <?php echo $_SESSION['nom_complet']; ?>!</h1>
							<p class="mb-0">Bienvenue dans votre tableau de bord</p>
						</div>
						<div class="col-auto">
							<span class="badge bg-light text-dark">
								<i class="fas fa-user-shield me-1"></i><?php echo ucfirst($_SESSION['user_role']); ?>
							</span>
						</div>
					</div>
				</div>

				<!-- Statistiques (uniquement pour admin et gestionnaire) -->
				<?php if (a_la_permission('admin') || a_la_permission('gestionnaire')): ?>
				<div class="row mb-4">
					<div class="col-xl-3 col-md-6 mb-4">
						<div class="card stat-card border-left-primary shadow h-100 py-2">
							<div class="card-body">
								<div class="row no-gutters align-items-center">
									<div class="col mr-2">
										<div class="text-xs font-weight-bold text-primary text-uppercase mb-1">
											Membres Actifs
										</div>
										<div class="h5 mb-0 font-weight-bold text-gray-800">
											<?php echo number_format($total_membres); ?>
										</div>
									</div>
									<div class="col-auto">
										<i class="fas fa-users fa-2x text-gray-300"></i>
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
											Emprunteurs Éligibles
										</div>
										<div class="h5 mb-0 font-weight-bold text-gray-800">
											<?php echo number_format($total_emprunteurs); ?>
										</div>
									</div>
									<div class="col-auto">
										<i class="fas fa-user-check fa-2x text-gray-300"></i>
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
											Prêts Actifs
										</div>
										<div class="h5 mb-0 font-weight-bold text-gray-800">
											<?php echo number_format($total_prets_actifs); ?>
										</div>
									</div>
									<div class="col-auto">
										<i class="fas fa-file-invoice-dollar fa-2x text-gray-300"></i>
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
											Dette Totale
										</div>
										<div class="h5 mb-0 font-weight-bold text-gray-800">
											<?php echo number_format($total_dette, 2); ?> <span class="currency">FBu</span>
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

				<div class="row mb-4">
					<div class="col-xl-3 col-md-6 mb-4">
						<div class="card stat-card border-left-danger shadow h-100 py-2">
							<div class="card-body">
								<div class="row no-gutters align-items-center">
									<div class="col mr-2">
										<div class="text-xs font-weight-bold text-danger text-uppercase mb-1">
											Prêts en Retard
										</div>
										<div class="h5 mb-0 font-weight-bold text-gray-800">
											<?php echo number_format($prets_retard); ?>
										</div>
									</div>
									<div class="col-auto">
										<i class="fas fa-exclamation-triangle fa-2x text-gray-300"></i>
									</div>
								</div>
							</div>
						</div>
					</div>

					<!-- Statistiques supplémentaires -->
					<?php
					// Récupérer le nombre de cotisations expirant bientôt
					$stmt_cotisations_expirant = $pdo->query("
						SELECT COUNT(*) as total 
						FROM cotisations 
						WHERE statut = 'valide' 
						AND DATEDIFF(date_fin, CURDATE()) BETWEEN 0 AND 30
					");
					$cotisations_expirant = $stmt_cotisations_expirant->fetch()['total'];
					?>

					<div class="col-xl-3 col-md-6 mb-4">
						<div class="card stat-card border-left-secondary shadow h-100 py-2">
							<div class="card-body">
								<div class="row no-gutters align-items-center">
									<div class="col mr-2">
										<div class="text-xs font-weight-bold text-secondary text-uppercase mb-1">
											Cotisations Expirant
										</div>
										<div class="h5 mb-0 font-weight-bold text-gray-800">
											<?php echo number_format($cotisations_expirant); ?>
										</div>
									</div>
									<div class="col-auto">
										<i class="fas fa-clock fa-2x text-gray-300"></i>
									</div>
								</div>
							</div>
						</div>
					</div>

					<?php
					// Récupérer le montant total des paiements du mois
					$stmt_paiements_mois = $pdo->query("
						SELECT SUM(montant_paye) as total 
						FROM paiements 
						WHERE MONTH(date_paiement) = MONTH(CURDATE()) 
						AND YEAR(date_paiement) = YEAR(CURDATE())
					");
					$paiements_mois = $stmt_paiements_mois->fetch()['total'] ?? 0;
					?>

					<div class="col-xl-3 col-md-6 mb-4">
						<div class="card stat-card border-left-info shadow h-100 py-2">
							<div class="card-body">
								<div class="row no-gutters align-items-center">
									<div class="col mr-2">
										<div class="text-xs font-weight-bold text-info text-uppercase mb-1">
											Paiements Ce Mois
										</div>
										<div class="h5 mb-0 font-weight-bold text-gray-800">
											<?php echo number_format($paiements_mois, 2); ?> <span class="currency">FBu</span>
										</div>
									</div>
									<div class="col-auto">
										<i class="fas fa-cash-register fa-2x text-gray-300"></i>
									</div>
								</div>
							</div>
						</div>
					</div>

					<?php
					// Récupérer le nombre de prêts en attente d'approbation
					$stmt_prets_attente = $pdo->query("
						SELECT COUNT(*) as total 
						FROM prets 
						WHERE statut = 'en_attente'
					");
					$prets_attente = $stmt_prets_attente->fetch()['total'];
					?>

					<div class="col-xl-3 col-md-6 mb-4">
						<div class="card stat-card border-left-warning shadow h-100 py-2">
							<div class="card-body">
								<div class="row no-gutters align-items-center">
									<div class="col mr-2">
										<div class="text-xs font-weight-bold text-warning text-uppercase mb-1">
											Prêts en Attente
										</div>
										<div class="h5 mb-0 font-weight-bold text-gray-800">
											<?php echo number_format($prets_attente); ?>
										</div>
									</div>
									<div class="col-auto">
										<i class="fas fa-hourglass-half fa-2x text-gray-300"></i>
									</div>
								</div>
							</div>
						</div>
					</div>
				</div>
				<?php endif; ?>

				<div class="row">
					<!-- Derniers prêts (uniquement pour admin et gestionnaire) -->
					<?php if (a_la_permission('admin') || a_la_permission('gestionnaire')): ?>
					<div class="col-lg-6 mb-4">
						<div class="card shadow h-100">
							<div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
								<h6 class="m-0 font-weight-bold">
									<i class="fas fa-file-invoice-dollar me-2"></i>Derniers Prêts Approuvés
								</h6>
								<a href="prets.php" class="btn btn-sm btn-light">Voir tout</a>
							</div>
							<div class="card-body">
								<?php if (!empty($derniers_prets)): ?>
									<div class="list-group list-group-flush">
										<?php foreach ($derniers_prets as $pret): ?>
											<div class="list-group-item d-flex justify-content-between align-items-center">
												<div>
													<h6 class="mb-1"><?php echo securiser($pret['nom'] . ' ' . $pret['prenom']); ?></h6>
													<small class="text-muted"><?php echo securiser($pret['code_pret']); ?></small>
												</div>
												<div class="text-end">
													<strong><?php echo number_format($pret['montant'], 2); ?> <span class="currency">FBu</span></strong>
													<br>
													<small class="text-muted"><?php echo date('d/m/Y', strtotime($pret['date_creation'])); ?></small>
												</div>
											</div>
										<?php endforeach; ?>
									</div>
								<?php else: ?>
									<p class="text-muted text-center my-3">Aucun prêt récent</p>
								<?php endif; ?>
							</div>
						</div>
					</div>
					<?php endif; ?>

					<!-- Alertes récentes (pour tous les utilisateurs) -->
					<div class="col-lg-<?php echo (a_la_permission('admin') || a_la_permission('gestionnaire')) ? '6' : '12'; ?> mb-4">
						<div class="card shadow h-100">
							<div class="card-header bg-warning text-dark d-flex justify-content-between align-items-center">
								<h6 class="m-0 font-weight-bold">
									<i class="fas fa-bell me-2"></i>Alertes Récentes
								</h6>
								<a href="alertes.php" class="btn btn-sm btn-dark">Voir tout</a>
							</div>
							<div class="card-body">
								<?php if (!empty($alertes)): ?>
									<div class="list-group list-group-flush">
										<?php foreach ($alertes as $alerte): ?>
											<div class="list-group-item">
												<div class="d-flex w-100 justify-content-between">
													<h6 class="mb-1 text-<?php 
														switch($alerte['type_alerte']) {
															case 'retard_paiement': echo 'danger'; break;
															case 'cotisation_expire': echo 'warning'; break;
															case 'pret_approche_echeance': echo 'info'; break;
															default: echo 'secondary';
														}
													?>">
														<i class="fas fa-<?php 
															switch($alerte['type_alerte']) {
																case 'retard_paiement': echo 'exclamation-triangle'; break;
																case 'cotisation_expire': echo 'clock'; break;
																case 'pret_approche_echeance': echo 'calendar-alt'; break;
																default: echo 'info-circle';
															}
														?> me-1"></i>
														<?php echo securiser($alerte['titre']); ?>
													</h6>
													<small><?php echo date('H:i', strtotime($alerte['date_creation'])); ?></small>
												</div>
												<p class="mb-1"><?php echo securiser($alerte['message']); ?></p>
											</div>
										<?php endforeach; ?>
									</div>
								<?php else: ?>
									<p class="text-muted text-center my-3">Aucune alerte récente</p>
								<?php endif; ?>
							</div>
						</div>
					</div>
				</div>

				<!-- Actions rapides -->
				<div class="row mt-4">
					<div class="col-12">
						<div class="card shadow">
							<div class="card-header bg-light">
								<h6 class="m-0 font-weight-bold text-primary">
									<i class="fas fa-bolt me-2"></i>Actions Rapides
								</h6>
							</div>
							<div class="card-body">
								<div class="row text-center">
									<?php if (a_la_permission('admin') || a_la_permission('gestionnaire')): ?>
									<div class="col-md-3 mb-3">
										<a href="nouveau_pret.php" class="btn btn-primary btn-lg w-100 py-3">
											<i class="fas fa-plus me-2"></i>Nouveau Prêt
										</a>
									</div>
									<div class="col-md-3 mb-3">
										<a href="nouveau_membre.php" class="btn btn-success btn-lg w-100 py-3">
											<i class="fas fa-user-plus me-2"></i>Nouveau Membre
										</a>
									</div>
									<div class="col-md-3 mb-3">
										<a href="emprunteurs.php" class="btn btn-info btn-lg w-100 py-3">
											<i class="fas fa-user-check me-2"></i>Gérer Emprunteurs
										</a>
									</div>
									<?php endif; ?>
									
									<div class="col-md-3 mb-3">
										<a href="enregistrer_paiement.php" class="btn btn-warning btn-lg w-100 py-3">
											<i class="fas fa-money-bill-wave me-2"></i>Enregistrer Paiement
										</a>
									</div>
								</div>
							</div>
						</div>
					</div>
				</div>

				<!-- Section informations système (uniquement pour admin) -->
				<?php if (a_la_permission('admin')): ?>
				<div class="row mt-4">
					<div class="col-12">
						<div class="card shadow">
							<div class="card-header bg-dark text-white">
								<h6 class="m-0 font-weight-bold">
									<i class="fas fa-info-circle me-2"></i>Informations Système
								</h6>
							</div>
							<div class="card-body">
								<div class="row">
									<div class="col-md-4">
										<strong>Version:</strong> 1.0.0<br>
										<strong>Dernière sauvegarde:</strong> <?php echo date('d/m/Y H:i'); ?>
									</div>
									<div class="col-md-4">
										<strong>Base de données:</strong> MySQL<br>
										<strong>Serveur:</strong> <?php echo $_SERVER['SERVER_SOFTWARE'] ?? 'N/A'; ?>
									</div>
									<div class="col-md-4">
										<strong>Utilisateurs connectés:</strong> 1<br>
										<strong>Statut:</strong> <span class="badge bg-success">Opérationnel</span>
									</div>
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
		// Auto-actualisation toutes les 5 minutes
		setTimeout(function() {
			window.location.reload();
		}, 300000);

		// Animation des cartes statistiques
		document.addEventListener('DOMContentLoaded', function() {
			const statCards = document.querySelectorAll('.stat-card');
			statCards.forEach((card, index) => {
				setTimeout(() => {
					card.style.opacity = '0';
					card.style.transform = 'translateY(20px)';
					card.style.transition = 'all 0.5s ease';
					
					setTimeout(() => {
						card.style.opacity = '1';
						card.style.transform = 'translateY(0)';
					}, 100);
				}, index * 100);
			});
		});
	</script>
</body>
</html>