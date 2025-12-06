<?php
require_once 'config.php';
require_once 'functions.php';

// Vérifier si l'utilisateur est connecté
if (!isLoggedIn()) {
    header('Location: login.php');
    exit;
}

// Récupérer les informations de l'utilisateur
$user = getCurrentUser();

if (!$user) {
    header('Location: logout.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tableau de bord</title>
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
            padding: 30px;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            margin-bottom: 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .header h1 {
            color: #333;
            font-size: 28px;
        }
        
        .logout-btn {
            background: #dc3545;
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 6px;
            cursor: pointer;
            font-size: 14px;
            text-decoration: none;
            display: inline-block;
        }
        
        .logout-btn:hover {
            background: #c82333;
        }
        
        .card {
            background: white;
            padding: 30px;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            margin-bottom: 20px;
        }
        
        .card h2 {
            color: #333;
            margin-bottom: 20px;
            font-size: 20px;
            border-bottom: 2px solid #667eea;
            padding-bottom: 10px;
        }
        
        .info-row {
            display: flex;
            padding: 12px 0;
            border-bottom: 1px solid #f0f0f0;
        }
        
        .info-row:last-child {
            border-bottom: none;
        }
        
        .info-label {
            font-weight: 600;
            color: #555;
            width: 200px;
        }
        
        .info-value {
            color: #333;
            flex: 1;
        }
        
        .badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 600;
        }
        
        .badge-google {
            background: #4285f4;
            color: white;
        }
        
        .badge-microsoft {
            background: #00a4ef;
            color: white;
        }
        
        .badge-yes {
            background: #28a745;
            color: white;
        }
        
        .badge-no {
            background: #6c757d;
            color: white;
        }
        
        .success-message {
            background: #d4edda;
            color: #155724;
            padding: 15px;
            border-radius: 6px;
            margin-bottom: 20px;
            border: 1px solid #c3e6cb;
        }
        
        .links {
            text-align: center;
            margin-top: 20px;
        }
        
        .links a {
            color: #667eea;
            text-decoration: none;
            margin: 0 15px;
        }
        
        .links a:hover {
            text-decoration: underline;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>Tableau de bord</h1>
            <a href="logout.php" class="logout-btn">Déconnexion</a>
        </div>
        
        <div class="success-message">
            ✓ Vous êtes connecté avec succès !
        </div>
        
        <div class="card">
            <h2>Vos informations</h2>
            
            <div class="info-row">
                <div class="info-label">Identifiant :</div>
                <div class="info-value">#<?php echo htmlspecialchars($user['id']); ?></div>
            </div>
            
            <div class="info-row">
                <div class="info-label">Méthode de connexion :</div>
                <div class="info-value">
                    <?php if ($user['sso_provider'] === 'google'): ?>
                        <span class="badge badge-google">Google</span>
                    <?php else: ?>
                        <span class="badge badge-microsoft">Microsoft</span>
                    <?php endif; ?>
                </div>
            </div>
            
            <div class="info-row">
                <div class="info-label">Pseudo :</div>
                <div class="info-value">
                    <?php echo $user['display_name'] ? htmlspecialchars($user['display_name']) : '<em>Non défini</em>'; ?>
                </div>
            </div>
            
            <div class="info-row">
                <div class="info-label">Hash email stocké :</div>
                <div class="info-value">
                    <?php if ($user['email_hash']): ?>
                        <span class="badge badge-yes">Oui</span>
                        <small style="color: #666; margin-left: 10px;">
                            (<?php echo substr($user['email_hash'], 0, 16); ?>...)
                        </small>
                    <?php else: ?>
                        <span class="badge badge-no">Non</span>
                    <?php endif; ?>
                </div>
            </div>
            
            <div class="info-row">
                <div class="info-label">Compte créé le :</div>
                <div class="info-value">
                    <?php echo date('d/m/Y à H:i', strtotime($user['created_at'])); ?>
                </div>
            </div>
            
            <div class="info-row">
                <div class="info-label">Dernière connexion :</div>
                <div class="info-value">
                    <?php echo date('d/m/Y à H:i', strtotime($user['last_login'])); ?>
                </div>
            </div>
        </div>
        
        <div class="card">
            <h2>Vie privée</h2>
            <p style="color: #666; line-height: 1.6;">
                Nous stockons uniquement votre identifiant SSO (impossible à relier à votre identité réelle).
                <?php if ($user['email_hash']): ?>
                    Vous avez autorisé le stockage d'un hash irréversible de votre email pour éviter les doublons.
                <?php else: ?>
                    Aucune autre donnée personnelle n'est stockée.
                <?php endif; ?>
            </p>
            
            <div class="links">
                <a href="my-data.php">Gérer mes données</a>
            </div>
        </div>
    </div>
</body>
</html>
