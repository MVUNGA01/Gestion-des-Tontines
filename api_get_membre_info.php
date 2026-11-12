<?php
require_once 'config_database.php';

header('Content-Type: application/json');

if (!isset($_GET['code_membre'])) {
    echo json_encode(['success' => false, 'message' => 'Code membre manquant']);
    exit;
}

$code_membre = securiser($_GET['code_membre']);

try {
    $stmt = $pdo->prepare("
        SELECT 
            m.id,
            m.code_membre,
            CONCAT(m.nom, ' ', m.prenom) AS nom_complet,
            m.telephone,
            m.email,
            m.statut,
            cm.nom_categorie,
            cm.cotisation_mensuelle
        FROM membres m
        INNER JOIN categories_membres cm ON m.categorie_id = cm.id
        WHERE m.code_membre = ? AND m.statut = 'actif'
    ");
    
    $stmt->execute([$code_membre]);
    $membre = $stmt->fetch();
    
    if ($membre) {
        echo json_encode([
            'success' => true,
            'nom_complet' => $membre['nom_complet'],
            'nom_categorie' => $membre['nom_categorie'],
            'telephone' => $membre['telephone'],
            'email' => $membre['email'],
            'cotisation_mensuelle' => $membre['cotisation_mensuelle']
        ]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Membre non trouvé ou inactif']);
    }
    
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Erreur de base de données']);
}
?>