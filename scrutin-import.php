<?php
/**
 * Import scrutin depuis fichier XLS (XML Spreadsheet)
 * Cree un nouveau scrutin a partir d'un fichier exporte
 */

require_once 'config.php';
require_once 'functions.php';

// Verifier que l'utilisateur est connecte
if (!isLoggedIn()) {
    header('Location: login.php');
    exit;
}

$user = getCurrentUser();
$csrfToken = generateCsrfToken();
$errors = [];
$success = false;
$preview = null;

// Traitement du formulaire
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Token de securite invalide';
    } elseif (isset($_POST['action']) && $_POST['action'] === 'confirm') {
        // Confirmation de l'import depuis les donnees de session
        if (isset($_SESSION['import_preview'])) {
            $data = $_SESSION['import_preview'];

            // Traitement du code URL
            $code = trim($_POST['code'] ?? '');

            if (empty($code)) {
                // Cas 3: champ vide -> generation automatique
                $newCode = generateScrutinCode();
            } else {
                // Validation du format
                if (!preg_match('/^[a-z0-9\-]+$/', $code)) {
                    $errors[] = 'Le code ne peut contenir que des lettres minuscules, chiffres et tirets';
                    // Garder les donnees en session pour retry
                    $preview = $data;
                } elseif (scrutinCodeExists($code)) {
                    // Cas 4: code deja pris
                    $errors[] = 'Ce code est deja utilise. Veuillez en choisir un autre.';
                    $preview = $data;
                } else {
                    // Cas 1 & 2: code valide
                    $newCode = $code;
                }
            }

            // Si pas d'erreur, continuer la creation
            if (empty($errors)) {
                unset($_SESSION['import_preview']);

            try {
                // Creer le scrutin
                $scrutinId = createScrutin([
                    'code' => $newCode,
                    'titre' => $data['scrutin']['titre'] . ' (copie)',
                    'resume' => $data['scrutin']['resume'] ?? null,
                    'notice' => $data['scrutin']['notice'] ?? null,
                    'image_url' => $data['scrutin']['image_url'] ?? null,
                    'debut_at' => null, // Reset dates
                    'fin_at' => null,
                    'nb_participants_attendus' => $data['scrutin']['nb_participants_attendus'] ?? 0,
                    'nb_gagnants' => $data['scrutin']['nb_gagnants'] ?? 1,
                    'affiche_resultats' => $data['scrutin']['affiche_resultats'] ?? 0,
                    'est_public' => $data['scrutin']['est_public'] ?? 0,
                    'ordre_mentions' => $data['scrutin']['ordre_mentions'] ?? 0,
                    'owner_id' => $user['id']
                ]);

                // Creer les questions
                foreach ($data['questions'] as $ordre => $q) {
                    $questionId = createQuestion([
                        'scrutin_id' => $scrutinId,
                        'type_question' => $q['type_id'],
                        'echelle_id' => ($q['type_id'] == 0) ? 1 : null,
                        'titre' => $q['titre'],
                        'question' => $q['description'] ?? null,
                        'image_url' => $q['image_url'] ?? null,
                        'lot' => $q['lot'] ?? 0,
                        'ordre' => $ordre,
                        'est_obligatoire' => $q['obligatoire'] ?? 0
                    ]);

                    // Reponses QCM
                    if ($q['type_id'] == 4 && !empty($q['reponses'])) {
                        foreach ($q['reponses'] as $rOrdre => $libelle) {
                            createReponsePossible($questionId, $libelle, $rOrdre);
                        }
                    }
                }

                header('Location: /' . urlencode($newCode) . '/v/?created=1');
                exit;

            } catch (Exception $e) {
                $errors[] = 'Erreur lors de la creation : ' . $e->getMessage();
            }
            } // Fin if (empty($errors))
        } else {
            $errors[] = 'Donnees d\'import expirees. Veuillez recommencer.';
        }
    } else {
        // Upload et parsing du fichier
        if (!isset($_FILES['xlsfile']) || $_FILES['xlsfile']['error'] !== UPLOAD_ERR_OK) {
            $errors[] = 'Erreur lors de l\'upload du fichier';
        } else {
            $file = $_FILES['xlsfile'];

            // Verifier la taille (5 Mo max)
            if ($file['size'] > 5 * 1024 * 1024) {
                $errors[] = 'Fichier trop volumineux (max 5 Mo)';
            } else {
                // Lire et parser le fichier XML
                $content = file_get_contents($file['tmp_name']);
                $parsed = parseXmlSpreadsheet($content);

                if ($parsed === false) {
                    $errors[] = 'Format de fichier invalide. Utilisez un fichier exporte depuis Vote Nuance.';
                } else {
                    // Stocker en session pour confirmation
                    $_SESSION['import_preview'] = $parsed;
                    $preview = $parsed;
                }
            }
        }
    }
}

/**
 * Parser un fichier XML Spreadsheet
 */
function parseXmlSpreadsheet($content) {
    // Supprimer le BOM si present
    $content = preg_replace('/^\xEF\xBB\xBF/', '', $content);

    // Charger le XML
    libxml_use_internal_errors(true);
    $xml = simplexml_load_string($content);

    if ($xml === false) {
        return false;
    }

    // Namespaces
    $namespaces = $xml->getNamespaces(true);
    $ss = isset($namespaces['ss']) ? $namespaces['ss'] : 'urn:schemas-microsoft-com:office:spreadsheet';

    $xml->registerXPathNamespace('ss', $ss);

    $result = [
        'scrutin' => [],
        'questions' => [],
        'reponses_qcm' => []
    ];

    // Parser l'onglet Scrutin
    $worksheets = $xml->xpath('//ss:Worksheet');
    foreach ($worksheets as $ws) {
        $name = (string)$ws->attributes($ss)['Name'];

        if ($name === 'Scrutin') {
            $rows = $ws->xpath('.//ss:Row');
            foreach ($rows as $row) {
                $cells = $row->xpath('.//ss:Cell/ss:Data');
                if (count($cells) >= 2) {
                    $field = strtolower(trim((string)$cells[0]));
                    $value = trim((string)$cells[1]);

                    switch ($field) {
                        case 'titre': $result['scrutin']['titre'] = $value; break;
                        case 'code': $result['scrutin']['code'] = $value; break;
                        case 'resume': $result['scrutin']['resume'] = $value; break;
                        case 'notice': $result['scrutin']['notice'] = $value; break;
                        case 'image url': $result['scrutin']['image_url'] = $value; break;
                        case 'nb participants attendus': $result['scrutin']['nb_participants_attendus'] = intval($value); break;
                        case 'nb gagnants': $result['scrutin']['nb_gagnants'] = intval($value); break;
                        case 'affiche resultats': $result['scrutin']['affiche_resultats'] = ($value === 'Oui') ? 1 : 0; break;
                        case 'est public': $result['scrutin']['est_public'] = ($value === 'Oui') ? 1 : 0; break;
                        case 'ordre mentions': $result['scrutin']['ordre_mentions'] = intval($value); break;
                    }
                }
            }
        } elseif ($name === 'Questions') {
            $rows = $ws->xpath('.//ss:Row');
            $isHeader = true;
            foreach ($rows as $row) {
                if ($isHeader) { $isHeader = false; continue; }

                $cells = $row->xpath('.//ss:Cell/ss:Data');
                if (count($cells) >= 6) {
                    $result['questions'][] = [
                        'ordre' => intval((string)$cells[0]),
                        'titre' => (string)$cells[1],
                        'description' => (string)$cells[2],
                        'type' => (string)$cells[3],
                        'lot' => intval((string)$cells[4]),
                        'obligatoire' => ((string)$cells[5] === 'Oui') ? 1 : 0,
                        'type_id' => isset($cells[6]) ? intval((string)$cells[6]) : 0,
                        'image_url' => isset($cells[7]) ? (string)$cells[7] : '',
                        'reponses' => []
                    ];
                }
            }
        } elseif ($name === 'Reponses QCM') {
            $rows = $ws->xpath('.//ss:Row');
            $isHeader = true;
            foreach ($rows as $row) {
                if ($isHeader) { $isHeader = false; continue; }

                $cells = $row->xpath('.//ss:Cell/ss:Data');
                if (count($cells) >= 3) {
                    $questionTitle = (string)$cells[0];
                    $reponse = (string)$cells[2];

                    // Associer aux questions
                    foreach ($result['questions'] as &$q) {
                        if ($q['titre'] === $questionTitle) {
                            $q['reponses'][] = $reponse;
                            break;
                        }
                    }
                    unset($q);
                }
            }
        }
    }

    // Validation minimale
    if (empty($result['scrutin']['titre'])) {
        return false;
    }

    return $result;
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Importer un scrutin - Vote Nuance</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            background: #f5f5f5;
            min-height: 100vh;
        }
        .page-content { padding: 20px; }
        <?php echo getNavigationCSS(); ?>
        .container { max-width: 800px; margin: 0 auto; }
        .page-header { margin-bottom: 20px; }
        .page-header h1 { color: #333; font-size: 24px; }
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
            font-size: 18px;
            border-bottom: 2px solid #667eea;
            padding-bottom: 10px;
        }
        .form-group { margin-bottom: 20px; }
        label { display: block; margin-bottom: 6px; font-weight: 600; color: #444; }
        input[type="file"] {
            width: 100%;
            padding: 12px;
            border: 2px dashed #ddd;
            border-radius: 6px;
            background: #f8f9fa;
        }
        .btn {
            padding: 12px 24px;
            border: none;
            border-radius: 6px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
            display: inline-block;
        }
        .btn-primary { background: #667eea; color: white; }
        .btn-primary:hover { background: #5a6fd6; }
        .btn-secondary { background: #6c757d; color: white; }
        .btn-success { background: #28a745; color: white; }
        .alert {
            padding: 15px 20px;
            border-radius: 8px;
            margin-bottom: 20px;
        }
        .alert-error { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
        .alert-info { background: #e7f3ff; color: #0c5460; border: 1px solid #b8daff; }
        .preview-section { margin-top: 20px; }
        .preview-item {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 10px;
        }
        .preview-item strong { color: #333; }
        .preview-questions { margin-top: 15px; }
        .preview-question {
            background: white;
            border: 1px solid #e9ecef;
            padding: 12px;
            border-radius: 6px;
            margin-bottom: 8px;
        }
        .form-actions {
            display: flex;
            gap: 15px;
            margin-top: 20px;
        }
        .info-box {
            background: #e7f3ff;
            border: 1px solid #b8daff;
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 20px;
            font-size: 14px;
            color: #0c5460;
        }
    </style>
</head>
<body>
    <?php echo renderNavigation(''); ?>

    <div class="page-content">
    <div class="container">
        <div class="page-header">
            <h1>Importer un scrutin</h1>
        </div>

        <?php foreach ($errors as $error): ?>
        <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
        <?php endforeach; ?>

        <?php if ($preview): ?>
        <!-- Previsualisation -->
        <div class="card">
            <h2>Previsualisation de l'import</h2>

            <div class="preview-section">
                <div class="preview-item">
                    <strong>Titre :</strong> <?php echo htmlspecialchars($preview['scrutin']['titre']); ?> (copie)
                </div>
                <?php if (!empty($preview['scrutin']['resume'])): ?>
                <div class="preview-item">
                    <strong>Resume :</strong> <?php echo htmlspecialchars($preview['scrutin']['resume']); ?>
                </div>
                <?php endif; ?>
                <div class="preview-item">
                    <strong>Nombre de questions :</strong> <?php echo count($preview['questions']); ?>
                </div>
                <div class="preview-item">
                    <strong>Type :</strong> <?php echo $preview['scrutin']['est_public'] ? 'Public' : 'Prive'; ?>
                </div>
            </div>

            <div class="preview-questions">
                <h3 style="margin: 20px 0 10px; font-size: 16px;">Questions :</h3>
                <?php foreach ($preview['questions'] as $i => $q): ?>
                <div class="preview-question">
                    <strong><?php echo ($i + 1) . '. ' . htmlspecialchars($q['titre']); ?></strong>
                    <span style="color: #666; font-size: 13px;"> (<?php echo htmlspecialchars($q['type']); ?>)</span>
                    <?php if (!empty($q['reponses'])): ?>
                    <div style="margin-top: 5px; font-size: 13px; color: #666;">
                        Reponses : <?php echo htmlspecialchars(implode(', ', $q['reponses'])); ?>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>

            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                <input type="hidden" name="action" value="confirm">

                <div class="form-group" style="margin-top: 20px;">
                    <label for="code">Code URL <small>(laissez vide pour generation automatique)</small></label>
                    <input type="text" id="code" name="code"
                           value="<?php echo htmlspecialchars($preview['scrutin']['code'] ?? ''); ?>"
                           placeholder="ex: mon-scrutin-2024" pattern="[a-z0-9\-]*"
                           style="max-width: 400px;">
                    <small style="display: block; margin-top: 5px; color: #666;">
                        Lettres minuscules, chiffres et tirets uniquement. Ce code definit l'URL d'acces au scrutin.
                    </small>
                </div>

                <div class="alert alert-info" style="margin-top: 20px;">
                    Les dates de debut/fin seront reinitialises.
                </div>

                <div class="form-actions">
                    <a href="/scrutin-import.php" class="btn btn-secondary">Annuler</a>
                    <button type="submit" class="btn btn-success">Confirmer l'import</button>
                </div>
            </form>
        </div>

        <?php else: ?>
        <!-- Formulaire d'upload -->
        <div class="card">
            <h2>Charger un fichier</h2>

            <div class="info-box">
                Importez un fichier XLS exporte depuis Vote Nuance pour creer un nouveau scrutin base sur ce modele.
                <br><br>
                <strong>Note :</strong> Les dates de debut/fin seront reinitialises et vous deviendrez le proprietaire du nouveau scrutin.
            </div>

            <form method="POST" enctype="multipart/form-data">
                <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">

                <div class="form-group">
                    <label for="xlsfile">Fichier XLS</label>
                    <input type="file" id="xlsfile" name="xlsfile" accept=".xls,.xml" required>
                </div>

                <div class="form-actions">
                    <a href="/mes-scrutins.php" class="btn btn-secondary">Annuler</a>
                    <button type="submit" class="btn btn-primary">Analyser le fichier</button>
                </div>
            </form>
        </div>
        <?php endif; ?>
    </div>

    <?php echo renderFooter(); ?>
    </div>
</body>
</html>
