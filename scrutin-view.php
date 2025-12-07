<?php
require_once 'config.php';
require_once 'functions.php';

$code = $_GET['code'] ?? '';
if (empty($code)) {
    header('Location: index.php');
    exit;
}

$scrutin = getScrutinByCode($code);
if (!$scrutin) {
    header('HTTP/1.0 404 Not Found');
    echo 'Scrutin introuvable';
    exit;
}

$questions = getQuestionsByScrutin($scrutin['id']);
$mentions = getMentionsByEchelle(1);

$user = null;
$isOwner = false;
if (isLoggedIn()) {
    $user = getCurrentUser();
    $isOwner = $user && $user['id'] == $scrutin['owner_id'];
}

// Vérifier accès
if (!$scrutin['est_public'] && !$isOwner) {
    if (!$user) {
        header('Location: login.php?redirect=' . urlencode($_SERVER['REQUEST_URI']));
        exit;
    }
}

// Gestion des jetons (actions POST)
$tokenMessage = null;
$tokenError = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $isOwner) {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $tokenError = 'Token de securite invalide';
    } else {
        $action = $_POST['token_action'] ?? '';

        if ($action === 'generate') {
            $count = intval($_POST['token_count'] ?? 1);
            $count = max(1, min(500, $count)); // Entre 1 et 500
            $newTokens = generateTokens($scrutin['id'], $count);
            $tokenMessage = $count . ' jeton(s) genere(s) avec succes.';
            $_SESSION['generated_tokens'] = $newTokens;
        } elseif ($action === 'revoke') {
            $tokenId = intval($_POST['token_id'] ?? 0);
            if ($tokenId && revokeToken($tokenId, $scrutin['id'])) {
                $tokenMessage = 'Jeton revoque avec succes.';
            } else {
                $tokenError = 'Impossible de revoquer ce jeton.';
            }
        }
    }
}

// Recuperer les stats des jetons si scrutin prive
$tokenStats = null;
$tokens = [];
if (!$scrutin['est_public'] && $isOwner) {
    $tokenStats = countTokens($scrutin['id']);
    $tokens = getTokensByScrutin($scrutin['id']);
}

// Verifier si des votes existent
$hasVotes = ($scrutin['nb_votes'] ?? 0) > 0;

$csrfToken = generateCsrfToken();

function getScrutinStatusInfo($scrutin) {
    $now = time();
    $debut = $scrutin['debut_at'] ? strtotime($scrutin['debut_at']) : null;
    $fin = $scrutin['fin_at'] ? strtotime($scrutin['fin_at']) : null;

    if ($scrutin['est_archive']) {
        return ['label' => 'Archivé', 'class' => 'status-archived', 'canVote' => false];
    }
    if ($fin && $now > $fin) {
        return ['label' => 'Terminé', 'class' => 'status-ended', 'canVote' => false];
    }
    if ($debut && $now < $debut) {
        return ['label' => 'Programmé', 'class' => 'status-scheduled', 'canVote' => false];
    }
    if (($debut === null || $now >= $debut) && ($fin === null || $now <= $fin)) {
        return ['label' => 'En cours', 'class' => 'status-active', 'canVote' => true];
    }
    return ['label' => 'Brouillon', 'class' => 'status-draft', 'canVote' => false];
}

$status = getScrutinStatusInfo($scrutin);

$typeLabels = [
    0 => 'Vote nuancé',
    1 => 'Réponse ouverte',
    2 => 'Séparateur',
    3 => 'Préféré du lot',
    4 => 'QCM'
];
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($scrutin['titre']); ?> - Vote Nuancé</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            background: #f5f5f5;
            min-height: 100vh;
            padding: 20px;
        }

        .container {
            max-width: 900px;
            margin: 0 auto;
        }

        .header {
            background: white;
            padding: 25px 30px;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            margin-bottom: 20px;
        }

        .header-top {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 15px;
        }

        .header h1 {
            color: #333;
            font-size: 24px;
            flex: 1;
        }

        .header-actions {
            display: flex;
            gap: 10px;
        }

        .btn {
            padding: 10px 20px;
            border: none;
            border-radius: 6px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
            display: inline-block;
        }

        .btn-primary {
            background: #667eea;
            color: white;
        }

        .btn-secondary {
            background: #6c757d;
            color: white;
        }

        .btn-success {
            background: #28a745;
            color: white;
        }

        .btn:hover {
            opacity: 0.9;
        }

        .status {
            padding: 6px 14px;
            border-radius: 20px;
            font-size: 13px;
            font-weight: 600;
        }

        .status-active { background: #28a745; color: white; }
        .status-scheduled { background: #17a2b8; color: white; }
        .status-ended { background: #6c757d; color: white; }
        .status-archived { background: #dee2e6; color: #495057; }
        .status-draft { background: #ffc107; color: #212529; }

        .header-meta {
            display: flex;
            gap: 25px;
            color: #666;
            font-size: 14px;
            flex-wrap: wrap;
        }

        .header-meta span {
            display: flex;
            align-items: center;
            gap: 5px;
        }

        .code-badge {
            font-family: monospace;
            background: #f8f9fa;
            padding: 2px 8px;
            border-radius: 4px;
        }

        .alert {
            padding: 15px 20px;
            border-radius: 8px;
            margin-bottom: 20px;
        }

        .alert-success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }

        .alert-info {
            background: #e7f3ff;
            color: #0c5460;
            border: 1px solid #b8daff;
        }

        .card {
            background: white;
            padding: 25px 30px;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            margin-bottom: 20px;
        }

        .card h2 {
            color: #333;
            font-size: 18px;
            margin-bottom: 15px;
            padding-bottom: 10px;
            border-bottom: 2px solid #667eea;
        }

        .description {
            color: #555;
            line-height: 1.6;
        }

        .notice {
            background: #fff3cd;
            border: 1px solid #ffc107;
            border-radius: 8px;
            padding: 15px 20px;
            margin-top: 15px;
        }

        .notice-title {
            font-weight: 600;
            color: #856404;
            margin-bottom: 5px;
        }

        .notice-content {
            color: #856404;
            line-height: 1.5;
        }

        .questions-list {
            margin-top: 10px;
        }

        .question-item {
            background: #f8f9fa;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 15px;
            border-left: 4px solid #667eea;
        }

        .question-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 10px;
        }

        .question-number {
            background: #667eea;
            color: white;
            width: 28px;
            height: 28px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            font-size: 14px;
            margin-right: 12px;
            flex-shrink: 0;
        }

        .question-title {
            font-weight: 600;
            color: #333;
            flex: 1;
        }

        .question-type {
            padding: 3px 10px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
            margin-left: 10px;
        }

        .type-0 { background: #667eea; color: white; }
        .type-1 { background: #17a2b8; color: white; }
        .type-2 { background: #6c757d; color: white; }
        .type-3 { background: #fd7e14; color: white; }
        .type-4 { background: #20c997; color: white; }

        .question-description {
            color: #666;
            font-size: 14px;
            margin-top: 8px;
            line-height: 1.5;
        }

        .question-meta {
            margin-top: 10px;
            font-size: 12px;
            color: #888;
        }

        .question-meta span {
            margin-right: 15px;
        }

        .mentions-preview {
            display: flex;
            gap: 6px;
            margin-top: 12px;
            flex-wrap: wrap;
        }

        .mention-chip {
            padding: 4px 10px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 500;
            color: white;
        }

        .reponses-list {
            margin-top: 10px;
            padding-left: 20px;
        }

        .reponses-list li {
            color: #555;
            margin-bottom: 4px;
        }

        .vote-section {
            text-align: center;
            padding: 30px;
        }

        .vote-section p {
            color: #666;
            margin-bottom: 20px;
        }

        .share-section {
            margin-top: 20px;
            padding-top: 20px;
            border-top: 1px solid #eee;
        }

        .share-section label {
            font-weight: 600;
            color: #333;
            display: block;
            margin-bottom: 8px;
        }

        .share-url {
            display: flex;
            gap: 10px;
        }

        .share-url input {
            flex: 1;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 6px;
            font-size: 14px;
        }

        .share-url button {
            padding: 10px 20px;
            background: #667eea;
            color: white;
            border: none;
            border-radius: 6px;
            cursor: pointer;
        }

        .back-link {
            display: inline-block;
            margin-bottom: 20px;
            color: #667eea;
            text-decoration: none;
        }

        .back-link:hover {
            text-decoration: underline;
        }

        /* Section jetons */
        .tokens-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
        }

        .tokens-stats {
            display: flex;
            gap: 20px;
        }

        .stat-box {
            text-align: center;
            padding: 10px 20px;
            background: #f8f9fa;
            border-radius: 8px;
        }

        .stat-value {
            font-size: 24px;
            font-weight: bold;
            color: #667eea;
        }

        .stat-label {
            font-size: 12px;
            color: #666;
        }

        .generate-form {
            display: flex;
            gap: 10px;
            align-items: center;
        }

        .generate-form input[type="number"] {
            width: 80px;
            padding: 8px 12px;
            border: 1px solid #ddd;
            border-radius: 6px;
            font-size: 14px;
        }

        .tokens-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
        }

        .tokens-table th,
        .tokens-table td {
            padding: 12px 15px;
            text-align: left;
            border-bottom: 1px solid #eee;
        }

        .tokens-table th {
            background: #f8f9fa;
            font-weight: 600;
            color: #333;
        }

        .tokens-table tr:hover {
            background: #fafafa;
        }

        .token-code {
            font-family: monospace;
            font-size: 14px;
            background: #f0f0f0;
            padding: 4px 8px;
            border-radius: 4px;
        }

        .token-status {
            padding: 4px 10px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 600;
        }

        .token-available {
            background: #d4edda;
            color: #155724;
        }

        .token-used {
            background: #f8d7da;
            color: #721c24;
        }

        .btn-small {
            padding: 5px 12px;
            font-size: 12px;
        }

        .btn-danger {
            background: #dc3545;
            color: white;
        }

        .generated-tokens {
            background: #e8f5e9;
            border: 1px solid #c8e6c9;
            border-radius: 8px;
            padding: 20px;
            margin: 20px 0;
        }

        .generated-tokens h3 {
            color: #2e7d32;
            margin-bottom: 15px;
        }

        .tokens-list {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            gap: 10px;
            margin-bottom: 15px;
        }

        .token-item {
            background: white;
            padding: 10px;
            border-radius: 6px;
            font-family: monospace;
            text-align: center;
        }

        .copy-all-btn {
            margin-top: 10px;
        }

        .alert-error {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }

        .no-tokens {
            text-align: center;
            color: #666;
            padding: 30px;
        }

        .token-link {
            font-size: 11px;
            color: #666;
            word-break: break-all;
        }
    </style>
</head>
<body>
    <div class="container">
        <?php if ($isOwner): ?>
        <a href="/mes-scrutins.php" class="back-link">← Retour à mes scrutins</a>
        <?php endif; ?>

        <?php if (isset($_GET['created'])): ?>
        <div class="alert alert-success">Votre scrutin a ete cree avec succes !</div>
        <?php endif; ?>

        <?php if (isset($_GET['error']) && $_GET['error'] === 'votes_exist'): ?>
        <div class="alert alert-info">Impossible de modifier ce scrutin : des votes ont deja ete enregistres.</div>
        <?php endif; ?>

        <div class="header">
            <div class="header-top">
                <h1><?php echo htmlspecialchars($scrutin['titre']); ?></h1>
                <span class="status <?php echo $status['class']; ?>"><?php echo $status['label']; ?></span>
            </div>
            <div class="header-meta">
                <span>Code : <span class="code-badge"><?php echo htmlspecialchars($scrutin['code']); ?></span></span>
                <span><?php echo count($questions); ?> question(s)</span>
                <?php if ($scrutin['nb_votes'] ?? 0): ?>
                <span><?php echo $scrutin['nb_votes']; ?> vote(s)</span>
                <?php endif; ?>
                <?php if ($scrutin['nb_gagnants'] > 1): ?>
                <span><?php echo $scrutin['nb_gagnants']; ?> gagnants</span>
                <?php endif; ?>
                <?php if ($scrutin['debut_at']): ?>
                <span>Debut : <?php echo date('d/m/Y H:i', strtotime($scrutin['debut_at'])); ?></span>
                <?php endif; ?>
                <?php if ($scrutin['fin_at']): ?>
                <span>Fin : <?php echo date('d/m/Y H:i', strtotime($scrutin['fin_at'])); ?></span>
                <?php endif; ?>
            </div>

            <?php if ($isOwner): ?>
            <div class="header-actions" style="margin-top: 20px; display: flex; gap: 10px; flex-wrap: wrap;">
                <?php if ($hasVotes): ?>
                <span class="btn btn-secondary" style="opacity: 0.5; cursor: not-allowed;" title="Impossible de modifier un scrutin avec des votes">Modifier</span>
                <?php else: ?>
                <a href="/<?php echo urlencode($scrutin['code']); ?>/s/" class="btn btn-secondary">Modifier</a>
                <?php endif; ?>
                <a href="/<?php echo urlencode($scrutin['code']); ?>/r/" class="btn btn-primary">Resultats</a>
            </div>
            <?php endif; ?>
        </div>

        <?php if ($scrutin['resume']): ?>
        <div class="card">
            <h2>Description</h2>
            <div class="description"><?php echo nl2br(htmlspecialchars($scrutin['resume'])); ?></div>
        </div>
        <?php endif; ?>

        <?php if ($scrutin['notice']): ?>
        <div class="notice">
            <div class="notice-title">Instructions</div>
            <div class="notice-content"><?php echo nl2br(htmlspecialchars($scrutin['notice'])); ?></div>
        </div>
        <?php endif; ?>

        <div class="card">
            <h2>Questions</h2>
            <div class="questions-list">
                <?php foreach ($questions as $i => $question): ?>
                <div class="question-item">
                    <div class="question-header">
                        <div style="display: flex; align-items: flex-start;">
                            <span class="question-number"><?php echo $i + 1; ?></span>
                            <span class="question-title"><?php echo htmlspecialchars($question['titre']); ?></span>
                        </div>
                        <span class="question-type type-<?php echo $question['type_question']; ?>">
                            <?php echo $typeLabels[$question['type_question']] ?? 'Inconnu'; ?>
                        </span>
                    </div>

                    <?php if ($question['question']): ?>
                    <div class="question-description"><?php echo nl2br(htmlspecialchars($question['question'])); ?></div>
                    <?php endif; ?>

                    <?php if ($question['type_question'] == 0): ?>
                    <div class="mentions-preview">
                        <?php foreach ($mentions as $mention): ?>
                        <span class="mention-chip" style="background: <?php echo $mention['couleur']; ?>;">
                            <?php echo htmlspecialchars($mention['code']); ?>
                        </span>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>

                    <?php if ($question['type_question'] == 4):
                        $reponses = getReponsesPossibles($question['id']);
                        if (!empty($reponses)):
                    ?>
                    <ul class="reponses-list">
                        <?php foreach ($reponses as $rep): ?>
                        <li><?php echo htmlspecialchars($rep['libelle']); ?></li>
                        <?php endforeach; ?>
                    </ul>
                    <?php endif; endif; ?>

                    <div class="question-meta">
                        <?php if ($question['est_obligatoire']): ?>
                        <span>Obligatoire</span>
                        <?php endif; ?>
                        <?php if ($question['lot'] > 0): ?>
                        <span>Lot <?php echo $question['lot']; ?></span>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <?php if ($status['canVote']): ?>
        <div class="card vote-section">
            <p>Ce scrutin est ouvert aux votes.</p>
            <?php
            $voteUrl = '/' . urlencode($scrutin['code']) . '/';
            if (!empty($_GET['jeton'])) {
                $voteUrl .= '?jeton=' . urlencode($_GET['jeton']);
            }
            ?>
            <a href="<?php echo htmlspecialchars($voteUrl); ?>" class="btn btn-success" style="font-size: 16px; padding: 15px 40px;">
                Participer au vote
            </a>
        </div>
        <?php endif; ?>

        <?php if ($isOwner && !$scrutin['est_public']): ?>
        <!-- Section gestion des jetons pour scrutins privés -->
        <div class="card">
            <h2>Jetons d'invitation</h2>

            <?php if ($tokenMessage): ?>
            <div class="alert alert-success"><?php echo htmlspecialchars($tokenMessage); ?></div>
            <?php endif; ?>

            <?php if ($tokenError): ?>
            <div class="alert alert-error"><?php echo htmlspecialchars($tokenError); ?></div>
            <?php endif; ?>

            <div class="tokens-header">
                <div class="tokens-stats">
                    <div class="stat-box">
                        <div class="stat-value"><?php echo $tokenStats['total']; ?></div>
                        <div class="stat-label">Total</div>
                    </div>
                    <div class="stat-box">
                        <div class="stat-value"><?php echo $tokenStats['utilises']; ?></div>
                        <div class="stat-label">Utilises</div>
                    </div>
                    <div class="stat-box">
                        <div class="stat-value"><?php echo $tokenStats['disponibles']; ?></div>
                        <div class="stat-label">Disponibles</div>
                    </div>
                </div>

                <form method="POST" class="generate-form">
                    <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                    <input type="hidden" name="token_action" value="generate">
                    <label>Generer</label>
                    <input type="number" name="token_count" value="10" min="1" max="500">
                    <button type="submit" class="btn btn-primary">jetons</button>
                </form>
            </div>

            <?php
            // Afficher les jetons nouvellement générés
            $generatedTokens = $_SESSION['generated_tokens'] ?? null;
            if ($generatedTokens):
                unset($_SESSION['generated_tokens']);
                $baseUrl = (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . '/' . $scrutin['code'] . '?jeton=';
            ?>
            <div class="generated-tokens">
                <h3>Jetons generes</h3>
                <div class="tokens-list">
                    <?php foreach ($generatedTokens as $token): ?>
                    <div class="token-item">
                        <?php echo htmlspecialchars($token); ?>
                    </div>
                    <?php endforeach; ?>
                </div>
                <button class="btn btn-secondary copy-all-btn" onclick="copyAllTokens()">Copier tous les liens</button>
                <button class="btn btn-primary copy-all-btn" onclick="exportTokensCsv()">Exporter CSV</button>

                <textarea id="all-tokens-urls" style="position: absolute; left: -9999px;"><?php
                    foreach ($generatedTokens as $token) {
                        echo $baseUrl . $token . "\n";
                    }
                ?></textarea>

                <script>
                var generatedTokens = <?php echo json_encode($generatedTokens); ?>;
                var baseUrl = <?php echo json_encode($baseUrl); ?>;
                </script>
            </div>
            <?php endif; ?>

            <?php if (empty($tokens)): ?>
            <div class="no-tokens">
                <p>Aucun jeton genere pour ce scrutin.</p>
                <p>Generez des jetons pour permettre aux participants de voter.</p>
            </div>
            <?php else: ?>

            <!-- Boutons d'export permanents -->
            <div style="margin: 20px 0; display: flex; gap: 10px;">
                <button class="btn btn-secondary" onclick="copyAllTokensFromTable()">Copier tous les liens</button>
                <button class="btn btn-primary" onclick="exportAllTokensCsv()">Exporter CSV</button>
            </div>

            <?php
            $baseUrl = (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . '/' . $scrutin['code'] . '?jeton=';
            ?>
            <script>
            var allTokens = <?php echo json_encode($tokens); ?>;
            var allTokensBaseUrl = <?php echo json_encode($baseUrl); ?>;
            </script>

            <table class="tokens-table">
                <thead>
                    <tr>
                        <th>Jeton</th>
                        <th>Lien</th>
                        <th>Statut</th>
                        <th>Date utilisation</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($tokens as $token): ?>
                    <tr>
                        <td><span class="token-code"><?php echo htmlspecialchars($token['code']); ?></span></td>
                        <td>
                            <span class="token-link"><?php echo htmlspecialchars($baseUrl . $token['code']); ?></span>
                        </td>
                        <td>
                            <?php if ($token['est_utilise']): ?>
                            <span class="token-status token-used">Utilise</span>
                            <?php else: ?>
                            <span class="token-status token-available">Disponible</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php echo $token['utilise_at'] ? date('d/m/Y H:i', strtotime($token['utilise_at'])) : '-'; ?>
                        </td>
                        <td>
                            <?php if (!$token['est_utilise']): ?>
                            <form method="POST" style="display: inline;" onsubmit="return confirm('Revoquer ce jeton ?');">
                                <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                                <input type="hidden" name="token_action" value="revoke">
                                <input type="hidden" name="token_id" value="<?php echo $token['id']; ?>">
                                <button type="submit" class="btn btn-danger btn-small">Revoquer</button>
                            </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <?php if ($isOwner): ?>
        <div class="card">
            <h2>Partage</h2>
            <div class="share-section" style="border-top: none; padding-top: 0; margin-top: 0;">
                <label>Lien de partage</label>
                <div class="share-url">
                    <input type="text" id="share-url" readonly value="<?php echo htmlspecialchars((isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . '/' . $scrutin['code']); ?>">
                    <button onclick="copyUrl()">Copier</button>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <script>
    function copyUrl() {
        const input = document.getElementById('share-url');
        input.select();
        document.execCommand('copy');
        alert('Lien copie !');
    }

    function copyAllTokens() {
        const textarea = document.getElementById('all-tokens-urls');
        if (textarea) {
            textarea.style.position = 'fixed';
            textarea.style.left = '0';
            textarea.select();
            document.execCommand('copy');
            textarea.style.position = 'absolute';
            textarea.style.left = '-9999px';
            alert('Tous les liens ont ete copies !');
        }
    }

    function exportTokensCsv() {
        if (typeof generatedTokens === 'undefined' || typeof baseUrl === 'undefined') {
            alert('Aucun jeton a exporter');
            return;
        }

        var csv = 'Jeton,Lien,Statut\n';
        generatedTokens.forEach(function(token) {
            csv += token + ',' + baseUrl + token + ',Disponible\n';
        });

        var blob = new Blob(['\ufeff' + csv], { type: 'text/csv;charset=utf-8;' });
        var link = document.createElement('a');
        var url = URL.createObjectURL(blob);
        link.setAttribute('href', url);
        link.setAttribute('download', 'jetons_<?php echo $scrutin['code']; ?>.csv');
        link.style.visibility = 'hidden';
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
    }

    // Export CSV de tous les jetons (avec statut)
    function exportAllTokensCsv() {
        if (typeof allTokens === 'undefined' || !allTokens.length) {
            alert('Aucun jeton a exporter');
            return;
        }

        var csv = 'Jeton,Lien,Statut,Date utilisation\n';
        allTokens.forEach(function(token) {
            var statut = token.est_utilise == 1 ? 'Utilise' : 'Disponible';
            var dateUtil = token.utilise_at || '';
            csv += token.code + ',' + allTokensBaseUrl + token.code + ',' + statut + ',' + dateUtil + '\n';
        });

        var blob = new Blob(['\ufeff' + csv], { type: 'text/csv;charset=utf-8;' });
        var link = document.createElement('a');
        var url = URL.createObjectURL(blob);
        link.setAttribute('href', url);
        link.setAttribute('download', 'jetons_<?php echo $scrutin['code']; ?>_complet.csv');
        link.style.visibility = 'hidden';
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
    }

    // Copier tous les liens depuis le tableau
    function copyAllTokensFromTable() {
        if (typeof allTokens === 'undefined' || !allTokens.length) {
            alert('Aucun jeton a copier');
            return;
        }

        var liens = allTokens.map(function(token) {
            return allTokensBaseUrl + token.code;
        }).join('\n');

        // Copier dans le presse-papier
        var textarea = document.createElement('textarea');
        textarea.value = liens;
        document.body.appendChild(textarea);
        textarea.select();
        document.execCommand('copy');
        document.body.removeChild(textarea);
        alert('Tous les liens ont ete copies !');
    }
    </script>
</body>
</html>
