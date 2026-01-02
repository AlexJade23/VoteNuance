<?php
require_once 'config.php';
require_once 'functions.php';

// Si deja connecte, rediriger vers dashboard
if (isLoggedIn()) {
    header('Location: dashboard.php');
    exit;
}

$csrfToken = generateCsrfToken();
$step = 'email'; // email, code
$email = '';
$error = '';
$success = '';

// Traitement du formulaire
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || !verifyCsrfToken($_POST['csrf_token'])) {
        $error = 'Erreur de securite : token CSRF invalide';
    } else {
        $action = $_POST['action'] ?? '';

        if ($action === 'request_code') {
            // Etape 1 : Demander le magic link
            $email = strtolower(trim($_POST['email'] ?? ''));

            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $error = 'Adresse email invalide';
            } else {
                $result = authRequestMagicLink($email);
                if ($result['success']) {
                    $_SESSION['magiclink_email'] = $email;
                    $_SESSION['magiclink_consent'] = [
                        'email_hash' => isset($_POST['consent_email_hash']) && $_POST['consent_email_hash'] === '1',
                        'display_name' => trim($_POST['display_name'] ?? '')
                    ];
                    $step = 'code';
                    $success = 'Un code de connexion a ete envoye a votre adresse email.';
                } else {
                    // On affiche quand meme le formulaire de code pour ne pas reveler si l'email existe
                    $_SESSION['magiclink_email'] = $email;
                    $_SESSION['magiclink_consent'] = [
                        'email_hash' => isset($_POST['consent_email_hash']) && $_POST['consent_email_hash'] === '1',
                        'display_name' => trim($_POST['display_name'] ?? '')
                    ];
                    $step = 'code';
                    $success = 'Si ce compte existe, un code de connexion a ete envoye.';
                }
            }
        } elseif ($action === 'verify_code') {
            // Etape 2 : Verifier le code
            $email = $_SESSION['magiclink_email'] ?? '';
            $code = strtoupper(trim($_POST['code'] ?? ''));

            if (empty($email)) {
                $error = 'Session expiree, veuillez recommencer.';
                $step = 'email';
            } elseif (empty($code)) {
                $error = 'Veuillez entrer le code recu par email.';
                $step = 'code';
            } else {
                $result = authVerifyCode($email, $code);

                if ($result['success']) {
                    if ($result['requires_totp']) {
                        // TOTP active : rediriger vers la page TOTP
                        $_SESSION['magiclink_temp_token'] = $result['access_token'];
                        header('Location: totp-verify.php');
                        exit;
                    } else {
                        // Connexion reussie : finaliser
                        $accessToken = $result['access_token'];
                        $userInfo = authGetUserInfo($accessToken);

                        if (!$userInfo) {
                            $error = 'Erreur lors de la recuperation des informations utilisateur.';
                            $step = 'code';
                        } else {
                            // Recuperer les preferences
                            $consent = $_SESSION['magiclink_consent'] ?? [];
                            $emailHash = null;
                            if ($consent['email_hash'] ?? false) {
                                $emailHash = hash('sha256', $email);
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

                            // Nettoyer les donnees temporaires
                            unset($_SESSION['magiclink_email']);
                            unset($_SESSION['magiclink_consent']);
                            unset($_SESSION['magiclink_temp_token']);

                            // Rediriger vers le dashboard
                            header('Location: dashboard.php');
                            exit;
                        }
                    }
                } else {
                    $error = $result['error'] ?? 'Code invalide ou expire.';
                    $step = 'code';
                }
            }
        } elseif ($action === 'resend_code') {
            // Renvoyer le code
            $email = $_SESSION['magiclink_email'] ?? '';
            if (empty($email)) {
                $error = 'Session expiree, veuillez recommencer.';
                $step = 'email';
            } else {
                $result = authRequestMagicLink($email);
                $step = 'code';
                $success = 'Un nouveau code a ete envoye a votre adresse email.';
            }
        } elseif ($action === 'back') {
            // Retour a l'etape email
            $step = 'email';
            unset($_SESSION['magiclink_email']);
        }
    }
}

// Si on revient sur cette page avec un email en session, afficher le formulaire de code
if ($step === 'email' && isset($_SESSION['magiclink_email'])) {
    $email = $_SESSION['magiclink_email'];
    $step = 'code';
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Connexion par email - Decision Collective</title>
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

        .input-group input[type="text"],
        .input-group input[type="email"] {
            width: 100%;
            padding: 12px;
            border: 2px solid #e0e0e0;
            border-radius: 6px;
            font-size: 14px;
            transition: border-color 0.3s;
        }

        .input-group input:focus {
            outline: none;
            border-color: #667eea;
        }

        .code-input {
            text-align: center;
            font-size: 24px;
            letter-spacing: 8px;
            text-transform: uppercase;
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

        .btn-secondary {
            background: #f0f0f0;
            color: #333;
            margin-top: 10px;
        }

        .btn-secondary:hover {
            background: #e0e0e0;
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

        .alert-success {
            background: #efe;
            border: 1px solid #cfc;
            color: #363;
        }

        .email-display {
            background: #f8f9fa;
            padding: 12px;
            border-radius: 6px;
            text-align: center;
            margin-bottom: 20px;
            font-size: 14px;
            color: #555;
        }

        .email-display strong {
            color: #333;
        }

        .footer {
            margin-top: 30px;
            text-align: center;
            font-size: 12px;
            color: #999;
        }

        .back-link {
            display: inline-block;
            margin-top: 20px;
            color: #667eea;
            text-decoration: none;
            font-size: 14px;
        }

        .back-link:hover {
            text-decoration: underline;
        }

        .resend-info {
            text-align: center;
            margin-top: 15px;
            font-size: 13px;
            color: #666;
        }
        <?php echo getTestBannerCSS(); ?>
    </style>
</head>
<body>
<?php echo renderTestBanner(); ?>
    <div class="container">
        <div style="text-align: center; margin-bottom: 25px;">
            <a href="https://decision-collective.fr/" target="_blank" title="Decouvrir le concept">
                <img src="https://decision-collective.fr/wp-content/uploads/2021/12/logov7long.png" alt="Decision Collective" style="height: 50px; width: auto;">
            </a>
        </div>

        <h1>Connexion par email</h1>
        <p class="subtitle">Recevez un code de connexion par email</p>

        <?php if ($error): ?>
            <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <?php if ($success): ?>
            <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
        <?php endif; ?>

        <?php if ($step === 'email'): ?>
            <form method="POST" action="">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                <input type="hidden" name="action" value="request_code">

                <div class="privacy-section">
                    <h2>Vos donnees</h2>

                    <div class="checkbox-wrapper">
                        <label>
                            <input type="checkbox" name="consent_email_hash" value="1">
                            <span>Autoriser le stockage de mon email crypte pour recevoir les notifications de vote.</span>
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

                <div class="input-group">
                    <label for="email">Adresse email</label>
                    <input type="email"
                           id="email"
                           name="email"
                           placeholder="votre@email.com"
                           required
                           autofocus>
                </div>

                <button type="submit" class="btn btn-primary">
                    Recevoir le code de connexion
                </button>
            </form>

        <?php elseif ($step === 'code'): ?>
            <div class="email-display">
                Code envoye a <strong><?php echo htmlspecialchars($_SESSION['magiclink_email'] ?? ''); ?></strong>
            </div>

            <form method="POST" action="">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                <input type="hidden" name="action" value="verify_code">

                <div class="input-group">
                    <label for="code">Code de connexion</label>
                    <input type="text"
                           id="code"
                           name="code"
                           class="code-input"
                           placeholder="ABC123"
                           maxlength="10"
                           autocomplete="off"
                           required
                           autofocus>
                    <p class="help-text" style="text-align: center;">Entrez le code recu par email</p>
                </div>

                <button type="submit" class="btn btn-primary">
                    Verifier le code
                </button>
            </form>

            <form method="POST" action="" style="margin-top: 10px;">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                <input type="hidden" name="action" value="back">
                <button type="submit" class="btn btn-secondary">
                    Utiliser une autre adresse email
                </button>
            </form>

            <form method="POST" action="" style="display: inline;">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                <input type="hidden" name="action" value="resend_code">
                <p class="resend-info">
                    Vous n'avez pas recu le code ? Verifiez vos spams ou
                    <button type="submit" style="background: none; border: none; color: #667eea; cursor: pointer; text-decoration: underline; font-size: inherit; padding: 0;">renvoyer le code</button>
                </p>
            </form>

        <?php endif; ?>

        <div style="text-align: center;">
            <a href="login.php" class="back-link">Retour aux autres methodes de connexion</a>
        </div>

        <div class="footer">
            Nous ne stockons que le strict necessaire.<br>
            Aucune donnee n'est partagee avec des tiers.
        </div>
    </div>
</body>
</html>
