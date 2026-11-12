<?php
require_once 'config_database.php';

if (!est_connecte() || !a_la_permission('gestionnaire')) {
    http_response_code(403);
    exit('Accès refusé');
}

if (isset($_POST['code_membre'])) {
    $code_membre = securiser($_POST['code_membre']);
    
    try {
        // Requête pour récupérer les détails complets
        $stmt = $pdo->prepare("
            SELECT e.*, m.*, cm.*
            FROM emprunteurs e
            INNER JOIN membres m ON e.code_membre = m.code_membre
            INNER JOIN categories_membres cm ON m.categorie_id = cm.id
            WHERE e.code_membre = ?
        ");
        $stmt->execute([$code_membre]);
        $details = $stmt->fetch();
        
        if ($details) {
            // Afficher les détails
            include 'vues/details_emprunteur.php';
        } else {
            echo '<div class="alert alert-warning">Emprunteur non trouvé.</div>';
        }
    } catch (PDOException $e) {
        error_log("Erreur détails emprunteur: " . $e->getMessage());
        echo '<div class="alert alert-danger">Erreur lors du chargement des détails.</div>';
    }
}
?>