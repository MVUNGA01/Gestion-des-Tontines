<?php
require_once 'config_database.php';

header('Content-Type: application/json');

if (!est_connecte()) {
    echo json_encode(['error' => 'Non authentifié']);
    exit;
}

$pret_id = isset($_GET['pret_id']) ? (int)$_GET['pret_id'] : 0;

if ($pret_id <= 0) {
    echo json_encode(['error' => 'ID prêt invalide']);
    exit;
}

try {
    $sql = "SELECT 
                ep.id,
                ep.numero_echeance,
                ep.date_echeance,
                ep.montant_echeance,
                ep.capital,
                ep.interet,
                ep.assurance,
                ep.capital_restant,
                ep.statut,
                ep.penalite_retard,
                ep.montant_paye
            FROM echeances_pret ep
            WHERE ep.pret_id = ? 
            AND ep.statut IN ('a_venir', 'due', 'en_retard')
            ORDER BY ep.numero_echeance";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$pret_id]);
    $echeances = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode($echeances);
    
} catch (PDOException $e) {
    echo json_encode(['error' => 'Erreur: ' . $e->getMessage()]);
}
?>