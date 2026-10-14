<?php
/**
 * Retour de l'autorisation Mastodon (issue #17)
 * Démarrage du flux : oauth-redirect.php avec provider=mastodon
 */
require_once 'config.php';
require_once 'functions.php';

/**
 * Abandonner la connexion : nettoyer la session OAuth et afficher l'erreur
 */
function mastodonFail($reason, $logMessage = null) {
    if ($logMessage !== null) {
        error_log($logMessage);
    }
    unset($_SESSION['oauth_state'], $_SESSION['oauth_pkce_verifier'], $_SESSION['oauth_consent']);
    header('Location: auth/error.php?reason=' . urlencode($reason));
    exit;
}

$consent = $_SESSION['oauth_consent'] ?? [];
$expectedState = $_SESSION['oauth_state'] ?? null;
$codeVerifier = $_SESSION['oauth_pkce_verifier'] ?? null;

// Vérifier le state (protection CSRF) avant toute autre chose
if (!is_string($_GET['state'] ?? null) || !is_string($expectedState) || !hash_equals($expectedState, $_GET['state'])
    || ($consent['provider'] ?? null) !== 'mastodon' || !is_string($codeVerifier)) {
    mastodonFail('state_invalid', 'Mastodon callback : state invalide ou session expiree');
}

// State et verifier sont à usage unique
unset($_SESSION['oauth_state'], $_SESSION['oauth_pkce_verifier']);

// L'utilisateur a refusé l'autorisation, ou Mastodon a renvoyé une erreur
if (isset($_GET['error'])) {
    $error = is_string($_GET['error']) ? preg_replace('/[^a-z_]/', '', $_GET['error']) : '';
    mastodonFail($error === 'access_denied' ? 'mastodon_denied' : 'mastodon_error', "Mastodon callback : erreur OAuth $error");
}

$code = $_GET['code'] ?? '';
if (!is_string($code) || $code === '') {
    mastodonFail('mastodon_error', 'Mastodon callback : code absent');
}

if (!mastodonIsConfigured()) {
    mastodonFail('mastodon_unavailable', 'Mastodon callback : configuration absente');
}

try {
    $accessToken = mastodonExchangeCode($code, $codeVerifier);

    try {
        $accountId = mastodonVerifyCredentials($accessToken);
    } finally {
        // Le jeton ne sert qu'à vérifier l'identité : on le révoque aussitôt
        mastodonRevokeToken($accessToken);
        unset($accessToken);
    }
} catch (MastodonAuthException $e) {
    mastodonFail($e->reason, $e->getMessage());
}

$ssoId = mastodonSsoId($accountId);

try {
    // Aucun rapprochement avec un autre compte : une identité Mastodon = un utilisateur
    $user = findUserBySsoId('mastodon', $ssoId);

    if (!$user) {
        $displayName = $consent['display_name'] ?? null;
        $userId = createUser('mastodon', $ssoId, null, $displayName !== '' ? $displayName : null, false);
        $user = ['id' => $userId];
    } else {
        updateLastLogin($user['id']);
    }
} catch (PDOException $e) {
    mastodonFail('unknown', 'Mastodon callback : erreur base de donnees ' . $e->getCode());
}

// Nouvel identifiant de session à la connexion (fixation de session)
session_regenerate_id(true);

$_SESSION['user_id'] = $user['id'];
$_SESSION['user_provider'] = 'mastodon';

unset($_SESSION['oauth_consent']);

header('Location: dashboard.php');
exit;
