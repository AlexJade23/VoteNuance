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
        $debut_at = $_POST['debut_at'] ?? null;
        $fin_at = $_POST['fin_at'] ?? null;
        $nb_participants = intval($_POST['nb_participants_attendus'] ?? 0);
        $nb_gagnants = intval($_POST['nb_gagnants'] ?? 1);
        $affiche_resultats = isset($_POST['affiche_resultats']) ? 1 : 0;
        $est_public = isset($_POST['est_public']) ? 1 : 0;

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

        if (empty($errors)) {
            try {
                updateScrutin($scrutin['id'], [
                    'titre' => $titre,
                    'resume' => $resume ?: null,
                    'notice' => $notice ?: null,
                    'debut_at' => $debut_at ?: null,
                    'fin_at' => $fin_at ?: null,
                    'nb_participants_attendus' => $nb_participants,
                    'nb_gagnants' => $nb_gagnants,
                    'affiche_resultats' => $affiche_resultats,
                    'est_public' => $est_public
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
            padding: 20px;
        }

        .container {
            max-width: 900px;
            margin: 0 auto;
        }

        .header {
            background: white;
            padding: 20px 30px;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            margin-bottom: 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .header h1 {
            color: #333;
            font-size: 24px;
        }

        .header-links a {
            color: #667eea;
            text-decoration: none;
            margin-left: 20px;
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
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>Modifier le scrutin</h1>
            <div class="header-links">
                <a href="/<?php echo urlencode($code); ?>/v/">Voir</a>
                <a href="mes-scrutins.php">Mes scrutins</a>
            </div>
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
    </script>
</body>
</html>
