<?php
/**
 * Stripe Success - Page de confirmation apres paiement
 * Affiche les jetons generes apres un paiement reussi
 */

require_once 'config.php';
require_once 'functions.php';

// Verifier que l'utilisateur est connecte
if (!isLoggedIn()) {
    header('Location: /login.php');
    exit;
}

$user = getCurrentUser();

// Recuperer l'ID de session Stripe
$sessionId = $_GET['session_id'] ?? '';

if (empty($sessionId)) {
    header('Location: /mes-scrutins.php');
    exit;
}

// Recuperer l'achat correspondant
$achat = getAchatByStripeSession($sessionId);

if (!$achat) {
    $error = 'Achat introuvable.';
    $scrutin = null;
} elseif ($achat['user_id'] != $user['id']) {
    $error = 'Acces refuse.';
    $scrutin = null;
} else {
    $error = null;
    $scrutin = getScrutinById($achat['scrutin_id']);

    // Recuperer les jetons generes (les plus recents du scrutin)
    if ($achat['status'] === 'paid') {
        $tokens = getTokensByScrutin($achat['scrutin_id']);
        // Filtrer pour ne garder que les jetons generes apres le paiement
        // (approximation basee sur la date)
        $paidAt = strtotime($achat['paid_at'] ?? $achat['created_at']);
        $recentTokens = array_filter($tokens, function($t) use ($paidAt, $achat) {
            $createdAt = strtotime($t['created_at']);
            // Jetons crees dans les 5 minutes suivant le paiement
            return $createdAt >= $paidAt - 60 && !$t['est_utilise'];
        });
        // Limiter au nombre de jetons achetes
        $recentTokens = array_slice($recentTokens, 0, $achat['nb_jetons']);
    } else {
        $recentTokens = [];
    }
}

$baseUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'];
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Paiement confirme - Vote Nuance</title>
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

        .card {
            background: white;
            padding: 30px;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            margin-bottom: 20px;
        }

        .success-header {
            text-align: center;
            margin-bottom: 30px;
        }

        .success-icon {
            width: 80px;
            height: 80px;
            background: #28a745;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 20px;
        }

        .success-icon svg {
            width: 40px;
            height: 40px;
            fill: white;
        }

        .success-header h1 {
            color: #28a745;
            font-size: 24px;
            margin-bottom: 10px;
        }

        .success-header p {
            color: #666;
        }

        .summary {
            background: #f8f9fa;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 25px;
        }

        .summary-row {
            display: flex;
            justify-content: space-between;
            padding: 8px 0;
            border-bottom: 1px solid #eee;
        }

        .summary-row:last-child {
            border-bottom: none;
            font-weight: bold;
        }

        .summary-label {
            color: #666;
        }

        .summary-value {
            color: #333;
        }

        h2 {
            color: #333;
            font-size: 18px;
            margin-bottom: 15px;
            padding-bottom: 10px;
            border-bottom: 2px solid #667eea;
        }

        .tokens-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));
            gap: 10px;
            margin-bottom: 20px;
        }

        .token-item {
            background: #e8f5e9;
            padding: 12px;
            border-radius: 8px;
            text-align: center;
            font-family: monospace;
            font-size: 14px;
            color: #2e7d32;
        }

        .btn {
            padding: 12px 24px;
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

        .btn:hover {
            opacity: 0.9;
        }

        .actions {
            display: flex;
            gap: 15px;
            flex-wrap: wrap;
            margin-top: 25px;
        }

        .alert {
            padding: 15px 20px;
            border-radius: 8px;
            margin-bottom: 20px;
        }

        .alert-warning {
            background: #fff3cd;
            color: #856404;
            border: 1px solid #ffc107;
        }

        .alert-error {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }

        .alert-info {
            background: #e7f3ff;
            color: #0c5460;
            border: 1px solid #b8daff;
        }

        .pending-notice {
            text-align: center;
            padding: 40px 20px;
        }

        .pending-notice .spinner {
            width: 50px;
            height: 50px;
            border: 4px solid #f3f3f3;
            border-top: 4px solid #667eea;
            border-radius: 50%;
            animation: spin 1s linear infinite;
            margin: 0 auto 20px;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        <?php echo getTestBannerCSS(); ?>
    </style>
</head>
<body>
<?php echo renderTestBanner(); ?>
    <div class="container">
        <?php if ($error): ?>
        <div class="card">
            <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
            <a href="/mes-scrutins.php" class="btn btn-primary">Retour a mes scrutins</a>
        </div>
        <?php elseif ($achat['status'] === 'pending'): ?>
        <div class="card">
            <div class="pending-notice">
                <div class="spinner"></div>
                <h2>Paiement en cours de traitement...</h2>
                <p>Veuillez patienter quelques instants.</p>
                <p style="margin-top: 15px; color: #666; font-size: 14px;">
                    La page se rafraichira automatiquement.
                </p>
            </div>
        </div>
        <script>
            // Rafraichir la page toutes les 3 secondes
            setTimeout(function() {
                window.location.reload();
            }, 3000);
        </script>
        <?php elseif ($achat['status'] === 'paid'): ?>
        <div class="card">
            <div class="success-header">
                <div class="success-icon">
                    <svg viewBox="0 0 24 24"><path d="M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41z"/></svg>
                </div>
                <h1>Paiement confirme !</h1>
                <p>Vos jetons ont ete generes avec succes.</p>
            </div>

            <div class="summary">
                <div class="summary-row">
                    <span class="summary-label">Scrutin</span>
                    <span class="summary-value"><?php echo htmlspecialchars($scrutin['titre']); ?></span>
                </div>
                <div class="summary-row">
                    <span class="summary-label">Nombre de jetons</span>
                    <span class="summary-value"><?php echo $achat['nb_jetons']; ?></span>
                </div>
                <div class="summary-row">
                    <span class="summary-label">Montant paye</span>
                    <span class="summary-value"><?php echo number_format($achat['montant_cents'] / 100, 2, ',', ' '); ?> EUR</span>
                </div>
                <div class="summary-row">
                    <span class="summary-label">Date</span>
                    <span class="summary-value"><?php echo date('d/m/Y H:i', strtotime($achat['paid_at'])); ?></span>
                </div>
            </div>

            <?php if (!empty($recentTokens)): ?>
            <h2>Jetons generes</h2>
            <div class="tokens-grid">
                <?php foreach ($recentTokens as $token): ?>
                <div class="token-item"><?php echo htmlspecialchars($token['code']); ?></div>
                <?php endforeach; ?>
            </div>

            <div class="alert alert-info">
                Vous retrouverez tous vos jetons sur la page de gestion du scrutin.
            </div>
            <?php else: ?>
            <div class="alert alert-info">
                Les jetons sont disponibles sur la page de gestion du scrutin.
            </div>
            <?php endif; ?>

            <div class="actions">
                <a href="/<?php echo htmlspecialchars($scrutin['code']); ?>/v/" class="btn btn-primary">Voir le scrutin</a>
                <a href="/mes-scrutins.php" class="btn btn-secondary">Mes scrutins</a>
            </div>
        </div>
        <?php else: ?>
        <div class="card">
            <div class="alert alert-warning">
                Le paiement n'a pas pu etre finalise. Si vous avez ete debite, contactez-nous.
            </div>
            <a href="/mes-scrutins.php" class="btn btn-primary">Retour a mes scrutins</a>
        </div>
        <?php endif; ?>
    </div>

    <?php echo renderFooter(); ?>
</body>
</html>
