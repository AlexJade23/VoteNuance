<?php
require_once '../config.php';
require_once '../functions.php';

// Recuperer la raison de l'erreur
$reason = $_GET['reason'] ?? 'unknown';

// Messages selon la raison
$errorMessages = [
    'token_expired' => [
        'title' => 'Lien expire',
        'message' => 'Ce lien de connexion a expire. Les liens sont valables 15 minutes.',
        'action' => 'Demander un nouveau lien'
    ],
    'token_invalid' => [
        'title' => 'Lien invalide',
        'message' => 'Ce lien de connexion n\'est pas valide ou a deja ete utilise.',
        'action' => 'Demander un nouveau lien'
    ],
    'unknown' => [
        'title' => 'Erreur',
        'message' => 'Une erreur est survenue lors de la connexion.',
        'action' => 'Retour a la connexion'
    ]
];

$errorInfo = $errorMessages[$reason] ?? $errorMessages['unknown'];
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($errorInfo['title']); ?> - Decision Collective</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }

        .container {
            background: white;
            border-radius: 12px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.2);
            max-width: 450px;
            width: 100%;
            padding: 40px;
            text-align: center;
        }

        .error-icon {
            width: 80px;
            height: 80px;
            background: #fee;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 25px;
        }

        .error-icon svg {
            width: 40px;
            height: 40px;
            color: #c33;
        }

        h1 {
            font-size: 28px;
            margin-bottom: 15px;
            color: #333;
        }

        .message {
            color: #666;
            font-size: 16px;
            line-height: 1.6;
            margin-bottom: 30px;
        }

        .btn {
            display: inline-block;
            padding: 14px 30px;
            border: none;
            border-radius: 8px;
            font-size: 15px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s;
            text-decoration: none;
        }

        .btn-primary {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(102, 126, 234, 0.4);
        }

        .back-link {
            display: block;
            margin-top: 20px;
            color: #667eea;
            text-decoration: none;
            font-size: 14px;
        }

        .back-link:hover {
            text-decoration: underline;
        }

        .footer {
            margin-top: 30px;
            font-size: 12px;
            color: #999;
        }
        <?php echo getTestBannerCSS(); ?>
    </style>
</head>
<body>
<?php echo renderTestBanner(); ?>
    <div class="container">
        <div style="margin-bottom: 25px;">
            <a href="https://decision-collective.fr/" target="_blank" title="Decouvrir le concept">
                <img src="https://decision-collective.fr/wp-content/uploads/2021/12/logov7long.png" alt="Decision Collective" style="height: 50px; width: auto;">
            </a>
        </div>

        <div class="error-icon">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
            </svg>
        </div>

        <h1><?php echo htmlspecialchars($errorInfo['title']); ?></h1>
        <p class="message"><?php echo htmlspecialchars($errorInfo['message']); ?></p>

        <a href="../login-magiclink.php" class="btn btn-primary">
            <?php echo htmlspecialchars($errorInfo['action']); ?>
        </a>

        <a href="../login.php" class="back-link">Autres methodes de connexion</a>

        <div class="footer">
            Decision Collective - Vote Nuance
        </div>
    </div>
</body>
</html>
