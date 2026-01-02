<?php
require_once 'config.php';
require_once 'functions.php';

// Si deja connecte, rediriger vers dashboard
if (isLoggedIn()) {
    header('Location: dashboard.php');
    exit;
}

// Verifier qu'on a un token temporaire en session
if (!isset($_SESSION['magiclink_temp_token'])) {
    header('Location: login-magiclink.php');
    exit;
}

$csrfToken = generateCsrfToken();
$error = '';

// Traitement du formulaire
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || !verifyCsrfToken($_POST['csrf_token'])) {
        $error = 'Erreur de securite : token CSRF invalide';
    } else {
        $totpCode = trim($_POST['totp_code'] ?? '');

        if (empty($totpCode) || !preg_match('/^\d{6}$/', $totpCode)) {
            $error = 'Veuillez entrer un code a 6 chiffres.';
        } else {
            $sessionToken = $_SESSION['magiclink_temp_token'];
            $result = authVerifyTotp($sessionToken, $totpCode);

            if ($result['success']) {
                // TOTP verifie : finaliser la connexion
                $accessToken = $result['access_token'];
                $userInfo = authGetUserInfo($accessToken);

                if (!$userInfo) {
                    $error = 'Erreur lors de la recuperation des informations utilisateur.';
                } else {
                    // Recuperer les preferences
                    $consent = $_SESSION['magiclink_consent'] ?? [];
                    $email = $_SESSION['magiclink_email'] ?? '';
                    $emailHash = null;
                    if (($consent['email_hash'] ?? false) && $email) {
                        $emailHash = hash('sha256', strtolower(trim($email)));
                    }
                    $displayName = $consent['display_name'] ?? null;

                    // Creer ou retrouver l'utilisateur
                    $user = findOrCreateMagicLinkUser(
                        $userInfo,
                        $emailHash,
                        $displayName,
                        $consent['email_hash'] ?? false
                    );

                    // Creer la session PHP
                    $_SESSION['user_id'] = $user['id'];
                    $_SESSION['user_provider'] = 'magiclink';
                    $_SESSION['magiclink_jwt'] = $accessToken;

                    // Nettoyer les donnees temporaires
                    unset($_SESSION['magiclink_email']);
                    unset($_SESSION['magiclink_consent']);
                    unset($_SESSION['magiclink_temp_token']);

                    // Rediriger vers le dashboard
                    header('Location: dashboard.php');
                    exit;
                }
            } else {
                $error = $result['error'] ?? 'Code TOTP invalide.';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verification TOTP - Decision Collective</title>
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
            max-width: 400px;
            width: 100%;
            padding: 40px;
        }

        h1 {
            font-size: 24px;
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

        .icon-lock {
            text-align: center;
            margin-bottom: 20px;
        }

        .icon-lock svg {
            width: 60px;
            height: 60px;
            color: #667eea;
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
            text-align: center;
        }

        .totp-input {
            width: 100%;
            padding: 16px;
            border: 2px solid #e0e0e0;
            border-radius: 6px;
            font-size: 28px;
            text-align: center;
            letter-spacing: 12px;
            transition: border-color 0.3s;
        }

        .totp-input:focus {
            outline: none;
            border-color: #667eea;
        }

        .btn {
            display: block;
            width: 100%;
            padding: 14px 20px;
            border: none;
            border-radius: 8px;
            font-size: 15px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s;
            text-decoration: none;
            text-align: center;
        }

        .btn-primary {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(102, 126, 234, 0.4);
        }

        .alert {
            padding: 12px 16px;
            border-radius: 6px;
            margin-bottom: 20px;
            font-size: 14px;
        }

        .alert-error {
            background: #fee;
            border: 1px solid #fcc;
            color: #c33;
        }

        .back-link {
            display: block;
            text-align: center;
            margin-top: 20px;
            color: #667eea;
            text-decoration: none;
            font-size: 14px;
        }

        .back-link:hover {
            text-decoration: underline;
        }

        .help-text {
            text-align: center;
            font-size: 12px;
            color: #888;
            margin-top: 15px;
        }
        <?php echo getTestBannerCSS(); ?>
    </style>
</head>
<body>
<?php echo renderTestBanner(); ?>
    <div class="container">
        <div class="icon-lock">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <rect x="3" y="11" width="18" height="11" rx="2" ry="2"/>
                <path d="M7 11V7a5 5 0 0110 0v4"/>
            </svg>
        </div>

        <h1>Verification en deux etapes</h1>
        <p class="subtitle">Entrez le code de votre application d'authentification</p>

        <?php if ($error): ?>
            <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <form method="POST" action="">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">

            <div class="input-group">
                <label for="totp_code">Code a 6 chiffres</label>
                <input type="text"
                       id="totp_code"
                       name="totp_code"
                       class="totp-input"
                       pattern="\d{6}"
                       maxlength="6"
                       inputmode="numeric"
                       autocomplete="one-time-code"
                       required
                       autofocus>
            </div>

            <button type="submit" class="btn btn-primary">
                Verifier
            </button>
        </form>

        <p class="help-text">
            Ouvrez votre application d'authentification (Google Authenticator, Authy, etc.)
            et entrez le code affiche.
        </p>

        <a href="login-magiclink.php" class="back-link">Annuler et recommencer</a>
    </div>

    <script>
        // Auto-submit quand 6 chiffres sont entres
        document.getElementById('totp_code').addEventListener('input', function(e) {
            this.value = this.value.replace(/\D/g, '');
            if (this.value.length === 6) {
                this.form.submit();
            }
        });
    </script>
</body>
</html>
