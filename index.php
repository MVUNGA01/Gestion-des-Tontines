<?php
// index.php
require_once 'config_database.php';

// Redirection vers le dashboard si déjà connecté
if (est_connecte()) { // CHANGEMENT ICI: isLoggedIn() -> est_connecte()
    header("Location: dashboard.php");
    exit();
} else {
    header("Location: login.php");
    exit();
}
?>