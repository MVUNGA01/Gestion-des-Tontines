<?php
require_once 'config_database.php';

// Vérifier la synchronisation entre paiements et échéances
$sql = "SELECT 
            p.id as pret_id,
            p.code_pret,
            p.montant_restant,
            SUM(ep.capital_restant) as total_capital_restant_echeances,
            COUNT(ep.id) as total_echeances,
            SUM(CASE WHEN ep.statut = 'payee' THEN 1 ELSE 0 END) as echeances_payees
        FROM prets p
        LEFT JOIN echeances_pret ep ON p.id = ep.pret_id
        WHERE p.statut IN ('actif', 'en_retard')
        GROUP BY p.id
        HAVING p.montant_restant != total_capital_restant_echeances";

$stmt = $pdo->query($sql);
$incoherences = $stmt->fetchAll();

if (count($incoherences) > 0) {
    echo "Incohérences détectées:\n";
    foreach ($incoherences as $inc) {
        echo "Prêt {$inc['code_pret']}: Montant restant = {$inc['montant_restant']}, Capital restant échéances = {$inc['total_capital_restant_echeances']}\n";
    }
} else {
    echo "Aucune incohérence détectée.\n";
}
?>