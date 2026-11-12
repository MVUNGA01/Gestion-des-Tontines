<?php
require_once 'config_database.php';

// Détruire la session
session_destroy();

// Rediriger vers la page de connexion
rediriger('login.php');
?>