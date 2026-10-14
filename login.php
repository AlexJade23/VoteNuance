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

        .mastodon-button {
            border-color: #6364ff;
        }

        .mastodon-button:hover {
            border-color: #6364ff;
            background: #f3f3ff;
        }

        .magiclink-button {
            border-color: #667eea;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }

        .magiclink-button:hover {
            border-color: #764ba2;
            background: linear-gradient(135deg, #764ba2 0%, #667eea 100%);
            color: white;
        }

        .separator {
            display: flex;
            align-items: center;
            margin: 20px 0;
            color: #999;
            font-size: 13px;
        }

        .separator::before,
        .separator::after {
            content: "";
            flex: 1;
            border-bottom: 1px solid #e0e0e0;
        }

        .separator::before {
            margin-right: 15px;
        }

        .separator::after {
            margin-left: 15px;
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

                <?php if (mastodonIsConfigured()): ?>
                <button type="submit" name="provider" value="mastodon" class="sso-button mastodon-button">
                    <svg width="20" height="20" viewBox="0 0 24 24">
                        <path fill="#6364FF" d="M23.268 5.313c-.35-2.578-2.617-4.61-5.304-5.004C17.51.242 15.792 0 11.813 0h-.03c-3.98 0-4.835.242-5.288.309C3.882.692 1.496 2.518.917 5.127.64 6.412.61 7.837.661 9.143c.074 1.874.088 3.745.26 5.611.118 1.24.325 2.47.62 3.68.55 2.237 2.777 4.098 4.96 4.857 2.336.792 4.849.923 7.256.38.265-.061.527-.132.786-.213.585-.184 1.27-.39 1.774-.753a.057.057 0 0 0 .023-.043v-1.809a.052.052 0 0 0-.02-.041.053.053 0 0 0-.046-.01 20.282 20.282 0 0 1-4.709.545c-2.73 0-3.463-1.284-3.674-1.818a5.593 5.593 0 0 1-.319-1.433.053.053 0 0 1 .066-.054c1.517.363 3.072.546 4.632.546.376 0 .75 0 1.125-.01 1.57-.044 3.224-.124 4.768-.422.038-.008.077-.015.11-.024 2.435-.464 4.753-1.92 4.989-5.604.008-.145.03-1.52.03-1.67.002-.512.167-3.63-.024-5.545zm-3.748 9.195h-2.561V8.29c0-1.309-.55-1.976-1.67-1.976-1.23 0-1.846.79-1.846 2.35v3.403h-2.546V8.663c0-1.56-.617-2.35-1.848-2.35-1.112 0-1.668.668-1.67 1.977v6.218H4.822V8.102c0-1.31.337-2.35 1.011-3.12.696-.77 1.608-1.164 2.74-1.164 1.311 0 2.302.5 2.962 1.498l.638 1.06.638-1.06c.66-.999 1.65-1.498 2.96-1.498 1.13 0 2.043.395 2.74 1.164.675.77 1.012 1.81 1.012 3.12z"/>
                    </svg>
                    Se connecter avec Mastodon
                </button>
                <p class="help-text">Mastodon ne transmet pas votre email : l'option ci-dessus est sans effet pour cette connexion.</p>
                <?php endif; ?>
            </div>
        </form>

        <div class="separator">ou</div>

        <a href="login-magiclink.php" class="sso-button magiclink-button" style="text-decoration: none;">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/>
                <polyline points="22,6 12,13 2,6"/>
            </svg>
            Se connecter par email
        </a>

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
