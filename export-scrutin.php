#!/usr/bin/env php
<?php
/**
 * Export d'un scrutin de PRODUCTION vers TEST
 * Génère un fichier SQL prêt à importer
 *
 * Usage:
 *   php export-scrutin.php --code=ABC123 --target-user=5 --prod-host=localhost --prod-db=deco --prod-user=root --prod-pass=secret
 *
 * Options:
 *   --code          Code du scrutin à exporter (obligatoire)
 *   --target-user   ID utilisateur destinataire dans la base test (obligatoire)
 *   --prod-host     Hôte de la base de production (défaut: localhost)
 *   --prod-db       Nom de la base de production (défaut: deco)
 *   --prod-user     Utilisateur MySQL production (obligatoire)
 *   --prod-pass     Mot de passe MySQL production (obligatoire)
 *   --output        Fichier de sortie (défaut: export_[code]_[timestamp].sql)
 *   --new-code      Nouveau code pour le scrutin (défaut: code original + suffixe)
 *   --list-users    Liste les utilisateurs de la base test et quitte
 *   --help          Affiche cette aide
 */

// ============================================================================
// CONFIGURATION CLI
// ============================================================================

$options = getopt('', [
    'code:',
    'target-user:',
    'prod-host:',
    'prod-db:',
    'prod-user:',
    'prod-pass:',
    'output:',
    'new-code:',
    'list-users',
    'help'
]);

if (isset($options['help'])) {
    echo file_get_contents(__FILE__);
    preg_match('/\/\*\*[\s\S]*?\*\//', file_get_contents(__FILE__), $matches);
    echo "\n" . str_replace(['/**', '*/', ' * '], '', $matches[0]) . "\n";
    exit(0);
}

// Charger la config test pour lister les utilisateurs
require_once __DIR__ . '/config.php';

// ============================================================================
// FONCTION : LISTER LES UTILISATEURS TEST
// ============================================================================

function listTestUsers() {
    try {
        $pdo = new PDO(
            'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
            DB_USER,
            DB_PASS,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );

        $stmt = $pdo->query('
            SELECT id, sso_provider, display_name, created_at, last_login
            FROM users
            ORDER BY id
        ');
        $users = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($users)) {
            echo "Aucun utilisateur dans la base test.\n";
            echo "Créez d'abord un compte sur " . (defined('TEST_SITE_URL') ? TEST_SITE_URL : 'l\'environnement de test') . "\n";
            return;
        }

        echo "\n=== Utilisateurs disponibles dans la base TEST ===\n\n";
        echo sprintf("%-5s %-12s %-25s %-20s\n", "ID", "Provider", "Display Name", "Dernière connexion");
        echo str_repeat("-", 70) . "\n";

        foreach ($users as $user) {
            echo sprintf(
                "%-5d %-12s %-25s %-20s\n",
                $user['id'],
                $user['sso_provider'] ?? '-',
                substr($user['display_name'] ?? '(non défini)', 0, 24),
                $user['last_login'] ?? 'jamais'
            );
        }
        echo "\n";

    } catch (PDOException $e) {
        echo "Erreur de connexion à la base test: " . $e->getMessage() . "\n";
        exit(1);
    }
}

if (isset($options['list-users'])) {
    listTestUsers();
    exit(0);
}

// ============================================================================
// VALIDATION DES PARAMÈTRES
// ============================================================================

$errors = [];

if (empty($options['code'])) {
    $errors[] = "--code est obligatoire (code du scrutin à exporter)";
}

if (empty($options['target-user'])) {
    $errors[] = "--target-user est obligatoire (ID utilisateur destinataire)";
    echo "Astuce: utilisez --list-users pour voir les utilisateurs disponibles\n";
}

if (empty($options['prod-user'])) {
    $errors[] = "--prod-user est obligatoire (utilisateur MySQL production)";
}

if (empty($options['prod-pass'])) {
    $errors[] = "--prod-pass est obligatoire (mot de passe MySQL production)";
}

if (!empty($errors)) {
    echo "Erreurs:\n";
    foreach ($errors as $error) {
        echo "  - $error\n";
    }
    echo "\nUtilisez --help pour l'aide complète.\n";
    exit(1);
}

$scrutinCode = $options['code'];
$targetUserId = (int) $options['target-user'];
$prodHost = $options['prod-host'] ?? 'localhost';
$prodDb = $options['prod-db'] ?? 'deco';
$prodUser = $options['prod-user'];
$prodPass = $options['prod-pass'];
$outputFile = $options['output'] ?? 'export_' . $scrutinCode . '_' . date('Ymd_His') . '.sql';
$newCode = $options['new-code'] ?? null;

// ============================================================================
// CONNEXION À LA BASE DE PRODUCTION
// ============================================================================

echo "Connexion à la base de production ($prodHost/$prodDb)...\n";

try {
    $prodPdo = new PDO(
        "mysql:host=$prodHost;dbname=$prodDb;charset=utf8mb4",
        $prodUser,
        $prodPass,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ]
    );
} catch (PDOException $e) {
    echo "Erreur de connexion à la production: " . $e->getMessage() . "\n";
    exit(1);
}

echo "Connexion réussie.\n\n";

// ============================================================================
// RÉCUPÉRATION DU SCRUTIN
// ============================================================================

echo "Recherche du scrutin '$scrutinCode'...\n";

$stmt = $prodPdo->prepare('SELECT * FROM scrutins WHERE code = ?');
$stmt->execute([strtolower($scrutinCode)]);
$scrutin = $stmt->fetch();

if (!$scrutin) {
    echo "Erreur: Scrutin avec le code '$scrutinCode' non trouvé.\n";
    exit(1);
}

echo "Scrutin trouvé: \"{$scrutin['titre']}\"\n";
echo "  - ID original: {$scrutin['id']}\n";
echo "  - Créé le: {$scrutin['created_at']}\n";

// ============================================================================
// RÉCUPÉRATION DES DONNÉES LIÉES
// ============================================================================

$scrutinId = $scrutin['id'];

// Questions
$stmt = $prodPdo->prepare('SELECT * FROM questions WHERE scrutin_id = ? ORDER BY ordre');
$stmt->execute([$scrutinId]);
$questions = $stmt->fetchAll();
echo "  - Questions: " . count($questions) . "\n";

// Mapping ancien ID question -> index
$questionIdMap = [];
foreach ($questions as $idx => $q) {
    $questionIdMap[$q['id']] = $idx;
}

// Réponses possibles (pour QCM)
$reponsesPossibles = [];
foreach ($questions as $q) {
    $stmt = $prodPdo->prepare('SELECT * FROM reponses_possibles WHERE question_id = ? ORDER BY ordre');
    $stmt->execute([$q['id']]);
    $reponsesPossibles[$q['id']] = $stmt->fetchAll();
}
$totalReponses = array_sum(array_map('count', $reponsesPossibles));
echo "  - Réponses possibles (QCM): $totalReponses\n";

// Bulletins (votes)
$stmt = $prodPdo->prepare('SELECT * FROM bulletins WHERE scrutin_id = ? ORDER BY id');
$stmt->execute([$scrutinId]);
$bulletins = $stmt->fetchAll();
echo "  - Bulletins: " . count($bulletins) . "\n";

// Émargements
$stmt = $prodPdo->prepare('SELECT * FROM emargements WHERE scrutin_id = ? ORDER BY id');
$stmt->execute([$scrutinId]);
$emargements = $stmt->fetchAll();
echo "  - Émargements: " . count($emargements) . "\n";

// Jetons (optionnel)
$stmt = $prodPdo->prepare('SELECT * FROM jetons WHERE scrutin_id = ? ORDER BY id');
$stmt->execute([$scrutinId]);
$jetons = $stmt->fetchAll();
echo "  - Jetons: " . count($jetons) . "\n";

echo "\n";

// ============================================================================
// GÉNÉRATION DU NOUVEAU CODE
// ============================================================================

if (!$newCode) {
    // Générer un nouveau code unique basé sur l'original
    $baseCode = substr($scrutinCode, 0, 6);
    $suffix = substr(bin2hex(random_bytes(2)), 0, 2);
    $newCode = strtolower($baseCode . $suffix);
}

echo "Nouveau code du scrutin: $newCode\n";
echo "Propriétaire (target-user): $targetUserId\n\n";

// ============================================================================
// GÉNÉRATION DU SQL
// ============================================================================

$sql = [];
$sql[] = "-- ============================================================================";
$sql[] = "-- Export du scrutin '$scrutinCode' vers l'environnement de test";
$sql[] = "-- Généré le: " . date('Y-m-d H:i:s');
$sql[] = "-- Source: $prodDb@$prodHost";
$sql[] = "-- Scrutin original: \"{$scrutin['titre']}\" (ID: {$scrutin['id']})";
$sql[] = "-- Nouveau code: $newCode";
$sql[] = "-- Propriétaire cible: user_id = $targetUserId";
$sql[] = "-- ============================================================================";
$sql[] = "";
$sql[] = "SET FOREIGN_KEY_CHECKS = 0;";
$sql[] = "SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO';";
$sql[] = "";

// Variables pour les nouveaux IDs
$sql[] = "-- Variables pour capturer les nouveaux IDs";
$sql[] = "SET @new_scrutin_id = NULL;";
$sql[] = "";

// ============================================================================
// INSERT SCRUTIN
// ============================================================================

$sql[] = "-- ============================================================================";
$sql[] = "-- SCRUTIN";
$sql[] = "-- ============================================================================";

$scrutinValues = [
    'code' => $newCode,
    'titre' => $scrutin['titre'],
    'resume' => $scrutin['resume'],
    'notice' => $scrutin['notice'],
    'image_url' => $scrutin['image_url'],
    'debut_at' => $scrutin['debut_at'],
    'fin_at' => $scrutin['fin_at'],
    'nb_participants_attendus' => $scrutin['nb_participants_attendus'],
    'nb_gagnants' => $scrutin['nb_gagnants'],
    'format' => $scrutin['format'],
    'affiche_resultats' => $scrutin['affiche_resultats'],
    'est_public' => $scrutin['est_public'],
    'est_archive' => $scrutin['est_archive'],
    'ordre_mentions' => $scrutin['ordre_mentions'],
    'nb_mentions' => $scrutin['nb_mentions'] ?? 7,
    'type_notification' => $scrutin['type_notification'],
    'destination_notification' => $scrutin['destination_notification'],
    'owner_id' => $targetUserId,
];

$columns = array_keys($scrutinValues);
$placeholders = array_map(function($v) {
    return $v === null ? 'NULL' : "'" . addslashes($v) . "'";
}, array_values($scrutinValues));

$sql[] = "INSERT INTO scrutins (" . implode(', ', $columns) . ")";
$sql[] = "VALUES (" . implode(', ', $placeholders) . ");";
$sql[] = "";
$sql[] = "SET @new_scrutin_id = LAST_INSERT_ID();";
$sql[] = "SELECT CONCAT('Scrutin créé avec ID: ', @new_scrutin_id) AS info;";
$sql[] = "";

// ============================================================================
// INSERT QUESTIONS
// ============================================================================

$sql[] = "-- ============================================================================";
$sql[] = "-- QUESTIONS (" . count($questions) . ")";
$sql[] = "-- ============================================================================";

foreach ($questions as $idx => $q) {
    $sql[] = "";
    $sql[] = "-- Question " . ($idx + 1) . ": " . substr($q['titre'], 0, 50);
    $sql[] = "SET @new_question_$idx = NULL;";

    $qValues = [
        'scrutin_id' => '@SCRUTIN_ID@', // Placeholder
        'echelle_id' => $q['echelle_id'],
        'type_question' => $q['type_question'],
        'numero' => $q['numero'],
        'titre' => $q['titre'],
        'question' => $q['question'],
        'description' => $q['description'],
        'image_url' => $q['image_url'],
        'lot' => $q['lot'],
        'ordre' => $q['ordre'],
        'est_obligatoire' => $q['est_obligatoire'],
        'est_cle' => $q['est_cle'],
        'horodatage' => $q['horodatage'],
        'est_donnee_personnelle' => $q['est_donnee_personnelle'],
        'est_donnee_sensible' => $q['est_donnee_sensible'],
    ];

    $cols = array_keys($qValues);
    $vals = [];
    foreach ($qValues as $k => $v) {
        if ($k === 'scrutin_id') {
            $vals[] = '@new_scrutin_id';
        } elseif ($v === null) {
            $vals[] = 'NULL';
        } else {
            $vals[] = "'" . addslashes($v) . "'";
        }
    }

    $sql[] = "INSERT INTO questions (" . implode(', ', $cols) . ")";
    $sql[] = "VALUES (" . implode(', ', $vals) . ");";
    $sql[] = "SET @new_question_$idx = LAST_INSERT_ID();";

    // Réponses possibles pour cette question
    if (!empty($reponsesPossibles[$q['id']])) {
        $sql[] = "";
        $sql[] = "-- Réponses possibles pour question " . ($idx + 1);
        foreach ($reponsesPossibles[$q['id']] as $rp) {
            $rpValues = [
                "question_id" => "@new_question_$idx",
                "libelle" => "'" . addslashes($rp['libelle']) . "'",
                "ordre" => $rp['ordre'],
                "question_conditionnee_id" => 'NULL', // On ne gère pas les conditions pour simplifier
            ];
            $sql[] = "INSERT INTO reponses_possibles (question_id, libelle, ordre, question_conditionnee_id)";
            $sql[] = "VALUES ({$rpValues['question_id']}, {$rpValues['libelle']}, {$rpValues['ordre']}, {$rpValues['question_conditionnee_id']});";
        }
    }
}

$sql[] = "";

// ============================================================================
// INSERT BULLETINS
// ============================================================================

if (!empty($bulletins)) {
    $sql[] = "-- ============================================================================";
    $sql[] = "-- BULLETINS (" . count($bulletins) . ")";
    $sql[] = "-- ============================================================================";
    $sql[] = "";

    // Grouper les bulletins par batch pour optimiser
    $batchSize = 100;
    $batches = array_chunk($bulletins, $batchSize);

    foreach ($batches as $batchIdx => $batch) {
        $sql[] = "-- Batch " . ($batchIdx + 1) . "/" . count($batches);
        $sql[] = "INSERT INTO bulletins (scrutin_id, question_id, ballot_hash, vote_mention, reponse, vote_at, est_test, est_importe, imported_at) VALUES";

        $rows = [];
        foreach ($batch as $b) {
            // Trouver l'index de la question
            $qIdx = $questionIdMap[$b['question_id']] ?? null;
            if ($qIdx === null) continue;

            $row = [
                '@new_scrutin_id',
                "@new_question_$qIdx",
                "'" . addslashes($b['ballot_hash']) . "'",
                $b['vote_mention'] === null ? 'NULL' : $b['vote_mention'],
                $b['reponse'] === null ? 'NULL' : "'" . addslashes($b['reponse']) . "'",
                $b['vote_at'] === null ? 'NULL' : "'" . $b['vote_at'] . "'",
                $b['est_test'] ?? 0,
                1, // Marquer comme importé
                'NOW()'
            ];
            $rows[] = "(" . implode(", ", $row) . ")";
        }

        $sql[] = implode(",\n", $rows) . ";";
        $sql[] = "";
    }
}

// ============================================================================
// INSERT ÉMARGEMENTS
// ============================================================================

if (!empty($emargements)) {
    $sql[] = "-- ============================================================================";
    $sql[] = "-- ÉMARGEMENTS (" . count($emargements) . ")";
    $sql[] = "-- ============================================================================";
    $sql[] = "";
    $sql[] = "INSERT INTO emargements (scrutin_id, emarge_at, ip_hash) VALUES";

    $rows = [];
    foreach ($emargements as $e) {
        $rows[] = "(@new_scrutin_id, '" . $e['emarge_at'] . "', '" . addslashes($e['ip_hash']) . "')";
    }
    $sql[] = implode(",\n", $rows) . ";";
    $sql[] = "";
}

// ============================================================================
// INSERT JETONS (un jeton organisateur pour le target user)
// ============================================================================

$sql[] = "-- ============================================================================";
$sql[] = "-- JETON ORGANISATEUR";
$sql[] = "-- ============================================================================";
$sql[] = "";
$sql[] = "-- Créer un jeton organisateur pour l'utilisateur cible";
$orgTokenCode = strtoupper(bin2hex(random_bytes(4)));
$sql[] = "INSERT INTO jetons (scrutin_id, user_id, code, est_organisateur, est_utilise)";
$sql[] = "VALUES (@new_scrutin_id, $targetUserId, '$orgTokenCode', 1, 0);";
$sql[] = "";

// ============================================================================
// FINALISATION
// ============================================================================

$sql[] = "-- ============================================================================";
$sql[] = "-- FINALISATION";
$sql[] = "-- ============================================================================";
$sql[] = "";
$sql[] = "SET FOREIGN_KEY_CHECKS = 1;";
$sql[] = "SET SQL_MODE=@OLD_SQL_MODE;";
$sql[] = "";
$sql[] = "-- Résumé de l'import";
$sql[] = "SELECT ";
$sql[] = "  @new_scrutin_id AS scrutin_id,";
$sql[] = "  '$newCode' AS code,";
$sql[] = "  (SELECT COUNT(*) FROM questions WHERE scrutin_id = @new_scrutin_id) AS nb_questions,";
$sql[] = "  (SELECT COUNT(*) FROM bulletins WHERE scrutin_id = @new_scrutin_id) AS nb_bulletins,";
$sql[] = "  (SELECT COUNT(*) FROM emargements WHERE scrutin_id = @new_scrutin_id) AS nb_emargements;";
$sql[] = "";
$sql[] = "-- Pour accéder au scrutin: https://tst.de-co.fr/scrutin.php?code=$newCode";
$sql[] = "";

// ============================================================================
// ÉCRITURE DU FICHIER
// ============================================================================

$sqlContent = implode("\n", $sql);
file_put_contents($outputFile, $sqlContent);

echo "=== Export terminé ===\n";
echo "Fichier généré: $outputFile\n";
echo "Taille: " . round(filesize($outputFile) / 1024, 2) . " Ko\n";
echo "\n";
echo "Pour importer dans la base test:\n";
echo "  mysql -h " . DB_HOST . " -u " . DB_USER . " -p " . DB_NAME . " < $outputFile\n";
echo "\n";
echo "URL du scrutin importé: " . (defined('TEST_SITE_URL') ? TEST_SITE_URL : 'https://tst.de-co.fr') . "/scrutin.php?code=$newCode\n";
