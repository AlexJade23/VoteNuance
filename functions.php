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

// ============================================================================
// SCRUTINS
// ============================================================================

/**
 * Générer un code unique pour un scrutin
 */
function generateScrutinCode() {
    $chars = 'abcdefghijklmnopqrstuvwxyz0123456789';
    do {
        $code = '';
        for ($i = 0; $i < 8; $i++) {
            $code .= $chars[random_int(0, strlen($chars) - 1)];
        }
    } while (scrutinCodeExists($code));
    return $code;
}

/**
 * Vérifier si un code de scrutin existe déjà
 */
function scrutinCodeExists($code) {
    $pdo = getDbConnection();
    $stmt = $pdo->prepare('SELECT 1 FROM scrutins WHERE code = ?');
    $stmt->execute([$code]);
    return $stmt->fetch() !== false;
}

/**
 * Créer un scrutin
 */
function createScrutin($data) {
    $pdo = getDbConnection();
    $stmt = $pdo->prepare('
        INSERT INTO scrutins (code, titre, resume, notice, image_url, debut_at, fin_at,
            nb_participants_attendus, nb_gagnants, affiche_resultats, est_public, ordre_mentions, owner_id)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ');
    $stmt->execute([
        $data['code'],
        $data['titre'],
        $data['resume'],
        $data['notice'],
        $data['image_url'] ?? null,
        $data['debut_at'],
        $data['fin_at'],
        $data['nb_participants_attendus'],
        $data['nb_gagnants'],
        $data['affiche_resultats'],
        $data['est_public'],
        $data['ordre_mentions'] ?? 0,
        $data['owner_id']
    ]);
    return $pdo->lastInsertId();
}

/**
 * Récupérer un scrutin par son code
 */
function getScrutinByCode($code) {
    $pdo = getDbConnection();
    $stmt = $pdo->prepare('SELECT * FROM scrutins WHERE code = ?');
    $stmt->execute([$code]);
    return $stmt->fetch();
}

/**
 * Récupérer un scrutin par son ID
 */
function getScrutinById($id) {
    $pdo = getDbConnection();
    $stmt = $pdo->prepare('SELECT * FROM scrutins WHERE id = ?');
    $stmt->execute([$id]);
    return $stmt->fetch();
}

/**
 * Récupérer les scrutins d'un utilisateur
 */
function getScrutinsByOwner($ownerId) {
    $pdo = getDbConnection();
    $stmt = $pdo->prepare('
        SELECT s.*,
            (SELECT COUNT(*) FROM questions q WHERE q.scrutin_id = s.id) as nb_questions,
            (SELECT COUNT(DISTINCT ballot_hash) FROM bulletins b WHERE b.scrutin_id = s.id AND b.est_test = 0) as nb_votes
        FROM scrutins s
        WHERE s.owner_id = ?
        ORDER BY s.created_at DESC
    ');
    $stmt->execute([$ownerId]);
    return $stmt->fetchAll();
}

/**
 * Mettre à jour un scrutin
 */
function updateScrutin($id, $data) {
    $pdo = getDbConnection();
    $stmt = $pdo->prepare('
        UPDATE scrutins SET
            titre = ?, resume = ?, notice = ?, image_url = ?, debut_at = ?, fin_at = ?,
            nb_participants_attendus = ?, nb_gagnants = ?, affiche_resultats = ?, est_public = ?, ordre_mentions = ?
        WHERE id = ?
    ');
    $stmt->execute([
        $data['titre'],
        $data['resume'],
        $data['notice'],
        $data['image_url'] ?? null,
        $data['debut_at'],
        $data['fin_at'],
        $data['nb_participants_attendus'],
        $data['nb_gagnants'],
        $data['affiche_resultats'],
        $data['est_public'],
        $data['ordre_mentions'] ?? 0,
        $id
    ]);
}

/**
 * Supprimer un scrutin
 */
function deleteScrutin($id) {
    $pdo = getDbConnection();
    $stmt = $pdo->prepare('DELETE FROM scrutins WHERE id = ?');
    $stmt->execute([$id]);
}

/**
 * Archiver un scrutin
 */
function archiveScrutin($id) {
    $pdo = getDbConnection();
    $stmt = $pdo->prepare('UPDATE scrutins SET est_archive = 1 WHERE id = ?');
    $stmt->execute([$id]);
}

/**
 * Desarchiver un scrutin
 */
function unarchiveScrutin($id) {
    $pdo = getDbConnection();
    $stmt = $pdo->prepare('UPDATE scrutins SET est_archive = 0 WHERE id = ?');
    $stmt->execute([$id]);
}

// ============================================================================
// QUESTIONS
// ============================================================================

/**
 * Créer une question
 */
function createQuestion($data) {
    $pdo = getDbConnection();
    $stmt = $pdo->prepare('
        INSERT INTO questions (scrutin_id, echelle_id, type_question, titre, question, image_url, lot, ordre, est_obligatoire)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
    ');
    $stmt->execute([
        $data['scrutin_id'],
        $data['echelle_id'],
        $data['type_question'],
        $data['titre'],
        $data['question'],
        $data['image_url'] ?? null,
        $data['lot'],
        $data['ordre'],
        $data['est_obligatoire']
    ]);
    return $pdo->lastInsertId();
}

/**
 * Récupérer les questions d'un scrutin
 */
function getQuestionsByScrutin($scrutinId) {
    $pdo = getDbConnection();
    $stmt = $pdo->prepare('
        SELECT q.*, e.libelle as echelle_libelle
        FROM questions q
        LEFT JOIN echelles e ON e.id = q.echelle_id
        WHERE q.scrutin_id = ?
        ORDER BY q.ordre
    ');
    $stmt->execute([$scrutinId]);
    return $stmt->fetchAll();
}

/**
 * Supprimer les questions d'un scrutin
 */
function deleteQuestionsByScrutin($scrutinId) {
    $pdo = getDbConnection();
    $stmt = $pdo->prepare('DELETE FROM questions WHERE scrutin_id = ?');
    $stmt->execute([$scrutinId]);
}

// ============================================================================
// RÉPONSES POSSIBLES (pour QCM)
// ============================================================================

/**
 * Créer une réponse possible
 */
function createReponsePossible($questionId, $libelle, $ordre) {
    $pdo = getDbConnection();
    $stmt = $pdo->prepare('
        INSERT INTO reponses_possibles (question_id, libelle, ordre)
        VALUES (?, ?, ?)
    ');
    $stmt->execute([$questionId, $libelle, $ordre]);
    return $pdo->lastInsertId();
}

/**
 * Récupérer les réponses possibles d'une question
 */
function getReponsesPossibles($questionId) {
    $pdo = getDbConnection();
    $stmt = $pdo->prepare('
        SELECT * FROM reponses_possibles
        WHERE question_id = ?
        ORDER BY ordre
    ');
    $stmt->execute([$questionId]);
    return $stmt->fetchAll();
}

// ============================================================================
// MENTIONS (échelle de vote)
// ============================================================================

/**
 * Récupérer les mentions d'une échelle
 */
function getMentionsByEchelle($echelleId = 1) {
    $pdo = getDbConnection();
    $stmt = $pdo->prepare('
        SELECT * FROM mentions
        WHERE echelle_id = ?
        ORDER BY rang
    ');
    $stmt->execute([$echelleId]);
    return $stmt->fetchAll();
}

// ============================================================================
// NAVIGATION
// ============================================================================

/**
 * Afficher le menu de navigation unifié
 */
function renderNavigation($activePage = '') {
    $isLoggedIn = isLoggedIn();
    $user = $isLoggedIn ? getCurrentUser() : null;

    $html = '<nav class="main-nav">';
    $html .= '<div class="nav-container">';
    $html .= '<a href="/" class="nav-brand">Vote Nuance</a>';
    $html .= '<div class="nav-links">';

    if ($isLoggedIn && $user) {
        $html .= '<a href="/mes-scrutins.php" class="nav-link' . ($activePage === 'mes-scrutins' ? ' active' : '') . '">Mes scrutins</a>';
        $html .= '<a href="/scrutin-create.php" class="nav-link' . ($activePage === 'create' ? ' active' : '') . '">Nouveau</a>';
        $html .= '<a href="/dashboard.php" class="nav-link' . ($activePage === 'dashboard' ? ' active' : '') . '">Mon compte</a>';
        $html .= '<a href="/logout.php" class="nav-link nav-logout">Deconnexion</a>';
    } else {
        $html .= '<a href="/login.php" class="nav-link">Connexion</a>';
    }

    $html .= '</div>';
    $html .= '</div>';
    $html .= '</nav>';

    return $html;
}

/**
 * CSS pour le menu de navigation
 */
function getNavigationCSS() {
    return '
    .main-nav {
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        padding: 0 20px;
        position: sticky;
        top: 0;
        z-index: 1000;
        box-shadow: 0 2px 10px rgba(0,0,0,0.1);
    }
    .nav-container {
        max-width: 1200px;
        margin: 0 auto;
        display: flex;
        justify-content: space-between;
        align-items: center;
        height: 56px;
    }
    .nav-brand {
        color: white;
        font-size: 18px;
        font-weight: 700;
        text-decoration: none;
    }
    .nav-links {
        display: flex;
        gap: 5px;
    }
    .nav-link {
        color: rgba(255,255,255,0.9);
        text-decoration: none;
        padding: 8px 16px;
        border-radius: 6px;
        font-size: 14px;
        font-weight: 500;
        transition: all 0.2s;
    }
    .nav-link:hover {
        background: rgba(255,255,255,0.15);
        color: white;
    }
    .nav-link.active {
        background: rgba(255,255,255,0.2);
        color: white;
    }
    .nav-logout {
        margin-left: 10px;
        border: 1px solid rgba(255,255,255,0.3);
    }
    .nav-logout:hover {
        background: rgba(255,255,255,0.25);
    }
    @media (max-width: 600px) {
        .nav-container { height: auto; flex-wrap: wrap; padding: 10px 0; }
        .nav-brand { width: 100%; text-align: center; margin-bottom: 10px; }
        .nav-links { width: 100%; justify-content: center; flex-wrap: wrap; }
        .nav-link { padding: 6px 12px; font-size: 13px; }
    }
    ';
}

// ============================================================================
// JETONS (gestion des droits de vote pour scrutins prives)
// ============================================================================

/**
 * Verifier si un jeton est valide pour un scrutin
 * @return array|false Retourne le jeton si valide, false sinon
 */
function verifyToken($scrutinId, $tokenCode) {
    if (empty($tokenCode)) {
        return false;
    }
    $pdo = getDbConnection();
    $stmt = $pdo->prepare('
        SELECT * FROM jetons
        WHERE scrutin_id = ? AND code = ?
    ');
    $stmt->execute([$scrutinId, $tokenCode]);
    return $stmt->fetch();
}

/**
 * Verifier si un jeton est disponible (existe et non utilise)
 * @return array ['valid' => bool, 'error' => string|null, 'token' => array|null]
 */
function checkTokenAvailability($scrutinId, $tokenCode) {
    $token = verifyToken($scrutinId, $tokenCode);

    if (!$token) {
        return [
            'valid' => false,
            'error' => 'Jeton invalide ou inconnu.',
            'token' => null
        ];
    }

    if ($token['est_utilise']) {
        return [
            'valid' => false,
            'error' => 'Ce jeton a deja ete utilise pour voter.',
            'token' => $token
        ];
    }

    return [
        'valid' => true,
        'error' => null,
        'token' => $token
    ];
}

/**
 * Marquer un jeton comme utilise
 */
function markTokenAsUsed($tokenId) {
    $pdo = getDbConnection();
    $stmt = $pdo->prepare('
        UPDATE jetons
        SET est_utilise = 1, utilise_at = NOW()
        WHERE id = ? AND est_utilise = 0
    ');
    $stmt->execute([$tokenId]);
    return $stmt->rowCount() > 0;
}

/**
 * Generer des jetons pour un scrutin
 * @return array Liste des codes de jetons generes
 */
function generateTokens($scrutinId, $count = 1) {
    $pdo = getDbConnection();
    $tokens = [];
    $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'; // Sans I, O, 0, 1 pour eviter confusion

    $stmt = $pdo->prepare('
        INSERT INTO jetons (scrutin_id, code, est_organisateur, est_utilise)
        VALUES (?, ?, 0, 0)
    ');

    for ($i = 0; $i < $count; $i++) {
        // Generer un code unique de 8 caracteres
        do {
            $code = '';
            for ($j = 0; $j < 8; $j++) {
                $code .= $chars[random_int(0, strlen($chars) - 1)];
            }
            // Verifier unicite
            $exists = verifyToken($scrutinId, $code);
        } while ($exists);

        $stmt->execute([$scrutinId, $code]);
        $tokens[] = $code;
    }

    return $tokens;
}

/**
 * Recuperer tous les jetons d'un scrutin avec leur statut
 */
function getTokensByScrutin($scrutinId) {
    $pdo = getDbConnection();
    $stmt = $pdo->prepare('
        SELECT id, code, est_utilise, utilise_at, created_at
        FROM jetons
        WHERE scrutin_id = ? AND est_organisateur = 0
        ORDER BY created_at DESC
    ');
    $stmt->execute([$scrutinId]);
    return $stmt->fetchAll();
}

/**
 * Compter les jetons d'un scrutin
 * @return array ['total' => int, 'utilises' => int, 'disponibles' => int]
 */
function countTokens($scrutinId) {
    $pdo = getDbConnection();
    $stmt = $pdo->prepare('
        SELECT
            COUNT(*) as total,
            SUM(CASE WHEN est_utilise = 1 THEN 1 ELSE 0 END) as utilises
        FROM jetons
        WHERE scrutin_id = ? AND est_organisateur = 0
    ');
    $stmt->execute([$scrutinId]);
    $row = $stmt->fetch();

    return [
        'total' => (int)$row['total'],
        'utilises' => (int)$row['utilises'],
        'disponibles' => (int)$row['total'] - (int)$row['utilises']
    ];
}

/**
 * Revoquer (supprimer) un jeton non utilise
 */
function revokeToken($tokenId, $scrutinId) {
    $pdo = getDbConnection();
    $stmt = $pdo->prepare('
        DELETE FROM jetons
        WHERE id = ? AND scrutin_id = ? AND est_utilise = 0 AND est_organisateur = 0
    ');
    $stmt->execute([$tokenId, $scrutinId]);
    return $stmt->rowCount() > 0;
}
