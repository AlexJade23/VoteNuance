<?php
/**
 * Execute les migrations de base de donnees
 * Usage: https://tst.de-co.fr/migrate.php?token=migrate-deco-test-2024
 */

require_once 'config.php';
require_once 'functions.php';

// Protection par token
$token = $_GET['token'] ?? '';
if ($token !== 'migrate-deco-test-2024') {
    header('HTTP/1.1 403 Forbidden');
    exit('Token invalide. Utilisez: migrate.php?token=migrate-deco-test-2024');
}

// Verifier qu'on est en environnement de test
if (!defined('IS_TEST_ENV') || IS_TEST_ENV !== true) {
    exit('Ce script ne fonctionne que sur l\'environnement de test.');
}

echo "<h1>Migrations de la base de donnees de test</h1>\n";

try {
    $pdo = getDbConnection();

    // Verifier si la colonne nb_mentions existe
    $stmt = $pdo->query("SHOW COLUMNS FROM scrutins LIKE 'nb_mentions'");
    $exists = $stmt->rowCount() > 0;

    if ($exists) {
        echo "<p style='color:green'>✓ La colonne nb_mentions existe deja.</p>\n";
    } else {
        echo "<p>Migration 004: Ajout de la colonne nb_mentions...</p>\n";

        $pdo->exec("
            ALTER TABLE scrutins
            ADD COLUMN nb_mentions TINYINT UNSIGNED DEFAULT 7
            COMMENT 'Nombre de mentions: 3, 5 ou 7 (defaut: 7)'
        ");

        echo "<p style='color:green'>✓ Colonne nb_mentions ajoutee avec succes!</p>\n";
    }

    // Migration 005 : format de la page de vote (issue #16)
    $stmt = $pdo->query("SHOW COLUMNS FROM scrutins LIKE 'format_vote'");
    if ($stmt->rowCount() > 0) {
        echo "<p style='color:green'>✓ La colonne format_vote existe deja.</p>\n";
    } else {
        echo "<p>Migration 005: Ajout de la colonne format_vote...</p>\n";

        $pdo->exec("
            ALTER TABLE scrutins
            ADD COLUMN format_vote TINYINT UNSIGNED DEFAULT 1
            COMMENT 'Format de la page de vote: 1 classique, 2 mobile first (defaut: 1)'
        ");

        echo "<p style='color:green'>✓ Colonne format_vote ajoutee avec succes!</p>\n";
    }

    // Migration 006 : jeu de couleurs du format de vote 2 (issue #16)
    $stmt = $pdo->query("SHOW COLUMNS FROM scrutins LIKE 'palette_vote'");
    if ($stmt->rowCount() > 0) {
        echo "<p style='color:green'>✓ La colonne palette_vote existe deja.</p>\n";
    } else {
        echo "<p>Migration 006: Ajout de la colonne palette_vote...</p>\n";

        $pdo->exec("
            ALTER TABLE scrutins
            ADD COLUMN palette_vote TINYINT UNSIGNED DEFAULT 1
            COMMENT 'Couleurs du format de vote 2: 1 bleu/orange, 2 classique, 3 violet/vert'
        ");

        echo "<p style='color:green'>✓ Colonne palette_vote ajoutee avec succes!</p>\n";
    }

    // Migration 007 : connexion Mastodon (issue #17)
    // Les fichiers de schema declaraient ENUM('google','microsoft') alors que le code
    // utilise aussi 'magiclink' : on lit le type reel et on conserve toutes ses valeurs.
    $stmt = $pdo->query("SHOW COLUMNS FROM users LIKE 'sso_provider'");
    $ssoProviderType = $stmt->fetch(PDO::FETCH_ASSOC)['Type'] ?? '';
    echo "<p>Type actuel de users.sso_provider : " . htmlspecialchars($ssoProviderType) . "</p>\n";

    if (stripos($ssoProviderType, 'enum(') !== 0) {
        echo "<p style='color:green'>✓ sso_provider n'est pas un ENUM : 'mastodon' est deja accepte.</p>\n";
    } else {
        preg_match_all("/'([^']*)'/", $ssoProviderType, $matches);
        $providers = $matches[1];

        if (in_array('mastodon', $providers, true)) {
            echo "<p style='color:green'>✓ La valeur mastodon existe deja dans sso_provider.</p>\n";
        } else {
            echo "<p>Migration 007: Ajout de la valeur mastodon a sso_provider...</p>\n";

            foreach (['google', 'microsoft', 'magiclink', 'mastodon'] as $provider) {
                if (!in_array($provider, $providers, true)) {
                    $providers[] = $provider;
                }
            }
            $enumValues = implode(', ', array_map([$pdo, 'quote'], $providers));

            $pdo->exec("ALTER TABLE users MODIFY COLUMN sso_provider ENUM($enumValues) NOT NULL");

            echo "<p style='color:green'>✓ sso_provider accepte maintenant : " . htmlspecialchars($enumValues) . "</p>\n";
        }
    }

    // Afficher la structure actuelle de la table scrutins
    echo "<h2>Structure de la table scrutins:</h2>\n";
    echo "<pre>\n";
    $stmt = $pdo->query("DESCRIBE scrutins");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        echo htmlspecialchars(implode(' | ', $row)) . "\n";
    }
    echo "</pre>\n";

    echo "<h2>Migrations terminees</h2>\n";
    echo "<p><a href='/'>Retour a l'accueil</a></p>\n";

} catch (PDOException $e) {
    echo "<p style='color:red'>Erreur: " . htmlspecialchars($e->getMessage()) . "</p>\n";
}
