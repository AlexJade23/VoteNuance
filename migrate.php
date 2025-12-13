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
