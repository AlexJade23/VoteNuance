<?php
require_once 'config.php';
require_once 'functions.php';

// Vérifier si l'utilisateur est connecté
if (!isLoggedIn()) {
    header('Location: login.php');
    exit;
}

// Récupérer l'utilisateur
$user = getCurrentUser();

if (!$user) {
    header('Location: logout.php');
    exit;
}

$message = '';
$error = '';

// Traiter les actions POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Vérifier le token CSRF
    if (!isset($_POST['csrf_token']) || !verifyCsrfToken($_POST['csrf_token'])) {
        $error = 'Erreur de sécurité';
    } else {
        $action = $_POST['action'] ?? '';
        
        switch ($action) {
            case 'delete_email_hash':
                deleteEmailHash($user['id']);
                $message = 'Le hash de votre email a été supprimé.';
                $user = getCurrentUser(); // Recharger
                break;
                
            case 'update_display_name':
                $newName = trim($_POST['new_display_name'] ?? '');
                if ($newName !== '') {
                    $newName = substr($newName, 0, 50);
                    $newName = htmlspecialchars($newName, ENT_QUOTES, 'UTF-8');
                }
                updateDisplayName($user['id'], $newName);
                $message = 'Votre pseudo a été mis à jour.';
                $user = getCurrentUser(); // Recharger
                break;
                
            case 'delete_account':
                // Supprimer le compte
                deleteUser($user['id']);
                logoutUser();
                header('Location: login.php?account_deleted=1');
                exit;
                break;
        }
    }
}

$csrfToken = generateCsrfToken();
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mes données personnelles</title>
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
        }
        
        .header h1 {
            color: #333;
            font-size: 28px;
            margin-bottom: 10px;
        }
        
        .breadcrumb {
            color: #666;
            font-size: 14px;
        }
        
        .breadcrumb a {
            color: #667eea;
            text-decoration: none;
        }
        
        .breadcrumb a:hover {
            text-decoration: underline;
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
        
        .message {
            padding: 15px;
            border-radius: 6px;
            margin-bottom: 20px;
        }
        
        .message.success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        
        .message.error {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
        
        .data-item {
            padding: 20px;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            margin-bottom: 15px;
        }
        
        .data-item h3 {
            color: #333;
            font-size: 16px;
            margin-bottom: 10px;
        }
        
        .data-item p {
            color: #666;
            margin-bottom: 15px;
            line-height: 1.6;
        }
        
        .data-value {
            background: #f8f9fa;
            padding: 12px;
            border-radius: 6px;
            font-family: monospace;
            color: #333;
            margin-bottom: 15px;
            word-break: break-all;
        }
        
        .form-group {
            margin-bottom: 20px;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: #333;
        }
        
        .form-group input[type="text"] {
            width: 100%;
            padding: 12px;
            border: 2px solid #e0e0e0;
            border-radius: 6px;
            font-size: 14px;
        }
        
        .form-group input[type="text"]:focus {
            outline: none;
            border-color: #667eea;
        }
        
        .btn {
            padding: 10px 20px;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 500;
            transition: all 0.3s;
        }
        
        .btn-primary {
            background: #667eea;
            color: white;
        }
        
        .btn-primary:hover {
            background: #5568d3;
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
        
        .danger-zone {
            border: 2px solid #dc3545;
            padding: 20px;
            border-radius: 8px;
            margin-top: 30px;
        }
        
        .danger-zone h3 {
            color: #dc3545;
            margin-bottom: 15px;
        }
        
        .danger-zone p {
            color: #666;
            margin-bottom: 15px;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>Mes données personnelles</h1>
            <div class="breadcrumb">
                <a href="dashboard.php">← Retour au tableau de bord</a>
            </div>
        </div>
        
        <?php if ($message): ?>
            <div class="message success"><?php echo htmlspecialchars($message); ?></div>
        <?php endif; ?>
        
        <?php if ($error): ?>
            <div class="message error"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>
        
        <div class="card">
            <h2>Données stockées</h2>
            
            <div class="data-item">
                <h3>Identifiant SSO (<?php echo ucfirst($user['sso_provider']); ?>)</h3>
                <p>Cet identifiant est <strong>nécessaire</strong> pour vous reconnecter. Il est opaque et ne permet pas de vous identifier.</p>
                <div class="data-value"><?php echo htmlspecialchars($user['sso_id']); ?></div>
            </div>
            
            <div class="data-item">
                <h3>Hash de l'email</h3>
                <?php if ($user['email_hash']): ?>
                    <p>Vous avez autorisé le stockage d'un hash irréversible de votre email pour éviter les doublons.</p>
                    <div class="data-value"><?php echo htmlspecialchars($user['email_hash']); ?></div>
                    <form method="POST" onsubmit="return confirm('Êtes-vous sûr de vouloir supprimer le hash de votre email ?');">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                        <input type="hidden" name="action" value="delete_email_hash">
                        <button type="submit" class="btn btn-secondary">Supprimer le hash email</button>
                    </form>
                <?php else: ?>
                    <p>Aucun hash d'email stocké. Votre confidentialité est maximale.</p>
                <?php endif; ?>
            </div>
            
            <div class="data-item">
                <h3>Pseudo</h3>
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                    <input type="hidden" name="action" value="update_display_name">
                    <div class="form-group">
                        <label>Modifier votre pseudo :</label>
                        <input type="text" 
                               name="new_display_name" 
                               value="<?php echo htmlspecialchars($user['display_name'] ?? ''); ?>"
                               placeholder="Laissez vide pour ne pas avoir de pseudo"
                               maxlength="50">
                    </div>
                    <button type="submit" class="btn btn-primary">Mettre à jour</button>
                </form>
            </div>
        </div>
        
        <div class="card">
            <div class="danger-zone">
                <h3>⚠️ Zone dangereuse</h3>
                <p>
                    <strong>Supprimer votre compte</strong><br>
                    Cette action est <strong>irréversible</strong>. Toutes vos données seront définitivement supprimées.
                </p>
                <form method="POST" onsubmit="return confirm('ATTENTION : Êtes-vous VRAIMENT sûr de vouloir supprimer définitivement votre compte ? Cette action est irréversible.');">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                    <input type="hidden" name="action" value="delete_account">
                    <button type="submit" class="btn btn-danger">Supprimer mon compte définitivement</button>
                </form>
            </div>
        </div>
    </div>
</body>
</html>
