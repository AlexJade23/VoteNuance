<?php
/**
 * Interface web d'export de scrutins
 * Génère un fichier SQL pour transférer vers un autre environnement
 *
 * ACCÈS : Réservé aux superadmins uniquement
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/functions.php';

// ============================================================================
// CONFIGURATION SUPERADMIN
// ============================================================================

// IDs des utilisateurs autorisés à accéder à cette page
$SUPERADMIN_IDS = [
    2,  // Superadmin principal
];

// Clé secrète pour la signature HMAC (identique sur PROD et TEST)
// NE PAS MODIFIER - utilisée pour valider les exports/imports
$HMAC_SECRET_KEY = 'ce664bd8d436b6c940702cbba68f56a1163cb3d081eea6f0cf507825006dc44e';

// ============================================================================
// VÉRIFICATION ACCÈS SUPERADMIN
// ============================================================================

if (!isLoggedIn()) {
    header('Location: /login.php?redirect=' . urlencode($_SERVER['REQUEST_URI']));
    exit;
}

$currentUser = getCurrentUser();
$isSuperAdmin = in_array((int)$currentUser['id'], $SUPERADMIN_IDS);

if (!$isSuperAdmin) {
    http_response_code(403);
    ?>
    <!DOCTYPE html>
    <html lang="fr">
    <head>
        <meta charset="UTF-8">
        <title>Accès refusé</title>
        <style>
            body {
                font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
                background: linear-gradient(135deg, #1a1a2e 0%, #16213e 100%);
                min-height: 100vh;
                margin: 0;
                display: flex;
                align-items: center;
                justify-content: center;
                color: #fff;
            }
            .error-box {
                background: rgba(220, 53, 69, 0.2);
                border: 1px solid #dc3545;
                padding: 40px;
                border-radius: 12px;
                text-align: center;
                max-width: 400px;
            }
            h1 { margin: 0 0 15px 0; }
            p { color: rgba(255,255,255,0.7); }
            a {
                display: inline-block;
                margin-top: 20px;
                color: #667eea;
                text-decoration: none;
            }
            .user-info {
                margin-top: 20px;
                padding: 15px;
                background: rgba(0,0,0,0.2);
                border-radius: 8px;
                font-size: 13px;
            }
        </style>
    </head>
    <body>
        <div class="error-box">
            <h1>Accès refusé</h1>
            <p>Cette page est réservée aux superadministrateurs.</p>
            <div class="user-info">
                Connecté en tant que : <strong><?= htmlspecialchars($currentUser['display_name'] ?? 'Sans nom') ?></strong><br>
                User ID : <strong><?= $currentUser['id'] ?></strong><br>
                Provider : <?= htmlspecialchars($currentUser['sso_provider']) ?>
            </div>
            <a href="/mes-scrutins.php">Retour à mes scrutins</a>
        </div>
    </body>
    </html>
    <?php
    exit;
}

// ============================================================================
// FONCTIONS UTILITAIRES
// ============================================================================

function getAllScrutins() {
    $pdo = getDbConnection();
    $stmt = $pdo->query('
        SELECT s.*,
            u.display_name as owner_name,
            (SELECT COUNT(*) FROM questions q WHERE q.scrutin_id = s.id) as nb_questions,
            (SELECT COUNT(DISTINCT ballot_hash) FROM bulletins b WHERE b.scrutin_id = s.id AND b.est_test = 0) as nb_votes
        FROM scrutins s
        LEFT JOIN users u ON u.id = s.owner_id
        ORDER BY s.created_at DESC
    ');
    return $stmt->fetchAll();
}

function generateExportSQL($scrutinCodes, $targetUserId) {
    $pdo = getDbConnection();

    // S'assurer que c'est un tableau
    if (!is_array($scrutinCodes)) {
        $scrutinCodes = [$scrutinCodes];
    }

    $sql = [];
    $sql[] = "-- ============================================================================";
    $sql[] = "-- Export de " . count($scrutinCodes) . " scrutin(s)";
    $sql[] = "-- Généré le: " . date('Y-m-d H:i:s');
    $sql[] = "-- Source: " . DB_NAME . " (" . (IS_TEST_ENV ? 'TEST' : 'PRODUCTION') . ")";
    $sql[] = "-- Propriétaire cible: user_id = $targetUserId";
    $sql[] = "-- ============================================================================";
    $sql[] = "";
    $sql[] = "SET FOREIGN_KEY_CHECKS = 0;";
    $sql[] = "SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO';";
    $sql[] = "";

    $totalStats = ['scrutins' => 0, 'questions' => 0, 'bulletins' => 0, 'emargements' => 0];
    $exportedCodes = [];

    foreach ($scrutinCodes as $scrutinCode) {
        // Récupération du scrutin
        $stmt = $pdo->prepare('SELECT * FROM scrutins WHERE code = ?');
        $stmt->execute([strtolower($scrutinCode)]);
        $scrutin = $stmt->fetch();

        if (!$scrutin) {
            $sql[] = "-- ERREUR: Scrutin '$scrutinCode' non trouvé, ignoré";
            $sql[] = "";
            continue;
        }

        $scrutinId = $scrutin['id'];
        $code = $scrutin['code']; // Garder le code original

        // Questions
        $stmt = $pdo->prepare('SELECT * FROM questions WHERE scrutin_id = ? ORDER BY ordre');
        $stmt->execute([$scrutinId]);
        $questions = $stmt->fetchAll();

        $questionIdMap = [];
        foreach ($questions as $idx => $q) {
            $questionIdMap[$q['id']] = $idx;
        }

        // Réponses possibles
        $reponsesPossibles = [];
        foreach ($questions as $q) {
            $stmt = $pdo->prepare('SELECT * FROM reponses_possibles WHERE question_id = ? ORDER BY ordre');
            $stmt->execute([$q['id']]);
            $reponsesPossibles[$q['id']] = $stmt->fetchAll();
        }

        // Bulletins
        $stmt = $pdo->prepare('SELECT * FROM bulletins WHERE scrutin_id = ? ORDER BY id');
        $stmt->execute([$scrutinId]);
        $bulletins = $stmt->fetchAll();

        // Émargements
        $stmt = $pdo->prepare('SELECT * FROM emargements WHERE scrutin_id = ? ORDER BY id');
        $stmt->execute([$scrutinId]);
        $emargements = $stmt->fetchAll();

        // Stats
        $totalStats['scrutins']++;
        $totalStats['questions'] += count($questions);
        $totalStats['bulletins'] += count($bulletins);
        $totalStats['emargements'] += count($emargements);
        $exportedCodes[] = $code;

        $sql[] = "-- ============================================================================";
        $sql[] = "-- SCRUTIN: \"{$scrutin['titre']}\" (code: $code)";
        $sql[] = "-- Questions: " . count($questions) . " | Bulletins: " . count($bulletins) . " | Émargements: " . count($emargements);
        $sql[] = "-- ============================================================================";
        $sql[] = "";

        // Supprimer l'ancien scrutin avec ce code s'il existe (ordre FK)
        $sql[] = "-- Suppression de l'ancien scrutin (si existe)";
        $sql[] = "SET @old_scrutin_id = (SELECT id FROM scrutins WHERE code = '$code');";
        $sql[] = "DELETE FROM bulletins WHERE scrutin_id = @old_scrutin_id;";
        $sql[] = "DELETE FROM emargements WHERE scrutin_id = @old_scrutin_id;";
        $sql[] = "DELETE FROM jetons WHERE scrutin_id = @old_scrutin_id;";
        $sql[] = "DELETE FROM reponses_possibles WHERE question_id IN (SELECT id FROM questions WHERE scrutin_id = @old_scrutin_id);";
        $sql[] = "DELETE FROM questions WHERE scrutin_id = @old_scrutin_id;";
        $sql[] = "DELETE FROM scrutins WHERE id = @old_scrutin_id;";
        $sql[] = "";

        // SCRUTIN
        $sql[] = "-- SCRUTIN";
        $scrutinValues = [
            'code' => $code,
            'titre' => $scrutin['titre'],
            'resume' => $scrutin['resume'],
            'notice' => $scrutin['notice'],
            'image_url' => $scrutin['image_url'],
            'debut_at' => $scrutin['debut_at'],
            'fin_at' => $scrutin['fin_at'],
            'nb_participants_attendus' => $scrutin['nb_participants_attendus'],
            'nb_gagnants' => $scrutin['nb_gagnants'],
            'format' => $scrutin['format'],
            'affiche_resultats' => $scrutin['affiche_resultats'],
            'est_public' => $scrutin['est_public'],
            'est_archive' => $scrutin['est_archive'],
            'ordre_mentions' => $scrutin['ordre_mentions'],
            'nb_mentions' => $scrutin['nb_mentions'] ?? 7,
            'type_notification' => $scrutin['type_notification'],
            'destination_notification' => $scrutin['destination_notification'],
            'owner_id' => $targetUserId,
        ];

        $columns = array_keys($scrutinValues);
        $placeholders = array_map(function($v) {
            return $v === null ? 'NULL' : "'" . addslashes($v) . "'";
        }, array_values($scrutinValues));

        $sql[] = "INSERT INTO scrutins (" . implode(', ', $columns) . ")";
        $sql[] = "VALUES (" . implode(', ', $placeholders) . ");";
        $sql[] = "SET @new_scrutin_id = LAST_INSERT_ID();";
        $sql[] = "";

        // QUESTIONS
        if (!empty($questions)) {
            $sql[] = "-- QUESTIONS (" . count($questions) . ")";
            foreach ($questions as $idx => $q) {
                $sql[] = "SET @new_question_$idx = NULL;";

                $qValues = [
                    'scrutin_id' => '@new_scrutin_id',
                    'echelle_id' => $q['echelle_id'],
                    'type_question' => $q['type_question'],
                    'numero' => $q['numero'],
                    'titre' => $q['titre'],
                    'question' => $q['question'],
                    'description' => $q['description'],
                    'image_url' => $q['image_url'],
                    'lot' => $q['lot'],
                    'ordre' => $q['ordre'],
                    'est_obligatoire' => $q['est_obligatoire'],
                    'est_cle' => $q['est_cle'] ?? 0,
                    'horodatage' => $q['horodatage'] ?? 0,
                    'est_donnee_personnelle' => $q['est_donnee_personnelle'] ?? 0,
                    'est_donnee_sensible' => $q['est_donnee_sensible'] ?? 0,
                ];

                $cols = [];
                $vals = [];
                foreach ($qValues as $k => $v) {
                    $cols[] = $k;
                    if ($k === 'scrutin_id') {
                        $vals[] = '@new_scrutin_id';
                    } elseif ($v === null) {
                        $vals[] = 'NULL';
                    } else {
                        $vals[] = "'" . addslashes($v) . "'";
                    }
                }

                $sql[] = "INSERT INTO questions (" . implode(', ', $cols) . ") VALUES (" . implode(', ', $vals) . ");";
                $sql[] = "SET @new_question_$idx = LAST_INSERT_ID();";

                // Réponses possibles
                if (!empty($reponsesPossibles[$q['id']])) {
                    foreach ($reponsesPossibles[$q['id']] as $rp) {
                        $sql[] = "INSERT INTO reponses_possibles (question_id, libelle, ordre) VALUES (@new_question_$idx, '" . addslashes($rp['libelle']) . "', " . $rp['ordre'] . ");";
                    }
                }
            }
            $sql[] = "";
        }

        // BULLETINS
        if (!empty($bulletins)) {
            $sql[] = "-- BULLETINS (" . count($bulletins) . ")";

            $batchSize = 100;
            $batches = array_chunk($bulletins, $batchSize);

            foreach ($batches as $batch) {
                $sql[] = "INSERT INTO bulletins (scrutin_id, question_id, ballot_hash, vote_mention, reponse, vote_at, est_test) VALUES";

                $rows = [];
                foreach ($batch as $b) {
                    $qIdx = $questionIdMap[$b['question_id']] ?? null;
                    if ($qIdx === null) continue;

                    $row = [
                        '@new_scrutin_id',
                        "@new_question_$qIdx",
                        "'" . addslashes($b['ballot_hash']) . "'",
                        $b['vote_mention'] === null ? 'NULL' : $b['vote_mention'],
                        $b['reponse'] === null ? 'NULL' : "'" . addslashes($b['reponse']) . "'",
                        $b['vote_at'] === null ? 'NULL' : "'" . $b['vote_at'] . "'",
                        $b['est_test'] ?? 0
                    ];
                    $rows[] = "(" . implode(", ", $row) . ")";
                }

                $sql[] = implode(",\n", $rows) . ";";
            }
            $sql[] = "";
        }

        // ÉMARGEMENTS
        if (!empty($emargements)) {
            $sql[] = "-- ÉMARGEMENTS (" . count($emargements) . ")";
            $sql[] = "INSERT INTO emargements (scrutin_id, emarge_at, ip_hash) VALUES";

            $rows = [];
            foreach ($emargements as $e) {
                $rows[] = "(@new_scrutin_id, '" . $e['emarge_at'] . "', '" . addslashes($e['ip_hash']) . "')";
            }
            $sql[] = implode(",\n", $rows) . ";";
            $sql[] = "";
        }

        // JETON ORGANISATEUR
        $sql[] = "-- JETON ORGANISATEUR";
        $orgTokenCode = strtoupper(bin2hex(random_bytes(4)));
        $sql[] = "INSERT INTO jetons (scrutin_id, user_id, code, est_organisateur, est_utilise) VALUES (@new_scrutin_id, $targetUserId, '$orgTokenCode', 1, 0);";
        $sql[] = "";
    }

    // FINALISATION
    $sql[] = "-- ============================================================================";
    $sql[] = "-- FINALISATION";
    $sql[] = "-- ============================================================================";
    $sql[] = "SET FOREIGN_KEY_CHECKS = 1;";
    $sql[] = "SET SQL_MODE=@OLD_SQL_MODE;";
    $sql[] = "";
    $sql[] = "-- Résumé: " . $totalStats['scrutins'] . " scrutin(s), " . $totalStats['questions'] . " questions, " . $totalStats['bulletins'] . " bulletins";
    $sql[] = "-- Codes exportés: " . implode(', ', $exportedCodes);
    $sql[] = "";

    // Calculer le HMAC-SHA256 du contenu (avant ajout de la signature)
    global $HMAC_SECRET_KEY;
    $contentForHash = implode("\n", $sql);
    $signature = hash_hmac('sha256', $contentForHash, $HMAC_SECRET_KEY);

    // Ajouter la signature
    $sql[] = "-- ============================================================================";
    $sql[] = "-- SIGNATURE DECO (ne pas modifier ce fichier)";
    $sql[] = "-- DECO_SIGNATURE:" . $signature;
    $sql[] = "-- ============================================================================";

    return [
        'sql' => implode("\n", $sql),
        'codes' => $exportedCodes,
        'stats' => $totalStats,
        'signature' => $signature
    ];
}

// ============================================================================
// TRAITEMENT DES ACTIONS
// ============================================================================

$error = null;
$success = null;
$scrutins = [];

try {
    $scrutins = getAllScrutins();
} catch (Exception $e) {
    $error = "Erreur de connexion: " . $e->getMessage();
}

// Export
if (isset($_POST['action']) && $_POST['action'] === 'export') {
    try {
        $selectedCodes = $_POST['scrutin_codes'] ?? [];
        if (empty($selectedCodes)) {
            throw new Exception("Aucun scrutin sélectionné");
        }

        // Validation stricte : entier positif uniquement
        $targetUser = filter_var($_POST['target_user'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($targetUser === false) {
            throw new Exception("L'ID utilisateur doit être un entier positif");
        }

        $result = generateExportSQL(
            $selectedCodes,
            $targetUser
        );

        // Téléchargement direct
        $filename = 'export_' . count($selectedCodes) . 'scrutins_' . date('Ymd_His') . '.sql';
        header('Content-Type: application/sql');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . strlen($result['sql']));
        echo $result['sql'];
        exit;

    } catch (Exception $e) {
        $error = "Erreur export: " . $e->getMessage();
    }
}

// Import
$importResult = null;
if (isset($_POST['action']) && $_POST['action'] === 'import') {
    try {
        // Vérifier le fichier uploadé
        if (!isset($_FILES['sql_file']) || $_FILES['sql_file']['error'] !== UPLOAD_ERR_OK) {
            $uploadErrors = [
                UPLOAD_ERR_INI_SIZE => 'Fichier trop volumineux (limite PHP)',
                UPLOAD_ERR_FORM_SIZE => 'Fichier trop volumineux (limite formulaire)',
                UPLOAD_ERR_PARTIAL => 'Fichier partiellement uploadé',
                UPLOAD_ERR_NO_FILE => 'Aucun fichier sélectionné',
                UPLOAD_ERR_NO_TMP_DIR => 'Dossier temporaire manquant',
                UPLOAD_ERR_CANT_WRITE => 'Erreur d\'écriture disque',
            ];
            $errCode = $_FILES['sql_file']['error'] ?? UPLOAD_ERR_NO_FILE;
            throw new Exception($uploadErrors[$errCode] ?? 'Erreur upload inconnue');
        }

        $file = $_FILES['sql_file'];

        // Vérifier l'extension
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if ($ext !== 'sql') {
            throw new Exception("Seuls les fichiers .sql sont acceptés");
        }

        // Vérifier la taille (max 10 Mo)
        if ($file['size'] > 10 * 1024 * 1024) {
            throw new Exception("Fichier trop volumineux (max 10 Mo)");
        }

        // Lire le contenu
        $sqlContent = file_get_contents($file['tmp_name']);
        if ($sqlContent === false) {
            throw new Exception("Impossible de lire le fichier");
        }

        // Vérifier que c'est bien un export généré par cet outil
        if (strpos($sqlContent, '-- Export de') === false && strpos($sqlContent, 'SET FOREIGN_KEY_CHECKS') === false) {
            throw new Exception("Ce fichier ne semble pas être un export valide");
        }

        // Vérifier la signature HMAC-SHA256
        global $HMAC_SECRET_KEY;

        if (preg_match('/-- DECO_SIGNATURE:([a-f0-9]{64})/', $sqlContent, $matches)) {
            $expectedSignature = $matches[1];

            // Extraire le contenu sans les lignes de signature
            $lines = explode("\n", $sqlContent);

            // Trouver la ligne "-- SIGNATURE DECO" et couper avant
            $signatureIndex = null;
            for ($i = count($lines) - 1; $i >= 0; $i--) {
                if (strpos($lines[$i], '-- SIGNATURE DECO') !== false) {
                    $signatureIndex = $i - 1; // -1 pour inclure la ligne vide avant
                    break;
                }
            }

            if ($signatureIndex !== null) {
                $contentLines = array_slice($lines, 0, $signatureIndex);
                $contentForHash = implode("\n", $contentLines);
                $actualSignature = hash_hmac('sha256', $contentForHash, $HMAC_SECRET_KEY);

                if (!hash_equals($expectedSignature, $actualSignature)) {
                    throw new Exception(
                        "SIGNATURE INVALIDE : Le fichier a été modifié ou provient d'une autre instance.\n" .
                        "Import refusé pour protéger la base de données."
                    );
                }
            }
        } else {
            throw new Exception(
                "SIGNATURE MANQUANTE : Ce fichier ne contient pas de signature DECO valide.\n" .
                "Seuls les fichiers générés par l'export DECO peuvent être importés."
            );
        }

        // Exécuter le SQL
        $pdo = getDbConnection();
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        // Exécuter les requêtes une par une
        // On nettoie les commentaires de chaque statement avant de l'exécuter
        $rawStatements = explode(';', $sqlContent);
        $statements = [];
        foreach ($rawStatements as $stmt) {
            // Supprimer les lignes de commentaires (lignes commençant par --)
            $lines = explode("\n", $stmt);
            $cleanedLines = array_filter($lines, function($line) {
                $trimmed = trim($line);
                return !empty($trimmed) && strpos($trimmed, '--') !== 0;
            });
            $cleanedStmt = trim(implode("\n", $cleanedLines));
            if (!empty($cleanedStmt)) {
                $statements[] = $cleanedStmt;
            }
        }

        $executed = 0;
        $pdo->beginTransaction();

        try {
            foreach ($statements as $stmt) {
                if (!empty(trim($stmt))) {
                    $pdo->exec($stmt);
                    $executed++;
                }
            }
            $pdo->commit();

            $importResult = [
                'success' => true,
                'message' => "Import réussi : $executed requêtes exécutées",
                'filename' => $file['name']
            ];
        } catch (PDOException $e) {
            $pdo->rollBack();
            throw new Exception("Erreur SQL : " . $e->getMessage());
        }

    } catch (Exception $e) {
        $error = "Erreur import: " . $e->getMessage();
    }
}

?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Export Scrutins - Admin</title>
    <style>
        * { box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: linear-gradient(135deg, #1a1a2e 0%, #16213e 100%);
            min-height: 100vh;
            margin: 0;
            padding: 20px;
            color: #fff;
        }
        .container {
            max-width: 900px;
            margin: 0 auto;
        }
        .card {
            background: rgba(255,255,255,0.1);
            border-radius: 12px;
            padding: 30px;
            margin-bottom: 20px;
            backdrop-filter: blur(10px);
        }
        h1 {
            margin: 0 0 10px 0;
            font-size: 24px;
        }
        h2 {
            font-size: 18px;
            margin: 25px 0 15px 0;
            padding-bottom: 10px;
            border-bottom: 1px solid rgba(255,255,255,0.1);
        }
        .subtitle {
            color: rgba(255,255,255,0.6);
            margin-bottom: 20px;
        }
        .env-badge {
            display: inline-block;
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            margin-left: 10px;
        }
        .env-test {
            background: linear-gradient(135deg, #ff6b35, #f7931e);
        }
        .env-prod {
            background: #dc3545;
        }
        .user-bar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 15px 20px;
            background: rgba(0,0,0,0.2);
            border-radius: 8px;
            margin-bottom: 20px;
        }
        .user-bar a {
            color: rgba(255,255,255,0.7);
            text-decoration: none;
        }
        .user-bar a:hover {
            color: #fff;
        }
        label {
            display: block;
            margin-bottom: 5px;
            font-weight: 500;
            color: rgba(255,255,255,0.8);
        }
        select {
            width: 100%;
            padding: 12px 15px;
            border: 1px solid rgba(255,255,255,0.2);
            border-radius: 8px;
            background: rgba(0,0,0,0.3);
            color: #fff;
            font-size: 14px;
            margin-bottom: 15px;
        }
        select:focus {
            outline: none;
            border-color: #667eea;
        }
        select option {
            background: #1a1a2e;
            color: #fff;
        }
        input[type="text"] {
            width: 100%;
            padding: 12px 15px;
            border: 1px solid rgba(255,255,255,0.2);
            border-radius: 8px;
            background: rgba(0,0,0,0.3);
            color: #fff;
            font-size: 14px;
            margin-bottom: 15px;
        }
        input:focus {
            outline: none;
            border-color: #667eea;
        }
        button {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            padding: 12px 30px;
            border-radius: 8px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: transform 0.2s, box-shadow 0.2s;
        }
        button:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 20px rgba(102, 126, 234, 0.4);
        }
        button:disabled {
            opacity: 0.5;
            cursor: not-allowed;
            transform: none;
        }
        .error {
            background: rgba(220, 53, 69, 0.2);
            border: 1px solid #dc3545;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
        }
        .badge {
            display: inline-block;
            padding: 3px 8px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
            margin-left: 5px;
        }
        .badge-votes {
            background: #28a745;
        }
        .badge-questions {
            background: #667eea;
        }
        .stats {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 15px;
            margin-bottom: 20px;
        }
        .stat-box {
            background: rgba(0,0,0,0.2);
            padding: 20px;
            border-radius: 8px;
            text-align: center;
        }
        .stat-value {
            font-size: 28px;
            font-weight: 700;
            color: #667eea;
        }
        .stat-label {
            font-size: 13px;
            color: rgba(255,255,255,0.6);
            margin-top: 5px;
        }
        .help-text {
            font-size: 13px;
            color: rgba(255,255,255,0.5);
            margin-top: -10px;
            margin-bottom: 15px;
        }
        .select-actions {
            display: flex;
            gap: 10px;
            align-items: center;
            margin-bottom: 15px;
        }
        .select-actions button {
            padding: 8px 15px;
            font-size: 13px;
            background: rgba(255,255,255,0.1);
        }
        .select-actions button:hover {
            background: rgba(255,255,255,0.2);
            transform: none;
            box-shadow: none;
        }
        .selected-count {
            margin-left: 10px;
            color: #667eea;
            font-weight: 600;
        }
        .scrutin-list {
            max-height: 350px;
            overflow-y: auto;
            border: 1px solid rgba(255,255,255,0.1);
            border-radius: 8px;
            margin-bottom: 20px;
        }
        .scrutin-checkbox {
            display: flex;
            align-items: flex-start;
            padding: 12px 15px;
            border-bottom: 1px solid rgba(255,255,255,0.05);
            cursor: pointer;
            transition: background 0.2s;
        }
        .scrutin-checkbox:hover {
            background: rgba(255,255,255,0.05);
        }
        .scrutin-checkbox:last-child {
            border-bottom: none;
        }
        .scrutin-checkbox input[type="checkbox"] {
            margin-right: 12px;
            margin-top: 4px;
            width: 18px;
            height: 18px;
            cursor: pointer;
        }
        .scrutin-info {
            flex: 1;
        }
        .scrutin-title {
            font-weight: 500;
            margin-bottom: 4px;
        }
        .scrutin-meta {
            font-size: 12px;
            color: rgba(255,255,255,0.6);
        }
        .success {
            background: rgba(40, 167, 69, 0.2);
            border: 1px solid #28a745;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
        }
        .file-upload {
            position: relative;
            margin-bottom: 15px;
        }
        .file-upload input[type="file"] {
            position: absolute;
            width: 100%;
            height: 100%;
            opacity: 0;
            cursor: pointer;
            z-index: 2;
        }
        .file-label {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            padding: 30px;
            border: 2px dashed rgba(255,255,255,0.3);
            border-radius: 8px;
            background: rgba(0,0,0,0.2);
            cursor: pointer;
            transition: all 0.2s;
        }
        .file-label:hover {
            border-color: #667eea;
            background: rgba(102, 126, 234, 0.1);
        }
        .file-icon {
            font-size: 24px;
            color: #667eea;
        }
        .file-text {
            color: rgba(255,255,255,0.7);
        }
        .file-name {
            display: block;
            margin-top: 10px;
            color: #28a745;
            font-weight: 500;
            text-align: center;
        }
        .btn-import {
            background: linear-gradient(135deg, #28a745 0%, #20c997 100%);
        }
        .btn-import:hover {
            box-shadow: 0 5px 20px rgba(40, 167, 69, 0.4);
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="user-bar">
            <div>
                Connecté : <strong><?= htmlspecialchars($currentUser['display_name'] ?? 'Admin') ?></strong>
                (ID: <?= $currentUser['id'] ?>)
                <span class="env-badge <?= IS_TEST_ENV ? 'env-test' : 'env-prod' ?>">
                    <?= IS_TEST_ENV ? 'TEST' : 'PRODUCTION' ?>
                </span>
            </div>
            <div>
                <a href="/mes-scrutins.php">Mes scrutins</a> |
                <a href="/logout.php">Déconnexion</a>
            </div>
        </div>

        <div class="card">
            <h1>Export de scrutins</h1>
            <p class="subtitle">
                Générer un fichier SQL pour transférer un scrutin avec ses votes vers un autre environnement
            </p>

            <?php if ($error): ?>
                <div class="error"><?= nl2br(htmlspecialchars($error)) ?></div>
            <?php endif; ?>

            <div class="stats">
                <div class="stat-box">
                    <div class="stat-value"><?= count($scrutins) ?></div>
                    <div class="stat-label">Scrutins</div>
                </div>
                <div class="stat-box">
                    <div class="stat-value"><?= array_sum(array_column($scrutins, 'nb_votes')) ?></div>
                    <div class="stat-label">Votes total</div>
                </div>
                <div class="stat-box">
                    <div class="stat-value"><?= array_sum(array_column($scrutins, 'nb_questions')) ?></div>
                    <div class="stat-label">Questions</div>
                </div>
            </div>

            <form method="post">
                <input type="hidden" name="action" value="export">

                <h2>1. Scrutins à exporter</h2>
                <p class="help-text">
                    Sélectionnez un ou plusieurs scrutins. Le code original sera conservé.
                </p>
                <div class="select-actions">
                    <button type="button" onclick="selectAll(true)">Tout sélectionner</button>
                    <button type="button" onclick="selectAll(false)">Tout désélectionner</button>
                    <span class="selected-count">0 sélectionné(s)</span>
                </div>
                <div class="scrutin-list">
                    <?php foreach ($scrutins as $s): ?>
                        <label class="scrutin-checkbox">
                            <input type="checkbox" name="scrutin_codes[]" value="<?= htmlspecialchars($s['code']) ?>" onchange="updateCount()">
                            <div class="scrutin-info">
                                <div class="scrutin-title"><?= htmlspecialchars($s['titre']) ?></div>
                                <div class="scrutin-meta">
                                    Code: <strong><?= $s['code'] ?></strong>
                                    <span class="badge badge-questions"><?= $s['nb_questions'] ?> questions</span>
                                    <span class="badge badge-votes"><?= $s['nb_votes'] ?> votes</span>
                                    <?php if ($s['owner_name']): ?>
                                        | par <?= htmlspecialchars($s['owner_name']) ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </label>
                    <?php endforeach; ?>
                </div>

                <h2>2. Propriétaire dans la base cible</h2>
                <p class="help-text">
                    ID de l'utilisateur qui sera propriétaire des scrutins importés (entier uniquement)
                </p>
                <input type="number" name="target_user" min="1" step="1" required
                       placeholder="Ex: 1" value="<?= $currentUser['id'] ?>"
                       pattern="[0-9]+" inputmode="numeric">

                <div style="margin-top: 25px;">
                    <button type="submit" id="submit-btn">
                        Générer et télécharger le SQL
                    </button>
                </div>
            </form>

            <script>
                function selectAll(checked) {
                    document.querySelectorAll('input[name="scrutin_codes[]"]').forEach(cb => cb.checked = checked);
                    updateCount();
                }
                function updateCount() {
                    const count = document.querySelectorAll('input[name="scrutin_codes[]"]:checked').length;
                    document.querySelector('.selected-count').textContent = count + ' sélectionné(s)';
                    document.getElementById('submit-btn').disabled = count === 0;
                }
                updateCount();
            </script>
        </div>

        <div class="card">
            <h1>Import de scrutins</h1>
            <p class="subtitle">
                Importer un fichier SQL généré par l'export ci-dessus
            </p>

            <?php if ($importResult && $importResult['success']): ?>
                <div class="success">
                    <strong>Import réussi !</strong><br>
                    Fichier : <?= htmlspecialchars($importResult['filename']) ?><br>
                    <?= htmlspecialchars($importResult['message']) ?>
                </div>
            <?php endif; ?>

            <form method="post" enctype="multipart/form-data">
                <input type="hidden" name="action" value="import">

                <h2>Fichier SQL à importer</h2>
                <p class="help-text">
                    Sélectionnez un fichier .sql généré par l'export (max 10 Mo).<br>
                    Le fichier doit contenir une signature DECO valide (vérification d'intégrité MD5).
                </p>

                <div class="file-upload">
                    <input type="file" name="sql_file" id="sql_file" accept=".sql" required>
                    <label for="sql_file" class="file-label">
                        <span class="file-icon">+</span>
                        <span class="file-text">Choisir un fichier .sql</span>
                    </label>
                    <span class="file-name"></span>
                </div>

                <div style="margin-top: 25px;">
                    <button type="submit" class="btn-import">
                        Importer dans la base
                    </button>
                </div>
            </form>

            <script>
                document.getElementById('sql_file').addEventListener('change', function() {
                    const fileName = this.files[0] ? this.files[0].name : '';
                    document.querySelector('.file-name').textContent = fileName;
                    document.querySelector('.file-text').textContent = fileName ? 'Fichier sélectionné' : 'Choisir un fichier .sql';
                });
            </script>
        </div>

        <div class="card" style="background: rgba(255,165,0,0.1); border: 1px solid rgba(255,165,0,0.3);">
            <p style="margin: 0; color: rgba(255,255,255,0.8); font-size: 14px;">
                <strong>Note :</strong> Les codes des scrutins sont conservés. Si un scrutin avec le même code existe déjà dans la base, il sera <strong>supprimé puis recréé</strong> avec les nouvelles données.
            </p>
        </div>
    </div>
</body>
</html>
