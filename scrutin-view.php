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
    // TODO: vérifier si l'utilisateur a un jeton pour ce scrutin
}

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
    </style>
</head>
<body>
    <div class="container">
        <?php if ($isOwner): ?>
        <a href="/mes-scrutins.php" class="back-link">← Retour à mes scrutins</a>
        <?php endif; ?>

        <?php if (isset($_GET['created'])): ?>
        <div class="alert alert-success">Votre scrutin a été créé avec succès !</div>
        <?php endif; ?>

        <div class="header">
            <div class="header-top">
                <h1><?php echo htmlspecialchars($scrutin['titre']); ?></h1>
                <span class="status <?php echo $status['class']; ?>"><?php echo $status['label']; ?></span>
            </div>
            <div class="header-meta">
                <span>Code : <span class="code-badge"><?php echo htmlspecialchars($scrutin['code']); ?></span></span>
                <span><?php echo count($questions); ?> question(s)</span>
                <?php if ($scrutin['nb_gagnants'] > 1): ?>
                <span><?php echo $scrutin['nb_gagnants']; ?> gagnants</span>
                <?php endif; ?>
                <?php if ($scrutin['debut_at']): ?>
                <span>Début : <?php echo date('d/m/Y H:i', strtotime($scrutin['debut_at'])); ?></span>
                <?php endif; ?>
                <?php if ($scrutin['fin_at']): ?>
                <span>Fin : <?php echo date('d/m/Y H:i', strtotime($scrutin['fin_at'])); ?></span>
                <?php endif; ?>
            </div>

            <?php if ($isOwner): ?>
            <div class="header-actions" style="margin-top: 20px;">
                <a href="/<?php echo urlencode($scrutin['code']); ?>/s/" class="btn btn-secondary">Modifier</a>
                <a href="/<?php echo urlencode($scrutin['code']); ?>/r/" class="btn btn-primary">Résultats</a>
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
            <a href="/<?php echo urlencode($scrutin['code']); ?>/" class="btn btn-success" style="font-size: 16px; padding: 15px 40px;">
                Participer au vote
            </a>
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
        alert('Lien copié !');
    }
    </script>
</body>
</html>
