<?php
require_once 'config_database.php';

// Vérifier l'authentification et les permissions admin
if (!est_connecte() || !a_la_permission('admin')) {
    rediriger('dashboard.php');
}

// Variables de filtrage (les mêmes que dans audit_log.php)
$filtre_table = isset($_GET['table']) ? securiser($_GET['table']) : '';
$filtre_action = isset($_GET['action']) ? securiser($_GET['action']) : '';
$filtre_user = isset($_GET['user']) ? securiser($_GET['user']) : '';
$filtre_date_debut = isset($_GET['date_debut']) ? securiser($_GET['date_debut']) : '';
$filtre_date_fin = isset($_GET['date_fin']) ? securiser($_GET['date_fin']) : '';

// Construction de la requête avec filtres
$where_conditions = [];
$params = [];
$types = '';

if (!empty($filtre_table)) {
    $where_conditions[] = "table_name = ?";
    $params[] = $filtre_table;
    $types .= 's';
}

if (!empty($filtre_action)) {
    $where_conditions[] = "action = ?";
    $params[] = $filtre_action;
    $types .= 's';
}

if (!empty($filtre_user)) {
    $where_conditions[] = "user_id LIKE ?";
    $params[] = "%$filtre_user%";
    $types .= 's';
}

if (!empty($filtre_date_debut)) {
    $where_conditions[] = "DATE(changed_at) >= ?";
    $params[] = $filtre_date_debut;
    $types .= 's';
}

if (!empty($filtre_date_fin)) {
    $where_conditions[] = "DATE(changed_at) <= ?";
    $params[] = $filtre_date_fin;
    $types .= 's';
}

$where_sql = '';
if (!empty($where_conditions)) {
    $where_sql = 'WHERE ' . implode(' AND ', $where_conditions);
}

// Récupérer tous les logs d'audit selon les filtres
$sql = "SELECT * FROM audit_log $where_sql ORDER BY changed_at DESC";
$stmt = $pdo->prepare($sql);

if (!empty($params)) {
    $stmt->execute($params);
} else {
    $stmt->execute();
}

$logs_audit = $stmt->fetchAll();

// Générer le nom du fichier CSV
$date_export = date('Y-m-d_H-i-s');
$filename = "audit_logs_export_{$date_export}.csv";

// En-têtes pour le téléchargement CSV
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');

// Créer le fichier CSV en sortie
$output = fopen('php://output', 'w');

// En-têtes du CSV
fputcsv($output, [
    'ID',
    'Date/Heure',
    'Table',
    'Action',
    'ID Enregistrement',
    'Utilisateur',
    'Adresse IP',
    'Anciennes Valeurs',
    'Nouvelles Valeurs',
    'User Agent'
], ';');

// Données du CSV
foreach ($logs_audit as $log) {
    // Formater les valeurs JSON pour une meilleure lisibilité
    $old_values = $log['old_values'] ? json_encode(json_decode($log['old_values']), JSON_PRETTY_PRINT) : '';
    $new_values = $log['new_values'] ? json_encode(json_decode($log['new_values']), JSON_PRETTY_PRINT) : '';
    
    // Nettoyer les sauts de ligne pour le CSV
    $old_values = str_replace(["\r\n", "\r", "\n"], " | ", $old_values);
    $new_values = str_replace(["\r\n", "\r", "\n"], " | ", $new_values);
    $user_agent = str_replace(["\r\n", "\r", "\n"], " | ", $log['user_agent'] ?? '');
    
    fputcsv($output, [
        $log['id'],
        $log['changed_at'],
        $log['table_name'],
        $log['action'],
        $log['record_id'],
        $log['user_id'] ?? 'Système',
        $log['ip_address'],
        $old_values,
        $new_values,
        $user_agent
    ], ';');
}

fclose($output);
exit;