<?php
require_once 'config.php';
require_once 'functions.php';

// Si déjà connecté, rediriger vers dashboard
if (isLoggedIn()) {
    header('Location: dashboard.php');
    exit;
}

$csrfToken = generateCsrfToken();
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Connexion SSO</title>
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
        }
        
        h1 {
            font-size: 28px;
            margin-bottom: 10px;
            color: #333;
            text-align: center;
        }
        
        .subtitle {
            text-align: center;
            color: #666;
            margin-bottom: 30px;
            font-size: 14px;
        }
        
        .privacy-section {
            background: #f8f9fa;
            border-left: 4px solid #667eea;
            padding: 20px;
            margin-bottom: 25px;
            border-radius: 4px;
        }
        
        .privacy-section h2 {
            font-size: 16px;
            margin-bottom: 15px;
            color: #333;
        }
        
        .checkbox-wrapper {
            margin-bottom: 15px;
        }
        
        .checkbox-wrapper label {
            display: flex;
            align-items: start;
            cursor: pointer;
            font-size: 14px;
            color: #555;
        }
        
        .checkbox-wrapper input[type="checkbox"] {
            margin-right: 10px;
            margin-top: 3px;
            cursor: pointer;
        }
        
        .help-text {
            font-size: 12px;
            color: #777;
            margin-top: 5px;
            padding-left: 24px;
        }
        
        .input-group {
            margin-bottom: 20px;
        }
        
        .input-group label {
            display: block;
            margin-bottom: 8px;
            font-size: 14px;
            font-weight: 500;
            color: #333;
        }
        
        .input-group input[type="text"] {
            width: 100%;
            padding: 12px;
            border: 2px solid #e0e0e0;
            border-radius: 6px;
            font-size: 14px;
            transition: border-color 0.3s;
        }
        
        .input-group input[type="text"]:focus {
            outline: none;
            border-color: #667eea;
        }
        
        .sso-buttons {
            display: flex;
            flex-direction: column;
            gap: 12px;
        }
        
        .sso-button {
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 14px 20px;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            background: white;
            cursor: pointer;
            font-size: 15px;
            font-weight: 500;
            transition: all 0.3s;
            text-decoration: none;
            color: #333;
        }
        
        .sso-button:hover {
            border-color: #667eea;
            background: #f8f9ff;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(102, 126, 234, 0.2);
        }
        
        .sso-button img {
            width: 20px;
            height: 20px;
            margin-right: 12px;
        }
        
        .google-button {
            border-color: #4285f4;
        }
        
        .google-button:hover {
            border-color: #4285f4;
            background: #f1f5ff;
        }
        
        .microsoft-button {
            border-color: #00a4ef;
        }
        
        .microsoft-button:hover {
            border-color: #00a4ef;
            background: #f0f9ff;
        }
        
        .footer {
            margin-top: 30px;
            text-align: center;
            font-size: 12px;
            color: #999;
        }
        <?php echo getTestBannerCSS(); ?>
    </style>
</head>
<body>
<?php echo renderTestBanner(); ?>
    <div class="container">
        <div style="text-align: center; margin-bottom: 25px;">
            <a href="https://decision-collective.fr/" target="_blank" title="Découvrir le concept">
                <img src="https://decision-collective.fr/wp-content/uploads/2021/12/logov7long.png" alt="Décision Collective" style="height: 50px; width: auto;">
            </a>
        </div>
        <h1>Connexion</h1>
        <p class="subtitle">Authentification respectueuse de votre vie privée</p>
        
        <form id="loginForm" method="POST" action="oauth-redirect.php">
            <div class="privacy-section">
                <h2>Vos données</h2>
                
                <div class="checkbox-wrapper">
                    <label>
                        <input type="checkbox" name="consent_email_hash" value="1" id="consentCheckbox">
                        <span>Autoriser le stockage de mon email crypté pour recevoir les notifications de vote.</span>
                    </label>
                </div>
                
                <div class="input-group">
                    <label for="displayName">Pseudo (optionnel)</label>
                    <input type="text" 
                           id="displayName" 
                           name="display_name" 
                           placeholder="Ex: Jean42" 
                           maxlength="50">
                    <p class="help-text">Ce nom sera visible si l'application l'affiche.</p>
                </div>
            </div>
            
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
            <input type="hidden" name="provider" id="providerInput" value="">
            
            <div class="sso-buttons">
                <button type="submit" name="provider" value="google" class="sso-button google-button">
                    <svg width="20" height="20" viewBox="0 0 24 24">
                        <path fill="#4285F4" d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z"/>
                        <path fill="#34A853" d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z"/>
                        <path fill="#FBBC05" d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l2.85-2.22.81-.62z"/>
                        <path fill="#EA4335" d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z"/>
                    </svg>
                    Se connecter avec Google
                </button>
                
                <button type="submit" name="provider" value="microsoft" class="sso-button microsoft-button">
                    <svg width="20" height="20" viewBox="0 0 23 23">
                        <path fill="#f25022" d="M0 0h11v11H0z"/>
                        <path fill="#00a4ef" d="M12 0h11v11H12z"/>
                        <path fill="#7fba00" d="M0 12h11v11H0z"/>
                        <path fill="#ffb900" d="M12 12h11v11H12z"/>
                    </svg>
                    Se connecter avec Microsoft
                </button>
            </div>
        </form>
        
        <div class="footer">
            Nous ne stockons que le strict nécessaire.<br>
            Aucune donnée n'est partagée avec des tiers.
        </div>
        <div style="text-align: center; margin-top: 20px;">
            <a href="https://buy.stripe.com/aEUeWy74mgRwc2Q8wB" target="_blank" style="display: inline-block; padding: 10px 20px; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; border-radius: 20px; font-weight: 500; text-decoration: none; font-size: 14px;">
                Soutenir le projet
            </a>
        </div>
    </div>
</body>
</html>
