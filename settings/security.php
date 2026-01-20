<?php
require_once '../config.php';
require_once '../functions.php';

// Verifier si l'utilisateur est connecte
if (!isLoggedIn()) {
    header('Location: ../login.php');
    exit;
}

$user = getCurrentUser();
if (!$user) {
    header('Location: ../logout.php');
    exit;
}

// Cette page ne fonctionne que pour les utilisateurs magiclink
if ($user['sso_provider'] !== 'magiclink') {
    $error = 'La double authentification n\'est disponible que pour les connexions par email.';
    $canManageTotp = false;
} else {
    $canManageTotp = true;
}

// Recuperer le JWT stocke en session
$accessToken = $_SESSION['magiclink_jwt'] ?? null;

$csrfToken = generateCsrfToken();
$error = $error ?? '';
$success = '';
$step = 'view'; // view, setup, confirm, disable
$totpSetupData = null;

// Recuperer les infos utilisateur depuis l'API si on a un token
$authUserInfo = null;
if ($accessToken && $canManageTotp) {
    $authUserInfo = authGetUserInfo($accessToken);
    if (!$authUserInfo) {
        // Token expire, demander reconnexion
        $error = 'Votre session a expire. Veuillez vous reconnecter pour gerer la double authentification.';
        $canManageTotp = false;
        unset($_SESSION['magiclink_jwt']);
    }
}

// Traitement des actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $canManageTotp && $accessToken) {
    if (!isset($_POST['csrf_token']) || !verifyCsrfToken($_POST['csrf_token'])) {
        $error = 'Erreur de securite : token CSRF invalide';
    } else {
        $action = $_POST['action'] ?? '';

        if ($action === 'start_setup') {
            // Demarrer le setup TOTP
            $totpSetupData = authTotpSetup($accessToken);
            if ($totpSetupData) {
                $_SESSION['totp_setup_data'] = $totpSetupData;
                $step = 'setup';
            } else {
                $error = 'Erreur lors de la generation du code TOTP.';
            }
        } elseif ($action === 'confirm_setup') {
            // Confirmer le setup avec le code
            $code = trim($_POST['totp_code'] ?? '');
            if (empty($code) || !preg_match('/^\d{6}$/', $code)) {
                $error = 'Veuillez entrer un code a 6 chiffres.';
                $totpSetupData = $_SESSION['totp_setup_data'] ?? null;
                $step = 'setup';
            } else {
                $result = authTotpConfirm($accessToken, $code);
                if ($result['success']) {
                    $success = 'Double authentification activee avec succes !';
                    unset($_SESSION['totp_setup_data']);
                    // Rafraichir les infos utilisateur
                    $authUserInfo = authGetUserInfo($accessToken);
                } else {
                    $error = $result['error'];
                    $totpSetupData = $_SESSION['totp_setup_data'] ?? null;
                    $step = 'setup';
                }
            }
        } elseif ($action === 'start_disable') {
            $step = 'disable';
        } elseif ($action === 'confirm_disable') {
            // Desactiver TOTP
            $code = trim($_POST['totp_code'] ?? '');
            if (empty($code) || !preg_match('/^\d{6}$/', $code)) {
                $error = 'Veuillez entrer un code a 6 chiffres.';
                $step = 'disable';
            } else {
                $result = authTotpDisable($accessToken, $code);
                if ($result['success']) {
                    $success = 'Double authentification desactivee.';
                    // Rafraichir les infos utilisateur
                    $authUserInfo = authGetUserInfo($accessToken);
                } else {
                    $error = $result['error'];
                    $step = 'disable';
                }
            }
        } elseif ($action === 'cancel') {
            $step = 'view';
            unset($_SESSION['totp_setup_data']);
        }
    }
}

$totpEnabled = $authUserInfo['totp_enabled'] ?? false;
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Securite - Decision Collective</title>
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
            max-width: 600px;
            margin: 0 auto;
        }

        .page-header {
            margin-bottom: 20px;
        }

        .page-header h1 {
            color: #333;
            font-size: 28px;
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

        .alert-info {
            background: #e7f3ff;
            border: 1px solid #b3d7ff;
            color: #004085;
        }

        .status-badge {
            display: inline-block;
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 14px;
            font-weight: 600;
        }

        .status-enabled {
            background: #d4edda;
            color: #155724;
        }

        .status-disabled {
            background: #f8d7da;
            color: #721c24;
        }

        .btn {
            display: inline-block;
            padding: 12px 24px;
            border: none;
            border-radius: 8px;
            font-size: 14px;
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

        .btn-danger {
            background: #dc3545;
            color: white;
        }

        .btn-danger:hover {
            background: #c82333;
        }

        .btn-secondary {
            background: #6c757d;
            color: white;
        }

        .btn-secondary:hover {
            background: #5a6268;
        }

        .totp-info {
            margin: 20px 0;
            line-height: 1.6;
            color: #555;
        }

        .qr-container {
            text-align: center;
            margin: 20px 0;
            padding: 20px;
            background: #f8f9fa;
            border-radius: 8px;
        }

        .qr-container img {
            max-width: 200px;
            border: 4px solid white;
            border-radius: 8px;
        }

        .secret-key {
            font-family: monospace;
            font-size: 18px;
            letter-spacing: 2px;
            background: #e9ecef;
            padding: 10px 15px;
            border-radius: 6px;
            margin: 10px 0;
            word-break: break-all;
        }

        .input-group {
            margin: 20px 0;
        }

        .input-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 500;
            color: #333;
        }

        .totp-input {
            width: 100%;
            max-width: 200px;
            padding: 12px;
            border: 2px solid #e0e0e0;
            border-radius: 6px;
            font-size: 24px;
            text-align: center;
            letter-spacing: 8px;
        }

        .totp-input:focus {
            outline: none;
            border-color: #667eea;
        }

        .recovery-codes {
            background: #fff3cd;
            border: 1px solid #ffc107;
            border-radius: 8px;
            padding: 15px;
            margin: 20px 0;
        }

        .recovery-codes h4 {
            color: #856404;
            margin-bottom: 10px;
        }

        .recovery-codes ul {
            list-style: none;
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 8px;
        }

        .recovery-codes li {
            font-family: monospace;
            font-size: 14px;
            background: white;
            padding: 5px 10px;
            border-radius: 4px;
        }

        .actions {
            display: flex;
            gap: 10px;
            margin-top: 20px;
        }

        .back-link {
            display: inline-block;
            margin-top: 20px;
            color: #667eea;
            text-decoration: none;
        }

        .back-link:hover {
            text-decoration: underline;
        }
    </style>
</head>
<body>
    <?php echo renderNavigation('settings'); ?>

    <div class="page-content">
        <div class="container">
            <div class="page-header">
                <h1>Securite</h1>
            </div>

            <?php if ($error): ?>
                <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>

            <?php if ($success): ?>
                <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
            <?php endif; ?>

            <div class="card">
                <h2>Double authentification (TOTP)</h2>

                <?php if (!$canManageTotp): ?>
                    <div class="alert alert-info">
                        <?php if ($user['sso_provider'] !== 'magiclink'): ?>
                            La double authentification n'est disponible que pour les connexions par email.
                            Vous etes connecte via <?php echo htmlspecialchars(ucfirst($user['sso_provider'])); ?>.
                        <?php else: ?>
                            Veuillez vous <a href="../login-magiclink.php">reconnecter</a> pour gerer la double authentification.
                        <?php endif; ?>
                    </div>

                <?php elseif ($step === 'view'): ?>
                    <p class="totp-info">
                        La double authentification ajoute une couche de securite supplementaire a votre compte.
                        En plus de votre email, vous devrez entrer un code genere par une application d'authentification.
                    </p>

                    <p style="margin: 20px 0;">
                        <strong>Statut actuel :</strong>
                        <?php if ($totpEnabled): ?>
                            <span class="status-badge status-enabled">Active</span>
                        <?php else: ?>
                            <span class="status-badge status-disabled">Desactive</span>
                        <?php endif; ?>
                    </p>

                    <form method="POST">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                        <?php if ($totpEnabled): ?>
                            <input type="hidden" name="action" value="start_disable">
                            <button type="submit" class="btn btn-danger">Desactiver la double authentification</button>
                        <?php else: ?>
                            <input type="hidden" name="action" value="start_setup">
                            <button type="submit" class="btn btn-primary">Activer la double authentification</button>
                        <?php endif; ?>
                    </form>

                <?php elseif ($step === 'setup'): ?>
                    <p class="totp-info">
                        Scannez le QR code ci-dessous avec votre application d'authentification
                        (Google Authenticator, Authy, etc.) ou entrez la cle manuellement.
                    </p>

                    <?php if ($totpSetupData): ?>
                        <div class="qr-container">
                            <img src="https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=<?php echo urlencode($totpSetupData['qr_uri']); ?>" alt="QR Code TOTP">
                        </div>

                        <p style="text-align: center;">
                            <strong>Cle secrete :</strong><br>
                            <span class="secret-key"><?php echo htmlspecialchars($totpSetupData['secret']); ?></span>
                        </p>

                        <?php if (!empty($totpSetupData['recovery_codes'])): ?>
                            <div class="recovery-codes">
                                <h4>Codes de recuperation</h4>
                                <p style="font-size: 13px; margin-bottom: 10px;">
                                    Conservez ces codes en lieu sur. Ils vous permettront de vous connecter si vous perdez acces a votre application.
                                </p>
                                <ul>
                                    <?php foreach ($totpSetupData['recovery_codes'] as $code): ?>
                                        <li><?php echo htmlspecialchars($code); ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                        <?php endif; ?>

                        <form method="POST">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                            <input type="hidden" name="action" value="confirm_setup">

                            <div class="input-group">
                                <label for="totp_code">Entrez le code affiche dans votre application :</label>
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

                            <div class="actions">
                                <button type="submit" class="btn btn-primary">Confirmer l'activation</button>
                                <button type="submit" name="action" value="cancel" class="btn btn-secondary">Annuler</button>
                            </div>
                        </form>
                    <?php endif; ?>

                <?php elseif ($step === 'disable'): ?>
                    <p class="totp-info">
                        Pour desactiver la double authentification, entrez un code de votre application.
                    </p>

                    <form method="POST">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                        <input type="hidden" name="action" value="confirm_disable">

                        <div class="input-group">
                            <label for="totp_code">Code de verification :</label>
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

                        <div class="actions">
                            <button type="submit" class="btn btn-danger">Confirmer la desactivation</button>
                            <button type="submit" name="action" value="cancel" class="btn btn-secondary">Annuler</button>
                        </div>
                    </form>
                <?php endif; ?>
            </div>

            <a href="../dashboard.php" class="back-link">Retour au tableau de bord</a>
        </div>

        <?php echo renderFooter(); ?>
    </div>
</body>
</html>
