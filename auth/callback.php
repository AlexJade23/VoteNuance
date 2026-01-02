<?php
require_once '../config.php';
require_once '../functions.php';

// Si deja connecte, rediriger vers dashboard
if (isLoggedIn()) {
    header('Location: ../dashboard.php');
    exit;
}

// Recuperer le token depuis l'URL
$token = $_GET['token'] ?? '';

if (empty($token)) {
    header('Location: ../login-magiclink.php');
    exit;
}

// Verifier le token aupres de l'API
$userInfo = authGetUserInfo($token);

if (!$userInfo) {
    // Token invalide ou expire
    header('Location: ../login-magiclink.php?error=token_invalid');
    exit;
}

// Recuperer les preferences depuis la session (si disponibles)
$consent = $_SESSION['magiclink_consent'] ?? [];
$email = $_SESSION['magiclink_email'] ?? '';
$emailHash = null;
if (($consent['email_hash'] ?? false) && $email) {
    $emailHash = hash('sha256', strtolower(trim($email)));
}
$displayName = $consent['display_name'] ?? null;

// Creer ou retrouver l'utilisateur
$user = findOrCreateMagicLinkUser(
    $userInfo,
    $emailHash,
    $displayName,
    $consent['email_hash'] ?? false
);

// Creer la session PHP
$_SESSION['user_id'] = $user['id'];
$_SESSION['user_provider'] = 'magiclink';
$_SESSION['magiclink_jwt'] = $token;

// Nettoyer les donnees temporaires
unset($_SESSION['magiclink_email']);
unset($_SESSION['magiclink_consent']);
unset($_SESSION['magiclink_temp_token']);

// Rediriger vers le dashboard
header('Location: ../dashboard.php');
exit;
