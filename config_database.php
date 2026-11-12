<?php
// Configuration de la base de données
define('DB_HOST', 'localhost');
define('DB_NAME', 'loan_management_system');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_CHARSET', 'utf8mb4');

// Configuration de l'application
define('APP_NAME', 'Système de Gestion de Prêts');
define('APP_VERSION', '1.0.0');
define('SESSION_TIMEOUT', 3600); // 1 heure en secondes

// Chemins de l'application
define('BASE_URL', 'http://localhost/loan_management');
define('UPLOAD_PATH', __DIR__ . '/uploads/');

// Niveaux d'accès
$roles = [
    'admin' => 4,
    'gestionnaire' => 3,
    'caissier' => 2,
    'analyste' => 1
];

// Connexion à la base de données
try {
    $pdo = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET,
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );
} catch (PDOException $e) {
    error_log("Erreur de connexion: " . $e->getMessage());
    die("Erreur de connexion à la base de données");
}

// Démarrer la session
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
function mettre_a_jour_dette_emprunteur($code_membre, $pdo) {
    try {
        // Calculer la dette actuelle
        $sql_dette = "SELECT COALESCE(SUM(montant_restant), 0) as dette_actuelle 
                      FROM prets 
                      WHERE code_membre = ? 
                      AND statut IN ('actif', 'en_retard', 'approuve')";
        $stmt_dette = $pdo->prepare($sql_dette);
        $stmt_dette->execute([$code_membre]);
        $dette = $stmt_dette->fetchColumn();
        
        // Mettre à jour la table emprunteurs
        $sql_update = "UPDATE emprunteurs 
                       SET dette_actuelle = ?, derniere_modification = NOW() 
                       WHERE code_membre = ?";
        $stmt_update = $pdo->prepare($sql_update);
        $stmt_update->execute([$dette, $code_membre]);
        
        return true;
    } catch (PDOException $e) {
        error_log("Erreur mise à jour dette: " . $e->getMessage());
        return false;
    }
}
// Fonction de vérification d'authentification
function est_connecte() {
    if (!isset($_SESSION['user_id']) || !isset($_SESSION['user_role'])) {
        return false;
    }
    
    // Vérifier le timeout de session
    if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity'] > SESSION_TIMEOUT)) {
        session_destroy();
        return false;
    }
    
    $_SESSION['last_activity'] = time();
    return true;
}

// Fonction de vérification des permissions
function a_la_permission($role_requis) {
    global $roles;
    
    if (!est_connecte()) {
        return false;
    }
    
    $role_actuel = $_SESSION['user_role'];
    return isset($roles[$role_actuel]) && $roles[$role_actuel] >= $roles[$role_requis];
}

// Fonction de redirection
function rediriger($page) {
    header("Location: " . $page);
    exit;
}

// Fonction de sécurisation des données
function securiser($data) {
    if (is_array($data)) {
        return array_map('securiser', $data);
    }
    return htmlspecialchars(trim($data ?? ''), ENT_QUOTES, 'UTF-8');
}
?>