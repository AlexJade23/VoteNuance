<?php
/**
 * Import votes depuis fichier XLS (XML Spreadsheet)
 * Permet de fusionner des votes offline avec les votes online
 */

require_once 'config.php';
require_once 'functions.php';

// Verifier que l'utilisateur est connecte
if (!isLoggedIn()) {
    header('Location: login.php');
    exit;
}

$user = getCurrentUser();
$csrfToken = generateCsrfToken();
$errors = [];
$success = false;
$preview = null;

// Recuperer le scrutin
$code = $_GET['code'] ?? $_POST['code'] ?? '';
if (empty($code)) {
    header('Location: /mes-scrutins.php');
    exit;
}

$scrutin = getScrutinByCode($code);
if (!$scrutin) {
    header('HTTP/1.0 404 Not Found');
    exit('Scrutin introuvable');
}

// Verifier que l'utilisateur est proprietaire
if ($scrutin['owner_id'] != $user['id']) {
    header('HTTP/1.1 403 Forbidden');
    exit('Acces refuse');
}

$questions = getQuestionsByScrutin($scrutin['id']);

// Traitement du formulaire
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Token de securite invalide';
    } elseif (isset($_POST['action']) && $_POST['action'] === 'confirm') {
        // Confirmation de l'import
        $mode = $_POST['mode'] ?? 'add';

        if (isset($_SESSION['import_votes_preview'])) {
            $data = $_SESSION['import_votes_preview'];
            unset($_SESSION['import_votes_preview']);

            try {
                $pdo = getDbConnection();
                $pdo->beginTransaction();

                if ($mode === 'replace') {
                    // Supprimer les votes existants (sauf jetons organisateurs)
                    $stmt = $pdo->prepare('
                        DELETE FROM bulletins
                        WHERE scrutin_id = ? AND est_test = 0
                    ');
                    $stmt->execute([$scrutin['id']]);

                    // Supprimer les emargements
                    $stmt = $pdo->prepare('DELETE FROM emargements WHERE scrutin_id = ?');
                    $stmt->execute([$scrutin['id']]);
                }

                // Ajouter les votes importes
                foreach ($data['votes'] as $vote) {
                    // Trouver la question par titre
                    $questionId = null;
                    foreach ($questions as $q) {
                        if ($q['titre'] === $vote['question'] && $q['type_question'] == 0) {
                            $questionId = $q['id'];
                            break;
                        }
                    }

                    if (!$questionId) continue;

                    // Creer les bulletins pour chaque mention
                    $mentionMapping = [
                        'ac' => 1, 'fc' => 2, 'pc' => 3, 'sa' => 4,
                        'pp' => 5, 'fp' => 6, 'ap' => 7
                    ];

                    foreach ($mentionMapping as $mentionCode => $mentionId) {
                        $count = intval($vote[$mentionCode] ?? 0);
                        for ($i = 0; $i < $count; $i++) {
                            // Generer un ballot_hash unique pour chaque bulletin importe
                            $ballotHash = 'import_' . bin2hex(random_bytes(16));
                            $stmt = $pdo->prepare('
                                INSERT INTO bulletins (scrutin_id, question_id, vote_mention, ballot_hash, est_test, est_importe, imported_at)
                                VALUES (?, ?, ?, ?, 0, 1, NOW())
                            ');
                            $stmt->execute([$scrutin['id'], $questionId, $mentionId, $ballotHash]);
                        }
                    }
                }

                // Creer un emargement "import" pour tracer
                $totalVotesImported = 0;
                foreach ($data['votes'] as $vote) {
                    $totalVotesImported += array_sum([
                        intval($vote['ac'] ?? 0),
                        intval($vote['fc'] ?? 0),
                        intval($vote['pc'] ?? 0),
                        intval($vote['sa'] ?? 0),
                        intval($vote['pp'] ?? 0),
                        intval($vote['fp'] ?? 0),
                        intval($vote['ap'] ?? 0)
                    ]);
                }

                // Calculer le nombre de participants importes (approximation)
                $nbQuestionsVoteNuance = count(array_filter($questions, fn($q) => $q['type_question'] == 0));
                if ($nbQuestionsVoteNuance > 0 && $totalVotesImported > 0) {
                    $nbParticipantsImported = intdiv($totalVotesImported, $nbQuestionsVoteNuance);
                    if ($nbParticipantsImported < 1) $nbParticipantsImported = 1;

                    // Creer des emargements pour les votes importes
                    for ($i = 0; $i < $nbParticipantsImported; $i++) {
                        $fakeKey = 'import_' . uniqid() . '_' . $i;
                        $stmt = $pdo->prepare('
                            INSERT INTO emargements (scrutin_id, cle_verification, voted_at)
                            VALUES (?, ?, NOW())
                        ');
                        $stmt->execute([$scrutin['id'], $fakeKey]);
                    }
                }

                $pdo->commit();
                $success = true;

            } catch (Exception $e) {
                $pdo->rollBack();
                $errors[] = 'Erreur lors de l\'import : ' . $e->getMessage();
            }
        } else {
            $errors[] = 'Donnees d\'import expirees. Veuillez recommencer.';
        }
    } else {
        // Upload et parsing du fichier
        if (!isset($_FILES['xlsfile']) || $_FILES['xlsfile']['error'] !== UPLOAD_ERR_OK) {
            $errors[] = 'Erreur lors de l\'upload du fichier';
        } else {
            $file = $_FILES['xlsfile'];

            if ($file['size'] > 5 * 1024 * 1024) {
                $errors[] = 'Fichier trop volumineux (max 5 Mo)';
            } else {
                $content = file_get_contents($file['tmp_name']);
                $parsed = parseVotesXmlSpreadsheet($content, $questions);

                if ($parsed === false) {
                    $errors[] = 'Format de fichier invalide.';
                } elseif (!empty($parsed['errors'])) {
                    $errors = array_merge($errors, $parsed['errors']);
                } else {
                    $_SESSION['import_votes_preview'] = $parsed;
                    $preview = $parsed;
                }
            }
        }
    }
}

/**
 * Parser un fichier XML Spreadsheet de votes
 */
function parseVotesXmlSpreadsheet($content, $scrutinQuestions) {
    $content = preg_replace('/^\xEF\xBB\xBF/', '', $content);

    libxml_use_internal_errors(true);
    $xml = simplexml_load_string($content);

    if ($xml === false) {
        return false;
    }

    $namespaces = $xml->getNamespaces(true);
    $ss = isset($namespaces['ss']) ? $namespaces['ss'] : 'urn:schemas-microsoft-com:office:spreadsheet';
    $xml->registerXPathNamespace('ss', $ss);

    $result = [
        'votes' => [],
        'errors' => []
    ];

    // Chercher l'onglet "Votes bruts"
    $worksheets = $xml->xpath('//ss:Worksheet');
    $found = false;

    foreach ($worksheets as $ws) {
        $name = (string)$ws->attributes($ss)['Name'];

        if ($name === 'Votes bruts') {
            $found = true;
            $rows = $ws->xpath('.//ss:Row');
            $isHeader = true;

            foreach ($rows as $row) {
                if ($isHeader) { $isHeader = false; continue; }

                $cells = $row->xpath('.//ss:Cell/ss:Data');
                if (count($cells) >= 8) {
                    $questionTitle = trim((string)$cells[0]);

                    $result['votes'][] = [
                        'question' => $questionTitle,
                        'ac' => intval((string)$cells[1]),
                        'fc' => intval((string)$cells[2]),
                        'pc' => intval((string)$cells[3]),
                        'sa' => intval((string)$cells[4]),
                        'pp' => intval((string)$cells[5]),
                        'fp' => intval((string)$cells[6]),
                        'ap' => intval((string)$cells[7])
                    ];
                }
            }
        }
    }

    if (!$found) {
        $result['errors'][] = 'Onglet "Votes bruts" introuvable dans le fichier.';
        return $result;
    }

    // Validation bijective : verifier que toutes les questions du fichier existent dans le scrutin
    $scrutinTitles = array_map(fn($q) => $q['titre'], array_filter($scrutinQuestions, fn($q) => $q['type_question'] == 0));

    foreach ($result['votes'] as $v) {
        if (!in_array($v['question'], $scrutinTitles)) {
            $result['errors'][] = 'Question introuvable dans le scrutin : "' . $v['question'] . '"';
        }
    }

    return $result;
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Importer des votes - Vote Nuance</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            background: #f5f5f5;
            min-height: 100vh;
        }
        .page-content { padding: 20px; }
        <?php echo getNavigationCSS(); ?>
        .container { max-width: 900px; margin: 0 auto; }
        .page-header { margin-bottom: 20px; }
        .page-header h1 { color: #333; font-size: 24px; }
        .card {
            background: white;
            padding: 30px;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            margin-bottom: 20px;
        }
        .card h2 {
            color: #333;
            margin-bottom: 20px;
            font-size: 18px;
            border-bottom: 2px solid #667eea;
            padding-bottom: 10px;
        }
        .form-group { margin-bottom: 20px; }
        label { display: block; margin-bottom: 6px; font-weight: 600; color: #444; }
        input[type="file"] {
            width: 100%;
            padding: 12px;
            border: 2px dashed #ddd;
            border-radius: 6px;
            background: #f8f9fa;
        }
        select {
            width: 100%;
            padding: 12px;
            border: 1px solid #ddd;
            border-radius: 6px;
            font-size: 14px;
        }
        .btn {
            padding: 12px 24px;
            border: none;
            border-radius: 6px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
            display: inline-block;
        }
        .btn-primary { background: #667eea; color: white; }
        .btn-secondary { background: #6c757d; color: white; }
        .btn-success { background: #28a745; color: white; }
        .btn-danger { background: #dc3545; color: white; }
        .alert {
            padding: 15px 20px;
            border-radius: 8px;
            margin-bottom: 20px;
        }
        .alert-error { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
        .alert-success { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .alert-warning { background: #fff3cd; color: #856404; border: 1px solid #ffc107; }
        .alert-info { background: #e7f3ff; color: #0c5460; border: 1px solid #b8daff; }
        .form-actions { display: flex; gap: 15px; margin-top: 20px; }
        .preview-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 15px;
            font-size: 13px;
        }
        .preview-table th, .preview-table td {
            border: 1px solid #ddd;
            padding: 8px;
            text-align: center;
        }
        .preview-table th {
            background: #667eea;
            color: white;
        }
        .preview-table td:first-child {
            text-align: left;
        }
        .mode-option {
            padding: 15px;
            border: 2px solid #ddd;
            border-radius: 8px;
            margin-bottom: 10px;
            cursor: pointer;
        }
        .mode-option:hover {
            border-color: #667eea;
        }
        .mode-option.selected {
            border-color: #667eea;
            background: #f0f4ff;
        }
        .mode-option input {
            margin-right: 10px;
        }
        .mode-option strong {
            color: #333;
        }
        .mode-option p {
            margin: 5px 0 0 25px;
            color: #666;
            font-size: 13px;
        }
    </style>
</head>
<body>
    <?php echo renderNavigation(''); ?>

    <div class="page-content">
    <div class="container">
        <div class="page-header">
            <h1>Importer des votes</h1>
            <p style="color: #666; margin-top: 5px;">Scrutin : <?php echo htmlspecialchars($scrutin['titre']); ?></p>
        </div>

        <?php foreach ($errors as $error): ?>
        <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
        <?php endforeach; ?>

        <?php if ($success): ?>
        <div class="alert alert-success">
            <strong>Import reussi !</strong> Les votes ont ete importes.
        </div>
        <div class="card" style="text-align: center;">
            <a href="/<?php echo urlencode($code); ?>/r/" class="btn btn-primary">Voir les resultats</a>
        </div>

        <?php elseif ($preview): ?>
        <!-- Previsualisation -->
        <div class="card">
            <h2>Previsualisation des votes a importer</h2>

            <table class="preview-table">
                <tr>
                    <th>Question</th>
                    <th>AC</th>
                    <th>FC</th>
                    <th>PC</th>
                    <th>SA</th>
                    <th>PP</th>
                    <th>FP</th>
                    <th>AP</th>
                    <th>Total</th>
                </tr>
                <?php foreach ($preview['votes'] as $v):
                    $total = $v['ac'] + $v['fc'] + $v['pc'] + $v['sa'] + $v['pp'] + $v['fp'] + $v['ap'];
                ?>
                <tr>
                    <td><?php echo htmlspecialchars($v['question']); ?></td>
                    <td><?php echo $v['ac']; ?></td>
                    <td><?php echo $v['fc']; ?></td>
                    <td><?php echo $v['pc']; ?></td>
                    <td><?php echo $v['sa']; ?></td>
                    <td><?php echo $v['pp']; ?></td>
                    <td><?php echo $v['fp']; ?></td>
                    <td><?php echo $v['ap']; ?></td>
                    <td><strong><?php echo $total; ?></strong></td>
                </tr>
                <?php endforeach; ?>
            </table>

            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                <input type="hidden" name="code" value="<?php echo htmlspecialchars($code); ?>">
                <input type="hidden" name="action" value="confirm">

                <h3 style="margin: 25px 0 15px; font-size: 16px;">Mode d'import :</h3>

                <label class="mode-option" onclick="this.querySelector('input').checked=true; document.querySelectorAll('.mode-option').forEach(e=>e.classList.remove('selected')); this.classList.add('selected');">
                    <input type="radio" name="mode" value="add" checked>
                    <strong>Ajouter</strong>
                    <p>Les votes importes s'ajoutent aux votes existants (fusion)</p>
                </label>

                <label class="mode-option" onclick="this.querySelector('input').checked=true; document.querySelectorAll('.mode-option').forEach(e=>e.classList.remove('selected')); this.classList.add('selected');">
                    <input type="radio" name="mode" value="replace">
                    <strong>Remplacer</strong>
                    <p>Les votes existants sont supprimes et remplaces par les votes importes</p>
                </label>

                <div class="alert alert-warning" style="margin-top: 20px;">
                    <strong>Attention :</strong> Cette action est irreversible. Les votes importes seront marques comme tels pour tracabilite.
                </div>

                <div class="form-actions">
                    <a href="/votes-import.php?code=<?php echo urlencode($code); ?>" class="btn btn-secondary">Annuler</a>
                    <button type="submit" class="btn btn-success">Confirmer l'import</button>
                </div>
            </form>
        </div>

        <?php else: ?>
        <!-- Formulaire d'upload -->
        <div class="card">
            <h2>Charger un fichier de votes</h2>

            <div class="alert alert-info">
                <strong>Format attendu :</strong> Fichier XLS exporte depuis Vote Nuance avec l'onglet "Votes bruts".<br><br>
                Vous pouvez modifier les valeurs dans Excel avant de reimporter pour fusionner des votes papier/offline avec les votes en ligne.
            </div>

            <form method="POST" enctype="multipart/form-data">
                <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                <input type="hidden" name="code" value="<?php echo htmlspecialchars($code); ?>">

                <div class="form-group">
                    <label for="xlsfile">Fichier XLS</label>
                    <input type="file" id="xlsfile" name="xlsfile" accept=".xls,.xml" required>
                </div>

                <div class="form-actions">
                    <a href="/<?php echo urlencode($code); ?>/r/" class="btn btn-secondary">Annuler</a>
                    <button type="submit" class="btn btn-primary">Analyser le fichier</button>
                </div>
            </form>
        </div>
        <?php endif; ?>
    </div>

    <?php echo renderFooter(); ?>
    </div>
</body>
</html>
