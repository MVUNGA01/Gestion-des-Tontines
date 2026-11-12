<?php
require_once 'config_database.php';

// Vérifier l'authentification et les permissions admin
if (!est_connecte() || !a_la_permission('admin')) {
    rediriger('dashboard.php');
}

// Vérifier que c'est bien une requête POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    rediriger('audit_log.php');
}

try {
    // Supprimer les logs de plus de 6 mois
    $sql = "DELETE FROM audit_log WHERE changed_at < DATE_SUB(NOW(), INTERVAL 6 MONTH)";
    $stmt = $pdo->prepare($sql);
    $stmt->execute();
    
    $rows_deleted = $stmt->rowCount();
    
    // Journaliser l'action de nettoyage
    $sql_insert = "INSERT INTO audit_log (table_name, action, record_id, user_id, ip_address, user_agent) 
                   VALUES (?, ?, ?, ?, ?, ?)";
    $stmt_insert = $pdo->prepare($sql_insert);
    $stmt_insert->execute([
        'audit_log',
        'DELETE',
        'batch_cleanup',
        $_SESSION['user_id'] ?? 'system',
        $_SERVER['REMOTE_ADDR'],
        $_SERVER['HTTP_USER_AGENT'] ?? ''
    ]);
    
    // Message de succès
    $_SESSION['success_message'] = "Nettoyage réussi : $rows_deleted anciens logs d'audit ont été supprimés.";
    
} catch (PDOException $e) {
    $_SESSION['error_message'] = "Erreur lors du nettoyage : " . $e->getMessage();
}

// Rediriger vers la page des logs d'audit
rediriger('audit_log.php');