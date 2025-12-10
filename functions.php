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
 * Mélanger aléatoirement les questions Vote Nuancé (type=0) appartenant à un même lot
 * Les questions avec lot=0 gardent leur position d'origine
 * Les questions "Préféré du lot" (type=3) ne sont JAMAIS mélangées
 */
function shuffleQuestionsInLots($questions) {
    if (empty($questions)) {
        return $questions;
    }

    // Identifier les lots présents (lot > 0) avec uniquement les questions Vote Nuancé (type=0)
    $lots = [];
    foreach ($questions as $idx => $q) {
        $lot = intval($q['lot'] ?? 0);
        $type = intval($q['type_question'] ?? 0);
        // Seules les questions Vote Nuancé (type=0) avec lot > 0 sont mélangées
        if ($lot > 0 && $type === 0) {
            if (!isset($lots[$lot])) {
                $lots[$lot] = [];
            }
            $lots[$lot][] = $idx;
        }
    }

    // Si aucun lot, retourner tel quel
    if (empty($lots)) {
        return $questions;
    }

    // Pour chaque lot, mélanger les indices
    foreach ($lots as $lotNum => $indices) {
        if (count($indices) > 1) {
            // Extraire les questions de ce lot
            $lotQuestions = [];
            foreach ($indices as $idx) {
                $lotQuestions[] = $questions[$idx];
            }

            // Mélanger
            shuffle($lotQuestions);

            // Remettre en place
            foreach ($indices as $i => $idx) {
                $questions[$idx] = $lotQuestions[$i];
            }
        }
    }

    return $questions;
}

/**
 * Récupérer les titres des questions Vote Nuancé d'un lot donné
 * Utilisé pour générer les options d'une question "Préféré du lot"
 */
function getQuestionTitlesForLot($scrutinId, $lotNum) {
    $pdo = getDbConnection();
    $stmt = $pdo->prepare('
        SELECT id, titre
        FROM questions
        WHERE scrutin_id = ? AND lot = ? AND type_question = 0
        ORDER BY ordre
    ');
    $stmt->execute([$scrutinId, $lotNum]);
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
    $html .= '<a href="https://decision-collective.fr/" class="nav-brand" target="_blank" title="Découvrir le concept">';
    $html .= '<img src="https://decision-collective.fr/wp-content/uploads/2021/12/logov7long.png" alt="Décision Collective" class="nav-logo">';
    $html .= '</a>';
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
        display: flex;
        align-items: center;
        text-decoration: none;
    }
    .nav-logo {
        height: 36px;
        width: auto;
        border-radius: 6px;
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
        .nav-brand { width: 100%; text-align: center; margin-bottom: 10px; justify-content: center; }
        .nav-links { width: 100%; justify-content: center; flex-wrap: wrap; }
        .nav-link { padding: 6px 12px; font-size: 13px; }
    }
    .site-footer {
        margin-top: 40px;
        padding: 20px;
        text-align: center;
        font-size: 13px;
        color: #888;
        border-top: 1px solid #eee;
    }
    .site-footer a {
        color: #667eea;
        text-decoration: none;
    }
    .site-footer a:hover {
        text-decoration: underline;
    }
    .site-footer .donate-link {
        display: inline-block;
        margin-top: 10px;
        padding: 8px 16px;
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        color: white;
        border-radius: 20px;
        font-weight: 500;
    }
    .site-footer .donate-link:hover {
        opacity: 0.9;
        text-decoration: none;
    }
    ';
}

/**
 * Afficher le pied de page avec lien de don
 */
function renderFooter() {
    return '
    <footer style="margin-top: 40px; padding: 20px; text-align: center; font-size: 13px; color: #888; border-top: 1px solid #eee;">
        <div>
            <a href="https://decision-collective.fr/" target="_blank" style="color: #667eea; text-decoration: none;">Découvrir le concept</a> ·
            <a href="/my-data.php" style="color: #667eea; text-decoration: none;">Mes données</a>
        </div>
        <a href="https://buy.stripe.com/aEUeWy74mgRwc2Q8wB" target="_blank" style="display: inline-block; margin-top: 10px; padding: 8px 16px; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; border-radius: 20px; font-weight: 500; text-decoration: none;">
            Soutenir le projet
        </a>
    </footer>
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

// ============================================================================
// ACHATS STRIPE (paiement des jetons)
// ============================================================================

/**
 * Verifier si Stripe est correctement configure
 * @return bool
 */
function isStripeConfigured() {
    // Verifier que les cles ne sont pas les valeurs par defaut
    if (!defined('STRIPE_SECRET_KEY') || strpos(STRIPE_SECRET_KEY, 'VOTRE_CLE') !== false) {
        return false;
    }
    if (!defined('STRIPE_PUBLIC_KEY') || strpos(STRIPE_PUBLIC_KEY, 'VOTRE_CLE') !== false) {
        return false;
    }
    if (!defined('STRIPE_WEBHOOK_SECRET') || strpos(STRIPE_WEBHOOK_SECRET, 'VOTRE_SECRET') !== false) {
        return false;
    }
    return true;
}

/**
 * Creer un achat en attente de paiement
 * @return int ID de l'achat cree
 */
function createAchat($userId, $scrutinId, $nbJetons, $montantCents, $stripeSessionId) {
    $pdo = getDbConnection();
    $stmt = $pdo->prepare('
        INSERT INTO achats (user_id, scrutin_id, nb_jetons, montant_cents, stripe_session_id, status)
        VALUES (?, ?, ?, ?, ?, "pending")
    ');
    $stmt->execute([$userId, $scrutinId, $nbJetons, $montantCents, $stripeSessionId]);
    return $pdo->lastInsertId();
}

/**
 * Recuperer un achat par son ID de session Stripe
 */
function getAchatByStripeSession($stripeSessionId) {
    $pdo = getDbConnection();
    $stmt = $pdo->prepare('SELECT * FROM achats WHERE stripe_session_id = ?');
    $stmt->execute([$stripeSessionId]);
    return $stmt->fetch();
}

/**
 * Recuperer un achat par son ID
 */
function getAchatById($achatId) {
    $pdo = getDbConnection();
    $stmt = $pdo->prepare('SELECT * FROM achats WHERE id = ?');
    $stmt->execute([$achatId]);
    return $stmt->fetch();
}

/**
 * Marquer un achat comme paye et generer les jetons
 * @return array Liste des jetons generes
 */
function markAchatAsPaid($achatId, $stripePaymentIntent = null) {
    $pdo = getDbConnection();

    // Recuperer l'achat
    $achat = getAchatById($achatId);
    if (!$achat || $achat['status'] !== 'pending') {
        return false;
    }

    // Mettre a jour le statut
    $stmt = $pdo->prepare('
        UPDATE achats
        SET status = "paid", paid_at = NOW(), stripe_payment_intent = ?
        WHERE id = ? AND status = "pending"
    ');
    $stmt->execute([$stripePaymentIntent, $achatId]);

    if ($stmt->rowCount() === 0) {
        return false;
    }

    // Generer les jetons
    $tokens = generateTokens($achat['scrutin_id'], $achat['nb_jetons']);

    return $tokens;
}

/**
 * Marquer un achat comme echoue
 */
function markAchatAsFailed($achatId) {
    $pdo = getDbConnection();
    $stmt = $pdo->prepare('UPDATE achats SET status = "failed" WHERE id = ?');
    $stmt->execute([$achatId]);
}

/**
 * Recuperer l'historique des achats d'un utilisateur pour un scrutin
 */
function getAchatsByUserAndScrutin($userId, $scrutinId) {
    $pdo = getDbConnection();
    $stmt = $pdo->prepare('
        SELECT * FROM achats
        WHERE user_id = ? AND scrutin_id = ?
        ORDER BY created_at DESC
    ');
    $stmt->execute([$userId, $scrutinId]);
    return $stmt->fetchAll();
}

/**
 * Calculer le prix total en centimes
 */
function calculateTotalPrice($nbJetons) {
    return $nbJetons * STRIPE_PRICE_PER_TOKEN_CENTS;
}

/**
 * Creer une session Stripe Checkout
 * @return array|false Retourne les infos de session ou false en cas d'erreur
 */
function createStripeCheckoutSession($scrutinId, $userId, $nbJetons, $successUrl, $cancelUrl) {
    $montantCents = calculateTotalPrice($nbJetons);

    // Recuperer le scrutin pour le nom
    $scrutin = getScrutinById($scrutinId);
    if (!$scrutin) {
        return false;
    }

    // Appel API Stripe
    $ch = curl_init('https://api.stripe.com/v1/checkout/sessions');

    $data = [
        'payment_method_types[]' => 'card',
        'line_items[0][price_data][currency]' => 'eur',
        'line_items[0][price_data][product_data][name]' => 'Jetons de vote - ' . $scrutin['titre'],
        'line_items[0][price_data][product_data][description]' => $nbJetons . ' jeton(s) pour le scrutin "' . $scrutin['titre'] . '"',
        'line_items[0][price_data][unit_amount]' => STRIPE_PRICE_PER_TOKEN_CENTS,
        'line_items[0][quantity]' => $nbJetons,
        'mode' => 'payment',
        'success_url' => $successUrl,
        'cancel_url' => $cancelUrl,
        'metadata[scrutin_id]' => $scrutinId,
        'metadata[user_id]' => $userId,
        'metadata[nb_jetons]' => $nbJetons
    ];

    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_USERPWD, STRIPE_SECRET_KEY . ':');
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/x-www-form-urlencoded'
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200) {
        error_log('Stripe Checkout error: ' . $response);
        return false;
    }

    $session = json_decode($response, true);

    if (!isset($session['id']) || !isset($session['url'])) {
        error_log('Stripe Checkout invalid response: ' . $response);
        return false;
    }

    // Creer l'achat en base
    $achatId = createAchat($userId, $scrutinId, $nbJetons, $montantCents, $session['id']);

    return [
        'session_id' => $session['id'],
        'checkout_url' => $session['url'],
        'achat_id' => $achatId
    ];
}

// ============================================================================
// RESULTATS ET EXPORTS
// ============================================================================

/**
 * Recuperer les resultats agreges par question pour un scrutin (Vote Nuance)
 * @return array [question_id => ['ac' => X, 'fc' => X, ...]]
 */
function getResultsByScrutin($scrutinId) {
    $pdo = getDbConnection();
    $stmt = $pdo->prepare('
        SELECT
            question_id,
            SUM(CASE WHEN vote_mention = 1 THEN 1 ELSE 0 END) AS ac,
            SUM(CASE WHEN vote_mention = 2 THEN 1 ELSE 0 END) AS fc,
            SUM(CASE WHEN vote_mention = 3 THEN 1 ELSE 0 END) AS pc,
            SUM(CASE WHEN vote_mention = 4 THEN 1 ELSE 0 END) AS sa,
            SUM(CASE WHEN vote_mention = 5 THEN 1 ELSE 0 END) AS pp,
            SUM(CASE WHEN vote_mention = 6 THEN 1 ELSE 0 END) AS fp,
            SUM(CASE WHEN vote_mention = 7 THEN 1 ELSE 0 END) AS ap
        FROM bulletins
        WHERE scrutin_id = ? AND est_test = 0 AND vote_mention IS NOT NULL
        GROUP BY question_id
    ');
    $stmt->execute([$scrutinId]);
    $results = [];
    foreach ($stmt->fetchAll() as $row) {
        $results[$row['question_id']] = $row;
    }
    return $results;
}

/**
 * Recuperer les emargements d'un scrutin
 */
function getEmargementsByScrutin($scrutinId) {
    $pdo = getDbConnection();
    $stmt = $pdo->prepare('SELECT * FROM emargements WHERE scrutin_id = ? ORDER BY emarge_at');
    $stmt->execute([$scrutinId]);
    return $stmt->fetchAll();
}

/**
 * Recuperer les votes QCM pour une question
 * @return array [['reponse_id' => X, 'nb_votes' => Y], ...]
 */
function getVotesQcm($questionId) {
    $pdo = getDbConnection();
    $stmt = $pdo->prepare('
        SELECT
            rp.id AS reponse_id,
            rp.libelle,
            COUNT(b.id) AS nb_votes
        FROM reponses_possibles rp
        LEFT JOIN bulletins b ON b.reponse = rp.libelle AND b.question_id = rp.question_id AND b.est_test = 0
        WHERE rp.question_id = ?
        GROUP BY rp.id, rp.libelle
        ORDER BY rp.ordre
    ');
    $stmt->execute([$questionId]);
    return $stmt->fetchAll();
}

/**
 * Recuperer les reponses ouvertes pour une question
 */
function getReponsesOuvertes($questionId) {
    $pdo = getDbConnection();
    $stmt = $pdo->prepare('
        SELECT reponse
        FROM bulletins
        WHERE question_id = ? AND est_test = 0 AND reponse IS NOT NULL AND reponse != ""
        ORDER BY vote_at
    ');
    $stmt->execute([$questionId]);
    return $stmt->fetchAll();
}

/**
 * Recuperer les votes "Prefere du lot" pour une question
 * @return array [['option_titre' => X, 'nb_votes' => Y], ...]
 */
function getVotesPrefere($questionId) {
    $pdo = getDbConnection();
    $stmt = $pdo->prepare('
        SELECT
            reponse AS option_titre,
            COUNT(*) AS nb_votes
        FROM bulletins
        WHERE question_id = ? AND est_test = 0 AND reponse IS NOT NULL
        GROUP BY reponse
        ORDER BY nb_votes DESC
    ');
    $stmt->execute([$questionId]);
    return $stmt->fetchAll();
}

// ============================================================================
// STATISTIQUES PARTICIPATION
// ============================================================================

/**
 * Recuperer la timeline de participation (emargements) pour un scrutin
 * Granularite automatique selon la duree du scrutin
 * @return array ['data' => [...], 'granularity' => 'minute'|'hour'|'day', 'labels' => [...]]
 */
function getParticipationTimeline($scrutinId) {
    $pdo = getDbConnection();

    // Recuperer le premier et dernier emargement
    $stmt = $pdo->prepare('
        SELECT MIN(emarge_at) as first_vote, MAX(emarge_at) as last_vote, COUNT(*) as total
        FROM emargements
        WHERE scrutin_id = ?
    ');
    $stmt->execute([$scrutinId]);
    $range = $stmt->fetch();

    if (!$range['first_vote'] || $range['total'] == 0) {
        return [
            'data' => [],
            'cumulative' => [],
            'labels' => [],
            'granularity' => 'hour',
            'total' => 0
        ];
    }

    $firstVote = strtotime($range['first_vote']);
    $lastVote = strtotime($range['last_vote']);
    $duration = $lastVote - $firstVote;

    // Determiner la granularite
    if ($duration < 86400) { // < 1 jour
        $granularity = 'minute';
        $sqlFormat = '%Y-%m-%d %H:%i';
        $phpFormat = 'H:i';
        $interval = 60; // 1 minute
    } elseif ($duration < 7 * 86400) { // 1-7 jours
        $granularity = 'hour';
        $sqlFormat = '%Y-%m-%d %H:00';
        $phpFormat = 'd/m H\\h';
        $interval = 3600; // 1 heure
    } else { // > 7 jours
        $granularity = 'day';
        $sqlFormat = '%Y-%m-%d';
        $phpFormat = 'd/m';
        $interval = 86400; // 1 jour
    }

    // Agreger les emargements par periode
    $stmt = $pdo->prepare("
        SELECT DATE_FORMAT(emarge_at, ?) as period, COUNT(*) as count
        FROM emargements
        WHERE scrutin_id = ?
        GROUP BY period
        ORDER BY period
    ");
    $stmt->execute([$sqlFormat, $scrutinId]);
    $rows = $stmt->fetchAll();

    // Construire les donnees
    $data = [];
    $labels = [];
    $cumulative = [];
    $cumulativeTotal = 0;

    foreach ($rows as $row) {
        $labels[] = date($phpFormat, strtotime($row['period']));
        $data[] = (int)$row['count'];
        $cumulativeTotal += (int)$row['count'];
        $cumulative[] = $cumulativeTotal;
    }

    return [
        'data' => $data,
        'cumulative' => $cumulative,
        'labels' => $labels,
        'granularity' => $granularity,
        'total' => (int)$range['total']
    ];
}

/**
 * Verifier la signature d'un webhook Stripe
 */
function verifyStripeWebhookSignature($payload, $sigHeader) {
    $elements = explode(',', $sigHeader);
    $timestamp = null;
    $signatures = [];

    foreach ($elements as $element) {
        $parts = explode('=', $element, 2);
        if (count($parts) === 2) {
            if ($parts[0] === 't') {
                $timestamp = $parts[1];
            } elseif ($parts[0] === 'v1') {
                $signatures[] = $parts[1];
            }
        }
    }

    if (!$timestamp || empty($signatures)) {
        return false;
    }

    // Verifier que le timestamp n'est pas trop vieux (5 minutes)
    if (abs(time() - intval($timestamp)) > 300) {
        return false;
    }

    // Calculer la signature attendue
    $signedPayload = $timestamp . '.' . $payload;
    $expectedSignature = hash_hmac('sha256', $signedPayload, STRIPE_WEBHOOK_SECRET);

    // Verifier contre toutes les signatures v1
    foreach ($signatures as $sig) {
        if (hash_equals($expectedSignature, $sig)) {
            return true;
        }
    }

    return false;
}
