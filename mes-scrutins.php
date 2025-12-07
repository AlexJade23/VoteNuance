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

$scrutins = getScrutinsByOwner($user['id']);
$csrfToken = generateCsrfToken();

// Traitement suppression
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = 'Token de sécurité invalide';
    } else {
        $scrutinId = intval($_POST['scrutin_id'] ?? 0);
        $scrutin = getScrutinById($scrutinId);

        if ($scrutin && $scrutin['owner_id'] == $user['id']) {
            if ($_POST['action'] === 'delete') {
                deleteScrutin($scrutinId);
                header('Location: mes-scrutins.php?deleted=1');
                exit;
            } elseif ($_POST['action'] === 'archive') {
                archiveScrutin($scrutinId);
                header('Location: mes-scrutins.php?archived=1');
                exit;
            }
        }
    }
    $scrutins = getScrutinsByOwner($user['id']);
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
        .btn-edit:hover { background: #667eea; color: white; }
        .btn-delete:hover { background: #dc3545; color: white; }

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
    </style>
</head>
<body>
    <?php echo renderNavigation('mes-scrutins'); ?>

    <div class="page-content">
    <div class="container">
        <div class="page-header">
            <h1>Mes scrutins</h1>
        </div>

        <?php if (isset($_GET['deleted'])): ?>
        <div class="alert alert-success">Le scrutin a été supprimé.</div>
        <?php endif; ?>

        <?php if (isset($_GET['archived'])): ?>
        <div class="alert alert-success">Le scrutin a été archivé.</div>
        <?php endif; ?>

        <?php if (isset($_GET['created'])): ?>
        <div class="alert alert-success">Votre scrutin a été créé avec succès !</div>
        <?php endif; ?>

        <?php if (empty($scrutins)): ?>
        <div class="empty-state">
            <h2>Aucun scrutin</h2>
            <p>Vous n'avez pas encore créé de scrutin. Commencez maintenant !</p>
            <a href="/scrutin-create.php" class="btn btn-primary">Créer mon premier scrutin</a>
        </div>
        <?php else: ?>
        <div class="scrutins-grid">
            <?php foreach ($scrutins as $scrutin):
                $status = getScrutinStatus($scrutin);
            ?>
            <div class="scrutin-card">
                <div class="scrutin-main">
                    <div class="scrutin-header">
                        <h3 class="scrutin-title">
                            <a href="/<?php echo urlencode($scrutin['code']); ?>/v/">
                                <?php echo htmlspecialchars($scrutin['titre']); ?>
                            </a>
                        </h3>
                        <span class="status <?php echo $status['class']; ?>"><?php echo $status['label']; ?></span>
                    </div>
                    <div class="scrutin-meta">
                        <span>Code : <span class="scrutin-code"><?php echo htmlspecialchars($scrutin['code']); ?></span></span>
                        <span><?php echo $scrutin['nb_questions']; ?> question(s)</span>
                        <span><?php echo $scrutin['nb_votes']; ?> vote(s)</span>
                        <?php if ($scrutin['debut_at']): ?>
                        <span>Début : <?php echo date('d/m/Y H:i', strtotime($scrutin['debut_at'])); ?></span>
                        <?php endif; ?>
                        <?php if ($scrutin['fin_at']): ?>
                        <span>Fin : <?php echo date('d/m/Y H:i', strtotime($scrutin['fin_at'])); ?></span>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="scrutin-actions">
                    <a href="/<?php echo urlencode($scrutin['code']); ?>/v/" class="btn-view">Voir</a>
                    <a href="/<?php echo urlencode($scrutin['code']); ?>/s/" class="btn-edit">Modifier</a>
                    <button type="button" class="btn-delete" onclick="confirmDelete(<?php echo $scrutin['id']; ?>, '<?php echo htmlspecialchars(addslashes($scrutin['titre'])); ?>')">Supprimer</button>
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
    </script>
    </div>
</body>
</html>
