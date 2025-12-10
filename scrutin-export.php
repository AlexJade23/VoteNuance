<?php
/**
 * Export scrutin en format XML Spreadsheet (Excel)
 * Genere un fichier .xls multi-onglets sans dependance externe
 */

require_once 'config.php';
require_once 'functions.php';

// Verifier que l'utilisateur est connecte
if (!isLoggedIn()) {
    header('HTTP/1.1 401 Unauthorized');
    exit('Non authentifie');
}

$user = getCurrentUser();

// Recuperer le scrutin
$code = $_GET['code'] ?? '';
if (empty($code)) {
    header('HTTP/1.1 400 Bad Request');
    exit('Code scrutin manquant');
}

$scrutin = getScrutinByCode($code);
if (!$scrutin) {
    header('HTTP/1.1 404 Not Found');
    exit('Scrutin introuvable');
}

// Verifier que l'utilisateur est proprietaire
if ($scrutin['owner_id'] != $user['id']) {
    header('HTTP/1.1 403 Forbidden');
    exit('Acces refuse');
}

// Recuperer les questions et reponses
$questions = getQuestionsByScrutin($scrutin['id']);
$reponsesByQuestion = [];
foreach ($questions as $q) {
    if ($q['type_question'] == 4) { // QCM
        $reponsesByQuestion[$q['id']] = getReponsesPossibles($q['id']);
    }
}

// Fonction pour echapper les caracteres XML
function xmlEscape($str) {
    return htmlspecialchars($str ?? '', ENT_XML1 | ENT_QUOTES, 'UTF-8');
}

// Generer le nom du fichier
$filename = 'scrutin_' . $scrutin['code'] . '_' . date('Y-m-d') . '.xls';

// Headers pour telecharger le fichier
header('Content-Type: application/vnd.ms-excel');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: max-age=0');

// Generer le XML Spreadsheet
echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
?>
<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet"
 xmlns:o="urn:schemas-microsoft-com:office:office"
 xmlns:x="urn:schemas-microsoft-com:office:excel"
 xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet">

 <Styles>
  <Style ss:ID="Header">
   <Font ss:Bold="1"/>
   <Interior ss:Color="#667eea" ss:Pattern="Solid"/>
   <Font ss:Color="#FFFFFF"/>
  </Style>
  <Style ss:ID="Bold">
   <Font ss:Bold="1"/>
  </Style>
 </Styles>

 <!-- Onglet Scrutin -->
 <Worksheet ss:Name="Scrutin">
  <Table>
   <Column ss:Width="150"/>
   <Column ss:Width="400"/>
   <Row>
    <Cell ss:StyleID="Header"><Data ss:Type="String">Champ</Data></Cell>
    <Cell ss:StyleID="Header"><Data ss:Type="String">Valeur</Data></Cell>
   </Row>
   <Row>
    <Cell ss:StyleID="Bold"><Data ss:Type="String">Titre</Data></Cell>
    <Cell><Data ss:Type="String"><?php echo xmlEscape($scrutin['titre']); ?></Data></Cell>
   </Row>
   <Row>
    <Cell ss:StyleID="Bold"><Data ss:Type="String">Code</Data></Cell>
    <Cell><Data ss:Type="String"><?php echo xmlEscape($scrutin['code']); ?></Data></Cell>
   </Row>
   <Row>
    <Cell ss:StyleID="Bold"><Data ss:Type="String">Resume</Data></Cell>
    <Cell><Data ss:Type="String"><?php echo xmlEscape($scrutin['resume']); ?></Data></Cell>
   </Row>
   <Row>
    <Cell ss:StyleID="Bold"><Data ss:Type="String">Notice</Data></Cell>
    <Cell><Data ss:Type="String"><?php echo xmlEscape($scrutin['notice']); ?></Data></Cell>
   </Row>
   <Row>
    <Cell ss:StyleID="Bold"><Data ss:Type="String">Image URL</Data></Cell>
    <Cell><Data ss:Type="String"><?php echo xmlEscape($scrutin['image_url']); ?></Data></Cell>
   </Row>
   <Row>
    <Cell ss:StyleID="Bold"><Data ss:Type="String">Date debut</Data></Cell>
    <Cell><Data ss:Type="String"><?php echo xmlEscape($scrutin['debut_at']); ?></Data></Cell>
   </Row>
   <Row>
    <Cell ss:StyleID="Bold"><Data ss:Type="String">Date fin</Data></Cell>
    <Cell><Data ss:Type="String"><?php echo xmlEscape($scrutin['fin_at']); ?></Data></Cell>
   </Row>
   <Row>
    <Cell ss:StyleID="Bold"><Data ss:Type="String">Nb participants attendus</Data></Cell>
    <Cell><Data ss:Type="Number"><?php echo intval($scrutin['nb_participants_attendus']); ?></Data></Cell>
   </Row>
   <Row>
    <Cell ss:StyleID="Bold"><Data ss:Type="String">Nb gagnants</Data></Cell>
    <Cell><Data ss:Type="Number"><?php echo intval($scrutin['nb_gagnants']); ?></Data></Cell>
   </Row>
   <Row>
    <Cell ss:StyleID="Bold"><Data ss:Type="String">Affiche resultats</Data></Cell>
    <Cell><Data ss:Type="String"><?php echo $scrutin['affiche_resultats'] ? 'Oui' : 'Non'; ?></Data></Cell>
   </Row>
   <Row>
    <Cell ss:StyleID="Bold"><Data ss:Type="String">Est public</Data></Cell>
    <Cell><Data ss:Type="String"><?php echo $scrutin['est_public'] ? 'Oui' : 'Non'; ?></Data></Cell>
   </Row>
   <Row>
    <Cell ss:StyleID="Bold"><Data ss:Type="String">Ordre mentions</Data></Cell>
    <Cell><Data ss:Type="Number"><?php echo intval($scrutin['ordre_mentions']); ?></Data></Cell>
   </Row>
  </Table>
 </Worksheet>

 <!-- Onglet Questions -->
 <Worksheet ss:Name="Questions">
  <Table>
   <Column ss:Width="50"/>
   <Column ss:Width="250"/>
   <Column ss:Width="300"/>
   <Column ss:Width="100"/>
   <Column ss:Width="50"/>
   <Column ss:Width="50"/>
   <Column ss:Width="80"/>
   <Column ss:Width="200"/>
   <Row>
    <Cell ss:StyleID="Header"><Data ss:Type="String">Ordre</Data></Cell>
    <Cell ss:StyleID="Header"><Data ss:Type="String">Titre</Data></Cell>
    <Cell ss:StyleID="Header"><Data ss:Type="String">Description</Data></Cell>
    <Cell ss:StyleID="Header"><Data ss:Type="String">Type</Data></Cell>
    <Cell ss:StyleID="Header"><Data ss:Type="String">Lot</Data></Cell>
    <Cell ss:StyleID="Header"><Data ss:Type="String">Obligatoire</Data></Cell>
    <Cell ss:StyleID="Header"><Data ss:Type="String">Type ID</Data></Cell>
    <Cell ss:StyleID="Header"><Data ss:Type="String">Image URL</Data></Cell>
   </Row>
<?php
$typeLabels = [
    0 => 'Vote nuance',
    1 => 'Reponse ouverte',
    2 => 'Separateur',
    3 => 'Prefere du lot',
    4 => 'QCM'
];
foreach ($questions as $q):
    $typeLabel = $typeLabels[$q['type_question']] ?? 'Inconnu';
?>
   <Row>
    <Cell><Data ss:Type="Number"><?php echo intval($q['ordre']); ?></Data></Cell>
    <Cell><Data ss:Type="String"><?php echo xmlEscape($q['titre']); ?></Data></Cell>
    <Cell><Data ss:Type="String"><?php echo xmlEscape($q['question']); ?></Data></Cell>
    <Cell><Data ss:Type="String"><?php echo xmlEscape($typeLabel); ?></Data></Cell>
    <Cell><Data ss:Type="Number"><?php echo intval($q['lot']); ?></Data></Cell>
    <Cell><Data ss:Type="String"><?php echo $q['est_obligatoire'] ? 'Oui' : 'Non'; ?></Data></Cell>
    <Cell><Data ss:Type="Number"><?php echo intval($q['type_question']); ?></Data></Cell>
    <Cell><Data ss:Type="String"><?php echo xmlEscape($q['image_url']); ?></Data></Cell>
   </Row>
<?php endforeach; ?>
  </Table>
 </Worksheet>

 <!-- Onglet Reponses QCM -->
 <Worksheet ss:Name="Reponses QCM">
  <Table>
   <Column ss:Width="250"/>
   <Column ss:Width="50"/>
   <Column ss:Width="300"/>
   <Row>
    <Cell ss:StyleID="Header"><Data ss:Type="String">Question (titre)</Data></Cell>
    <Cell ss:StyleID="Header"><Data ss:Type="String">Ordre</Data></Cell>
    <Cell ss:StyleID="Header"><Data ss:Type="String">Reponse</Data></Cell>
   </Row>
<?php
foreach ($questions as $q):
    if ($q['type_question'] == 4 && isset($reponsesByQuestion[$q['id']])):
        foreach ($reponsesByQuestion[$q['id']] as $r):
?>
   <Row>
    <Cell><Data ss:Type="String"><?php echo xmlEscape($q['titre']); ?></Data></Cell>
    <Cell><Data ss:Type="Number"><?php echo intval($r['ordre']); ?></Data></Cell>
    <Cell><Data ss:Type="String"><?php echo xmlEscape($r['libelle']); ?></Data></Cell>
   </Row>
<?php
        endforeach;
    endif;
endforeach;
?>
  </Table>
 </Worksheet>

</Workbook>
