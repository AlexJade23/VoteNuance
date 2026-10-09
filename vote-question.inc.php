<?php
/**
 * Rendu d'une question autre que Vote nuancé (séparateur, réponse ouverte, QCM, préféré du lot).
 * Partagé par les formats de vote 1 et 2 de vote.php.
 * Variables attendues : $question, $i, $scrutin
 */
if (!isset($question)) {
    http_response_code(404);
    exit;
}
?>
<?php if ($question['type_question'] == 2): ?>
<!-- Séparateur -->
<div class="question-card separator">
    <div class="question-title"><?php echo htmlspecialchars($question['titre']); ?></div>
</div>

<?php elseif ($question['type_question'] == 1): ?>
<!-- Réponse ouverte -->
<div class="question-card">
    <?php if (!empty($question['image_url'])): ?>
    <img src="<?php echo htmlspecialchars($question['image_url']); ?>" alt="" class="clickable-image question-image" onclick="openLightbox(this.src)">
    <?php endif; ?>
    <div class="question-header">
        <span class="question-number"><?php echo $i + 1; ?></span>
        <div class="question-content">
            <div class="question-title">
                <?php echo htmlspecialchars($question['titre']); ?>
                <?php if ($question['est_obligatoire']): ?><span class="required">*</span><?php endif; ?>
            </div>
            <?php if ($question['question']): ?>
            <div class="question-description"><?php echo nl2br(htmlspecialchars($question['question'])); ?></div>
            <?php endif; ?>
        </div>
    </div>
    <div class="open-response">
        <textarea name="reponse[<?php echo $question['id']; ?>]"
                  placeholder="Votre réponse..."><?php echo htmlspecialchars($_POST['reponse'][$question['id']] ?? ''); ?></textarea>
    </div>
</div>

<?php elseif ($question['type_question'] == 4): ?>
<!-- QCM -->
<?php $reponsesPossibles = getReponsesPossibles($question['id']); ?>
<div class="question-card">
    <?php if (!empty($question['image_url'])): ?>
    <img src="<?php echo htmlspecialchars($question['image_url']); ?>" alt="" class="clickable-image question-image" onclick="openLightbox(this.src)">
    <?php endif; ?>
    <div class="question-header">
        <span class="question-number"><?php echo $i + 1; ?></span>
        <div class="question-content">
            <div class="question-title">
                <?php echo htmlspecialchars($question['titre']); ?>
                <?php if ($question['est_obligatoire']): ?><span class="required">*</span><?php endif; ?>
            </div>
            <?php if ($question['question']): ?>
            <div class="question-description"><?php echo nl2br(htmlspecialchars($question['question'])); ?></div>
            <?php endif; ?>
        </div>
    </div>
    <div class="qcm-options">
        <?php foreach ($reponsesPossibles as $rep): ?>
        <div class="qcm-option">
            <label>
                <input type="radio" name="reponse[<?php echo $question['id']; ?>]"
                       value="<?php echo htmlspecialchars($rep['libelle']); ?>">
                <span><?php echo htmlspecialchars($rep['libelle']); ?></span>
            </label>
        </div>
        <?php endforeach; ?>
    </div>
</div>

<?php elseif ($question['type_question'] == 3): ?>
<!-- Préféré du lot : selection unique parmi les questions Vote Nuancé du même lot -->
<?php
// Générer les options automatiquement depuis les titres des questions du lot
$lotNum = intval($question['lot'] ?? 0);
$lotQuestions = getQuestionTitlesForLot($scrutin['id'], $lotNum);
?>
<div class="question-card">
    <?php if (!empty($question['image_url'])): ?>
    <img src="<?php echo htmlspecialchars($question['image_url']); ?>" alt="" class="clickable-image question-image" onclick="openLightbox(this.src)">
    <?php endif; ?>
    <div class="question-header">
        <span class="question-number"><?php echo $i + 1; ?></span>
        <div class="question-content">
            <div class="question-title">
                <?php echo htmlspecialchars($question['titre']); ?>
                <?php if ($question['est_obligatoire']): ?><span class="required">*</span><?php endif; ?>
            </div>
            <?php if ($question['question']): ?>
            <div class="question-description"><?php echo nl2br(htmlspecialchars($question['question'])); ?></div>
            <?php endif; ?>
        </div>
    </div>
    <div class="prefere-options">
        <?php foreach ($lotQuestions as $lq): ?>
        <div class="prefere-option">
            <label class="prefere-label">
                <input type="radio"
                       name="reponse[<?php echo $question['id']; ?>]"
                       value="<?php echo htmlspecialchars($lq['titre']); ?>"
                       <?php echo (($_POST['reponse'][$question['id']] ?? '') === $lq['titre']) ? 'checked' : ''; ?>>
                <span class="prefere-text"><?php echo htmlspecialchars($lq['titre']); ?></span>
            </label>
        </div>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>
