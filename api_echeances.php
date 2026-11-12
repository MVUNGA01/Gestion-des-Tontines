<?php
require_once 'config_database.php';

header('Content-Type: application/json');

if (!est_connecte()) {
    echo json_encode(['error' => 'Non authentifié']);
    exit;
}

$pret_id = isset($_GET['pret_id']) ? (int)$_GET['pret_id'] : 0;

if ($pret_id <= 0) {
    echo json_encode(['error' => 'ID de prêt invalide']);
    exit;
}

try {
    $sql = "SELECT id, numero_echeance, date_echeance, montant_echeance, statut, penalite_retard
            FROM echeances_pret 
            WHERE pret_id = ? AND statut IN ('due', 'en_retard', 'a_venir')
            ORDER BY numero_echeance";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$pret_id]);
    $echeances = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode($echeances);
    
} catch (PDOException $e) {
    echo json_encode(['error' => 'Erreur de base de données: ' . $e->getMessage()]);
}
?>