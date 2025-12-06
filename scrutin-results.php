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

// Inverser l'ordre si demandé
$ordreInverse = $scrutin['ordre_mentions'] ?? 0;

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
        $neutre = $counts[4] ?? 0;

        return [
            'type' => 'nuance',
            'counts' => $counts,
            'score' => $score,
            'pour' => $pour,
            'contre' => $contre,
            'neutre' => $neutre,
            'total' => $total,
            // Pour le graphe centré : hauteur au-dessus = pour + moitié SA, hauteur en-dessous = contre + moitié SA
            'hauteur_haut' => $pour + ($neutre / 2),
            'hauteur_bas' => $contre + ($neutre / 2),
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

// Collecter tous les résultats vote nuancé pour le graphe global
$nuanceResults = [];
foreach ($questions as $question) {
    if ($question['type_question'] == 0) {
        $results = getResultsForQuestion($scrutin['id'], $question['id'], 0);
        if ($results && $results['total'] > 0) {
            $results['titre'] = $question['titre'];
            $results['id'] = $question['id'];
            $nuanceResults[] = $results;
        }
    }
}

// Trier par score décroissant
usort($nuanceResults, function($a, $b) {
    if ($a['score'] !== $b['score']) return $b['score'] - $a['score'];
    if ($a['niveau1'] !== $b['niveau1']) return $b['niveau1'] - $a['niveau1'];
    if ($a['niveau2'] !== $b['niveau2']) return $b['niveau2'] - $a['niveau2'];
    return $b['niveau3'] - $a['niveau3'];
});

// Trouver les extremes pour calibrer le graphe
$maxHaut = 0;
$maxBas = 0;
foreach ($nuanceResults as $r) {
    if ($r['hauteur_haut'] > $maxHaut) $maxHaut = $r['hauteur_haut'];
    if ($r['hauteur_bas'] > $maxBas) $maxBas = $r['hauteur_bas'];
}
$totalHeight = $maxHaut + $maxBas;
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
            max-width: 1100px;
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

        .card h2 {
            color: #333;
            font-size: 18px;
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 2px solid #667eea;
        }

        /* Graphe en barres verticales centrées */
        .chart-container {
            padding: 20px 0;
        }

        .vertical-bars-chart {
            display: flex;
            align-items: stretch;
            justify-content: space-around;
            height: 400px;
            position: relative;
            padding: 0 10px;
        }

        .bar-column {
            display: flex;
            flex-direction: column;
            align-items: center;
            flex: 1;
            max-width: 120px;
            min-width: 60px;
            position: relative;
        }

        .bar-wrapper {
            flex: 1;
            width: 100%;
            display: flex;
            flex-direction: column;
            position: relative;
        }

        .bar-top {
            display: flex;
            flex-direction: column;
            justify-content: flex-end;
            align-items: center;
        }

        .bar-bottom {
            display: flex;
            flex-direction: column;
            justify-content: flex-start;
            align-items: center;
        }

        .bar-segment {
            width: 50px;
            min-height: 2px;
            position: relative;
            cursor: pointer;
            transition: opacity 0.2s;
        }

        .bar-segment:hover {
            opacity: 0.8;
        }

        .bar-label {
            font-size: 11px;
            color: #333;
            text-align: center;
            padding: 8px 4px 0;
            word-wrap: break-word;
            max-width: 100%;
            line-height: 1.2;
        }

        .bar-score {
            font-size: 13px;
            font-weight: bold;
            text-align: center;
            padding: 4px 0;
        }

        .bar-score.positive { color: #28a745; }
        .bar-score.negative { color: #dc3545; }
        .bar-score.neutral { color: #6c757d; }

        /* Ligne centrale (Sans Avis) */
        .center-line {
            position: absolute;
            left: 0;
            right: 0;
            height: 2px;
            background: #9E9E9E;
            z-index: 1;
        }

        /* Tooltip */
        .tooltip {
            position: absolute;
            background: rgba(0,0,0,0.85);
            color: white;
            padding: 10px 14px;
            border-radius: 6px;
            font-size: 13px;
            pointer-events: none;
            z-index: 100;
            white-space: nowrap;
            display: none;
        }

        .tooltip.visible {
            display: block;
        }

        .tooltip-title {
            font-weight: bold;
            margin-bottom: 6px;
            border-bottom: 1px solid rgba(255,255,255,0.3);
            padding-bottom: 4px;
        }

        .tooltip-row {
            display: flex;
            justify-content: space-between;
            gap: 15px;
            margin: 3px 0;
        }

        .tooltip-color {
            width: 12px;
            height: 12px;
            border-radius: 2px;
            display: inline-block;
            margin-right: 6px;
            vertical-align: middle;
        }

        /* Légende */
        .legend {
            display: flex;
            justify-content: center;
            flex-wrap: wrap;
            gap: 15px;
            margin-top: 20px;
            padding-top: 15px;
            border-top: 1px solid #eee;
        }

        .legend-item {
            display: flex;
            align-items: center;
            font-size: 12px;
            color: #555;
        }

        .legend-color {
            width: 16px;
            height: 16px;
            border-radius: 3px;
            margin-right: 6px;
        }

        /* Autres types de résultats */
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

        .participants-count {
            text-align: center;
            color: #666;
            font-size: 14px;
            margin-bottom: 15px;
        }

        @media (max-width: 600px) {
            .vertical-bars-chart {
                height: 300px;
            }
            .bar-segment {
                width: 35px;
            }
            .bar-label {
                font-size: 9px;
            }
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
                        <span><?php echo $nbParticipants; ?> votant<?php echo $nbParticipants > 1 ? 's' : ''; ?></span>
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

        <?php if (!empty($nuanceResults)): ?>
        <div class="card">
            <h2>Vote nuancé - Vue d'ensemble</h2>
            <div class="participants-count"><?php echo $nbParticipants; ?> votant<?php echo $nbParticipants > 1 ? 's' : ''; ?></div>

            <div class="chart-container">
                <div class="vertical-bars-chart" id="mainChart">
                    <?php
                    // Position de la ligne centrale (en % depuis le haut)
                    $centerLinePos = $totalHeight > 0 ? ($maxHaut / $totalHeight) * 100 : 50;
                    ?>
                    <div class="center-line" style="top: <?php echo $centerLinePos; ?>%;"></div>

                    <?php foreach ($nuanceResults as $idx => $r):
                        // Calculer les hauteurs en pixels relatifs
                        $topHeight = $totalHeight > 0 ? ($r['hauteur_haut'] / $totalHeight) * 100 : 0;
                        $bottomHeight = $totalHeight > 0 ? ($r['hauteur_bas'] / $totalHeight) * 100 : 0;

                        // Couleurs des mentions
                        $colors = [
                            7 => '#388E3C', // AP
                            6 => '#7CB342', // FP
                            5 => '#C0CA33', // PP
                            4 => '#9E9E9E', // SA
                            3 => '#FBC02D', // PC
                            2 => '#F57C00', // FC
                            1 => '#D32F2F'  // AC
                        ];

                        // Segments pour (ordre: AP en haut, puis FP, puis PP)
                        $pourSegments = $ordreInverse
                            ? [['rang' => 5, 'label' => 'PP'], ['rang' => 6, 'label' => 'FP'], ['rang' => 7, 'label' => 'AP']]
                            : [['rang' => 7, 'label' => 'AP'], ['rang' => 6, 'label' => 'FP'], ['rang' => 5, 'label' => 'PP']];

                        // Segments contre (ordre: PC en haut du bas, puis FC, puis AC)
                        $contreSegments = $ordreInverse
                            ? [['rang' => 3, 'label' => 'PC'], ['rang' => 2, 'label' => 'FC'], ['rang' => 1, 'label' => 'AC']]
                            : [['rang' => 3, 'label' => 'PC'], ['rang' => 2, 'label' => 'FC'], ['rang' => 1, 'label' => 'AC']];
                    ?>
                    <div class="bar-column" data-question="<?php echo $idx; ?>">
                        <div class="bar-wrapper">
                            <!-- Partie haute (Pour) -->
                            <div class="bar-top" style="height: <?php echo $centerLinePos; ?>%; justify-content: flex-end;">
                                <?php
                                // Calculer la hauteur totale de la partie pour
                                $pourTotal = $r['pour'] + ($r['neutre'] / 2);
                                foreach ($pourSegments as $seg):
                                    $count = $r['counts'][$seg['rang']] ?? 0;
                                    if ($seg['rang'] == 5) $count += $r['neutre'] / 2; // Ajouter moitié SA à PP
                                    $segHeight = $pourTotal > 0 ? ($count / $pourTotal) * $topHeight : 0;
                                    if ($segHeight < 0.5) continue;
                                ?>
                                <div class="bar-segment"
                                     style="height: <?php echo $segHeight; ?>%; background: <?php echo $colors[$seg['rang']]; ?>;"
                                     data-label="<?php echo $seg['label']; ?>"
                                     data-count="<?php echo $r['counts'][$seg['rang']] ?? 0; ?>"
                                     data-rang="<?php echo $seg['rang']; ?>">
                                </div>
                                <?php endforeach; ?>
                            </div>

                            <!-- Partie basse (Contre) -->
                            <div class="bar-bottom" style="height: <?php echo 100 - $centerLinePos; ?>%;">
                                <?php
                                $contreTotal = $r['contre'] + ($r['neutre'] / 2);
                                foreach ($contreSegments as $seg):
                                    $count = $r['counts'][$seg['rang']] ?? 0;
                                    if ($seg['rang'] == 3) $count += $r['neutre'] / 2; // Ajouter moitié SA à PC
                                    $segHeight = $contreTotal > 0 ? ($count / $contreTotal) * $bottomHeight : 0;
                                    if ($segHeight < 0.5) continue;
                                ?>
                                <div class="bar-segment"
                                     style="height: <?php echo $segHeight; ?>%; background: <?php echo $colors[$seg['rang']]; ?>;"
                                     data-label="<?php echo $seg['label']; ?>"
                                     data-count="<?php echo $r['counts'][$seg['rang']] ?? 0; ?>"
                                     data-rang="<?php echo $seg['rang']; ?>">
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <div class="bar-score <?php echo $r['score'] > 0 ? 'positive' : ($r['score'] < 0 ? 'negative' : 'neutral'); ?>">
                            <?php echo ($r['score'] > 0 ? '+' : '') . $r['score']; ?>
                        </div>
                        <div class="bar-label"><?php echo htmlspecialchars(mb_substr($r['titre'], 0, 40)); ?><?php echo mb_strlen($r['titre']) > 40 ? '...' : ''; ?></div>
                    </div>
                    <?php endforeach; ?>

                    <div class="tooltip" id="tooltip"></div>
                </div>

                <div class="legend">
                    <?php
                    $legendItems = $ordreInverse
                        ? [
                            ['color' => '#388E3C', 'label' => 'Absolument Pour'],
                            ['color' => '#7CB342', 'label' => 'Franchement Pour'],
                            ['color' => '#C0CA33', 'label' => 'Plutôt Pour'],
                            ['color' => '#9E9E9E', 'label' => 'Sans Avis'],
                            ['color' => '#FBC02D', 'label' => 'Plutôt Contre'],
                            ['color' => '#F57C00', 'label' => 'Franchement Contre'],
                            ['color' => '#D32F2F', 'label' => 'Absolument Contre']
                        ]
                        : [
                            ['color' => '#D32F2F', 'label' => 'Absolument Contre'],
                            ['color' => '#F57C00', 'label' => 'Franchement Contre'],
                            ['color' => '#FBC02D', 'label' => 'Plutôt Contre'],
                            ['color' => '#9E9E9E', 'label' => 'Sans Avis'],
                            ['color' => '#C0CA33', 'label' => 'Plutôt Pour'],
                            ['color' => '#7CB342', 'label' => 'Franchement Pour'],
                            ['color' => '#388E3C', 'label' => 'Absolument Pour']
                        ];
                    foreach ($legendItems as $item):
                    ?>
                    <div class="legend-item">
                        <div class="legend-color" style="background: <?php echo $item['color']; ?>;"></div>
                        <?php echo $item['label']; ?>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <?php
        // Autres types de questions (QCM, ouvertes)
        $questionNum = 0;
        foreach ($questions as $question):
            if ($question['type_question'] == 2) continue; // Ignorer séparateurs
            if ($question['type_question'] == 0) continue; // Déjà affiché dans le graphe
            $questionNum++;
            $results = getResultsForQuestion($scrutin['id'], $question['id'], $question['type_question']);
            if (!$results) continue;
        ?>

        <div class="card result-card">
            <div class="question-header">
                <span class="question-number"><?php echo $questionNum; ?></span>
                <span class="question-title"><?php echo htmlspecialchars($question['titre']); ?></span>
            </div>

            <?php if ($results['type'] === 'qcm'): ?>
            <!-- Résultats QCM -->
            <?php foreach ($results['results'] as $r):
                $percent = $results['total'] > 0 ? ($r['count'] / $results['total']) * 100 : 0;
            ?>
            <div class="qcm-result">
                <div class="qcm-label">
                    <span><?php echo htmlspecialchars($r['reponse']); ?></span>
                    <span><?php echo $r['count']; ?></span>
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

    <script>
    (function() {
        const chart = document.getElementById('mainChart');
        if (!chart) return;

        const tooltip = document.getElementById('tooltip');
        const data = <?php echo json_encode(array_map(function($r) {
            return [
                'titre' => $r['titre'],
                'counts' => $r['counts'],
                'pour' => $r['pour'],
                'contre' => $r['contre'],
                'neutre' => $r['neutre'],
                'score' => $r['score']
            ];
        }, $nuanceResults)); ?>;

        const mentionLabels = {
            1: 'Absolument Contre',
            2: 'Franchement Contre',
            3: 'Plutôt Contre',
            4: 'Sans Avis',
            5: 'Plutôt Pour',
            6: 'Franchement Pour',
            7: 'Absolument Pour'
        };

        const colors = {
            1: '#D32F2F',
            2: '#F57C00',
            3: '#FBC02D',
            4: '#9E9E9E',
            5: '#C0CA33',
            6: '#7CB342',
            7: '#388E3C'
        };

        chart.addEventListener('mousemove', function(e) {
            const column = e.target.closest('.bar-column');
            if (!column) {
                tooltip.classList.remove('visible');
                return;
            }

            const idx = parseInt(column.dataset.question);
            const d = data[idx];
            if (!d) return;

            let html = '<div class="tooltip-title">' + d.titre + '</div>';

            // Afficher les mentions dans l'ordre
            for (let rang = 7; rang >= 1; rang--) {
                const count = d.counts[rang] || 0;
                html += '<div class="tooltip-row">';
                html += '<span><span class="tooltip-color" style="background:' + colors[rang] + '"></span>' + mentionLabels[rang] + '</span>';
                html += '<span>' + count + '</span>';
                html += '</div>';
            }

            html += '<div class="tooltip-row" style="margin-top:8px;padding-top:8px;border-top:1px solid rgba(255,255,255,0.3);">';
            html += '<span><strong>Pour</strong></span><span>' + d.pour + '</span></div>';
            html += '<div class="tooltip-row"><span><strong>Contre</strong></span><span>' + d.contre + '</span></div>';
            html += '<div class="tooltip-row"><span><strong>Score</strong></span><span>' + (d.score > 0 ? '+' : '') + d.score + '</span></div>';

            tooltip.innerHTML = html;
            tooltip.classList.add('visible');

            // Position
            const rect = chart.getBoundingClientRect();
            let x = e.clientX - rect.left + 15;
            let y = e.clientY - rect.top - 10;

            if (x + tooltip.offsetWidth > rect.width) {
                x = e.clientX - rect.left - tooltip.offsetWidth - 15;
            }

            tooltip.style.left = x + 'px';
            tooltip.style.top = y + 'px';
        });

        chart.addEventListener('mouseleave', function() {
            tooltip.classList.remove('visible');
        });
    })();
    </script>
</body>
</html>
