<?php
/**
 * Fonctions utilitaires pour le SSO
 */

require_once 'config.php';

/**
 * Connexion à la base de données
 */
function getDbConnection() {
    static $pdo = null;
    
    if ($pdo === null) {
        try {
            $pdo = new PDO(
                'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
                DB_USER,
                DB_PASS,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false
                ]
            );
        } catch (PDOException $e) {
            die('Erreur de connexion à la base de données : ' . $e->getMessage());
        }
    }
    
    return $pdo;
}

/**
 * Décoder un JWT (JSON Web Token) de manière native
 * Sans vérification de signature (car on est en HTTPS et on fait confiance à Google/Microsoft)
 */
function decodeJWT($jwt) {
    $parts = explode('.', $jwt);
    
    if (count($parts) !== 3) {
        throw new Exception('JWT invalide');
    }
    
    // Décoder la partie payload (index 1)
    $payload = base64_decode(strtr($parts[1], '-_', '+/'));
    $data = json_decode($payload, true);
    
    if (!$data) {
        throw new Exception('Impossible de décoder le JWT');
    }
    
    return $data;
}

/**
 * Trouver un utilisateur par son SSO ID
 */
function findUserBySsoId($provider, $ssoId) {
    $pdo = getDbConnection();
    $stmt = $pdo->prepare('SELECT * FROM users WHERE sso_provider = ? AND sso_id = ?');
    $stmt->execute([$provider, $ssoId]);
    return $stmt->fetch();
}

/**
 * Créer un nouvel utilisateur
 */
function createUser($provider, $ssoId, $emailHash = null, $displayName = null, $emailConsent = false) {
    $pdo = getDbConnection();
    
    $stmt = $pdo->prepare('
        INSERT INTO users (sso_provider, sso_id, email_hash, display_name, email_hash_consent)
        VALUES (?, ?, ?, ?, ?)
    ');
    
    $stmt->execute([
        $provider,
        $ssoId,
        $emailHash,
        $displayName,
        $emailConsent ? 1 : 0
    ]);
    
    return $pdo->lastInsertId();
}

/**
 * Mettre à jour la date de dernière connexion
 */
function updateLastLogin($userId) {
    $pdo = getDbConnection();
    $stmt = $pdo->prepare('UPDATE users SET last_login = NOW() WHERE id = ?');
    $stmt->execute([$userId]);
}

/**
 * Vérifier si l'utilisateur est connecté
 */
function isLoggedIn() {
    return isset($_SESSION['user_id']) && isset($_SESSION['user_provider']);
}

/**
 * Obtenir l'utilisateur connecté
 */
function getCurrentUser() {
    if (!isLoggedIn()) {
        return null;
    }
    
    $pdo = getDbConnection();
    $stmt = $pdo->prepare('SELECT * FROM users WHERE id = ?');
    $stmt->execute([$_SESSION['user_id']]);
    return $stmt->fetch();
}

/**
 * Déconnecter l'utilisateur
 */
function logoutUser() {
    $_SESSION = [];
    
    if (isset($_COOKIE[session_name()])) {
        setcookie(session_name(), '', time() - 3600, '/');
    }
    
    session_destroy();
}

/**
 * Générer un token CSRF
 */
function generateCsrfToken() {
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Vérifier un token CSRF
 */
function verifyCsrfToken($token) {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

/**
 * Échanger le code OAuth contre les tokens
 */
function exchangeCodeForTokens($code, $provider) {
    if ($provider === 'google') {
        $tokenUrl = GOOGLE_TOKEN_URL;
        $params = [
            'code' => $code,
            'client_id' => GOOGLE_CLIENT_ID,
            'client_secret' => GOOGLE_CLIENT_SECRET,
            'redirect_uri' => GOOGLE_REDIRECT_URI,
            'grant_type' => 'authorization_code'
        ];
    } elseif ($provider === 'microsoft') {
        $tokenUrl = MICROSOFT_TOKEN_URL;
        $params = [
            'code' => $code,
            'client_id' => MICROSOFT_CLIENT_ID,
            'client_secret' => MICROSOFT_CLIENT_SECRET,
            'redirect_uri' => MICROSOFT_REDIRECT_URI,
            'grant_type' => 'authorization_code',
            'scope' => 'openid'
        ];
    } else {
        throw new Exception('Provider inconnu');
    }
    
    // Appel cURL
    $ch = curl_init($tokenUrl);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Accept: application/json']);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode !== 200) {
        throw new Exception('Erreur lors de l\'échange du code : ' . $response);
    }
    
    $data = json_decode($response, true);
    
    if (!isset($data['id_token'])) {
        throw new Exception('Pas d\'ID token reçu');
    }
    
    return $data;
}

/**
 * Supprimer le hash email d'un utilisateur
 */
function deleteEmailHash($userId) {
    $pdo = getDbConnection();
    $stmt = $pdo->prepare('UPDATE users SET email_hash = NULL, email_hash_consent = 0 WHERE id = ?');
    $stmt->execute([$userId]);
}

/**
 * Mettre à jour le pseudo d'un utilisateur
 */
function updateDisplayName($userId, $displayName) {
    $pdo = getDbConnection();
    $stmt = $pdo->prepare('UPDATE users SET display_name = ? WHERE id = ?');
    $stmt->execute([$displayName, $userId]);
}

/**
 * Supprimer un utilisateur
 */
function deleteUser($userId) {
    $pdo = getDbConnection();
    $stmt = $pdo->prepare('DELETE FROM users WHERE id = ?');
    $stmt->execute([$userId]);
}
