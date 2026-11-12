<?php
require_once 'config_database.php';

// Script pour corriger toutes les dettes
try {
    $pdo->beginTransaction();
    
    // Corriger tous les prêts
    $sql_corriger_prets = "
        UPDATE prets p
        SET montant_restant = GREATEST(p.montant - COALESCE((
            SELECT SUM(pmt.montant_capital) 
            FROM paiements pmt 
            WHERE pmt.pret_id = p.id 
            AND pmt.statut IN ('complet', 'partiel')
        ), 0), 0),
        statut = CASE 
            WHEN p.montant - COALESCE((
                SELECT SUM(pmt.montant_capital) 
                FROM paiements pmt 
                WHERE pmt.pret_id = p.id 
                AND pmt.statut IN ('complet', 'partiel')
            ), 0) <= 0 THEN 'complet'
            ELSE p.statut
        END
        WHERE p.statut IN ('actif', 'en_retard', 'complet')
    ";
    
    $pdo->exec($sql_corriger_prets);
    
    // Corriger tous les emprunteurs
    $sql_corriger_emprunteurs = "
        UPDATE emprunteurs e
        SET dette_actuelle = COALESCE((
            SELECT SUM(p.montant_restant)
            FROM prets p
            WHERE p.code_membre = e.code_membre
            AND p.statut IN ('actif', 'en_retard')
        ), 0),
        nombre_prets_actifs = COALESCE((
            SELECT COUNT(*)
            FROM prets p
            WHERE p.code_membre = e.code_membre
            AND p.statut IN ('actif', 'en_retard')
        ), 0)
    ";
    
    $pdo->exec($sql_corriger_emprunteurs);
    
    $pdo->commit();
    echo "Dettes corrigées avec succès!";
    
} catch (PDOException $e) {
    $pdo->rollBack();
    echo "Erreur: " . $e->getMessage();
}
?>