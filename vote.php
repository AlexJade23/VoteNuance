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

// Inverser l'ordre si demandé (1 = Pour vers Contre)
if ($scrutin['ordre_mentions'] ?? 0) {
    $mentions = array_reverse($mentions);
}

// Vérifier si le scrutin est ouvert
$now = time();
$debut = $scrutin['debut_at'] ? strtotime($scrutin['debut_at']) : null;
$fin = $scrutin['fin_at'] ? strtotime($scrutin['fin_at']) : null;

$canVote = true;
$message = '';

if ($scrutin['est_archive']) {
    $canVote = false;
    $message = 'Ce scrutin est archivé.';
} elseif ($fin && $now > $fin) {
    $canVote = false;
    $message = 'Ce scrutin est terminé.';
} elseif ($debut && $now < $debut) {
    $canVote = false;
    $message = 'Ce scrutin n\'a pas encore commencé. Début : ' . date('d/m/Y à H:i', $debut);
}

// Vérifier accès (scrutin non public)
$user = null;
if (isLoggedIn()) {
    $user = getCurrentUser();
}

if (!$scrutin['est_public'] && !$user) {
    header('Location: login.php?redirect=' . urlencode($_SERVER['REQUEST_URI']));
    exit;
}

$errors = [];
$success = false;

// Traitement du vote
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $canVote) {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Token de sécurité invalide';
    } else {
        // Générer une clé secrète pour ce bulletin
        $ballotSecret = bin2hex(random_bytes(32));
        $ballotHash = hash('sha256', $ballotSecret);

        $votes = $_POST['vote'] ?? [];
        $reponses = $_POST['reponse'] ?? [];

        // Validation des questions obligatoires
        foreach ($questions as $q) {
            if ($q['est_obligatoire'] && $q['type_question'] != 2) {
                if ($q['type_question'] == 0 && empty($votes[$q['id']])) {
                    $errors[] = 'La question "' . $q['titre'] . '" est obligatoire';
                } elseif ($q['type_question'] == 1 && empty(trim($reponses[$q['id']] ?? ''))) {
                    $errors[] = 'La question "' . $q['titre'] . '" est obligatoire';
                } elseif ($q['type_question'] == 4 && empty($reponses[$q['id']])) {
                    $errors[] = 'La question "' . $q['titre'] . '" est obligatoire';
                }
            }
        }

        if (empty($errors)) {
            try {
                $pdo = getDbConnection();
                $pdo->beginTransaction();

                // Enregistrer les bulletins
                foreach ($questions as $q) {
                    if ($q['type_question'] == 2) continue; // Séparateur

                    $voteMention = null;
                    $reponseText = null;

                    if ($q['type_question'] == 0) {
                        // Vote nuancé
                        $voteMention = intval($votes[$q['id']] ?? 0) ?: null;
                    } elseif ($q['type_question'] == 1) {
                        // Réponse ouverte
                        $reponseText = trim($reponses[$q['id']] ?? '') ?: null;
                    } elseif ($q['type_question'] == 4) {
                        // QCM
                        $reponseText = $reponses[$q['id']] ?? null;
                    } elseif ($q['type_question'] == 3) {
                        // Préféré du lot
                        $reponseText = $reponses[$q['id']] ?? null;
                    }

                    if ($voteMention !== null || $reponseText !== null) {
                        $stmt = $pdo->prepare('
                            INSERT INTO bulletins (scrutin_id, question_id, ballot_hash, vote_mention, reponse)
                            VALUES (?, ?, ?, ?, ?)
                        ');
                        $stmt->execute([
                            $scrutin['id'],
                            $q['id'],
                            $ballotHash,
                            $voteMention,
                            $reponseText
                        ]);
                    }
                }

                // Enregistrer l'émargement
                $ipHash = hash('sha256', $_SERVER['REMOTE_ADDR'] ?? '');
                $stmt = $pdo->prepare('INSERT INTO emargements (scrutin_id, ip_hash) VALUES (?, ?)');
                $stmt->execute([$scrutin['id'], $ipHash]);

                // Marquer le jeton comme utilisé si applicable
                if ($user) {
                    $stmt = $pdo->prepare('
                        UPDATE jetons SET est_utilise = 1, utilise_at = NOW()
                        WHERE scrutin_id = ? AND user_id = ? AND est_utilise = 0
                    ');
                    $stmt->execute([$scrutin['id'], $user['id']]);
                }

                $pdo->commit();
                $success = true;

                // Stocker la clé secrète en session pour affichage
                $_SESSION['last_ballot_secret'] = $ballotSecret;
                $_SESSION['last_ballot_scrutin'] = $scrutin['code'];

            } catch (Exception $e) {
                $pdo->rollBack();
                $errors[] = 'Erreur lors de l\'enregistrement du vote : ' . $e->getMessage();
            }
        }
    }
}

$csrfToken = generateCsrfToken();

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
    <title><?php echo htmlspecialchars($scrutin['titre']); ?> - Voter</title>
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
            max-width: 800px;
            margin: 0 auto;
        }

        .header {
            background: white;
            padding: 25px 30px;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            margin-bottom: 20px;
            text-align: center;
        }

        .header h1 {
            color: #333;
            font-size: 24px;
            margin-bottom: 10px;
        }

        .header p {
            color: #666;
            line-height: 1.5;
        }

        .notice {
            background: #fff3cd;
            border: 1px solid #ffc107;
            border-radius: 8px;
            padding: 15px 20px;
            margin-bottom: 20px;
            color: #856404;
        }

        .card {
            background: white;
            padding: 25px 30px;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            margin-bottom: 20px;
        }

        .error-box {
            background: #f8d7da;
            color: #721c24;
            padding: 15px;
            border-radius: 6px;
            margin-bottom: 20px;
            border: 1px solid #f5c6cb;
        }

        .error-box ul {
            margin: 0;
            padding-left: 20px;
        }

        .success-box {
            background: #d4edda;
            color: #155724;
            padding: 25px;
            border-radius: 12px;
            margin-bottom: 20px;
            border: 1px solid #c3e6cb;
            text-align: center;
        }

        .success-box h2 {
            margin-bottom: 15px;
        }

        .ballot-secret {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 8px;
            margin: 15px 0;
            font-family: monospace;
            word-break: break-all;
            font-size: 12px;
        }

        .ballot-secret-label {
            font-weight: 600;
            margin-bottom: 5px;
            display: block;
        }

        .closed-box {
            background: #e9ecef;
            padding: 40px;
            border-radius: 12px;
            text-align: center;
            color: #495057;
        }

        .closed-box h2 {
            margin-bottom: 10px;
        }

        .question-card {
            background: #f8f9fa;
            border-radius: 10px;
            padding: 25px;
            margin-bottom: 20px;
            border-left: 4px solid #667eea;
        }

        .question-header {
            display: flex;
            align-items: flex-start;
            margin-bottom: 15px;
        }

        .question-number {
            background: #667eea;
            color: white;
            width: 32px;
            height: 32px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            margin-right: 15px;
            flex-shrink: 0;
        }

        .question-content {
            flex: 1;
        }

        .question-title {
            font-size: 18px;
            font-weight: 600;
            color: #333;
            margin-bottom: 5px;
        }

        .question-title .required {
            color: #dc3545;
        }

        .question-description {
            color: #666;
            font-size: 14px;
            line-height: 1.5;
        }

        /* Vote nuancé */
        .mentions-grid {
            display: grid;
            grid-template-columns: repeat(7, 1fr);
            gap: 8px;
            margin-top: 15px;
        }

        @media (max-width: 600px), (orientation: portrait) {
            .mentions-grid {
                grid-template-columns: 1fr;
                gap: 6px;
            }
        }

        .mention-option {
            aspect-ratio: 1 / 1;
        }

        @media (max-width: 600px), (orientation: portrait) {
            .mention-option {
                aspect-ratio: auto;
            }
        }

        .mention-option input {
            display: none;
        }

        .mention-option label {
            display: flex;
            align-items: center;
            justify-content: center;
            width: 100%;
            height: 100%;
            min-height: 60px;
            text-align: center;
            border-radius: 8px;
            cursor: pointer;
            border: 3px solid transparent;
            transition: all 0.2s;
            font-size: 11px;
            font-weight: 600;
            color: white;
            padding: 8px 4px;
            line-height: 1.2;
        }

        @media (max-width: 600px), (orientation: portrait) {
            .mention-option label {
                min-height: 50px;
                font-size: 14px;
                padding: 12px 15px;
            }
        }

        .mention-option input:checked + label {
            border-color: #000;
            box-shadow: 0 4px 12px rgba(0,0,0,0.4);
            transform: scale(1.03);
        }

        .mention-option label:hover {
            opacity: 0.9;
        }

        /* Réponse ouverte */
        .open-response textarea {
            width: 100%;
            padding: 12px;
            border: 1px solid #ddd;
            border-radius: 6px;
            font-size: 14px;
            resize: vertical;
            min-height: 100px;
            margin-top: 15px;
        }

        .open-response textarea:focus {
            outline: none;
            border-color: #667eea;
        }

        /* QCM */
        .qcm-options {
            margin-top: 15px;
        }

        .qcm-option {
            margin-bottom: 10px;
        }

        .qcm-option label {
            display: flex;
            align-items: center;
            padding: 12px 15px;
            background: white;
            border: 1px solid #ddd;
            border-radius: 6px;
            cursor: pointer;
            transition: all 0.2s;
        }

        .qcm-option label:hover {
            border-color: #667eea;
            background: #f8f9ff;
        }

        .qcm-option input {
            margin-right: 12px;
        }

        .qcm-option input:checked + span {
            font-weight: 600;
            color: #667eea;
        }

        /* Séparateur */
        .separator {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-left: none;
            text-align: center;
            padding: 20px;
        }

        .separator .question-title {
            color: white;
            font-size: 20px;
        }

        /* Boutons */
        .btn {
            padding: 15px 40px;
            border: none;
            border-radius: 8px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
            display: inline-block;
        }

        .btn-primary {
            background: #667eea;
            color: white;
        }

        .btn-success {
            background: #28a745;
            color: white;
        }

        .btn:hover {
            opacity: 0.9;
        }

        .form-actions {
            text-align: center;
            margin-top: 30px;
        }

        .results-link {
            margin-top: 20px;
        }

        .results-link a {
            color: #667eea;
            text-decoration: none;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1><?php echo htmlspecialchars($scrutin['titre']); ?></h1>
            <?php if ($scrutin['resume']): ?>
            <p><?php echo nl2br(htmlspecialchars($scrutin['resume'])); ?></p>
            <?php endif; ?>
        </div>

        <?php if ($scrutin['notice']): ?>
        <div class="notice">
            <?php echo nl2br(htmlspecialchars($scrutin['notice'])); ?>
        </div>
        <?php endif; ?>

        <?php if (!$canVote): ?>
        <div class="closed-box">
            <h2>Vote non disponible</h2>
            <p><?php echo htmlspecialchars($message); ?></p>
            <?php if ($scrutin['affiche_resultats'] || ($fin && $now > $fin)): ?>
            <div class="results-link">
                <a href="/<?php echo urlencode($scrutin['code']); ?>/r/">Voir les résultats</a>
            </div>
            <?php endif; ?>
        </div>

        <?php elseif ($success): ?>
        <div class="success-box">
            <h2>Merci pour votre vote !</h2>
            <p>Votre participation a été enregistrée avec succès.</p>

            <?php if (isset($_SESSION['last_ballot_secret'])): ?>
            <div class="ballot-secret">
                <span class="ballot-secret-label">Votre clé de vérification (conservez-la) :</span>
                <?php echo htmlspecialchars($_SESSION['last_ballot_secret']); ?>
            </div>
            <p style="font-size: 13px; color: #666;">
                Cette clé vous permet de vérifier que votre vote a bien été comptabilisé.<br>
                Elle ne permet pas de vous identifier.
            </p>
            <?php endif; ?>

            <?php if ($scrutin['affiche_resultats']): ?>
            <div class="results-link">
                <a href="/<?php echo urlencode($scrutin['code']); ?>/r/" class="btn btn-primary" style="margin-top: 20px;">
                    Voir les résultats
                </a>
            </div>
            <?php endif; ?>
        </div>

        <?php else: ?>

        <?php if (!empty($errors)): ?>
        <div class="error-box">
            <ul>
                <?php foreach ($errors as $error): ?>
                    <li><?php echo htmlspecialchars($error); ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php endif; ?>

        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">

            <?php foreach ($questions as $i => $question): ?>

            <?php if ($question['type_question'] == 2): ?>
            <!-- Séparateur -->
            <div class="question-card separator">
                <div class="question-title"><?php echo htmlspecialchars($question['titre']); ?></div>
            </div>

            <?php elseif ($question['type_question'] == 0): ?>
            <!-- Vote nuancé -->
            <div class="question-card">
                <div class="question-header">
                    <span class="question-number"><?php echo $i + 1; ?></span>
                    <div class="question-content">
                        <div class="question-title">
                            <?php echo htmlspecialchars($question['titre']); ?>
                            <?php if ($question['est_obligatoire']): ?><span class="required">*</span><?php endif; ?>
                        </div>
                        <?php if ($question['question']): ?>
                        <div class="question-description"><?php echo nl2br(htmlspecialchars($question['question'])); ?></div>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="mentions-grid">
                    <?php foreach ($mentions as $mention): ?>
                    <div class="mention-option">
                        <input type="radio" name="vote[<?php echo $question['id']; ?>]"
                               id="q<?php echo $question['id']; ?>_m<?php echo $mention['rang']; ?>"
                               value="<?php echo $mention['rang']; ?>">
                        <label for="q<?php echo $question['id']; ?>_m<?php echo $mention['rang']; ?>"
                               style="background: <?php echo $mention['couleur']; ?>;">
                            <?php echo htmlspecialchars($mention['libelle']); ?>
                        </label>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <?php elseif ($question['type_question'] == 1): ?>
            <!-- Réponse ouverte -->
            <div class="question-card">
                <div class="question-header">
                    <span class="question-number"><?php echo $i + 1; ?></span>
                    <div class="question-content">
                        <div class="question-title">
                            <?php echo htmlspecialchars($question['titre']); ?>
                            <?php if ($question['est_obligatoire']): ?><span class="required">*</span><?php endif; ?>
                        </div>
                        <?php if ($question['question']): ?>
                        <div class="question-description"><?php echo nl2br(htmlspecialchars($question['question'])); ?></div>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="open-response">
                    <textarea name="reponse[<?php echo $question['id']; ?>]"
                              placeholder="Votre réponse..."><?php echo htmlspecialchars($_POST['reponse'][$question['id']] ?? ''); ?></textarea>
                </div>
            </div>

            <?php elseif ($question['type_question'] == 4): ?>
            <!-- QCM -->
            <?php $reponsesPossibles = getReponsesPossibles($question['id']); ?>
            <div class="question-card">
                <div class="question-header">
                    <span class="question-number"><?php echo $i + 1; ?></span>
                    <div class="question-content">
                        <div class="question-title">
                            <?php echo htmlspecialchars($question['titre']); ?>
                            <?php if ($question['est_obligatoire']): ?><span class="required">*</span><?php endif; ?>
                        </div>
                        <?php if ($question['question']): ?>
                        <div class="question-description"><?php echo nl2br(htmlspecialchars($question['question'])); ?></div>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="qcm-options">
                    <?php foreach ($reponsesPossibles as $rep): ?>
                    <div class="qcm-option">
                        <label>
                            <input type="radio" name="reponse[<?php echo $question['id']; ?>]"
                                   value="<?php echo htmlspecialchars($rep['libelle']); ?>">
                            <span><?php echo htmlspecialchars($rep['libelle']); ?></span>
                        </label>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <?php elseif ($question['type_question'] == 3): ?>
            <!-- Préféré du lot (simplifié comme QCM pour l'instant) -->
            <div class="question-card">
                <div class="question-header">
                    <span class="question-number"><?php echo $i + 1; ?></span>
                    <div class="question-content">
                        <div class="question-title">
                            <?php echo htmlspecialchars($question['titre']); ?>
                            <?php if ($question['est_obligatoire']): ?><span class="required">*</span><?php endif; ?>
                        </div>
                        <?php if ($question['question']): ?>
                        <div class="question-description"><?php echo nl2br(htmlspecialchars($question['question'])); ?></div>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="open-response">
                    <textarea name="reponse[<?php echo $question['id']; ?>]"
                              placeholder="Votre choix préféré..."><?php echo htmlspecialchars($_POST['reponse'][$question['id']] ?? ''); ?></textarea>
                </div>
            </div>
            <?php endif; ?>

            <?php endforeach; ?>

            <div class="form-actions">
                <button type="submit" class="btn btn-success">Valider mon vote</button>
            </div>
        </form>
        <?php endif; ?>
    </div>
</body>
</html>
