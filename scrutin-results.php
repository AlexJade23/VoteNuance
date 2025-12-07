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

// Récupérer les résultats pour une question
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
            $counts[$row['vote_mention']] = (int)$row['count'];
        }

        // Comptages individuels
        $ac = $counts[1] ?? 0;  // Absolument Contre
        $fc = $counts[2] ?? 0;  // Franchement Contre
        $pc = $counts[3] ?? 0;  // Plutôt Contre
        $sa = $counts[4] ?? 0;  // Sans Avis
        $pp = $counts[5] ?? 0;  // Plutôt Pour
        $fp = $counts[6] ?? 0;  // Franchement Pour
        $ap = $counts[7] ?? 0;  // Absolument Pour

        // Calcul du CLASSEMENT (formule Vote Nuancé) : AP + FP + PP + SA/2
        $classement = $ap + $fp + $pp + ($sa / 2);

        // Niveaux de départage
        $niveau1 = $ap - $ac;  // Avis absolus
        $niveau2 = $fp - $fc;  // Avis francs
        $niveau3 = $pp - $pc;  // Avis normaux

        // Totaux pour statistiques
        $pour = $ap + $fp + $pp;
        $contre = $ac + $fc + $pc;
        $total = array_sum($counts);

        // Taux partisans
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
            $results['ordre'] = $question['ordre'];
            $nuanceResults[] = $results;
        }
    }
}

// Normaliser : ajouter des Sans Avis pour que toutes les questions aient le meme nombre de votes
if (!empty($nuanceResults)) {
    $maxVotes = max(array_column($nuanceResults, 'total'));

    foreach ($nuanceResults as &$r) {
        $delta = $maxVotes - $r['total'];
        if ($delta > 0) {
            // Ajouter les Sans Avis manquants
            $r['counts'][4] = ($r['counts'][4] ?? 0) + $delta;
            $r['neutre'] = $r['counts'][4];
            $r['total'] = $maxVotes;

            // Recalculer le classement avec les nouveaux SA
            $ap = $r['counts'][7] ?? 0;
            $fp = $r['counts'][6] ?? 0;
            $pp = $r['counts'][5] ?? 0;
            $sa = $r['counts'][4];
            $r['classement'] = $ap + $fp + $pp + ($sa / 2);

            // Recalculer les taux
            $pour = $r['pour'];
            $contre = $r['contre'];
            $r['tauxPartisans'] = $maxVotes > 0 ? round(($pour / $maxVotes) * 100, 1) : 0;
            $r['tauxOpposants'] = $maxVotes > 0 ? round(($contre / $maxVotes) * 100, 1) : 0;
            $r['tauxPartisansNet'] = $maxVotes > 0 ? round((($pour - $contre) / $maxVotes) * 100, 1) : 0;
        }
    }
    unset($r); // Casser la reference
}

// Copier pour l'ordre initial (apres normalisation)
$nuanceResultsOrdre = $nuanceResults;

// Trier par classement décroissant puis niveaux de départage
usort($nuanceResults, function($a, $b) {
    if ($a['classement'] != $b['classement']) return $b['classement'] - $a['classement'];
    if ($a['niveau1'] != $b['niveau1']) return $b['niveau1'] - $a['niveau1'];
    if ($a['niveau2'] != $b['niveau2']) return $b['niveau2'] - $a['niveau2'];
    return $b['niveau3'] - $a['niveau3'];
});

// Garder l'ordre initial pour le 2e graphique
usort($nuanceResultsOrdre, function($a, $b) {
    return $a['ordre'] - $b['ordre'];
});

// Trouver le classement minimum pour le décalage (barre blanche)
$classementMini = PHP_FLOAT_MAX;
foreach ($nuanceResults as $r) {
    if ($r['classement'] < $classementMini) {
        $classementMini = $r['classement'];
    }
}
if ($classementMini == PHP_FLOAT_MAX) $classementMini = 0;

// Construire les séries pour Chart.js (comme get_datasets en Python)
function buildChartDatasets($results, $classementMini, $mentions) {
    if (empty($results)) return [];

    // Série 1 : barre blanche de décalage
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

    // Séries 2-8 : mentions dans l'ordre AC -> AP (rang 1 à 7)
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

// Préparer les labels (titres des questions)
$labelsClassement = [];
$labelsOrdre = [];
foreach ($nuanceResults as $idx => $r) {
    $labelsClassement[] = ($idx + 1);
}
foreach ($nuanceResultsOrdre as $idx => $r) {
    $labelsOrdre[] = ($idx + 1);
}

$datasetsClassement = buildChartDatasets($nuanceResults, $classementMini, $mentions);
$datasetsOrdre = buildChartDatasets($nuanceResultsOrdre, $classementMini, $mentions);
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Resultats - <?php echo htmlspecialchars($scrutin['titre']); ?></title>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            background: #f5f5f5;
            min-height: 100vh;
            padding: 20px;
        }
        .container { max-width: 1200px; margin: 0 auto; }
        .header {
            background: white;
            padding: 25px 30px;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            margin-bottom: 20px;
        }
        .header-top { display: flex; justify-content: space-between; align-items: flex-start; }
        .header h1 { color: #333; font-size: 24px; margin-bottom: 10px; }
        .header-meta { display: flex; gap: 20px; color: #666; font-size: 14px; margin-top: 10px; }
        .status { padding: 6px 14px; border-radius: 20px; font-size: 13px; font-weight: 600; }
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
        .chart-wrapper {
            width: 100%;
            min-height: 400px;
            position: relative;
        }
        .chart-wrapper canvas {
            max-width: 100%;
        }
        .legende {
            display: inline;
            font-size: 12px;
            color: #555;
            margin-right: 5px;
        }
        .questions-list {
            margin-top: 15px;
            padding: 15px;
            background: #f8f9fa;
            border-radius: 8px;
            font-size: 13px;
            line-height: 1.6;
        }
        .result-card { border-left: 4px solid #667eea; }
        .question-header { display: flex; align-items: flex-start; margin-bottom: 20px; }
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
        .question-title { font-size: 18px; font-weight: 600; color: #333; }
        .qcm-result { margin-bottom: 12px; }
        .qcm-label { display: flex; justify-content: space-between; margin-bottom: 5px; font-size: 14px; }
        .qcm-bar-container { height: 20px; background: #e9ecef; border-radius: 10px; overflow: hidden; }
        .qcm-bar-fill { height: 100%; background: #667eea; border-radius: 10px; }
        .open-responses { max-height: 300px; overflow-y: auto; }
        .open-response-item {
            padding: 12px 15px;
            background: #f8f9fa;
            border-radius: 6px;
            margin-bottom: 8px;
            font-size: 14px;
            line-height: 1.5;
        }
        .no-responses { color: #666; font-style: italic; text-align: center; padding: 20px; }
        .back-link { display: inline-block; margin-bottom: 20px; color: #667eea; text-decoration: none; }
        .back-link:hover { text-decoration: underline; }
        .btn { padding: 10px 20px; border: none; border-radius: 6px; font-size: 14px; font-weight: 600; cursor: pointer; text-decoration: none; display: inline-block; }
        .btn-primary { background: #667eea; color: white; }
        .participants-count { text-align: center; color: #666; font-size: 14px; margin-bottom: 15px; }
        #mode-portrait { display: none; }
        @media (max-width: 639px) {
            #mode-paysage, #mode-paysage2 { display: none !important; }
            #mode-portrait { display: block; }
        }
    </style>
</head>
<body>
    <div class="container">
        <a href="/<?php echo urlencode($code); ?>/v/" class="back-link">&#8592; Retour au scrutin</a>

        <div class="header">
            <div class="header-top">
                <div>
                    <h1>Resultats : <?php echo htmlspecialchars($scrutin['titre']); ?></h1>
                    <div class="header-meta">
                        <span><?php echo $nbParticipants; ?> votant<?php echo $nbParticipants > 1 ? 's' : ''; ?></span>
                        <?php if ($scrutin['fin_at']): ?>
                        <span>Cloture : <?php echo date('d/m/Y H:i', strtotime($scrutin['fin_at'])); ?></span>
                        <?php endif; ?>
                    </div>
                </div>
                <span class="status <?php echo $isEnded ? 'status-ended' : 'status-active'; ?>">
                    <?php echo $isEnded ? 'Termine' : 'En cours'; ?>
                </span>
            </div>
        </div>

        <?php if ($nbParticipants == 0): ?>
        <div class="card">
            <p style="text-align: center; color: #666; padding: 40px;">
                Aucun vote n'a encore ete enregistre.
            </p>
        </div>
        <?php else: ?>

        <?php if (!empty($nuanceResultsOrdre)): ?>
        <!-- Graphique 1 : Ordre initial des questions -->
        <div id="mode-paysage2" class="card">
            <h2>Classement dans l'ordre initial des questions</h2>
            <div class="chart-wrapper">
                <canvas id="chartOrdre"></canvas>
            </div>
            <div class="questions-list">
                <?php foreach ($nuanceResultsOrdre as $idx => $r): ?>
                <span class="legende"><?php echo ($idx + 1); ?>. <?php echo htmlspecialchars($r['titre']); ?> |</span>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <?php if (!empty($nuanceResults)): ?>
        <!-- Graphique 2 : Tri par taux de partisans net -->
        <div id="mode-paysage" class="card">
            <h2>Classement par taux de partisans net (1er a gauche... dernier a droite)</h2>
            <div class="chart-wrapper">
                <canvas id="chartClassement"></canvas>
            </div>
            <div class="questions-list">
                <?php foreach ($nuanceResults as $idx => $r): ?>
                <span class="legende"><?php echo ($idx + 1); ?>. <?php echo htmlspecialchars($r['titre']); ?> |</span>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <div id="mode-portrait" class="card">
            <p style="text-align: center; padding: 20px;">
                Un ecran en mode Paysage d'au moins 640px de large est necessaire pour afficher correctement les resultats.
                Merci de tourner votre ecran ou de regarder sur un ecran d'ordinateur.
            </p>
        </div>

        <?php
        // Autres types de questions (QCM, ouvertes)
        $questionNum = 0;
        foreach ($questions as $question):
            if ($question['type_question'] == 2) continue; // Ignorer separateurs
            if ($question['type_question'] == 0) continue; // Deja affiche dans le graphe
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
            <?php if (empty($results['responses'])): ?>
            <div class="no-responses">Aucune reponse</div>
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

    <?php if (!empty($nuanceResults)): ?>
    <script>
    // Donnees pour les graphiques
    const datasetsClassement = <?php echo json_encode($datasetsClassement); ?>;
    const datasetsOrdre = <?php echo json_encode($datasetsOrdre); ?>;
    const labelsClassement = <?php echo json_encode($labelsClassement); ?>;
    const labelsOrdre = <?php echo json_encode($labelsOrdre); ?>;

    // Configuration commune Chart.js
    const chartOptions = {
        animation: { duration: 2000, easing: "easeOutCirc" },
        responsive: true,
        maintainAspectRatio: false,
        indexAxis: 'x',
        scales: {
            x: { stacked: true },
            y: { stacked: true, display: false }
        },
        plugins: {
            legend: { display: false },
            tooltip: {
                callbacks: {
                    label: function(context) {
                        if (context.dataset.label === ' ') return null;
                        return context.dataset.label + ': ' + context.parsed.y;
                    }
                }
            }
        }
    };

    // Graphique ordre initial
    const ctxOrdre = document.getElementById('chartOrdre');
    if (ctxOrdre) {
        new Chart(ctxOrdre.getContext('2d'), {
            type: 'bar',
            data: { labels: labelsOrdre, datasets: datasetsOrdre },
            options: chartOptions
        });
    }

    // Graphique par classement
    const ctxClassement = document.getElementById('chartClassement');
    if (ctxClassement) {
        new Chart(ctxClassement.getContext('2d'), {
            type: 'bar',
            data: { labels: labelsClassement, datasets: datasetsClassement },
            options: chartOptions
        });
    }
    </script>
    <?php endif; ?>
</body>
</html>
