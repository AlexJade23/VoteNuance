<?php
/**
 * Configuration SSO Google + Microsoft
 * Authentification minimaliste avec respect de la vie privée
 */

// Charger les secrets depuis un fichier hors de la racine web
$secretFile = __DIR__ . '/../secret/sso.php';
if (file_exists($secretFile)) {
    require_once $secretFile;
}

// Configuration base de données (valeurs par défaut si secrets non chargés)
if (!defined('DB_HOST')) define('DB_HOST', 'localhost');
if (!defined('DB_NAME')) define('DB_NAME', 'votre_base');
if (!defined('DB_USER')) define('DB_USER', 'votre_user');
if (!defined('DB_PASS')) define('DB_PASS', 'votre_password');

// Configuration Google OAuth (valeurs par défaut si secrets non chargés)
if (!defined('GOOGLE_CLIENT_ID')) define('GOOGLE_CLIENT_ID', 'VOTRE_CLIENT_ID_GOOGLE.apps.googleusercontent.com');
if (!defined('GOOGLE_CLIENT_SECRET')) define('GOOGLE_CLIENT_SECRET', 'VOTRE_CLIENT_SECRET_GOOGLE');
if (!defined('GOOGLE_REDIRECT_URI')) define('GOOGLE_REDIRECT_URI', 'https://votresite.com/callback.php');

// Configuration Microsoft OAuth
define('MICROSOFT_CLIENT_ID', 'VOTRE_CLIENT_ID_MICROSOFT');
define('MICROSOFT_CLIENT_SECRET', 'VOTRE_CLIENT_SECRET_MICROSOFT');
define('MICROSOFT_REDIRECT_URI', 'https://votresite.com/callback.php');
define('MICROSOFT_TENANT', 'common'); // 'common' pour comptes personnels et professionnels

// URLs OAuth
define('GOOGLE_AUTH_URL', 'https://accounts.google.com/o/oauth2/v2/auth');
define('GOOGLE_TOKEN_URL', 'https://oauth2.googleapis.com/token');

define('MICROSOFT_AUTH_URL', 'https://login.microsoftonline.com/' . MICROSOFT_TENANT . '/oauth2/v2.0/authorize');
define('MICROSOFT_TOKEN_URL', 'https://login.microsoftonline.com/' . MICROSOFT_TENANT . '/oauth2/v2.0/token');

// Configuration session
ini_set('session.cookie_httponly', 1);
ini_set('session.cookie_secure', 1); // HTTPS uniquement
ini_set('session.cookie_samesite', 'Lax');

// Démarrer la session si pas déjà fait
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
