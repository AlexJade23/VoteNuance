<?php
/**
 * Export PDF des resultats
 * Cette page genere une version imprimable optimisee pour l'export PDF via le navigateur
 */
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

// Verifier acces aux resultats
$now = time();
$fin = $scrutin['fin_at'] ? strtotime($scrutin['fin_at']) : null;
$isEnded = $fin && $now > $fin;

$user = null;
$isOwner = false;
if (isLoggedIn()) {
    $user = getCurrentUser();
    $isOwner = $user && $user['id'] == $scrutin['owner_id'];
}

// Resultats visibles si : scrutin termine OU affiche_resultats OU proprietaire
if (!$isEnded && !$scrutin['affiche_resultats'] && !$isOwner) {
    header('Location: /' . urlencode($code) . '/v/');
    exit;
}

$questions = getQuestionsByScrutin($scrutin['id']);
$mentions = getMentionsByEchelle(1);

// Recuperer les resultats pour une question
function getResultsForQuestion($scrutinId, $questionId, $typeQuestion) {
    $pdo = getDbConnection();

    if ($typeQuestion == 0) {
        // Vote nuance - comptage par mention
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
            $counts[$row['vote_mention']] = (int)$row['count'];
        }

        $ac = $counts[1] ?? 0;
        $fc = $counts[2] ?? 0;
        $pc = $counts[3] ?? 0;
        $sa = $counts[4] ?? 0;
        $pp = $counts[5] ?? 0;
        $fp = $counts[6] ?? 0;
        $ap = $counts[7] ?? 0;

        $classement = $ap + $fp + $pp + ($sa / 2);
        $niveau1 = $ap - $ac;
        $niveau2 = $fp - $fc;
        $niveau3 = $pp - $pc;

        $pour = $ap + $fp + $pp;
        $contre = $ac + $fc + $pc;
        $total = array_sum($counts);

        $tauxPartisans = $total > 0 ? round(($pour / $total) * 100, 1) : 0;
        $tauxOpposants = $total > 0 ? round(($contre / $total) * 100, 1) : 0;
        $tauxPartisansNet = $total > 0 ? round((($pour - $contre) / $total) * 100, 1) : 0;

        return [
            'type' => 'nuance',
            'counts' => $counts,
            'classement' => $classement,
            'niveau1' => $niveau1,
            'niveau2' => $niveau2,
            'niveau3' => $niveau3,
            'pour' => $pour,
            'contre' => $contre,
            'neutre' => $sa,
            'total' => $total,
            'tauxPartisans' => $tauxPartisans,
            'tauxOpposants' => $tauxOpposants,
            'tauxPartisansNet' => $tauxPartisansNet
        ];
    } elseif ($typeQuestion == 1) {
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
    } elseif ($typeQuestion == 3) {
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
            'type' => 'prefere',
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

// Collecter tous les resultats vote nuance GROUPÉS PAR LOT
$resultsByLot = [];
foreach ($questions as $question) {
    if ($question['type_question'] == 0) {
        $results = getResultsForQuestion($scrutin['id'], $question['id'], 0);
        if ($results && $results['total'] > 0) {
            $results['titre'] = $question['titre'];
            $results['id'] = $question['id'];
            $results['ordre'] = $question['ordre'];
            $results['lot'] = intval($question['lot'] ?? 0);

            $lot = $results['lot'];
            if (!isset($resultsByLot[$lot])) {
                $resultsByLot[$lot] = [];
            }
            $resultsByLot[$lot][] = $results;
        }
    }
}

// Trier les lots par numéro
ksort($resultsByLot);

// Fonction pour trier par classement avec départage
function sortByClassement($results) {
    usort($results, function($a, $b) {
        // 1. Trier par classement décroissant
        $cmp = $b['classement'] <=> $a['classement'];
        if ($cmp !== 0) return $cmp;

        // 2. Départage niveau 1 : AP - AC (avis absolus)
        $cmp = $b['niveau1'] <=> $a['niveau1'];
        if ($cmp !== 0) return $cmp;

        // 3. Départage niveau 2 : FP - FC (avis francs)
        $cmp = $b['niveau2'] <=> $a['niveau2'];
        if ($cmp !== 0) return $cmp;

        // 4. Départage niveau 3 : PP - PC (avis normaux)
        return $b['niveau3'] <=> $a['niveau3'];
    });
    return $results;
}

// Fonction pour trier par ordre initial
function sortByOrdre($results) {
    usort($results, function($a, $b) {
        return $a['ordre'] - $b['ordre'];
    });
    return $results;
}

// Fonction pour trouver le classement minimum
function findClassementMini($results) {
    $mini = PHP_FLOAT_MAX;
    foreach ($results as $r) {
        if ($r['classement'] < $mini) {
            $mini = $r['classement'];
        }
    }
    return $mini == PHP_FLOAT_MAX ? 0 : $mini;
}

// Fonction pour calculer les rangs avec ex aequo (1,2,3,3,5,6...)
function calculateRanks($results) {
    $ranks = [];
    $prevResult = null;
    $currentRank = 0;

    foreach ($results as $idx => $r) {
        $position = $idx + 1;

        if ($prevResult === null) {
            $currentRank = 1;
        } elseif ($r['classement'] == $prevResult['classement']
                  && $r['niveau1'] == $prevResult['niveau1']
                  && $r['niveau2'] == $prevResult['niveau2']
                  && $r['niveau3'] == $prevResult['niveau3']) {
            // Ex aequo : garder le même rang
        } else {
            $currentRank = $position;
        }

        $ranks[] = $currentRank;
        $prevResult = $r;
    }

    return $ranks;
}

// Construire les series pour Chart.js
function buildChartDatasets($results, $classementMini, $mentions) {
    if (empty($results)) return [];

    $decalages = [];
    foreach ($results as $r) {
        $decalages[] = round($r['classement'] - $classementMini, 1);
    }

    $datasets = [
        [
            'label' => ' ',
            'backgroundColor' => 'white',
            'borderColor' => 'white',
            'borderWidth' => 0,
            'data' => $decalages
        ]
    ];

    foreach ($mentions as $mention) {
        $rang = (int)$mention['rang'];
        $data = [];
        foreach ($results as $r) {
            $data[] = $r['counts'][$rang] ?? 0;
        }
        $datasets[] = [
            'label' => $mention['libelle'],
            'backgroundColor' => $mention['couleur'],
            'borderColor' => 'white',
            'borderWidth' => 1,
            'data' => $data
        ];
    }

    return $datasets;
}

// Préparer les données pour chaque lot
$lotsData = [];
foreach ($resultsByLot as $lotNum => $results) {
    $classement = sortByClassement($results);
    $ordre = sortByOrdre($results);
    $classementMini = findClassementMini($classement);
    $ranks = calculateRanks($classement);

    $labelsClassement = [];
    foreach ($classement as $idx => $r) {
        $labelsClassement[] = ($idx + 1);
    }

    $labelsOrdre = [];
    foreach ($ordre as $idx => $r) {
        $labelsOrdre[] = ($idx + 1);
    }

    $lotsData[$lotNum] = [
        'classement' => $classement,
        'ordre' => $ordre,
        'classementMini' => $classementMini,
        'ranks' => $ranks,
        'labelsClassement' => $labelsClassement,
        'labelsOrdre' => $labelsOrdre,
        'datasetsClassement' => buildChartDatasets($classement, $classementMini, $mentions),
        'datasetsOrdre' => buildChartDatasets($ordre, $classementMini, $mentions),
        'showOrdre' => ($lotNum == 0) // Afficher l'ordre initial seulement pour lot 0
    ];
}

$dateExport = date('d/m/Y H:i');
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Resultats - <?php echo htmlspecialchars($scrutin['titre']); ?></title>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        /* Reset et base */
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: "Helvetica Neue", Arial, sans-serif;
            font-size: 11pt;
            line-height: 1.4;
            color: #333;
            padding: 20mm;
        }

        /* Styles d'impression */
        @media print {
            body { padding: 0; }
            .no-print { display: none !important; }
            .page-break { page-break-after: always; }
            @page {
                size: A4 landscape;
                margin: 10mm;
            }
        }

        /* Header document */
        .doc-header {
            border-bottom: 3px solid #667eea;
            padding-bottom: 15px;
            margin-bottom: 20px;
        }
        .doc-title {
            font-size: 20pt;
            font-weight: bold;
            color: #333;
            margin-bottom: 5px;
        }
        .doc-subtitle {
            font-size: 14pt;
            color: #667eea;
            margin-bottom: 10px;
        }
        .doc-meta {
            font-size: 10pt;
            color: #666;
        }
        .doc-meta span { margin-right: 20px; }

        /* Sections */
        .section {
            margin-bottom: 25px;
        }
        .section-title {
            font-size: 14pt;
            font-weight: bold;
            color: #667eea;
            border-bottom: 1px solid #ddd;
            padding-bottom: 5px;
            margin-bottom: 15px;
        }

        /* Tableau resultats Vote Nuance */
        .results-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 9pt;
            margin-bottom: 15px;
        }
        .results-table th, .results-table td {
            border: 1px solid #ddd;
            padding: 6px 8px;
            text-align: center;
        }
        .results-table th {
            background: #f5f5f5;
            font-weight: bold;
            font-size: 8pt;
        }
        .results-table td:first-child {
            text-align: left;
            font-weight: 500;
        }
        .results-table .rank {
            width: 30px;
            text-align: center;
            font-weight: bold;
            background: #667eea;
            color: white;
        }
        .results-table .question-cell {
            max-width: 200px;
        }
        .results-table .score-positive { color: #28a745; font-weight: bold; }
        .results-table .score-negative { color: #dc3545; font-weight: bold; }

        /* Legende mentions */
        .legend {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin-bottom: 15px;
            font-size: 9pt;
        }
        .legend-item {
            display: flex;
            align-items: center;
            gap: 5px;
        }
        .legend-color {
            width: 12px;
            height: 12px;
            border-radius: 2px;
        }

        /* Barres visuelles */
        .bar-container {
            display: flex;
            height: 16px;
            border-radius: 3px;
            overflow: hidden;
            background: #f0f0f0;
        }
        .bar-segment {
            height: 100%;
        }

        /* Autres resultats */
        .other-result {
            margin-bottom: 20px;
            padding: 12px;
            background: #fafafa;
            border-radius: 4px;
            border-left: 3px solid #667eea;
        }
        .other-result h4 {
            font-size: 11pt;
            margin-bottom: 10px;
        }
        .qcm-row {
            display: flex;
            justify-content: space-between;
            padding: 4px 0;
            border-bottom: 1px solid #eee;
        }
        .qcm-row:last-child { border-bottom: none; }
        .open-response {
            padding: 6px 10px;
            background: white;
            border-radius: 3px;
            margin-bottom: 5px;
            font-size: 10pt;
        }

        /* Footer */
        .doc-footer {
            margin-top: 30px;
            padding-top: 15px;
            border-top: 1px solid #ddd;
            font-size: 9pt;
            color: #999;
            text-align: center;
        }

        /* Bouton imprimer (ecran uniquement) */
        .print-btn {
            position: fixed;
            top: 20px;
            right: 20px;
            padding: 12px 24px;
            background: #667eea;
            color: white;
            border: none;
            border-radius: 6px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            box-shadow: 0 2px 8px rgba(0,0,0,0.2);
        }
        .print-btn:hover {
            background: #5a6fd6;
        }
        .back-link {
            position: fixed;
            top: 20px;
            left: 20px;
            color: #667eea;
            text-decoration: none;
            font-size: 14px;
        }
        .back-link:hover { text-decoration: underline; }

        /* Graphiques */
        .chart-section {
            margin-bottom: 25px;
            page-break-inside: avoid;
        }
        .chart-wrapper {
            width: 100%;
            height: 450px;
            position: relative;
            margin-bottom: 10px;
        }
        .chart-wrapper canvas {
            max-width: 100%;
        }
        .chart-legend {
            font-size: 9pt;
            color: #555;
            line-height: 1.6;
        }
        .chart-legend span {
            margin-right: 5px;
        }
    </style>
</head>
<body>
    <a href="/<?php echo urlencode($code); ?>/r/" class="back-link no-print">&larr; Retour aux resultats</a>
    <button onclick="window.print()" class="print-btn no-print">Imprimer / Exporter PDF</button>

    <div class="doc-header">
        <div class="doc-subtitle">Resultats du scrutin</div>
        <div class="doc-title"><?php echo htmlspecialchars($scrutin['titre']); ?></div>
        <div class="doc-meta">
            <span><strong>Participants :</strong> <?php echo $nbParticipants; ?></span>
            <?php if ($scrutin['fin_at']): ?>
            <span><strong>Cloture :</strong> <?php echo date('d/m/Y H:i', strtotime($scrutin['fin_at'])); ?></span>
            <?php endif; ?>
            <span><strong>Export :</strong> <?php echo $dateExport; ?></span>
        </div>
    </div>

    <?php if ($nbParticipants == 0): ?>
    <p style="text-align: center; padding: 40px; color: #666;">Aucun vote n'a encore ete enregistre.</p>
    <?php else: ?>

    <?php foreach ($lotsData as $lotNum => $lotData): ?>
    <?php if (!empty($lotData['classement'])): ?>
    <div class="section">
        <h2 class="section-title"><?php echo $lotNum > 0 ? "Lot $lotNum - " : ''; ?>Resultats Vote Nuance - Classement</h2>

        <div class="legend">
            <?php foreach ($mentions as $m): ?>
            <div class="legend-item">
                <div class="legend-color" style="background: <?php echo htmlspecialchars($m['couleur']); ?>"></div>
                <span><?php echo htmlspecialchars($m['libelle']); ?></span>
            </div>
            <?php endforeach; ?>
        </div>

        <table class="results-table">
            <thead>
                <tr>
                    <th class="rank">#</th>
                    <th class="question-cell">Question</th>
                    <th>Classement</th>
                    <th>AC</th>
                    <th>FC</th>
                    <th>PC</th>
                    <th>SA</th>
                    <th>PP</th>
                    <th>FP</th>
                    <th>AP</th>
                    <th>AP-AC</th>
                    <th>FP-FC</th>
                    <th>PP-PC</th>
                    <th>Total</th>
                    <th>Taux Net</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($lotData['classement'] as $idx => $r): ?>
                <tr>
                    <td class="rank"><?php echo $lotData['ranks'][$idx]; ?></td>
                    <td class="question-cell"><?php echo htmlspecialchars($r['titre']); ?></td>
                    <td><strong><?php echo number_format($r['classement'], 1); ?></strong></td>
                    <td><?php echo $r['counts'][1] ?? 0; ?></td>
                    <td><?php echo $r['counts'][2] ?? 0; ?></td>
                    <td><?php echo $r['counts'][3] ?? 0; ?></td>
                    <td><?php echo $r['counts'][4] ?? 0; ?></td>
                    <td><?php echo $r['counts'][5] ?? 0; ?></td>
                    <td><?php echo $r['counts'][6] ?? 0; ?></td>
                    <td><?php echo $r['counts'][7] ?? 0; ?></td>
                    <td class="<?php echo $r['niveau1'] >= 0 ? 'score-positive' : 'score-negative'; ?>"><?php echo ($r['niveau1'] >= 0 ? '+' : '') . $r['niveau1']; ?></td>
                    <td class="<?php echo $r['niveau2'] >= 0 ? 'score-positive' : 'score-negative'; ?>"><?php echo ($r['niveau2'] >= 0 ? '+' : '') . $r['niveau2']; ?></td>
                    <td class="<?php echo $r['niveau3'] >= 0 ? 'score-positive' : 'score-negative'; ?>"><?php echo ($r['niveau3'] >= 0 ? '+' : '') . $r['niveau3']; ?></td>
                    <td><?php echo $r['total']; ?></td>
                    <td class="<?php echo $r['tauxPartisansNet'] >= 0 ? 'score-positive' : 'score-negative'; ?>">
                        <?php echo ($r['tauxPartisansNet'] >= 0 ? '+' : '') . $r['tauxPartisansNet']; ?>%
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <p style="font-size: 9pt; color: #666;">
            <strong>Legende :</strong> AC = Absolument Contre, FC = Franchement Contre, PC = Plutot Contre,
            SA = Sans Avis, PP = Plutot Pour, FP = Franchement Pour, AP = Absolument Pour.<br>
            <strong>Classement</strong> = AP + FP + PP + SA/2. <strong>Taux Net</strong> = (Pour - Contre) / Total.<br>
            <strong>Departage ex aequo :</strong> AP-AC (avis absolus), puis FP-FC (avis francs), puis PP-PC (avis normaux).
        </p>
    </div>
    <div class="page-break"></div>

    <?php if ($lotData['showOrdre']): ?>
    <!-- Graphique ordre initial (seulement pour lot 0) -->
    <div class="section chart-section">
        <h2 class="section-title">Graphique - Ordre initial des questions</h2>
        <div class="chart-wrapper">
            <canvas id="chartOrdre_<?php echo $lotNum; ?>"></canvas>
        </div>
        <div class="chart-legend">
            <?php foreach ($lotData['ordre'] as $idx => $r): ?>
            <span><?php echo ($idx + 1); ?>. <?php echo htmlspecialchars($r['titre']); ?> |</span>
            <?php endforeach; ?>
        </div>
    </div>
    <div class="page-break"></div>
    <?php endif; ?>

    <!-- Graphique classement -->
    <div class="section chart-section">
        <h2 class="section-title"><?php echo $lotNum > 0 ? "Lot $lotNum - " : ''; ?>Graphique - Classement (1er a gauche)</h2>
        <div class="chart-wrapper">
            <canvas id="chartClassement_<?php echo $lotNum; ?>"></canvas>
        </div>
        <div class="chart-legend">
            <?php foreach ($lotData['classement'] as $idx => $r): ?>
            <span><?php echo ($idx + 1); ?>. <?php echo htmlspecialchars($r['titre']); ?> |</span>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>
    <?php endforeach; ?>

    <?php
    // Autres types de questions
    $hasOtherQuestions = false;
    foreach ($questions as $q) {
        if ($q['type_question'] != 0 && $q['type_question'] != 2) {
            $hasOtherQuestions = true;
            break;
        }
    }

    if ($hasOtherQuestions):
    ?>
    <div class="section">
        <h2 class="section-title">Autres questions</h2>

        <?php
        $questionNum = 0;
        foreach ($questions as $question):
            if ($question['type_question'] == 2) continue; // Separateur
            if ($question['type_question'] == 0) continue; // Deja affiche
            $questionNum++;
            $results = getResultsForQuestion($scrutin['id'], $question['id'], $question['type_question']);
            if (!$results) continue;
        ?>

        <div class="other-result">
            <h4><?php echo $questionNum; ?>. <?php echo htmlspecialchars($question['titre']); ?></h4>

            <?php if ($results['type'] === 'qcm'): ?>
                <?php foreach ($results['results'] as $r):
                    $percent = $results['total'] > 0 ? round(($r['count'] / $results['total']) * 100, 1) : 0;
                ?>
                <div class="qcm-row">
                    <span><?php echo htmlspecialchars($r['reponse']); ?></span>
                    <span><strong><?php echo $r['count']; ?></strong> (<?php echo $percent; ?>%)</span>
                </div>
                <?php endforeach; ?>

            <?php elseif ($results['type'] === 'prefere'): ?>
                <?php foreach ($results['results'] as $idx => $r):
                    $percent = $results['total'] > 0 ? round(($r['count'] / $results['total']) * 100, 1) : 0;
                ?>
                <div class="qcm-row">
                    <span><strong><?php echo $idx + 1; ?>.</strong> <?php echo htmlspecialchars($r['reponse']); ?></span>
                    <span><strong><?php echo $r['count']; ?></strong> vote<?php echo $r['count'] > 1 ? 's' : ''; ?> (<?php echo $percent; ?>%)</span>
                </div>
                <?php endforeach; ?>

            <?php elseif ($results['type'] === 'open'): ?>
                <?php if (empty($results['responses'])): ?>
                    <p style="color: #666; font-style: italic;">Aucune reponse</p>
                <?php else: ?>
                    <?php foreach ($results['responses'] as $response): ?>
                    <div class="open-response"><?php echo nl2br(htmlspecialchars($response)); ?></div>
                    <?php endforeach; ?>
                <?php endif; ?>
            <?php endif; ?>
        </div>

        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <?php endif; ?>

    <div class="doc-footer">
        Vote Nuance - <?php echo htmlspecialchars($scrutin['code']); ?> - Exporte le <?php echo $dateExport; ?>
    </div>

    <?php if (!empty($lotsData)): ?>
    <script>
    // Donnees pour les graphiques par lot
    const lotsData = <?php echo json_encode($lotsData); ?>;

    // Configuration commune Chart.js
    const chartOptions = {
        animation: false, // Pas d'animation pour l'impression
        responsive: true,
        maintainAspectRatio: false,
        indexAxis: 'x',
        scales: {
            x: { stacked: true },
            y: { stacked: true, display: false }
        },
        plugins: {
            legend: { display: false },
            tooltip: { enabled: false }
        }
    };

    // Créer les graphiques pour chaque lot
    Object.keys(lotsData).forEach(function(lotNum) {
        const lotData = lotsData[lotNum];

        // Graphique ordre initial (seulement si showOrdre = true)
        if (lotData.showOrdre) {
            const ctxOrdre = document.getElementById('chartOrdre_' + lotNum);
            if (ctxOrdre) {
                new Chart(ctxOrdre.getContext('2d'), {
                    type: 'bar',
                    data: { labels: lotData.labelsOrdre, datasets: lotData.datasetsOrdre },
                    options: chartOptions
                });
            }
        }

        // Graphique par classement
        const ctxClassement = document.getElementById('chartClassement_' + lotNum);
        if (ctxClassement) {
            new Chart(ctxClassement.getContext('2d'), {
                type: 'bar',
                data: { labels: lotData.labelsClassement, datasets: lotData.datasetsClassement },
                options: chartOptions
            });
        }
    });
    </script>
    <?php endif; ?>
</body>
</html>
