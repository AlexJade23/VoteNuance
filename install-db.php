<?php
/**
 * Script d'installation de la base de données pour l'environnement de TEST
 *
 * Usage : Accéder à https://tst.de-co.fr/install-db.php
 *
 * ATTENTION : Ce script est uniquement pour l'environnement de test !
 * Il ne doit JAMAIS être déployé en production.
 */

// Sécurité : vérifier qu'on est bien en environnement de test
require_once __DIR__ . '/config.php';

if (!defined('IS_TEST_ENV') || IS_TEST_ENV !== true) {
    die('ERREUR : Ce script ne peut être exécuté qu\'en environnement de test.');
}

// Token de sécurité pour éviter les exécutions accidentelles
$INSTALL_TOKEN = 'install-deco-test-2024';

$token = $_GET['token'] ?? '';
$action = $_GET['action'] ?? '';

?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Installation BDD - Environnement TEST</title>
    <style>
        * { box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: #1a1a2e;
            color: #eee;
            padding: 20px;
            max-width: 900px;
            margin: 0 auto;
        }
        .banner {
            background: linear-gradient(135deg, #ff6b35, #f7931e);
            color: white;
            padding: 15px 20px;
            border-radius: 8px;
            margin-bottom: 20px;
            text-align: center;
            font-weight: bold;
        }
        .card {
            background: #16213e;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 20px;
        }
        h1 { color: #f7931e; margin-top: 0; }
        h2 { color: #7cb342; margin-top: 0; }
        .success { background: #1b5e20; padding: 10px 15px; border-radius: 5px; margin: 10px 0; }
        .error { background: #b71c1c; padding: 10px 15px; border-radius: 5px; margin: 10px 0; }
        .warning { background: #e65100; padding: 10px 15px; border-radius: 5px; margin: 10px 0; }
        .info { background: #0d47a1; padding: 10px 15px; border-radius: 5px; margin: 10px 0; }
        pre {
            background: #0f0f23;
            padding: 15px;
            border-radius: 5px;
            overflow-x: auto;
            font-size: 12px;
            max-height: 300px;
            overflow-y: auto;
        }
        code { color: #7cb342; }
        .btn {
            display: inline-block;
            background: #7cb342;
            color: white;
            padding: 12px 24px;
            border-radius: 5px;
            text-decoration: none;
            font-weight: bold;
            margin: 5px;
        }
        .btn:hover { background: #689f38; }
        .btn-danger { background: #d32f2f; }
        .btn-danger:hover { background: #b71c1c; }
        table { width: 100%; border-collapse: collapse; margin: 15px 0; }
        th, td { padding: 10px; text-align: left; border-bottom: 1px solid #333; }
        th { background: #0d47a1; }
    </style>
</head>
<body>

<div class="banner">
    ⚠️ ENVIRONNEMENT DE TEST - tst.de-co.fr - Ce script installe la BDD de test
</div>

<?php

// Vérification du token
if ($token !== $INSTALL_TOKEN) {
    echo '<div class="card">';
    echo '<h1>Installation Base de Données TEST</h1>';
    echo '<p>Ce script permet d\'initialiser la base de données <code>deco_test</code>.</p>';
    echo '<div class="warning">⚠️ Pour des raisons de sécurité, un token est requis.</div>';
    echo '<p>Utilisez le lien suivant :</p>';
    echo '<pre>https://tst.de-co.fr/install-db.php?token=' . $INSTALL_TOKEN . '</pre>';
    echo '</div>';
    exit;
}

// Connexion à la BDD
try {
    $pdo = new PDO(
        'mysql:host=' . DB_HOST . ';charset=utf8mb4',
        DB_USER,
        DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
} catch (PDOException $e) {
    echo '<div class="error">Erreur de connexion : ' . htmlspecialchars($e->getMessage()) . '</div>';
    exit;
}

// Afficher les infos de connexion
echo '<div class="card">';
echo '<h1>Installation Base de Données TEST</h1>';
echo '<table>';
echo '<tr><th>Paramètre</th><th>Valeur</th></tr>';
echo '<tr><td>Hôte</td><td><code>' . htmlspecialchars(DB_HOST) . '</code></td></tr>';
echo '<tr><td>Base cible</td><td><code>' . htmlspecialchars(DB_NAME) . '</code></td></tr>';
echo '<tr><td>Utilisateur</td><td><code>' . htmlspecialchars(DB_USER) . '</code></td></tr>';
echo '</table>';
echo '</div>';

// Vérifier si la base existe
$stmt = $pdo->query("SHOW DATABASES LIKE '" . DB_NAME . "'");
$dbExists = $stmt->rowCount() > 0;

// Action : installation
if ($action === 'install') {
    echo '<div class="card">';
    echo '<h2>Exécution de l\'installation...</h2>';

    $errors = [];
    $successes = [];

    // 1. Créer la base si elle n'existe pas
    try {
        $pdo->exec("CREATE DATABASE IF NOT EXISTS `" . DB_NAME . "` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $successes[] = "Base de données `" . DB_NAME . "` créée ou déjà existante";
    } catch (PDOException $e) {
        $errors[] = "Création BDD : " . $e->getMessage();
    }

    // 2. Se connecter à la base
    try {
        $pdo->exec("USE `" . DB_NAME . "`");
        $successes[] = "Connexion à `" . DB_NAME . "` réussie";
    } catch (PDOException $e) {
        $errors[] = "Connexion BDD : " . $e->getMessage();
        goto end_install;
    }

    // 3. Lire et adapter le schéma (remplacer 'deco' par DB_NAME)
    $schemaFile = __DIR__ . '/database.schema.sql';
    if (!file_exists($schemaFile)) {
        $errors[] = "Fichier database.schema.sql introuvable";
        goto end_install;
    }

    $schema = file_get_contents($schemaFile);
    // Retirer les lignes CREATE DATABASE et USE car on est déjà connecté
    $schema = preg_replace('/CREATE DATABASE.*?;/s', '', $schema);
    $schema = preg_replace('/USE\s+\w+\s*;/s', '', $schema);

    // 4. Exécuter le schéma
    $statements = array_filter(
        array_map('trim', explode(';', $schema)),
        fn($s) => !empty($s) && !preg_match('/^--/', $s)
    );

    foreach ($statements as $i => $stmt) {
        if (empty(trim($stmt))) continue;
        try {
            $pdo->exec($stmt);
            // Extraire le nom de la table/vue pour le log
            if (preg_match('/CREATE\s+(?:TABLE|VIEW)\s+(?:IF\s+NOT\s+EXISTS\s+)?(?:OR\s+REPLACE\s+)?(\w+)/i', $stmt, $m)) {
                $successes[] = "Créé : " . $m[1];
            } elseif (preg_match('/INSERT\s+INTO\s+(\w+)/i', $stmt, $m)) {
                $successes[] = "Données insérées dans : " . $m[1];
            }
        } catch (PDOException $e) {
            // Ignorer les erreurs "already exists"
            if (strpos($e->getMessage(), 'already exists') === false &&
                strpos($e->getMessage(), 'Duplicate') === false) {
                $errors[] = "SQL Error: " . $e->getMessage();
            }
        }
    }

    // 5. Exécuter les migrations
    $migrationsFile = __DIR__ . '/database.migrations.sql';
    if (file_exists($migrationsFile)) {
        $migrations = file_get_contents($migrationsFile);
        $statements = array_filter(
            array_map('trim', explode(';', $migrations)),
            fn($s) => !empty($s) && !preg_match('/^--/', $s)
        );

        foreach ($statements as $stmt) {
            if (empty(trim($stmt))) continue;
            try {
                $pdo->exec($stmt);
                if (preg_match('/ALTER\s+TABLE\s+(\w+)/i', $stmt, $m)) {
                    $successes[] = "Migration : ALTER " . $m[1];
                } elseif (preg_match('/CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?(\w+)/i', $stmt, $m)) {
                    $successes[] = "Migration : CREATE " . $m[1];
                }
            } catch (PDOException $e) {
                // Ignorer "Duplicate column" (migration déjà appliquée)
                if (strpos($e->getMessage(), 'Duplicate column') === false &&
                    strpos($e->getMessage(), 'already exists') === false) {
                    $errors[] = "Migration: " . $e->getMessage();
                }
            }
        }
    }

    end_install:

    // Afficher le résultat
    if (count($errors) === 0) {
        echo '<div class="success">✅ Installation terminée avec succès !</div>';
    } else {
        echo '<div class="error">❌ Installation terminée avec des erreurs</div>';
    }

    echo '<h3>Opérations réussies (' . count($successes) . ')</h3>';
    echo '<pre>';
    foreach ($successes as $s) {
        echo "✓ " . htmlspecialchars($s) . "\n";
    }
    echo '</pre>';

    if (count($errors) > 0) {
        echo '<h3>Erreurs (' . count($errors) . ')</h3>';
        echo '<pre style="color: #ff6b6b;">';
        foreach ($errors as $e) {
            echo "✗ " . htmlspecialchars($e) . "\n";
        }
        echo '</pre>';
    }

    echo '<p><a href="/" class="btn">Aller à l\'accueil</a></p>';
    echo '</div>';

} elseif ($action === 'check') {
    // Vérifier les tables existantes
    echo '<div class="card">';
    echo '<h2>État de la base de données</h2>';

    if (!$dbExists) {
        echo '<div class="warning">La base `' . DB_NAME . '` n\'existe pas encore.</div>';
    } else {
        $pdo->exec("USE `" . DB_NAME . "`");
        $stmt = $pdo->query("SHOW TABLES");
        $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);

        if (count($tables) === 0) {
            echo '<div class="warning">La base existe mais ne contient aucune table.</div>';
        } else {
            echo '<div class="success">La base contient ' . count($tables) . ' table(s)</div>';
            echo '<table>';
            echo '<tr><th>Table</th><th>Nombre de lignes</th></tr>';
            foreach ($tables as $table) {
                $count = $pdo->query("SELECT COUNT(*) FROM `$table`")->fetchColumn();
                echo '<tr><td><code>' . htmlspecialchars($table) . '</code></td><td>' . $count . '</td></tr>';
            }
            echo '</table>';
        }
    }
    echo '</div>';

} else {
    // Menu principal
    echo '<div class="card">';
    echo '<h2>Actions disponibles</h2>';
    echo '<p><a href="?token=' . $INSTALL_TOKEN . '&action=check" class="btn">Vérifier l\'état de la BDD</a></p>';
    echo '<p><a href="?token=' . $INSTALL_TOKEN . '&action=install" class="btn btn-danger">Installer / Réinstaller la BDD</a></p>';
    echo '<div class="info">ℹ️ L\'installation crée les tables si elles n\'existent pas. Les tables existantes ne sont pas supprimées.</div>';
    echo '</div>';
}

?>

<div class="card">
    <h2>Après l'installation</h2>
    <p>Une fois la base installée, <strong>supprimez ce fichier</strong> du serveur pour des raisons de sécurité :</p>
    <pre>rm install-db.php</pre>
    <p>Ou retirez-le de la liste des fichiers déployés dans <code>deploy-test.sh</code>.</p>
</div>

</body>
</html>
