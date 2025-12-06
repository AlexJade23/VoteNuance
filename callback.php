<?php
require_once 'config.php';
require_once 'functions.php';

// Vérifier la présence du code
if (!isset($_GET['code'])) {
    die('Erreur : code OAuth manquant');
}

// Vérifier le state (protection CSRF)
if (!isset($_GET['state']) || !isset($_SESSION['oauth_state']) || $_GET['state'] !== $_SESSION['oauth_state']) {
    die('Erreur de sécurité : state invalide');
}

// Récupérer les choix de l'utilisateur stockés en session
$consent = $_SESSION['oauth_consent'] ?? [];
$consentEmailHash = $consent['email_hash'] ?? false;
$displayName = $consent['display_name'] ?? null;
$provider = $consent['provider'] ?? null;

if (!$provider || !in_array($provider, ['google', 'microsoft'])) {
    die('Erreur : provider invalide');
}

$code = $_GET['code'];

try {
    // Échanger le code contre les tokens
    $tokens = exchangeCodeForTokens($code, $provider);
    
    // Décoder l'ID token pour obtenir les informations utilisateur
    $userInfo = decodeJWT($tokens['id_token']);
    
    // Récupérer le sub (identifiant unique)
    $ssoId = $userInfo['sub'] ?? null;
    
    if (!$ssoId) {
        throw new Exception('Impossible de récupérer l\'identifiant utilisateur');
    }
    
    // Récupérer l'email s'il est présent (et si consentement donné)
    $email = null;
    $emailHash = null;
    
    if ($consentEmailHash && isset($userInfo['email'])) {
        $email = strtolower(trim($userInfo['email']));
        // Créer le hash SHA-256 de l'email
        $emailHash = hash('sha256', $email);
    }
    
    // Vérifier si l'utilisateur existe déjà
    $user = findUserBySsoId($provider, $ssoId);
    
    if (!$user) {
        // Nouvel utilisateur : créer le compte
        $userId = createUser($provider, $ssoId, $emailHash, $displayName, $consentEmailHash);
        $user = ['id' => $userId];
    } else {
        // Utilisateur existant : mettre à jour la dernière connexion
        updateLastLogin($user['id']);
    }
    
    // Créer la session PHP
    $_SESSION['user_id'] = $user['id'];
    $_SESSION['user_provider'] = $provider;
    
    // Nettoyer les données temporaires OAuth
    unset($_SESSION['oauth_state']);
    unset($_SESSION['oauth_consent']);
    
    // Rediriger vers le dashboard
    header('Location: dashboard.php');
    exit;
    
} catch (Exception $e) {
    // En cas d'erreur, afficher un message et logger
    error_log('Erreur OAuth : ' . $e->getMessage());
    die('Erreur lors de la connexion : ' . htmlspecialchars($e->getMessage()));
}
