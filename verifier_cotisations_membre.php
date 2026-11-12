<?php
require_once 'config_database.php';

if (!est_connecte()) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Non autorisé']);
    exit;
}

$code_membre = $_GET['code_membre'] ?? '';

if (empty($code_membre)) {
    echo json_encode(['success' => false, 'message' => 'Code membre manquant']);
    exit;
}

try {
    // Compter les cotisations valides des 12 derniers mois
    $stmt = $pdo->prepare("
        SELECT 
            COUNT(*) as nb_cotisations,
            SUM(montant) as total_cotise,
            MIN(date_debut) as premiere_cotisation,
            MAX(date_fin) as derniere_cotisation
        FROM cotisations 
        WHERE code_membre = ? 
        AND statut = 'valide'
        AND date_fin >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
    ");
    $stmt->execute([$code_membre]);
    $cotisations = $stmt->fetch();

    echo json_encode([
        'success' => true,
        'nb_cotisations' => $cotisations['nb_cotisations'],
        'total_cotise' => $cotisations['total_cotise'],
        'premiere_cotisation' => $cotisations['premiere_cotisation'],
        'derniere_cotisation' => $cotisations['derniere_cotisation']
    ]);

} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Erreur: ' . $e->getMessage()]);
}
?>