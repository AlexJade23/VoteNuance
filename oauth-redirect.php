<?php
require_once 'config.php';
require_once 'functions.php';

// Vérifier le token CSRF
if (!isset($_POST['csrf_token']) || !verifyCsrfToken($_POST['csrf_token'])) {
    die('Erreur de sécurité : token CSRF invalide');
}

// Récupérer le provider
$provider = $_POST['provider'] ?? '';

if (!in_array($provider, ['google', 'microsoft', 'mastodon'])) {
    die('Provider invalide');
}

if ($provider === 'mastodon' && !mastodonIsConfigured()) {
    header('Location: auth/error.php?reason=mastodon_unavailable');
    exit;
}

// Récupérer les choix de l'utilisateur
$consentEmailHash = isset($_POST['consent_email_hash']) && $_POST['consent_email_hash'] === '1';

// Mastodon ne transmet pas d'email : le consentement est sans objet
if ($provider === 'mastodon') {
    $consentEmailHash = false;
}
$displayName = trim($_POST['display_name'] ?? '');

// Valider le pseudo (max 50 caractères, alphanumériques et quelques caractères spéciaux)
if ($displayName !== '') {
    $displayName = substr($displayName, 0, 50);
    // Nettoyer le pseudo (supprimer les caractères dangereux)
    $displayName = htmlspecialchars($displayName, ENT_QUOTES, 'UTF-8');
}

// Stocker les choix en session pour les récupérer au callback
$_SESSION['oauth_consent'] = [
    'email_hash' => $consentEmailHash,
    'display_name' => $displayName,
    'provider' => $provider
];

// Générer un state pour CSRF protection
$state = bin2hex(random_bytes(16));
$_SESSION['oauth_state'] = $state;

// Déterminer le scope selon le consentement
if ($consentEmailHash) {
    // L'utilisateur consent : on demande l'email
    $scope = 'openid email';
} else {
    // L'utilisateur ne consent pas : on ne demande que openid
    $scope = 'openid';
}

// Construire l'URL OAuth selon le provider
if ($provider === 'google') {
    $authUrl = GOOGLE_AUTH_URL . '?' . http_build_query([
        'client_id' => GOOGLE_CLIENT_ID,
        'redirect_uri' => GOOGLE_REDIRECT_URI,
        'response_type' => 'code',
        'scope' => $scope,
        'state' => $state,
        'access_type' => 'online',
        'prompt' => 'select_account' // Permet de choisir le compte à chaque fois
    ]);
} elseif ($provider === 'microsoft') {
    $authUrl = MICROSOFT_AUTH_URL . '?' . http_build_query([
        'client_id' => MICROSOFT_CLIENT_ID,
        'redirect_uri' => MICROSOFT_REDIRECT_URI,
        'response_type' => 'code',
        'scope' => $scope,
        'state' => $state,
        'response_mode' => 'query',
        'prompt' => 'select_account'
    ]);
} elseif ($provider === 'mastodon') {
    // PKCE (S256), pris en charge par Mastodon depuis la version 4.3
    $pkce = mastodonGeneratePkce();
    $_SESSION['oauth_pkce_verifier'] = $pkce['verifier'];

    $authUrl = mastodonInstanceUrl() . '/oauth/authorize?' . http_build_query([
        'response_type' => 'code',
        'client_id' => MASTODON_CLIENT_ID,
        'redirect_uri' => MASTODON_REDIRECT_URI,
        'scope' => 'read:accounts',
        'state' => $state,
        'code_challenge' => $pkce['challenge'],
        'code_challenge_method' => 'S256'
    ]);
}

// Rediriger vers la page OAuth du provider
header('Location: ' . $authUrl);
exit;
