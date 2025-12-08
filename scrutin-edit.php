<?php
require_once 'config.php';
require_once 'functions.php';

if (!isLoggedIn()) {
    header('Location: login.php');
    exit;
}

$user = getCurrentUser();
if (!$user) {
    header('Location: logout.php');
    exit;
}

$code = $_GET['code'] ?? '';
if (empty($code)) {
    header('Location: mes-scrutins.php');
    exit;
}

$scrutin = getScrutinByCode($code);
if (!$scrutin || $scrutin['owner_id'] != $user['id']) {
    header('Location: mes-scrutins.php');
    exit;
}

// Bloquer modification si des votes existent
$hasVotes = ($scrutin['nb_votes'] ?? 0) > 0;
if ($hasVotes) {
    header('Location: /' . urlencode($scrutin['code']) . '/v/?error=votes_exist');
    exit;
}

$questions = getQuestionsByScrutin($scrutin['id']);
$errors = [];

// Traitement du formulaire
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Token de sécurité invalide';
    } else {
        $titre = trim($_POST['titre'] ?? '');
        $resume = trim($_POST['resume'] ?? '');
        $notice = trim($_POST['notice'] ?? '');
        $image_url = trim($_POST['image_url'] ?? '');
        $debut_at = $_POST['debut_at'] ?? null;
        $fin_at = $_POST['fin_at'] ?? null;
        $nb_participants = intval($_POST['nb_participants_attendus'] ?? 0);
        $nb_gagnants = intval($_POST['nb_gagnants'] ?? 1);
        $affiche_resultats = isset($_POST['affiche_resultats']) ? 1 : 0;
        $est_public = isset($_POST['est_public']) ? 1 : 0;
        $ordre_mentions = intval($_POST['ordre_mentions'] ?? 0);

        if (empty($titre)) {
            $errors[] = 'Le titre est obligatoire';
        }

        if ($debut_at && $fin_at && strtotime($fin_at) <= strtotime($debut_at)) {
            $errors[] = 'La date de fin doit être après la date de début';
        }

        $newQuestions = $_POST['questions'] ?? [];
        if (empty($newQuestions)) {
            $errors[] = 'Au moins une question est requise';
        }

        // Validation des lots > 0 : seuls types 0 (Vote Nuancé) et 3 (Préféré du lot) autorisés
        // Le lot 0 n'est pas un vrai lot, il accepte tous les types
        $lotTypes = [];
        foreach ($newQuestions as $q) {
            $lot = intval($q['lot'] ?? 0);
            $type = intval($q['type'] ?? 0);
            if ($lot > 0) { // Seulement pour les vrais lots
                if (!isset($lotTypes[$lot])) {
                    $lotTypes[$lot] = [];
                }
                $lotTypes[$lot][] = $type;
            }
        }
        foreach ($lotTypes as $lotNum => $types) {
            foreach ($types as $type) {
                if ($type !== 0 && $type !== 3) {
                    $errors[] = "Le lot $lotNum ne peut contenir que des questions Vote Nuancé ou Préféré du lot";
                    break 2;
                }
            }
        }

        if (empty($errors)) {
            try {
                updateScrutin($scrutin['id'], [
                    'titre' => $titre,
                    'resume' => $resume ?: null,
                    'notice' => $notice ?: null,
                    'image_url' => $image_url ?: null,
                    'debut_at' => $debut_at ?: null,
                    'fin_at' => $fin_at ?: null,
                    'nb_participants_attendus' => $nb_participants,
                    'nb_gagnants' => $nb_gagnants,
                    'affiche_resultats' => $affiche_resultats,
                    'est_public' => $est_public,
                    'ordre_mentions' => $ordre_mentions
                ]);

                // Supprimer les anciennes questions et recréer
                deleteQuestionsByScrutin($scrutin['id']);

                foreach ($newQuestions as $ordre => $q) {
                    if (empty(trim($q['titre'] ?? ''))) continue;

                    $questionId = createQuestion([
                        'scrutin_id' => $scrutin['id'],
                        'type_question' => intval($q['type'] ?? 0),
                        'echelle_id' => ($q['type'] == 0) ? 1 : null,
                        'titre' => trim($q['titre']),
                        'question' => trim($q['description'] ?? '') ?: null,
                        'image_url' => trim($q['image_url'] ?? '') ?: null,
                        'lot' => intval($q['lot'] ?? 0),
                        'ordre' => $ordre,
                        'est_obligatoire' => isset($q['obligatoire']) ? 1 : 0
                    ]);

                    if ($q['type'] == 4 && !empty($q['reponses'])) {
                        $reponses = array_filter(array_map('trim', explode("\n", $q['reponses'])));
                        foreach ($reponses as $rOrdre => $libelle) {
                            createReponsePossible($questionId, $libelle, $rOrdre);
                        }
                    }
                }

                header('Location: /' . urlencode($code) . '/v/?updated=1');
                exit;

            } catch (Exception $e) {
                $errors[] = 'Erreur lors de la mise à jour : ' . $e->getMessage();
            }
        }

        // Recharger les données du formulaire
        $scrutin['titre'] = $titre;
        $scrutin['resume'] = $resume;
        $scrutin['notice'] = $notice;
        $scrutin['debut_at'] = $debut_at;
        $scrutin['fin_at'] = $fin_at;
        $scrutin['nb_participants_attendus'] = $nb_participants;
        $scrutin['nb_gagnants'] = $nb_gagnants;
        $scrutin['affiche_resultats'] = $affiche_resultats;
        $scrutin['est_public'] = $est_public;
    }
}

// Préparer les questions pour JS
$questionsData = [];
foreach ($questions as $q) {
    $reponses = '';
    if ($q['type_question'] == 4) {
        $reps = getReponsesPossibles($q['id']);
        $reponses = implode("\n", array_column($reps, 'libelle'));
    }
    $questionsData[] = [
        'type' => $q['type_question'],
        'titre' => $q['titre'],
        'description' => $q['question'] ?? '',
        'image_url' => $q['image_url'] ?? '',
        'lot' => $q['lot'],
        'obligatoire' => $q['est_obligatoire'],
        'reponses' => $reponses
    ];
}

$csrfToken = generateCsrfToken();
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Modifier le scrutin - Vote Nuancé</title>
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
            max-width: 900px;
            margin: 0 auto;
        }

        .page-header {
            margin-bottom: 20px;
        }

        .page-header h1 {
            color: #333;
            font-size: 24px;
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
            font-size: 18px;
            border-bottom: 2px solid #667eea;
            padding-bottom: 10px;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-row {
            display: flex;
            gap: 20px;
        }

        .form-row .form-group {
            flex: 1;
        }

        label {
            display: block;
            margin-bottom: 6px;
            font-weight: 600;
            color: #444;
        }

        label .required {
            color: #dc3545;
        }

        label small {
            font-weight: normal;
            color: #888;
        }

        input[type="text"],
        input[type="number"],
        input[type="datetime-local"],
        textarea,
        select {
            width: 100%;
            padding: 12px;
            border: 1px solid #ddd;
            border-radius: 6px;
            font-size: 14px;
        }

        input:focus,
        textarea:focus,
        select:focus {
            outline: none;
            border-color: #667eea;
        }

        textarea {
            resize: vertical;
            min-height: 80px;
        }

        .checkbox-group {
            display: flex;
            gap: 30px;
            flex-wrap: wrap;
        }

        .checkbox-item {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .checkbox-item input[type="checkbox"] {
            width: 18px;
            height: 18px;
        }

        .error-box {
            background: #f8d7da;
            color: #721c24;
            padding: 15px;
            border-radius: 6px;
            margin-bottom: 20px;
            border: 1px solid #f5c6cb;
        }

        .error-box ul {
            margin: 0;
            padding-left: 20px;
        }

        .code-display {
            background: #f8f9fa;
            padding: 10px 15px;
            border-radius: 6px;
            font-family: monospace;
            color: #666;
        }

        #questions-container {
            margin-top: 10px;
        }

        .question-card {
            background: #f8f9fa;
            border: 1px solid #e9ecef;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 15px;
            position: relative;
        }

        .question-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
        }

        .question-number {
            background: #667eea;
            color: white;
            width: 28px;
            height: 28px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            font-size: 14px;
        }

        .question-actions {
            display: flex;
            gap: 8px;
        }

        .btn-icon {
            background: none;
            border: 1px solid #ddd;
            width: 32px;
            height: 32px;
            border-radius: 6px;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .btn-icon:hover {
            background: #f0f0f0;
        }

        .btn-icon.danger:hover {
            background: #dc3545;
            border-color: #dc3545;
            color: white;
        }

        .question-fields {
            display: grid;
            grid-template-columns: 1fr 200px;
            gap: 15px;
        }

        .reponses-field {
            margin-top: 15px;
            display: none;
        }

        .reponses-field.visible {
            display: block;
        }

        .question-options {
            margin-top: 15px;
            display: flex;
            gap: 20px;
            flex-wrap: wrap;
        }

        .lot-field {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .lot-field input {
            width: 80px;
        }

        .btn {
            padding: 12px 24px;
            border: none;
            border-radius: 6px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
        }

        .btn-primary {
            background: #667eea;
            color: white;
        }

        .btn-secondary {
            background: #6c757d;
            color: white;
        }

        .btn-success {
            background: #28a745;
            color: white;
        }

        .btn-outline {
            background: white;
            border: 2px dashed #667eea;
            color: #667eea;
        }

        .btn-outline:hover {
            background: #667eea;
            color: white;
            border-style: solid;
        }

        .form-actions {
            display: flex;
            gap: 15px;
            justify-content: flex-end;
            margin-top: 20px;
            padding-top: 20px;
            border-top: 1px solid #eee;
        }

        #add-question-btn {
            width: 100%;
            padding: 15px;
            margin-top: 10px;
        }

        .type-indicator {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 4px;
            font-size: 11px;
            font-weight: 600;
            margin-left: 10px;
        }

        .type-0 { background: #667eea; color: white; }
        .type-1 { background: #17a2b8; color: white; }
        .type-2 { background: #6c757d; color: white; }
        .type-3 { background: #fd7e14; color: white; }
        .type-4 { background: #20c997; color: white; }

        /* Styles upload images */
        .image-upload-container {
            display: flex;
            align-items: center;
            gap: 15px;
            flex-wrap: wrap;
        }
        .image-preview {
            position: relative;
            width: 120px;
            height: 80px;
            border-radius: 6px;
            overflow: hidden;
            border: 1px solid #ddd;
        }
        .image-preview img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        .btn-remove-image {
            position: absolute;
            top: 4px;
            right: 4px;
            width: 22px;
            height: 22px;
            border-radius: 50%;
            background: rgba(220,53,69,0.9);
            color: white;
            border: none;
            cursor: pointer;
            font-size: 14px;
            line-height: 1;
        }
        .btn-upload {
            display: inline-flex;
            align-items: center;
            padding: 10px 16px;
            background: #f8f9fa;
            border: 2px dashed #ddd;
            border-radius: 6px;
            cursor: pointer;
            font-size: 13px;
            color: #666;
            transition: all 0.2s;
        }
        .btn-upload:hover {
            border-color: #667eea;
            color: #667eea;
        }
        .upload-progress {
            width: 120px;
            height: 6px;
            background: #e9ecef;
            border-radius: 3px;
            overflow: hidden;
        }
        .upload-progress .progress-bar {
            height: 100%;
            background: #667eea;
            width: 0;
            transition: width 0.3s;
        }
    </style>
</head>
<body>
    <?php echo renderNavigation(''); ?>

    <div class="page-content">
    <div class="container">
        <div class="page-header">
            <h1>Modifier le scrutin</h1>
        </div>

        <?php if (!empty($errors)): ?>
        <div class="error-box">
            <ul>
                <?php foreach ($errors as $error): ?>
                    <li><?php echo htmlspecialchars($error); ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php endif; ?>

        <form method="POST" id="scrutin-form">
            <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">

            <div class="card">
                <h2>Informations générales</h2>

                <div class="form-group">
                    <label>Code URL</label>
                    <div class="code-display"><?php echo htmlspecialchars($scrutin['code']); ?></div>
                </div>

                <div class="form-group">
                    <label for="titre">Titre du scrutin <span class="required">*</span></label>
                    <input type="text" id="titre" name="titre" required
                           value="<?php echo htmlspecialchars($scrutin['titre']); ?>">
                </div>

                <div class="form-group">
                    <label for="nb_gagnants">Nombre de gagnants</label>
                    <input type="number" id="nb_gagnants" name="nb_gagnants" min="1"
                           value="<?php echo htmlspecialchars($scrutin['nb_gagnants']); ?>">
                </div>

                <div class="form-group">
                    <label for="resume">Résumé</label>
                    <textarea id="resume" name="resume" rows="2"><?php echo htmlspecialchars($scrutin['resume'] ?? ''); ?></textarea>
                </div>

                <div class="form-group">
                    <label for="notice">Instructions pour les votants</label>
                    <textarea id="notice" name="notice" rows="3"><?php echo htmlspecialchars($scrutin['notice'] ?? ''); ?></textarea>
                </div>

                <div class="form-group">
                    <label>Image du scrutin</label>
                    <div class="image-upload-container" id="scrutin-image-container">
                        <input type="hidden" id="image_url" name="image_url" value="<?php echo htmlspecialchars($scrutin['image_url'] ?? ''); ?>">
                        <div class="image-preview" id="scrutin-image-preview" style="<?php echo empty($scrutin['image_url']) ? 'display:none' : ''; ?>">
                            <?php if (!empty($scrutin['image_url'])): ?>
                            <img src="<?php echo htmlspecialchars($scrutin['image_url']); ?>" alt="Aperçu">
                            <?php endif; ?>
                            <button type="button" class="btn-remove-image" onclick="removeImage('scrutin')">×</button>
                        </div>
                        <label class="btn-upload" id="scrutin-upload-label">
                            <input type="file" accept="image/*" onchange="uploadImage(this, 'scrutin')" style="display:none">
                            <span>Choisir une image</span>
                        </label>
                        <div class="upload-progress" id="scrutin-progress" style="display:none">
                            <div class="progress-bar"></div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="card">
                <h2>Temporalité</h2>

                <div class="form-row">
                    <div class="form-group">
                        <label for="debut_at">Date de début</label>
                        <input type="datetime-local" id="debut_at" name="debut_at"
                               value="<?php echo $scrutin['debut_at'] ? date('Y-m-d\TH:i', strtotime($scrutin['debut_at'])) : ''; ?>">
                    </div>
                    <div class="form-group">
                        <label for="fin_at">Date de fin</label>
                        <input type="datetime-local" id="fin_at" name="fin_at"
                               value="<?php echo $scrutin['fin_at'] ? date('Y-m-d\TH:i', strtotime($scrutin['fin_at'])) : ''; ?>">
                    </div>
                </div>

                <div class="form-group">
                    <label for="nb_participants_attendus">Nombre de participants attendus <small>(0 = illimité)</small></label>
                    <input type="number" id="nb_participants_attendus" name="nb_participants_attendus" min="0"
                           value="<?php echo htmlspecialchars($scrutin['nb_participants_attendus']); ?>">
                </div>
            </div>

            <div class="card">
                <h2>Options</h2>

                <div class="checkbox-group">
                    <label class="checkbox-item">
                        <input type="checkbox" name="affiche_resultats" value="1"
                               <?php echo $scrutin['affiche_resultats'] ? 'checked' : ''; ?>>
                        Afficher les résultats avant clôture
                    </label>

                    <label class="checkbox-item">
                        <input type="checkbox" name="est_public" value="1"
                               <?php echo $scrutin['est_public'] ? 'checked' : ''; ?>>
                        Scrutin public (visible par tous)
                    </label>
                </div>

                <div class="form-group" style="margin-top: 20px;">
                    <label>Ordre d'affichage des mentions</label>
                    <select name="ordre_mentions" style="max-width: 300px;">
                        <option value="0" <?php echo ($scrutin['ordre_mentions'] ?? 0) == 0 ? 'selected' : ''; ?>>
                            Contre → Pour (AC, FC, PC, SA, PP, FP, AP)
                        </option>
                        <option value="1" <?php echo ($scrutin['ordre_mentions'] ?? 0) == 1 ? 'selected' : ''; ?>>
                            Pour → Contre (AP, FP, PP, SA, PC, FC, AC)
                        </option>
                    </select>
                </div>
            </div>

            <div class="card">
                <h2>Questions</h2>

                <div id="questions-container">
                </div>

                <button type="button" id="add-question-btn" class="btn btn-outline">
                    + Ajouter une question
                </button>
            </div>

            <div class="form-actions">
                <a href="/<?php echo urlencode($code); ?>/v/" class="btn btn-secondary">Annuler</a>
                <button type="submit" class="btn btn-success">Enregistrer les modifications</button>
            </div>
        </form>
    </div>

    <script>
    (function() {
        const container = document.getElementById('questions-container');
        const addBtn = document.getElementById('add-question-btn');
        let questionCount = 0;

        const typeLabels = {
            0: 'Vote nuancé',
            1: 'Réponse ouverte',
            2: 'Séparateur',
            3: 'Préféré du lot',
            4: 'QCM'
        };

        const existingQuestions = <?php echo json_encode($questionsData); ?>;

        function createQuestionCard(data = {}) {
            const index = questionCount++;
            const card = document.createElement('div');
            card.className = 'question-card';
            card.dataset.index = index;

            const type = data.type || 0;
            const showReponses = type == 4;

            card.innerHTML = `
                <div class="question-header">
                    <div style="display: flex; align-items: center; gap: 10px;">
                        <span class="question-number">${index + 1}</span>
                        <span class="type-indicator type-${type}">${typeLabels[type]}</span>
                    </div>
                    <div class="question-actions">
                        <button type="button" class="btn-icon" onclick="moveQuestion(this, -1)" title="Monter">↑</button>
                        <button type="button" class="btn-icon" onclick="moveQuestion(this, 1)" title="Descendre">↓</button>
                        <button type="button" class="btn-icon danger" onclick="removeQuestion(this)" title="Supprimer">×</button>
                    </div>
                </div>

                <div class="question-fields">
                    <div class="form-group">
                        <label>Titre de la question <span class="required">*</span></label>
                        <input type="text" name="questions[${index}][titre]" required
                               value="${escapeHtml(data.titre || '')}">
                    </div>
                    <div class="form-group">
                        <label>Type</label>
                        <select name="questions[${index}][type]" onchange="updateQuestionType(this)">
                            <option value="0" ${type == 0 ? 'selected' : ''}>Vote nuancé (7 mentions)</option>
                            <option value="1" ${type == 1 ? 'selected' : ''}>Réponse ouverte</option>
                            <option value="2" ${type == 2 ? 'selected' : ''}>Séparateur (titre seul)</option>
                            <option value="3" ${type == 3 ? 'selected' : ''}>Préféré du lot</option>
                            <option value="4" ${type == 4 ? 'selected' : ''}>QCM</option>
                        </select>
                    </div>
                </div>

                <div class="form-group" style="margin-top: 15px;">
                    <label>Description / Contexte</label>
                    <textarea name="questions[${index}][description]" rows="2">${escapeHtml(data.description || '')}</textarea>
                </div>

                <div class="form-group">
                    <label>Image de la question</label>
                    <div class="image-upload-container">
                        <input type="hidden" name="questions[${index}][image_url]" class="question-image-url" value="${escapeHtml(data.image_url || '')}">
                        <div class="image-preview question-image-preview" style="${data.image_url ? '' : 'display:none'}">
                            ${data.image_url ? '<img src="' + escapeHtml(data.image_url) + '" alt="Apercu">' : ''}
                            <button type="button" class="btn-remove-image" onclick="removeQuestionImage(this)">×</button>
                        </div>
                        <label class="btn-upload">
                            <input type="file" accept="image/*" onchange="uploadQuestionImage(this)" style="display:none">
                            <span>Choisir une image</span>
                        </label>
                        <div class="upload-progress question-upload-progress" style="display:none">
                            <div class="progress-bar"></div>
                        </div>
                    </div>
                </div>

                <div class="reponses-field ${showReponses ? 'visible' : ''}">
                    <label>Réponses possibles</label>
                    <textarea name="questions[${index}][reponses]">${escapeHtml(data.reponses || '')}</textarea>
                    <small>Une réponse par ligne</small>
                </div>

                <div class="question-options">
                    <label class="checkbox-item">
                        <input type="checkbox" name="questions[${index}][obligatoire]" value="1"
                               ${data.obligatoire ? 'checked' : ''}>
                        Obligatoire
                    </label>
                    <div class="lot-field">
                        <label>Lot :</label>
                        <input type="number" name="questions[${index}][lot]" min="0"
                               value="${data.lot || 0}">
                        <small>(0 = aucun lot)</small>
                    </div>
                </div>
            `;

            return card;
        }

        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        window.updateQuestionType = function(select) {
            const card = select.closest('.question-card');
            const type = select.value;
            const indicator = card.querySelector('.type-indicator');
            const reponsesField = card.querySelector('.reponses-field');

            indicator.className = 'type-indicator type-' + type;
            indicator.textContent = typeLabels[type];

            if (type == 4) {
                reponsesField.classList.add('visible');
            } else {
                reponsesField.classList.remove('visible');
            }
        };

        window.removeQuestion = function(btn) {
            if (container.children.length <= 1) {
                alert('Au moins une question est requise');
                return;
            }
            btn.closest('.question-card').remove();
            renumberQuestions();
        };

        window.moveQuestion = function(btn, direction) {
            const card = btn.closest('.question-card');
            const cards = Array.from(container.children);
            const index = cards.indexOf(card);
            const newIndex = index + direction;

            if (newIndex < 0 || newIndex >= cards.length) return;

            if (direction < 0) {
                container.insertBefore(card, cards[newIndex]);
            } else {
                container.insertBefore(cards[newIndex], card);
            }
            renumberQuestions();
        };

        function renumberQuestions() {
            const cards = container.querySelectorAll('.question-card');
            cards.forEach((card, i) => {
                card.querySelector('.question-number').textContent = i + 1;
                card.querySelectorAll('[name^="questions["]').forEach(field => {
                    field.name = field.name.replace(/questions\[\d+\]/, `questions[${i}]`);
                });
            });
        }

        addBtn.addEventListener('click', function() {
            container.appendChild(createQuestionCard());
        });

        // Charger les questions existantes
        if (existingQuestions.length > 0) {
            existingQuestions.forEach(q => {
                container.appendChild(createQuestionCard(q));
            });
        } else {
            container.appendChild(createQuestionCard({ type: 0, obligatoire: true }));
        }
    })();

    // Fonctions d'upload d'images
    const csrfToken = '<?php echo $csrfToken; ?>';

    function uploadImage(input, prefix) {
        const file = input.files[0];
        if (!file) return;

        const preview = document.getElementById(prefix + '-image-preview');
        const progress = document.getElementById(prefix + '-progress');
        const hiddenInput = document.getElementById('image_url');
        const uploadLabel = document.getElementById(prefix + '-upload-label');

        if (!file.type.match(/^image\/(jpeg|png|gif|webp)$/)) {
            alert('Format non supporté. Utilisez JPG, PNG, GIF ou WebP.');
            return;
        }

        if (file.size > 5 * 1024 * 1024) {
            alert('Fichier trop volumineux (max 5 Mo)');
            return;
        }

        const formData = new FormData();
        formData.append('image', file);
        formData.append('csrf_token', csrfToken);

        progress.style.display = 'block';
        uploadLabel.style.display = 'none';
        const progressBar = progress.querySelector('.progress-bar');
        progressBar.style.width = '0%';

        const xhr = new XMLHttpRequest();
        xhr.open('POST', '/upload.php', true);

        xhr.upload.onprogress = function(e) {
            if (e.lengthComputable) {
                const percent = (e.loaded / e.total) * 100;
                progressBar.style.width = percent + '%';
            }
        };

        xhr.onload = function() {
            progress.style.display = 'none';
            uploadLabel.style.display = 'inline-flex';

            if (xhr.status === 200) {
                const response = JSON.parse(xhr.responseText);
                if (response.success) {
                    hiddenInput.value = response.url;
                    preview.innerHTML = '<img src="' + response.url + '" alt="Aperçu"><button type="button" class="btn-remove-image" onclick="removeImage(\'' + prefix + '\')">×</button>';
                    preview.style.display = 'block';
                } else {
                    alert(response.error || 'Erreur upload');
                }
            } else {
                alert('Erreur serveur');
            }
        };

        xhr.onerror = function() {
            progress.style.display = 'none';
            uploadLabel.style.display = 'inline-flex';
            alert('Erreur réseau');
        };

        xhr.send(formData);
    }

    function removeImage(prefix) {
        const preview = document.getElementById(prefix + '-image-preview');
        const hiddenInput = document.getElementById('image_url');
        preview.style.display = 'none';
        preview.innerHTML = '';
        hiddenInput.value = '';
    }

    function uploadQuestionImage(input) {
        const file = input.files[0];
        if (!file) return;

        const container = input.closest('.image-upload-container');
        const preview = container.querySelector('.question-image-preview');
        const progress = container.querySelector('.question-upload-progress');
        const hiddenInput = container.querySelector('.question-image-url');
        const uploadLabel = container.querySelector('.btn-upload');

        if (!file.type.match(/^image\/(jpeg|png|gif|webp)$/)) {
            alert('Format non supporté. Utilisez JPG, PNG, GIF ou WebP.');
            return;
        }

        if (file.size > 5 * 1024 * 1024) {
            alert('Fichier trop volumineux (max 5 Mo)');
            return;
        }

        const formData = new FormData();
        formData.append('image', file);
        formData.append('csrf_token', csrfToken);

        progress.style.display = 'block';
        uploadLabel.style.display = 'none';
        const progressBar = progress.querySelector('.progress-bar');
        progressBar.style.width = '0%';

        const xhr = new XMLHttpRequest();
        xhr.open('POST', '/upload.php', true);

        xhr.upload.onprogress = function(e) {
            if (e.lengthComputable) {
                const percent = (e.loaded / e.total) * 100;
                progressBar.style.width = percent + '%';
            }
        };

        xhr.onload = function() {
            progress.style.display = 'none';
            uploadLabel.style.display = 'inline-flex';

            if (xhr.status === 200) {
                const response = JSON.parse(xhr.responseText);
                if (response.success) {
                    hiddenInput.value = response.url;
                    preview.innerHTML = '<img src="' + response.url + '" alt="Aperçu"><button type="button" class="btn-remove-image" onclick="removeQuestionImage(this)">×</button>';
                    preview.style.display = 'block';
                } else {
                    alert(response.error || 'Erreur upload');
                }
            } else {
                alert('Erreur serveur');
            }
        };

        xhr.onerror = function() {
            progress.style.display = 'none';
            uploadLabel.style.display = 'inline-flex';
            alert('Erreur réseau');
        };

        xhr.send(formData);
    }

    function removeQuestionImage(btn) {
        const container = btn.closest('.image-upload-container');
        const preview = container.querySelector('.question-image-preview');
        const hiddenInput = container.querySelector('.question-image-url');
        preview.style.display = 'none';
        preview.innerHTML = '';
        hiddenInput.value = '';
    }
    </script>

    <?php echo renderFooter(); ?>
    </div>
</body>
</html>
