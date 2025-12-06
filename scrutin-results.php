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

// Vérifier accès aux résultats
$now = time();
$fin = $scrutin['fin_at'] ? strtotime($scrutin['fin_at']) : null;
$isEnded = $fin && $now > $fin;

$user = null;
$isOwner = false;
if (isLoggedIn()) {
    $user = getCurrentUser();
    $isOwner = $user && $user['id'] == $scrutin['owner_id'];
}

// Résultats visibles si : scrutin terminé OU affiche_resultats OU propriétaire
if (!$isEnded && !$scrutin['affiche_resultats'] && !$isOwner) {
    header('Location: /' . urlencode($code) . '/v/');
    exit;
}

$questions = getQuestionsByScrutin($scrutin['id']);
$mentions = getMentionsByEchelle(1);

// Récupérer les résultats
function getResultsForQuestion($scrutinId, $questionId, $typeQuestion) {
    $pdo = getDbConnection();

    if ($typeQuestion == 0) {
        // Vote nuancé - comptage par mention
        $stmt = $pdo->prepare('
            SELECT vote_mention, COUNT(*) as count
            FROM bulletins
            WHERE scrutin_id = ? AND question_id = ? AND est_test = 0 AND vote_mention IS NOT NULL
            GROUP BY vote_mention
            ORDER BY vote_mention
        ');
        $stmt->execute([$scrutinId, $questionId]);
        $counts = [];
        foreach ($stmt->fetchAll() as $row) {
            $counts[$row['vote_mention']] = $row['count'];
        }

        // Calculer le score
        $pour = ($counts[7] ?? 0) + ($counts[6] ?? 0) + ($counts[5] ?? 0);
        $contre = ($counts[1] ?? 0) + ($counts[2] ?? 0) + ($counts[3] ?? 0);
        $score = $pour - $contre;
        $total = array_sum($counts);

        return [
            'type' => 'nuance',
            'counts' => $counts,
            'score' => $score,
            'pour' => $pour,
            'contre' => $contre,
            'neutre' => $counts[4] ?? 0,
            'total' => $total,
            'niveau1' => ($counts[7] ?? 0) - ($counts[1] ?? 0),
            'niveau2' => ($counts[6] ?? 0) - ($counts[2] ?? 0),
            'niveau3' => ($counts[5] ?? 0) - ($counts[3] ?? 0)
        ];
    } elseif ($typeQuestion == 1) {
        // Réponse ouverte - liste des réponses
        $stmt = $pdo->prepare('
            SELECT reponse
            FROM bulletins
            WHERE scrutin_id = ? AND question_id = ? AND est_test = 0 AND reponse IS NOT NULL AND reponse != ""
            ORDER BY vote_at
        ');
        $stmt->execute([$scrutinId, $questionId]);
        return [
            'type' => 'open',
            'responses' => $stmt->fetchAll(PDO::FETCH_COLUMN)
        ];
    } elseif ($typeQuestion == 4) {
        // QCM - comptage par réponse
        $stmt = $pdo->prepare('
            SELECT reponse, COUNT(*) as count
            FROM bulletins
            WHERE scrutin_id = ? AND question_id = ? AND est_test = 0 AND reponse IS NOT NULL
            GROUP BY reponse
            ORDER BY count DESC
        ');
        $stmt->execute([$scrutinId, $questionId]);
        $results = $stmt->fetchAll();
        $total = array_sum(array_column($results, 'count'));
        return [
            'type' => 'qcm',
            'results' => $results,
            'total' => $total
        ];
    }

    return null;
}

// Compter les participants
$pdo = getDbConnection();
$stmt = $pdo->prepare('SELECT COUNT(DISTINCT ballot_hash) FROM bulletins WHERE scrutin_id = ? AND est_test = 0');
$stmt->execute([$scrutin['id']]);
$nbParticipants = $stmt->fetchColumn();
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Résultats - <?php echo htmlspecialchars($scrutin['titre']); ?></title>
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
        }

        .header h1 {
            color: #333;
            font-size: 24px;
            margin-bottom: 10px;
        }

        .header-meta {
            display: flex;
            gap: 20px;
            color: #666;
            font-size: 14px;
            margin-top: 10px;
        }

        .status {
            padding: 6px 14px;
            border-radius: 20px;
            font-size: 13px;
            font-weight: 600;
        }

        .status-ended { background: #6c757d; color: white; }
        .status-active { background: #28a745; color: white; }

        .card {
            background: white;
            padding: 25px 30px;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            margin-bottom: 20px;
        }

        .result-card {
            border-left: 4px solid #667eea;
        }

        .question-header {
            display: flex;
            align-items: flex-start;
            margin-bottom: 20px;
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

        .question-title {
            font-size: 18px;
            font-weight: 600;
            color: #333;
        }

        /* Score nuancé */
        .score-display {
            text-align: center;
            padding: 20px;
            background: #f8f9fa;
            border-radius: 10px;
            margin-bottom: 20px;
        }

        .score-value {
            font-size: 48px;
            font-weight: bold;
        }

        .score-positive { color: #28a745; }
        .score-negative { color: #dc3545; }
        .score-neutral { color: #6c757d; }

        .score-label {
            color: #666;
            margin-top: 5px;
        }

        .score-breakdown {
            display: flex;
            justify-content: center;
            gap: 30px;
            margin-top: 15px;
            font-size: 14px;
        }

        .score-breakdown span {
            padding: 5px 15px;
            border-radius: 20px;
        }

        .pour-count { background: #d4edda; color: #155724; }
        .contre-count { background: #f8d7da; color: #721c24; }
        .neutre-count { background: #e9ecef; color: #495057; }

        /* Barres de mentions */
        .mentions-bars {
            margin-top: 20px;
        }

        .mention-bar {
            display: flex;
            align-items: center;
            margin-bottom: 10px;
        }

        .mention-label {
            width: 140px;
            font-size: 13px;
            font-weight: 500;
        }

        .mention-bar-container {
            flex: 1;
            height: 24px;
            background: #e9ecef;
            border-radius: 12px;
            overflow: hidden;
            margin: 0 10px;
        }

        .mention-bar-fill {
            height: 100%;
            border-radius: 12px;
            transition: width 0.5s ease;
        }

        .mention-count {
            width: 60px;
            text-align: right;
            font-size: 13px;
            color: #666;
        }

        /* QCM résultats */
        .qcm-result {
            margin-bottom: 12px;
        }

        .qcm-label {
            display: flex;
            justify-content: space-between;
            margin-bottom: 5px;
            font-size: 14px;
        }

        .qcm-bar-container {
            height: 20px;
            background: #e9ecef;
            border-radius: 10px;
            overflow: hidden;
        }

        .qcm-bar-fill {
            height: 100%;
            background: #667eea;
            border-radius: 10px;
        }

        /* Réponses ouvertes */
        .open-responses {
            max-height: 300px;
            overflow-y: auto;
        }

        .open-response-item {
            padding: 12px 15px;
            background: #f8f9fa;
            border-radius: 6px;
            margin-bottom: 8px;
            font-size: 14px;
            line-height: 1.5;
        }

        .no-responses {
            color: #666;
            font-style: italic;
            text-align: center;
            padding: 20px;
        }

        /* Navigation */
        .back-link {
            display: inline-block;
            margin-bottom: 20px;
            color: #667eea;
            text-decoration: none;
        }

        .back-link:hover {
            text-decoration: underline;
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
    </style>
</head>
<body>
    <div class="container">
        <a href="/<?php echo urlencode($code); ?>/v/" class="back-link">← Retour au scrutin</a>

        <div class="header">
            <div class="header-top">
                <div>
                    <h1>Résultats : <?php echo htmlspecialchars($scrutin['titre']); ?></h1>
                    <div class="header-meta">
                        <span><?php echo $nbParticipants; ?> participant(s)</span>
                        <?php if ($scrutin['fin_at']): ?>
                        <span>Clôture : <?php echo date('d/m/Y H:i', strtotime($scrutin['fin_at'])); ?></span>
                        <?php endif; ?>
                    </div>
                </div>
                <span class="status <?php echo $isEnded ? 'status-ended' : 'status-active'; ?>">
                    <?php echo $isEnded ? 'Terminé' : 'En cours'; ?>
                </span>
            </div>
        </div>

        <?php if ($nbParticipants == 0): ?>
        <div class="card">
            <p style="text-align: center; color: #666; padding: 40px;">
                Aucun vote n'a encore été enregistré.
            </p>
        </div>
        <?php else: ?>

        <?php
        $questionNum = 0;
        foreach ($questions as $question):
            if ($question['type_question'] == 2) continue; // Ignorer séparateurs
            $questionNum++;
            $results = getResultsForQuestion($scrutin['id'], $question['id'], $question['type_question']);
            if (!$results) continue;
        ?>

        <div class="card result-card">
            <div class="question-header">
                <span class="question-number"><?php echo $questionNum; ?></span>
                <span class="question-title"><?php echo htmlspecialchars($question['titre']); ?></span>
            </div>

            <?php if ($results['type'] === 'nuance'): ?>
            <!-- Résultats vote nuancé -->
            <div class="score-display">
                <div class="score-value <?php
                    echo $results['score'] > 0 ? 'score-positive' :
                        ($results['score'] < 0 ? 'score-negative' : 'score-neutral');
                ?>">
                    <?php echo ($results['score'] > 0 ? '+' : '') . $results['score']; ?>
                </div>
                <div class="score-label">Score (Pour - Contre)</div>
                <div class="score-breakdown">
                    <span class="pour-count"><?php echo $results['pour']; ?> Pour</span>
                    <span class="neutre-count"><?php echo $results['neutre']; ?> Sans avis</span>
                    <span class="contre-count"><?php echo $results['contre']; ?> Contre</span>
                </div>
            </div>

            <div class="mentions-bars">
                <?php foreach ($mentions as $mention):
                    $count = $results['counts'][$mention['rang']] ?? 0;
                    $percent = $results['total'] > 0 ? ($count / $results['total']) * 100 : 0;
                ?>
                <div class="mention-bar">
                    <span class="mention-label"><?php echo htmlspecialchars($mention['libelle']); ?></span>
                    <div class="mention-bar-container">
                        <div class="mention-bar-fill" style="width: <?php echo $percent; ?>%; background: <?php echo $mention['couleur']; ?>;"></div>
                    </div>
                    <span class="mention-count"><?php echo $count; ?> (<?php echo round($percent); ?>%)</span>
                </div>
                <?php endforeach; ?>
            </div>

            <?php elseif ($results['type'] === 'qcm'): ?>
            <!-- Résultats QCM -->
            <?php foreach ($results['results'] as $r):
                $percent = $results['total'] > 0 ? ($r['count'] / $results['total']) * 100 : 0;
            ?>
            <div class="qcm-result">
                <div class="qcm-label">
                    <span><?php echo htmlspecialchars($r['reponse']); ?></span>
                    <span><?php echo $r['count']; ?> (<?php echo round($percent); ?>%)</span>
                </div>
                <div class="qcm-bar-container">
                    <div class="qcm-bar-fill" style="width: <?php echo $percent; ?>%;"></div>
                </div>
            </div>
            <?php endforeach; ?>

            <?php elseif ($results['type'] === 'open'): ?>
            <!-- Réponses ouvertes -->
            <?php if (empty($results['responses'])): ?>
            <div class="no-responses">Aucune réponse</div>
            <?php else: ?>
            <div class="open-responses">
                <?php foreach ($results['responses'] as $response): ?>
                <div class="open-response-item"><?php echo nl2br(htmlspecialchars($response)); ?></div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <?php endif; ?>
        </div>

        <?php endforeach; ?>
        <?php endif; ?>

        <?php if ($isOwner): ?>
        <div class="card" style="text-align: center;">
            <a href="/<?php echo urlencode($code); ?>/s/" class="btn btn-primary">Modifier le scrutin</a>
        </div>
        <?php endif; ?>
    </div>
</body>
</html>
