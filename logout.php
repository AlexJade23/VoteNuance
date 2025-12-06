<?php
require_once 'config.php';
require_once 'functions.php';

// Déconnecter l'utilisateur
logoutUser();

// Rediriger vers la page de connexion
header('Location: login.php?logged_out=1');
exit;
