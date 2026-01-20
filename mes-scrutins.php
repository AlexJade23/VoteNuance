<?php
require_once 'config.php';
require_once 'functions.php';

if (!isLoggedIn()) {
    header('Location: login.php');
    exit;
}

$user = getCurrentUser();
if (!$user) {
    header('Location: logout.php');
    exit;
}

$allScrutins = getScrutinsByOwner($user['id']);
$csrfToken = generateCsrfToken();

// Recuperer les stats de jetons pour les scrutins prives
$tokenStatsByScrutin = [];
foreach ($allScrutins as $s) {
    if (!$s['est_public']) {
        $tokenStatsByScrutin[$s['id']] = countTokens($s['id']);
    }
}

// Afficher les archives ?
$showArchives = isset($_GET['archives']);

// Toggle pour afficher les résultats (top 3)
$showResults = isset($_GET['results']);

// Filtre par statut
$filterStatus = $_GET['filter'] ?? 'all';

// Filtre par code URL
$filterCode = trim($_GET['code'] ?? '');

// Traitement actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = 'Token de sécurité invalide';
    } else {
        $scrutinId = intval($_POST['scrutin_id'] ?? 0);
        $scrutin = getScrutinById($scrutinId);

        if ($scrutin && $scrutin['owner_id'] == $user['id']) {
            if ($_POST['action'] === 'delete') {
                // Empecher suppression si votes existent
                if ($scrutin['nb_votes'] ?? 0 > 0) {
                    $error = 'Impossible de supprimer un scrutin avec des votes.';
                } else {
                    deleteScrutin($scrutinId);
                    header('Location: mes-scrutins.php?deleted=1');
                    exit;
                }
            } elseif ($_POST['action'] === 'archive') {
                archiveScrutin($scrutinId);
                header('Location: mes-scrutins.php?archived=1');
                exit;
            } elseif ($_POST['action'] === 'unarchive') {
                unarchiveScrutin($scrutinId);
                header('Location: mes-scrutins.php?archives&unarchived=1');
                exit;
            }
        }
    }
    $allScrutins = getScrutinsByOwner($user['id']);
}

// Filtrer selon archives ou non
$scrutins = array_filter($allScrutins, function($s) use ($showArchives) {
    return $showArchives ? $s['est_archive'] : !$s['est_archive'];
});
$nbArchives = count(array_filter($allScrutins, function($s) { return $s['est_archive']; }));

// Appliquer le filtre par statut
if ($filterStatus !== 'all' && !$showArchives) {
    $scrutins = array_filter($scrutins, function($s) use ($filterStatus) {
        $status = getScrutinStatus($s);
        return $status['class'] === 'status-' . $filterStatus;
    });
}

// Appliquer le filtre par code URL
if ($filterCode !== '' && !$showArchives) {
    $scrutins = array_filter($scrutins, function($s) use ($filterCode) {
        return stripos($s['code'], $filterCode) !== false;
    });
}

// Pré-calculer les top 3 pour chaque scrutin si le toggle est actif
$top3ByScrutin = [];
if ($showResults) {
    foreach ($scrutins as $s) {
        $top3 = getTop3ResultsForScrutin($s['id'], $s['nb_mentions'] ?? 7);
        if (!empty($top3)) {
            $top3ByScrutin[$s['id']] = $top3;
        }
    }
}

function getScrutinStatus($scrutin) {
    $now = time();
    $debut = $scrutin['debut_at'] ? strtotime($scrutin['debut_at']) : null;
    $fin = $scrutin['fin_at'] ? strtotime($scrutin['fin_at']) : null;

    if ($scrutin['est_archive']) {
        return ['label' => 'Archivé', 'class' => 'status-archived'];
    }
    if ($fin && $now > $fin) {
        return ['label' => 'Terminé', 'class' => 'status-ended'];
    }
    if ($debut && $now < $debut) {
        return ['label' => 'Programmé', 'class' => 'status-scheduled'];
    }
    if (($debut === null || $now >= $debut) && ($fin === null || $now <= $fin)) {
        return ['label' => 'En cours', 'class' => 'status-active'];
    }
    return ['label' => 'Brouillon', 'class' => 'status-draft'];
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mes scrutins - Vote Nuancé</title>
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
        }

        .page-content {
            padding: 20px;
        }

        <?php echo getNavigationCSS(); ?>

        .container {
            max-width: 1000px;
            margin: 0 auto;
        }

        .page-header {
            margin-bottom: 20px;
        }

        .page-header h1 {
            color: #333;
            font-size: 24px;
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

        .btn-primary:hover {
            background: #5a6fd6;
        }

        .alert {
            padding: 15px;
            border-radius: 6px;
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

        .scrutins-grid {
            display: grid;
            gap: 20px;
        }

        .scrutin-card {
            background: white;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            overflow: hidden;
            display: grid;
            grid-template-columns: 1fr auto;
        }

        .scrutin-main {
            padding: 25px;
        }

        .scrutin-header {
            display: flex;
            align-items: flex-start;
            gap: 15px;
            margin-bottom: 15px;
        }

        .scrutin-title {
            font-size: 18px;
            font-weight: 600;
            color: #333;
            flex: 1;
        }

        .scrutin-title a {
            color: inherit;
            text-decoration: none;
        }

        .scrutin-title a:hover {
            color: #667eea;
        }

        .status {
            padding: 4px 10px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 600;
            white-space: nowrap;
        }

        .status-active {
            background: #28a745;
            color: white;
        }

        .status-scheduled {
            background: #17a2b8;
            color: white;
        }

        .status-ended {
            background: #6c757d;
            color: white;
        }

        .status-archived {
            background: #dee2e6;
            color: #495057;
        }

        .status-draft {
            background: #ffc107;
            color: #212529;
        }

        .scrutin-meta {
            display: flex;
            gap: 25px;
            color: #666;
            font-size: 14px;
            flex-wrap: wrap;
        }

        .scrutin-meta span {
            display: flex;
            align-items: center;
            gap: 5px;
        }

        .scrutin-code {
            font-family: monospace;
            background: #f8f9fa;
            padding: 2px 8px;
            border-radius: 4px;
        }

        .scrutin-actions {
            background: #f8f9fa;
            padding: 25px 20px;
            display: flex;
            flex-direction: column;
            gap: 10px;
            justify-content: center;
            min-width: 140px;
        }

        .scrutin-actions a,
        .scrutin-actions button {
            padding: 8px 16px;
            border-radius: 6px;
            font-size: 13px;
            text-align: center;
            text-decoration: none;
            border: none;
            cursor: pointer;
            font-weight: 500;
        }

        .btn-view {
            background: #667eea;
            color: white;
        }

        .btn-edit {
            background: white;
            color: #667eea;
            border: 1px solid #667eea;
        }

        .btn-delete {
            background: white;
            color: #dc3545;
            border: 1px solid #dc3545;
        }

        .btn-view:hover { background: #5a6fd6; }
        .btn-edit:hover:not(.disabled) { background: #667eea; color: white; }
        .btn-delete:hover:not(.disabled) { background: #dc3545; color: white; }

        .btn-archive {
            background: white;
            color: #6c757d;
            border: 1px solid #6c757d;
        }
        .btn-archive:hover { background: #6c757d; color: white; }

        .btn-unarchive {
            background: white;
            color: #28a745;
            border: 1px solid #28a745;
        }
        .btn-unarchive:hover { background: #28a745; color: white; }

        .disabled {
            opacity: 0.5;
            cursor: not-allowed !important;
            pointer-events: none;
        }

        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
        }

        .header-actions {
            display: flex;
            gap: 10px;
        }

        .btn-secondary {
            background: #6c757d;
            color: white;
        }
        .btn-secondary:hover { background: #5a6268; }

        .btn-outline {
            background: white;
            color: #667eea;
            border: 1px solid #667eea;
        }
        .btn-outline:hover { background: #667eea; color: white; }

        .empty-state {
            background: white;
            padding: 60px 30px;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            text-align: center;
        }

        .empty-state h2 {
            color: #333;
            margin-bottom: 10px;
        }

        .empty-state p {
            color: #666;
            margin-bottom: 25px;
        }

        .modal-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0,0,0,0.5);
            justify-content: center;
            align-items: center;
            z-index: 1000;
        }

        .modal-overlay.active {
            display: flex;
        }

        .modal {
            background: white;
            padding: 30px;
            border-radius: 12px;
            max-width: 400px;
            width: 90%;
        }

        .modal h3 {
            margin-bottom: 15px;
            color: #333;
        }

        .modal p {
            color: #666;
            margin-bottom: 20px;
        }

        .modal-actions {
            display: flex;
            gap: 10px;
            justify-content: flex-end;
        }

        .modal-actions button {
            padding: 10px 20px;
            border-radius: 6px;
            font-size: 14px;
            cursor: pointer;
            border: none;
        }

        .btn-cancel {
            background: #6c757d;
            color: white;
        }

        .btn-confirm-delete {
            background: #dc3545;
            color: white;
        }

        .badge-warning {
            display: inline-block;
            padding: 3px 8px;
            border-radius: 10px;
            font-size: 11px;
            font-weight: 600;
            background: #fff3cd;
            color: #856404;
            border: 1px solid #ffc107;
            margin-left: 8px;
        }

        .badge-private {
            display: inline-block;
            padding: 3px 8px;
            border-radius: 10px;
            font-size: 11px;
            font-weight: 600;
            background: #e9ecef;
            color: #495057;
            margin-left: 8px;
        }

        /* Filtres et toggle */
        .filters-bar {
            display: flex;
            gap: 15px;
            align-items: center;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }
        .filter-group {
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .filter-group label {
            font-size: 13px;
            color: #666;
        }
        .filter-select, .filter-input {
            padding: 6px 12px;
            border: 1px solid #ddd;
            border-radius: 6px;
            font-size: 13px;
            background: white;
        }
        .filter-input {
            width: 220px;
        }
        .filter-input::placeholder {
            color: #999;
        }
        .filter-btn {
            padding: 6px 12px;
            border: 1px solid #667eea;
            border-radius: 6px;
            font-size: 13px;
            background: #667eea;
            color: white;
            cursor: pointer;
        }
        .filter-btn:hover {
            background: #5a6fd6;
        }
        .toggle-btn {
            padding: 6px 14px;
            border: 1px solid #667eea;
            border-radius: 6px;
            font-size: 13px;
            background: white;
            color: #667eea;
            cursor: pointer;
            text-decoration: none;
            transition: all 0.2s;
        }
        .toggle-btn:hover {
            background: #f0f3ff;
        }
        .toggle-btn.active {
            background: #667eea;
            color: white;
        }

        /* Mini-graphes résultats */
        .scrutin-results {
            padding-top: 15px;
            border-top: 1px solid #eee;
            display: flex;
            align-items: center;
            gap: 15px;
        }
        .results-list {
            display: flex;
            flex-direction: column;
            justify-content: center;
            gap: 4px;
        }
        .result-item {
            display: flex;
            align-items: center;
        }
        .result-rank {
            width: 16px;
            height: 16px;
            border-radius: 50%;
            background: #667eea;
            color: white;
            font-size: 9px;
            font-weight: 600;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            margin-right: 10px;
        }
        .result-rank.rank-1 { background: #ffd700; color: #333; }
        .result-rank.rank-2 { background: #c0c0c0; color: #333; }
        .result-rank.rank-3 { background: #cd7f32; color: white; }
        .result-name {
            font-size: 11px;
            color: #333;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            max-width: 100px;
        }
        .result-value {
            font-size: 10px;
            font-weight: 600;
            min-width: 36px;
            text-align: right;
            margin-left: 6px;
        }
        .result-value.positive { color: #388e3c; }
        .result-value.negative { color: #d32f2f; }

        /* Graphique 3 barres côte à côte */
        .mini-chart {
            display: flex;
            align-items: flex-end;
            justify-content: center;
            gap: 15px;
            height: 80px;
            flex: 1;
        }
        .mini-bar {
            width: 30px;
            display: flex;
            flex-direction: column;
        }
        .mini-bar-segment {
            width: 100%;
        }
        .seg-white { background: white; }
        /* Couleurs des 7 mentions (AC -> AP) */
        .seg-ac { background: #D32F2F; }
        .seg-fc { background: #F57C00; }
        .seg-pc { background: #FBC02D; }
        .seg-sa { background: #9E9E9E; }
        .seg-pp { background: #C0CA33; }
        .seg-fp { background: #7CB342; }
        .seg-ap { background: #388E3C; }
    </style>
</head>
<body>
    <?php echo renderNavigation('mes-scrutins'); ?>

    <div class="page-content">
    <div class="container">
        <div class="page-header">
            <h1><?php echo $showArchives ? 'Scrutins archives' : 'Mes scrutins'; ?></h1>
            <div class="header-actions">
                <?php if ($showArchives): ?>
                <a href="/mes-scrutins.php" class="btn btn-outline">Retour aux scrutins</a>
                <?php else: ?>
                <?php if ($nbArchives > 0): ?>
                <a href="/mes-scrutins.php?archives" class="btn btn-secondary">Archives (<?php echo $nbArchives; ?>)</a>
                <?php endif; ?>
                <a href="/scrutin-import.php" class="btn btn-outline">Importer XLS</a>
                <a href="/scrutin-create.php" class="btn btn-primary">Nouveau scrutin</a>
                <?php endif; ?>
            </div>
        </div>

        <?php if (isset($error)): ?>
        <div class="alert" style="background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb;"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <?php if (isset($_GET['deleted'])): ?>
        <div class="alert alert-success">Le scrutin a ete supprime.</div>
        <?php endif; ?>

        <?php if (isset($_GET['archived'])): ?>
        <div class="alert alert-success">Le scrutin a ete archive.</div>
        <?php endif; ?>

        <?php if (isset($_GET['unarchived'])): ?>
        <div class="alert alert-success">Le scrutin a ete desarchive.</div>
        <?php endif; ?>

        <?php if (isset($_GET['created'])): ?>
        <div class="alert alert-success">Votre scrutin a ete cree avec succes !</div>
        <?php endif; ?>

        <?php if (!$showArchives): ?>
        <!-- Barre de filtres -->
        <div class="filters-bar">
            <div class="filter-group">
                <label for="filter-status">Statut :</label>
                <select id="filter-status" class="filter-select" onchange="applyFilter()">
                    <option value="all" <?php echo $filterStatus === 'all' ? 'selected' : ''; ?>>Tous</option>
                    <option value="active" <?php echo $filterStatus === 'active' ? 'selected' : ''; ?>>En cours</option>
                    <option value="scheduled" <?php echo $filterStatus === 'scheduled' ? 'selected' : ''; ?>>Programmes</option>
                    <option value="ended" <?php echo $filterStatus === 'ended' ? 'selected' : ''; ?>>Termines</option>
                    <option value="draft" <?php echo $filterStatus === 'draft' ? 'selected' : ''; ?>>Brouillons</option>
                </select>
            </div>
            <div class="filter-group">
                <label for="filter-code">Code :</label>
                <input type="text" id="filter-code" class="filter-input" placeholder="tout ou partie du code du scrutin" value="<?php echo htmlspecialchars($filterCode); ?>" onkeydown="if(event.key==='Enter')applyFilter()">
                <button type="button" class="filter-btn" onclick="applyFilter()">Filtrer</button>
            </div>
            <?php
            $toggleParams = [];
            if ($filterStatus !== 'all') $toggleParams[] = 'filter=' . urlencode($filterStatus);
            if ($filterCode !== '') $toggleParams[] = 'code=' . urlencode($filterCode);
            $toggleUrl = $showResults
                ? '/mes-scrutins.php' . (count($toggleParams) > 0 ? '?' . implode('&', $toggleParams) : '')
                : '/mes-scrutins.php?results' . (count($toggleParams) > 0 ? '&' . implode('&', $toggleParams) : '');
            ?>
            <a href="<?php echo $toggleUrl; ?>" class="toggle-btn <?php echo $showResults ? 'active' : ''; ?>">
                <?php echo $showResults ? 'Masquer resultats' : 'Voir resultats'; ?>
            </a>
        </div>
        <?php endif; ?>

        <?php if (empty($scrutins)): ?>
        <div class="empty-state">
            <?php if ($showArchives): ?>
            <h2>Aucun scrutin archive</h2>
            <p>Vous n'avez pas de scrutin dans les archives.</p>
            <a href="/mes-scrutins.php" class="btn btn-primary">Retour aux scrutins</a>
            <?php else: ?>
            <h2>Aucun scrutin</h2>
            <p>Vous n'avez pas encore cree de scrutin. Commencez maintenant !</p>
            <a href="/scrutin-create.php" class="btn btn-primary">Creer mon premier scrutin</a>
            <?php endif; ?>
        </div>
        <?php else: ?>
        <div class="scrutins-grid">
            <?php foreach ($scrutins as $scrutin):
                $status = getScrutinStatus($scrutin);
                $hasVotes = ($scrutin['nb_votes'] ?? 0) > 0;
                $isPrivate = !$scrutin['est_public'];
                $tokenStats = $isPrivate ? ($tokenStatsByScrutin[$scrutin['id']] ?? null) : null;
                $noTokensAvailable = $tokenStats && $tokenStats['disponibles'] === 0;
            ?>
            <div class="scrutin-card">
                <div class="scrutin-main">
                    <div class="scrutin-header">
                        <h3 class="scrutin-title">
                            <a href="/<?php echo urlencode($scrutin['code']); ?>/v/">
                                <?php echo htmlspecialchars($scrutin['titre']); ?>
                            </a>
                            <?php if ($isPrivate): ?>
                            <span class="badge-private">Prive</span>
                            <?php endif; ?>
                            <?php if ($isPrivate && $noTokensAvailable): ?>
                            <span class="badge-warning" title="Aucun jeton disponible, personne ne peut voter">Aucun jeton</span>
                            <?php endif; ?>
                        </h3>
                        <span class="status <?php echo $status['class']; ?>"><?php echo $status['label']; ?></span>
                    </div>
                    <div class="scrutin-meta">
                        <span>Code : <span class="scrutin-code"><?php echo htmlspecialchars($scrutin['code']); ?></span></span>
                        <span><?php echo $scrutin['nb_questions']; ?> question(s)</span>
                        <span><?php echo $scrutin['nb_votes']; ?> vote(s)</span>
                        <?php if ($scrutin['debut_at']): ?>
                        <span>Debut : <?php echo date('d/m/Y H:i', strtotime($scrutin['debut_at'])); ?></span>
                        <?php endif; ?>
                        <?php if ($scrutin['fin_at']): ?>
                        <span>Fin : <?php echo date('d/m/Y H:i', strtotime($scrutin['fin_at'])); ?></span>
                        <?php endif; ?>
                    </div>

                    <?php if ($showResults && isset($top3ByScrutin[$scrutin['id']])):
                        $top3Data = $top3ByScrutin[$scrutin['id']];
                        // Classement min et max pour calculer le décalage
                        $classementMin = min(array_column($top3Data, 'classement'));
                        $classementMax = max(array_column($top3Data, 'classement'));
                        $classementRange = $classementMax - $classementMin;
                        // Hauteur fixe pour les 7 segments (100% votants) = 60px
                        $segmentsHeight = 60;
                        // Hauteur max du décalage = 20px
                        $maxDecalage = 20;
                    ?>
                    <!-- Top 3 résultats -->
                    <div class="scrutin-results">
                        <!-- Liste texte à gauche -->
                        <div class="results-list">
                            <?php foreach ($top3Data as $rank => $result):
                                $isPositive = $result['tauxPartisansNet'] >= 0;
                            ?>
                            <div class="result-item">
                                <span class="result-rank rank-<?php echo $rank + 1; ?>"><?php echo $rank + 1; ?></span>
                                <span class="result-name" title="<?php echo htmlspecialchars($result['titre']); ?>"><?php echo htmlspecialchars($result['titre']); ?></span>
                                <span class="result-value <?php echo $isPositive ? 'positive' : 'negative'; ?>">
                                    <?php echo ($isPositive ? '+' : '') . $result['tauxPartisansNet']; ?>%
                                </span>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <!-- Graphique 3 barres à droite (Rank 1 à gauche, Rank 3 à droite) -->
                        <div class="mini-chart">
                            <?php foreach ($top3Data as $rank => $result):
                                $votes = $result['votesByCode'];
                                $total = $result['total'];
                                // Décalage : Rank 1 (meilleur classement) = plus grand décalage, Rank 3 = plus petit
                                $decalage = $classementRange > 0 ? round(($result['classement'] - $classementMin) / $classementRange * $maxDecalage) : $maxDecalage;
                            ?>
                            <div class="mini-bar">
                                <!-- Segments colorés de haut en bas : AP -> AC -->
                                <?php foreach (array_reverse(['AC', 'FC', 'PC', 'SA', 'PP', 'FP', 'AP']) as $code):
                                    $pct = $total > 0 ? ($votes[$code] ?? 0) / $total * 100 : 0;
                                    $h = round($pct / 100 * $segmentsHeight);
                                    if ($h > 0): ?>
                                <div class="mini-bar-segment seg-<?php echo strtolower($code); ?>" style="height: <?php echo $h; ?>px;"></div>
                                <?php endif; endforeach; ?>
                                <!-- Décalage blanc en bas -->
                                <div class="mini-bar-segment seg-white" style="height: <?php echo $decalage; ?>px;"></div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
                <div class="scrutin-actions">
                    <a href="/<?php echo urlencode($scrutin['code']); ?>/v/" class="btn-view">Voir</a>
                    <?php if ($showArchives): ?>
                    <!-- Mode archives : bouton desarchiver -->
                    <form method="POST" style="display: contents;">
                        <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                        <input type="hidden" name="action" value="unarchive">
                        <input type="hidden" name="scrutin_id" value="<?php echo $scrutin['id']; ?>">
                        <button type="submit" class="btn-unarchive">Desarchiver</button>
                    </form>
                    <?php else: ?>
                    <!-- Mode normal -->
                    <?php if ($hasVotes): ?>
                    <span class="btn-edit disabled" title="Impossible de modifier un scrutin avec des votes">Modifier</span>
                    <span class="btn-delete disabled" title="Impossible de supprimer un scrutin avec des votes">Supprimer</span>
                    <?php else: ?>
                    <a href="/<?php echo urlencode($scrutin['code']); ?>/s/" class="btn-edit">Modifier</a>
                    <button type="button" class="btn-delete" onclick="confirmDelete(<?php echo $scrutin['id']; ?>, '<?php echo htmlspecialchars(addslashes($scrutin['titre'])); ?>')">Supprimer</button>
                    <?php endif; ?>
                    <form method="POST" style="display: contents;">
                        <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                        <input type="hidden" name="action" value="archive">
                        <input type="hidden" name="scrutin_id" value="<?php echo $scrutin['id']; ?>">
                        <button type="submit" class="btn-archive">Archiver</button>
                    </form>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>

    <div class="modal-overlay" id="delete-modal">
        <div class="modal">
            <h3>Confirmer la suppression</h3>
            <p>Êtes-vous sûr de vouloir supprimer le scrutin "<span id="delete-title"></span>" ?<br>Cette action est irréversible.</p>
            <form method="POST" id="delete-form">
                <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="scrutin_id" id="delete-id">
                <div class="modal-actions">
                    <button type="button" class="btn-cancel" onclick="closeModal()">Annuler</button>
                    <button type="submit" class="btn-confirm-delete">Supprimer</button>
                </div>
            </form>
        </div>
    </div>

    <script>
    function confirmDelete(id, title) {
        document.getElementById('delete-id').value = id;
        document.getElementById('delete-title').textContent = title;
        document.getElementById('delete-modal').classList.add('active');
    }

    function closeModal() {
        document.getElementById('delete-modal').classList.remove('active');
    }

    document.getElementById('delete-modal').addEventListener('click', function(e) {
        if (e.target === this) closeModal();
    });

    function applyFilter() {
        var filter = document.getElementById('filter-status').value;
        var code = document.getElementById('filter-code').value.trim();
        var showResults = <?php echo $showResults ? 'true' : 'false'; ?>;
        var url = '/mes-scrutins.php';
        var params = [];

        if (showResults) {
            params.push('results');
        }
        if (filter !== 'all') {
            params.push('filter=' + encodeURIComponent(filter));
        }
        if (code !== '') {
            params.push('code=' + encodeURIComponent(code));
        }

        if (params.length > 0) {
            url += '?' + params.join('&');
        }

        window.location.href = url;
    }
    </script>

    <?php echo renderFooter(); ?>
    </div>
</body>
</html>
