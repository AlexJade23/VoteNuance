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

// Récupérer les mentions selon l'échelle du scrutin (3, 5 ou 7 mentions)
$nbMentions = $scrutin['nb_mentions'] ?? 7;
$mentions = getMentionsForScale($nbMentions);

// Mélanger aléatoirement les questions appartenant à un même lot
// Les questions avec lot=0 gardent leur position d'origine
// Les questions d'un même lot > 0 sont mélangées entre elles
$questions = shuffleQuestionsInLots($questions);

// Inverser l'ordre si demandé (1 = Pour vers Contre)
if ($scrutin['ordre_mentions'] ?? 0) {
    $mentions = array_reverse($mentions);
}

// Format de la page de vote : 1 = classique, 2 = compact mobile first
$formatVote = intval($scrutin['format_vote'] ?? 1);
$compactStyles = getMentionsCompactStyles($nbMentions);

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

// Gestion des jetons pour scrutins privés
$tokenCode = $_GET['jeton'] ?? $_POST['jeton'] ?? $_SESSION['vote_token_' . $scrutin['id']] ?? '';
$tokenInfo = null;
$tokenError = null;
$requiresToken = !$scrutin['est_public'];

if ($requiresToken) {
    // Vérifier si un jeton est fourni
    if (!empty($tokenCode)) {
        $tokenCheck = checkTokenAvailability($scrutin['id'], $tokenCode);
        if ($tokenCheck['valid']) {
            $tokenInfo = $tokenCheck['token'];
            // Stocker en session pour ne pas le repasser à chaque requête
            $_SESSION['vote_token_' . $scrutin['id']] = $tokenCode;
        } else {
            $tokenError = $tokenCheck['error'];
            // Effacer le token invalide de la session
            unset($_SESSION['vote_token_' . $scrutin['id']]);
        }
    }
    // Si pas de jeton valide et pas connecté, on affichera le formulaire de saisie
}

$errors = [];
$success = false;

// Traitement du vote
// Verifier : scrutin ouvert ET (scrutin public OU jeton valide)
$canSubmitVote = $canVote && (!$requiresToken || $tokenInfo);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $canSubmitVote) {
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
                if ($tokenInfo) {
                    markTokenAsUsed($tokenInfo['id']);
                    // Nettoyer la session
                    unset($_SESSION['vote_token_' . $scrutin['id']]);
                } elseif ($user) {
                    // Ancien comportement pour utilisateurs connectés sans jeton
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

                // Stocker le récapitulatif des votes pour l'affichage
                $votesSummary = [];
                foreach ($questions as $q) {
                    if ($q['type_question'] == 2) continue; // Séparateur

                    $voteValue = null;
                    if ($q['type_question'] == 0) {
                        // Vote nuancé - trouver le libellé de la mention
                        $mentionRang = intval($votes[$q['id']] ?? 0);
                        foreach ($mentions as $m) {
                            if ($m['rang'] == $mentionRang) {
                                $voteValue = $m['libelle'];
                                break;
                            }
                        }
                    } elseif ($q['type_question'] == 1) {
                        // Réponse ouverte
                        $voteValue = trim($reponses[$q['id']] ?? '') ?: '(vide)';
                    } elseif ($q['type_question'] == 3 || $q['type_question'] == 4) {
                        // QCM ou Préféré du lot
                        $voteValue = $reponses[$q['id']] ?? '(non répondu)';
                    }

                    if ($voteValue) {
                        $votesSummary[] = [
                            'question' => $q['titre'],
                            'reponse' => $voteValue
                        ];
                    }
                }
                $_SESSION['last_votes_summary'] = $votesSummary;
                $_SESSION['last_scrutin_titre'] = $scrutin['titre'];

            } catch (Exception $e) {
                $pdo->rollBack();
                $errors[] = 'Erreur lors de l\'enregistrement du vote : ' . $e->getMessage();
            }
        }
    }
}

$csrfToken = generateCsrfToken();

// Le format compact ne s'applique qu'au formulaire de vote (pas au jeton, récépissé, etc.)
$showVoteForm = (!$requiresToken || $tokenInfo) && $canVote && !$success;
$isCompact = $showVoteForm && $formatVote === 2;

if ($isCompact) {
    $saRang = null;
    $validRangs = [];
    foreach ($mentions as $m) {
        $validRangs[] = $m['rang'];
        if ($m['code'] === 'SA') {
            $saRang = $m['rang'];
        }
    }
    // Valeur initiale de chaque question : choix déjà saisi (si erreur de validation) sinon Sans Avis
    $compactValues = [];
    $nbAvis = 0;
    $nbVoteQuestions = 0;
    foreach ($questions as $q) {
        if ($q['type_question'] != 0) continue;
        $nbVoteQuestions++;
        $posted = intval($_POST['vote'][$q['id']] ?? 0);
        $compactValues[$q['id']] = in_array($posted, $validRangs, true) ? $posted : $saRang;
        if ($compactValues[$q['id']] !== $saRang) {
            $nbAvis++;
        }
    }
}

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
    <meta name="viewport" content="width=device-width, initial-scale=1.0<?php echo $isCompact ? ', viewport-fit=cover' : ''; ?>">
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
            display: flex;
            justify-content: center;
            flex-wrap: wrap;
            gap: 8px;
            margin-top: 15px;
        }

        .mention-option {
            width: 90px;
            height: 90px;
        }

        @media (max-width: 600px), (orientation: portrait) {
            .mentions-grid {
                flex-direction: column;
                align-items: stretch;
                gap: 6px;
            }

            .mention-option {
                width: 100%;
                height: auto;
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

        /* Images cliquables */
        .clickable-image {
            display: block;
            margin: 0 auto 15px auto;
            max-width: 100%;
            max-height: 200px;
            border-radius: 8px;
            cursor: pointer;
            transition: transform 0.2s, box-shadow 0.2s;
        }

        .clickable-image:hover {
            transform: scale(1.02);
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        }

        .question-image {
            max-height: 150px;
        }

        /* Prefere du lot */
        .prefere-options {
            display: flex;
            flex-direction: column;
            gap: 10px;
            margin-top: 15px;
        }

        .prefere-option {
            background: #f8f9fa;
            border-radius: 8px;
            transition: all 0.2s;
        }

        .prefere-option:hover {
            background: #e9ecef;
        }

        .prefere-label {
            display: flex;
            align-items: center;
            padding: 15px 20px;
            cursor: pointer;
            gap: 15px;
        }

        .prefere-label input[type="radio"] {
            width: 20px;
            height: 20px;
            accent-color: #667eea;
            cursor: pointer;
        }

        .prefere-text {
            font-size: 15px;
            color: #333;
        }

        .prefere-option:has(input:checked) {
            background: #667eea;
            color: white;
        }

        .prefere-option:has(input:checked) .prefere-text {
            color: white;
            font-weight: 600;
        }

        /* Lightbox */
        .lightbox-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0,0,0,0.9);
            z-index: 9999;
            justify-content: center;
            align-items: center;
            padding: 20px;
        }

        .lightbox-overlay.active {
            display: flex;
        }

        .lightbox-content {
            position: relative;
            max-width: 90vw;
            max-height: 90vh;
        }

        .lightbox-content img {
            max-width: 100%;
            max-height: 90vh;
            border-radius: 8px;
            box-shadow: 0 8px 32px rgba(0,0,0,0.5);
        }

        .lightbox-close {
            position: absolute;
            top: -15px;
            right: -15px;
            width: 40px;
            height: 40px;
            background: white;
            border: none;
            border-radius: 50%;
            font-size: 24px;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 2px 8px rgba(0,0,0,0.3);
            color: #333;
        }

        .lightbox-close:hover {
            background: #f0f0f0;
        }

        /* Formulaire de jeton */
        .token-form-card {
            text-align: center;
            max-width: 400px;
            margin: 0 auto;
        }

        .token-form-card h2 {
            color: #333;
            margin-bottom: 10px;
        }

        .token-form-card p {
            color: #666;
            margin-bottom: 20px;
        }

        .token-input-group {
            margin-bottom: 20px;
        }

        .token-input-group label {
            display: block;
            font-weight: 600;
            margin-bottom: 8px;
            color: #333;
        }

        .token-input-group input {
            width: 100%;
            padding: 15px;
            font-size: 18px;
            text-align: center;
            text-transform: uppercase;
            letter-spacing: 3px;
            border: 2px solid #ddd;
            border-radius: 8px;
            font-family: monospace;
        }

        .token-input-group input:focus {
            outline: none;
            border-color: #667eea;
        }

        .token-help {
            font-size: 13px;
            color: #888;
            margin-top: 20px;
        }

        /* Récépissé de vote */
        .vote-receipt {
            background: white;
            border: 2px solid #667eea;
            border-radius: 12px;
            overflow: hidden;
            max-width: 700px;
            margin: 0 auto;
        }

        .receipt-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 25px;
            text-align: center;
        }

        .receipt-header h1 {
            font-size: 24px;
            margin-bottom: 10px;
        }

        .receipt-scrutin {
            font-size: 18px;
            opacity: 0.9;
        }

        .receipt-date {
            font-size: 14px;
            opacity: 0.8;
            margin-top: 5px;
        }

        .receipt-body {
            padding: 25px;
        }

        .receipt-section h3 {
            color: #333;
            font-size: 16px;
            margin-bottom: 15px;
            padding-bottom: 8px;
            border-bottom: 2px solid #667eea;
        }

        .receipt-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 25px;
        }

        .receipt-table tr {
            border-bottom: 1px solid #eee;
        }

        .receipt-table td {
            padding: 10px 5px;
            vertical-align: top;
        }

        .receipt-question {
            font-weight: 500;
            color: #333;
            width: 60%;
        }

        .receipt-answer {
            color: #667eea;
            font-weight: 600;
            text-align: right;
        }

        .receipt-verification {
            display: flex;
            gap: 20px;
            align-items: flex-start;
            background: #f8f9fa;
            padding: 20px;
            border-radius: 8px;
        }

        .receipt-qr {
            flex-shrink: 0;
        }

        .receipt-qr img {
            width: 120px;
            height: 120px;
            border: 2px solid #ddd;
            border-radius: 8px;
        }

        .receipt-key {
            flex: 1;
        }

        .receipt-key h3 {
            border: none;
            padding-bottom: 0;
            margin-bottom: 8px;
        }

        .key-description {
            font-size: 13px;
            color: #666;
            margin-bottom: 10px;
            line-height: 1.4;
        }

        .key-value {
            font-family: monospace;
            font-size: 11px;
            background: white;
            padding: 10px;
            border-radius: 6px;
            word-break: break-all;
            border: 1px solid #ddd;
            color: #333;
        }

        .receipt-footer {
            background: #f8f9fa;
            padding: 15px;
            text-align: center;
            font-size: 12px;
            color: #888;
            border-top: 1px solid #eee;
        }

        /* Styles d'impression */
        @media print {
            body {
                background: white;
                padding: 0;
            }

            .no-print {
                display: none !important;
            }

            .container {
                max-width: 100%;
            }

            .header, .notice {
                display: none;
            }

            .vote-receipt {
                border: 1px solid #333;
                box-shadow: none;
                max-width: 100%;
            }

            .receipt-header {
                background: #667eea !important;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }

            @page {
                size: A4;
                margin: 15mm;
            }
        }
        /* ===== Format 2 : compact, mobile first ===== */
        body.v2 {
            padding: 0;
            background: #EEF2F6;
        }

        body.v2 .test-banner {
            position: static;
        }

        .v2-form {
            max-width: 720px;
            margin: 0 auto;
            min-height: 100vh;
            min-height: 100dvh;
            display: flex;
            flex-direction: column;
            background: #FFFFFF;
        }

        .v2-header {
            position: sticky;
            top: 0;
            z-index: 10;
            background: #FFFFFF;
            border-bottom: 1px solid #DDE3EA;
            padding: 10px 12px 8px;
            box-shadow: 0 2px 6px rgba(15, 23, 42, 0.06);
        }

        .v2-title-line {
            display: flex;
            justify-content: space-between;
            align-items: baseline;
            gap: 12px;
        }

        .v2-header h1 {
            font-size: 18px;
            color: #0F172A;
            line-height: 1.25;
        }

        .v2-counter {
            font-size: 14px;
            color: #5B6B7B;
            white-space: nowrap;
            font-variant-numeric: tabular-nums;
        }

        .v2-grid,
        .v2-legend {
            display: grid;
            grid-template-columns: repeat(var(--cols), minmax(0, 1fr));
            gap: 4px;
        }

        .v2-legend {
            margin-top: 8px;
            font-size: 10.5px;
            line-height: 1.15;
            text-align: center;
            color: #5B6B7B;
            align-items: stretch;
            overflow-wrap: anywhere;
            hyphens: auto;
        }

        .v2-legend span {
            display: flex;
            align-items: center;
            justify-content: center;
            height: 100%;
            min-height: 28px;
            padding: 3px 1px;
            border-radius: 6px;
            font-weight: 600;
        }

        /* Échelle à 7 mentions : colonnes étroites sur mobile */
        .v2-legend.v2-legend-dense {
            font-size: 9px;
        }

        .v2-list {
            flex: 1;
        }

        .v2-intro {
            padding: 16px 12px;
            color: #333;
            line-height: 1.5;
            border-bottom: 1px solid #DDE3EA;
        }

        .v2-intro .clickable-image {
            margin-bottom: 12px;
        }

        .v2-intro .notice,
        .v2-intro .error-box {
            margin: 12px 0 0;
        }

        .v2-row {
            padding: 10px 12px;
            background: #FFFFFF;
            border-bottom: 1px solid #DDE3EA;
        }

        .v2-row.v2-alt {
            background: #EEF2F6;
        }

        .v2-row-head {
            display: flex;
            justify-content: space-between;
            align-items: baseline;
            gap: 12px;
            margin-bottom: 6px;
        }

        .v2-nom-wrap {
            display: flex;
            align-items: center;
            gap: 8px;
            flex: 0 1 auto;
            min-width: 0;
        }

        .v2-thumb {
            width: 36px;
            height: 36px;
            object-fit: cover;
            border-radius: 6px;
            cursor: pointer;
            flex-shrink: 0;
            align-self: center;
        }

        .v2-nom {
            font-weight: 700;
            font-size: 16px;
            color: #0F172A;
        }

        .v2-nom .required {
            color: #dc3545;
        }

        /* Le parti occupe tout l'espace restant, texte justifié jusqu'à la dernière ligne */
        .v2-parti {
            flex: 1 1 0;
            min-width: 0;
            text-align: justify;
            text-align-last: justify;
            hyphens: auto;
            font-size: 13px;
            line-height: 1.3;
            color: #5B6B7B;
        }

        .v2-btn {
            height: 44px;
            min-width: 0;
            border: 2px solid #D5DCE4;
            border-radius: 8px;
            background: #FFFFFF;
            color: #5B6B7B;
            font: inherit;
            font-size: 18px;
            font-weight: 700;
            cursor: pointer;
            touch-action: manipulation;
        }

        .v2-btn[aria-pressed="true"] {
            border-color: #0F172A;
            background: var(--sel-bg);
            color: var(--sel-fg);
        }

        .v2-btn:focus-visible {
            outline: 3px solid #2563EB;
            outline-offset: 2px;
        }

        .v2-other {
            padding: 12px;
            border-bottom: 1px solid #DDE3EA;
        }

        .v2-other .question-card {
            margin-bottom: 0;
        }

        .v2-footer {
            position: sticky;
            bottom: 0;
            z-index: 10;
            background: #FFFFFF;
            border-top: 1px solid #DDE3EA;
            padding: 10px 12px;
            padding-bottom: calc(10px + env(safe-area-inset-bottom, 0px));
            box-shadow: 0 -2px 6px rgba(15, 23, 42, 0.06);
        }

        .v2-footer .btn {
            width: 100%;
            min-height: 48px;
            padding: 12px;
        }

        /* Desktop : colonne centrée, mêmes comportements */
        @media (min-width: 760px) {
            body.v2 {
                padding: 0 20px;
            }

            .v2-form {
                box-shadow: 0 0 0 1px #DDE3EA, 0 4px 24px rgba(15, 23, 42, 0.08);
            }

            .v2-header,
            .v2-intro,
            .v2-row,
            .v2-other,
            .v2-footer {
                padding-left: 24px;
                padding-right: 24px;
            }

            .v2-header h1 {
                font-size: 22px;
            }

            .v2-legend,
            .v2-legend.v2-legend-dense {
                font-size: 12px;
            }

            .v2-btn:hover:not([aria-pressed="true"]) {
                border-color: #94A3B8;
                color: #0F172A;
            }

            .v2-footer .btn {
                width: auto;
                min-width: 280px;
                display: block;
                margin: 0 auto;
            }
        }
        <?php echo getTestBannerCSS(); ?>
    </style>
</head>
<body<?php echo $isCompact ? ' class="v2"' : ''; ?>>
<?php echo renderTestBanner(); ?>
    <div class="<?php echo $isCompact ? 'v2-page' : 'container'; ?>">
        <?php if (!$isCompact): ?>
        <div class="header">
            <?php if (!empty($scrutin['image_url'])): ?>
            <img src="<?php echo htmlspecialchars($scrutin['image_url']); ?>" alt="" class="clickable-image" onclick="openLightbox(this.src)">
            <?php endif; ?>
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
        <?php endif; ?>

        <?php
        // Pour un scrutin privé, il faut un jeton valide (même si connecté)
        $canAccessVote = !$requiresToken || $tokenInfo;
        ?>

        <?php if ($requiresToken && !$tokenInfo): ?>
        <!-- Formulaire de saisie de jeton pour scrutin privé -->
        <div class="card token-form-card">
            <h2>Scrutin prive</h2>
            <p>Ce scrutin necessite un jeton d'invitation pour voter.</p>
            <?php if ($user): ?>
            <p style="font-size: 13px; color: #666; margin-bottom: 15px;">
                Vous etes connecte en tant que <strong><?php echo htmlspecialchars($user['display_name'] ?? 'utilisateur'); ?></strong>,
                mais un jeton est tout de meme requis pour ce scrutin.
            </p>
            <?php endif; ?>

            <?php if ($tokenError): ?>
            <div class="error-box">
                <?php echo htmlspecialchars($tokenError); ?>
            </div>
            <?php endif; ?>

            <form method="GET" action="/<?php echo urlencode($scrutin['code']); ?>/">
                <div class="token-input-group">
                    <label for="jeton">Entrez votre jeton :</label>
                    <input type="text"
                           id="jeton"
                           name="jeton"
                           placeholder="Ex: ABCD1234"
                           maxlength="32"
                           autocomplete="off"
                           autofocus
                           value="<?php echo htmlspecialchars($_GET['jeton'] ?? ''); ?>">
                </div>
                <button type="submit" class="btn btn-primary">Acceder au vote</button>
            </form>

            <p class="token-help">
                Le jeton vous a ete communique par l'organisateur du scrutin.
            </p>
        </div>

        <?php elseif (!$canVote): ?>
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
        <!-- Boutons d'action (masqués à l'impression) -->
        <div class="no-print" style="text-align: center; margin-bottom: 20px;">
            <button onclick="window.print()" class="btn btn-primary" style="margin-right: 10px;">
                Imprimer / Sauvegarder PDF
            </button>
            <?php if ($scrutin['affiche_resultats']): ?>
            <a href="/<?php echo urlencode($scrutin['code']); ?>/r/" class="btn btn-success">
                Voir les résultats
            </a>
            <?php endif; ?>
        </div>

        <!-- Récépissé de vote imprimable -->
        <div class="vote-receipt">
            <div class="receipt-header">
                <h1>Recepisse de vote</h1>
                <p class="receipt-scrutin"><?php echo htmlspecialchars($_SESSION['last_scrutin_titre'] ?? $scrutin['titre']); ?></p>
                <p class="receipt-date">Vote enregistre le <?php echo date('d/m/Y à H:i'); ?></p>
            </div>

            <div class="receipt-body">
                <div class="receipt-section">
                    <h3>Recapitulatif de vos choix</h3>
                    <table class="receipt-table">
                        <?php if (isset($_SESSION['last_votes_summary'])): ?>
                        <?php foreach ($_SESSION['last_votes_summary'] as $vote): ?>
                        <tr>
                            <td class="receipt-question"><?php echo htmlspecialchars($vote['question']); ?></td>
                            <td class="receipt-answer"><?php echo htmlspecialchars($vote['reponse']); ?></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </table>
                </div>

                <div class="receipt-verification">
                    <div class="receipt-qr">
                        <?php if (isset($_SESSION['last_ballot_secret'])): ?>
                        <?php $verifyUrl = 'https://app.decision-collective.fr/verify/' . $_SESSION['last_ballot_secret']; ?>
                        <img src="https://api.qrserver.com/v1/create-qr-code/?size=150x150&data=<?php echo urlencode($verifyUrl); ?>" alt="QR Code de verification">
                        <?php endif; ?>
                    </div>
                    <div class="receipt-key">
                        <h3>Cle de verification</h3>
                        <p class="key-description">Conservez ce recepisse. La cle ci-dessous vous permet de verifier que votre vote a bien ete comptabilise, sans reveler votre identite.</p>
                        <?php if (isset($_SESSION['last_ballot_secret'])): ?>
                        <div class="key-value"><?php echo htmlspecialchars($_SESSION['last_ballot_secret']); ?></div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="receipt-footer">
                <p>Vote Nuance - <?php echo htmlspecialchars($scrutin['code']); ?> - Ce document fait foi de votre participation</p>
            </div>
        </div>

        <?php elseif ($isCompact): ?>
        <!-- Format 2 : compact, mobile first -->
        <form method="POST" class="v2-form">
            <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
            <?php if ($tokenInfo): ?>
            <input type="hidden" name="jeton" value="<?php echo htmlspecialchars($tokenCode); ?>">
            <?php endif; ?>

            <header class="v2-header">
                <div class="v2-title-line">
                    <h1><?php echo htmlspecialchars($scrutin['titre']); ?></h1>
                    <?php if ($nbVoteQuestions > 0): ?>
                    <span class="v2-counter" id="v2-counter" aria-live="polite"><?php echo $nbAvis; ?> / <?php echo $nbVoteQuestions; ?> votes</span>
                    <?php endif; ?>
                </div>
                <?php if ($nbVoteQuestions > 0): ?>
                <div class="v2-legend<?php echo count($mentions) > 5 ? ' v2-legend-dense' : ''; ?>" style="--cols: <?php echo count($mentions); ?>;" aria-hidden="true">
                    <?php foreach ($mentions as $mention): ?>
                    <?php $style = $compactStyles[$mention['code']]; ?>
                    <span style="background: <?php echo $style['fond']; ?>; color: <?php echo $style['texte']; ?>;"><?php echo htmlspecialchars($mention['libelle']); ?></span>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </header>

            <div class="v2-list">
                <?php if (!empty($scrutin['image_url']) || $scrutin['resume'] || $scrutin['notice'] || !empty($errors)): ?>
                <div class="v2-intro">
                    <?php if (!empty($scrutin['image_url'])): ?>
                    <img src="<?php echo htmlspecialchars($scrutin['image_url']); ?>" alt="" class="clickable-image" onclick="openLightbox(this.src)">
                    <?php endif; ?>
                    <?php if ($scrutin['resume']): ?>
                    <p><?php echo nl2br(htmlspecialchars($scrutin['resume'])); ?></p>
                    <?php endif; ?>
                    <?php if ($scrutin['notice']): ?>
                    <div class="notice"><?php echo nl2br(htmlspecialchars($scrutin['notice'])); ?></div>
                    <?php endif; ?>
                    <?php if (!empty($errors)): ?>
                    <div class="error-box" role="alert">
                        <ul>
                            <?php foreach ($errors as $error): ?>
                            <li><?php echo htmlspecialchars($error); ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

                <?php $rowIndex = 0; ?>
                <?php foreach ($questions as $i => $question): ?>
                <?php if ($question['type_question'] == 0): ?>
                <?php $qid = $question['id']; ?>
                <div class="v2-row<?php echo ($rowIndex++ % 2) ? ' v2-alt' : ''; ?>" role="group" aria-labelledby="v2-nom-<?php echo $qid; ?>">
                    <div class="v2-row-head">
                        <span class="v2-nom-wrap">
                            <?php if (!empty($question['image_url'])): ?>
                            <img src="<?php echo htmlspecialchars($question['image_url']); ?>" alt="" class="v2-thumb" onclick="openLightbox(this.src)">
                            <?php endif; ?>
                            <span class="v2-nom" id="v2-nom-<?php echo $qid; ?>"><?php echo htmlspecialchars($question['titre']); ?><?php if ($question['est_obligatoire']): ?><span class="required">*</span><?php endif; ?></span>
                        </span>
                        <?php if ($question['question']): ?>
                        <span class="v2-parti"><?php echo nl2br(htmlspecialchars($question['question'])); ?></span>
                        <?php endif; ?>
                    </div>
                    <input type="hidden" class="v2-value" name="vote[<?php echo $qid; ?>]"
                           value="<?php echo $compactValues[$qid]; ?>" data-sa="<?php echo $saRang; ?>">
                    <div class="v2-grid" style="--cols: <?php echo count($mentions); ?>;">
                        <?php foreach ($mentions as $mention): ?>
                        <?php $style = $compactStyles[$mention['code']]; ?>
                        <button type="button" class="v2-btn"
                                data-value="<?php echo $mention['rang']; ?>"
                                aria-pressed="<?php echo ($compactValues[$qid] === $mention['rang']) ? 'true' : 'false'; ?>"
                                aria-label="<?php echo htmlspecialchars($question['titre'] . ' : ' . $mention['libelle']); ?>"
                                style="--sel-bg: <?php echo $style['fond']; ?>; --sel-fg: <?php echo $style['texte']; ?>;"><?php echo $style['court']; ?></button>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php else: ?>
                <div class="v2-other">
                    <?php include __DIR__ . '/vote-question.inc.php'; ?>
                </div>
                <?php endif; ?>
                <?php endforeach; ?>
            </div>

            <div class="v2-footer">
                <button type="submit" class="btn btn-success">Valider mon vote</button>
            </div>
        </form>

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
            <?php if ($tokenInfo): ?>
            <input type="hidden" name="jeton" value="<?php echo htmlspecialchars($tokenCode); ?>">
            <?php endif; ?>

            <?php foreach ($questions as $i => $question): ?>

            <?php if ($question['type_question'] == 0): ?>
            <!-- Vote nuancé -->
            <div class="question-card">
                <?php if (!empty($question['image_url'])): ?>
                <img src="<?php echo htmlspecialchars($question['image_url']); ?>" alt="" class="clickable-image question-image" onclick="openLightbox(this.src)">
                <?php endif; ?>
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
                               value="<?php echo $mention['rang']; ?>"
                               <?php echo ($mention['code'] == 'SA') ? 'checked' : ''; ?>>
                        <label for="q<?php echo $question['id']; ?>_m<?php echo $mention['rang']; ?>"
                               style="background: <?php echo $mention['couleur']; ?>;">
                            <?php echo htmlspecialchars($mention['libelle']); ?>
                        </label>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <?php else: ?>
            <?php include __DIR__ . '/vote-question.inc.php'; ?>
            <?php endif; ?>

            <?php endforeach; ?>

            <div class="form-actions">
                <button type="submit" class="btn btn-success">Valider mon vote</button>
            </div>
        </form>
        <?php endif; ?>
    </div>

    <!-- Lightbox -->
    <div class="lightbox-overlay" id="lightbox" onclick="closeLightbox(event)">
        <div class="lightbox-content">
            <img src="" alt="" id="lightbox-img">
            <button class="lightbox-close" onclick="closeLightbox(event)">×</button>
        </div>
    </div>

    <script>
    function openLightbox(src) {
        const lightbox = document.getElementById('lightbox');
        const img = document.getElementById('lightbox-img');
        img.src = src;
        lightbox.classList.add('active');
        document.body.style.overflow = 'hidden';
    }

    function closeLightbox(e) {
        if (e.target.classList.contains('lightbox-overlay') || e.target.classList.contains('lightbox-close')) {
            const lightbox = document.getElementById('lightbox');
            lightbox.classList.remove('active');
            document.body.style.overflow = '';
        }
    }

    // Format 2 : sélection d'une mention par bouton et compteur de votes
    document.querySelectorAll('.v2-row').forEach(function(row) {
        const input = row.querySelector('.v2-value');
        const buttons = row.querySelectorAll('.v2-btn');
        buttons.forEach(function(btn) {
            btn.addEventListener('click', function() {
                input.value = btn.dataset.value;
                buttons.forEach(function(b) {
                    b.setAttribute('aria-pressed', b === btn ? 'true' : 'false');
                });
                updateV2Counter();
            });
        });
    });

    function updateV2Counter() {
        const counter = document.getElementById('v2-counter');
        if (!counter) return;
        const inputs = document.querySelectorAll('.v2-value');
        let nbAvis = 0;
        inputs.forEach(function(input) {
            if (input.value !== input.dataset.sa) nbAvis++;
        });
        counter.textContent = nbAvis + ' / ' + inputs.length + ' votes';
    }

    // Fermer avec Echap
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            const lightbox = document.getElementById('lightbox');
            lightbox.classList.remove('active');
            document.body.style.overflow = '';
        }
    });
    </script>
</body>
</html>
