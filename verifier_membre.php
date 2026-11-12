<?php
require_once 'config_database.php';

header('Content-Type: application/json');

if (!isset($_GET['code'])) {
    echo json_encode(['success' => false, 'message' => 'Code membre non fourni']);
    exit;
}

$code_membre = securiser($_GET['code']);

try {
    $stmt = $pdo->prepare("
        SELECT 
            m.id,
            m.code_membre,
            m.nom,
            m.prenom,
            m.telephone,
            m.email,
            cm.id as categorie_id,
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
            'membre' => [
                'id' => $membre['id'],
                'code_membre' => $membre['code_membre'],
                'nom' => $membre['nom'],
                'prenom' => $membre['prenom'],
                'telephone' => $membre['telephone'],
                'email' => $membre['email'],
                'categorie_id' => $membre['categorie_id'],
                'nom_categorie' => $membre['nom_categorie'],
                'cotisation_mensuelle' => floatval($membre['cotisation_mensuelle'])
            ]
        ]);
    } else {
        echo json_encode([
            'success' => false,
            'message' => 'Membre non trouvé ou inactif'
        ]);
    }
    
} catch (PDOException $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Erreur de base de données: ' . $e->getMessage()
    ]);
}
?>