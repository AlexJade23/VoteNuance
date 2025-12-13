<?php
/**
 * Export votes/resultats en format XML Spreadsheet (Excel) avec FORMULES
 * Genere un fichier .xls multi-onglets pedagogique sans dependance externe
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

// Recuperer les donnees
$questions = getQuestionsByScrutin($scrutin['id']);
$results = getResultsByScrutin($scrutin['id']);
$emargements = getEmargementsByScrutin($scrutin['id']);
$nbParticipants = count($emargements);

// Fonction pour echapper les caracteres XML
function xmlEscape($str) {
    return htmlspecialchars($str ?? '', ENT_XML1 | ENT_QUOTES, 'UTF-8');
}

// Generer le nom du fichier
$filename = 'resultats_' . $scrutin['code'] . '_' . date('Y-m-d') . '.xls';

// Headers pour telecharger le fichier
header('Content-Type: application/vnd.ms-excel');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: max-age=0');

// Labels des mentions selon l'echelle du scrutin
$nbMentions = $scrutin['nb_mentions'] ?? 7;
$mentionsList = getMentionsForScale($nbMentions);
$mentionCodes = array_column($mentionsList, 'code');
$mentionLabels = [];
foreach ($mentionsList as $m) {
    $mentionLabels[$m['code']] = $m['libelle'];
}

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
  <Style ss:ID="Formula">
   <Interior ss:Color="#e8f5e9" ss:Pattern="Solid"/>
   <Font ss:Bold="1"/>
  </Style>
  <Style ss:ID="AP"><Interior ss:Color="#1e7b1e" ss:Pattern="Solid"/><Font ss:Color="#FFFFFF"/></Style>
  <Style ss:ID="FP"><Interior ss:Color="#7bc87b" ss:Pattern="Solid"/></Style>
  <Style ss:ID="PP"><Interior ss:Color="#cce5cc" ss:Pattern="Solid"/></Style>
  <Style ss:ID="SA"><Interior ss:Color="#f0f0f0" ss:Pattern="Solid"/></Style>
  <Style ss:ID="PC"><Interior ss:Color="#f5cccc" ss:Pattern="Solid"/></Style>
  <Style ss:ID="FC"><Interior ss:Color="#e68a8a" ss:Pattern="Solid"/></Style>
  <Style ss:ID="AC"><Interior ss:Color="#c0392b" ss:Pattern="Solid"/><Font ss:Color="#FFFFFF"/></Style>
  <Style ss:ID="P"><Interior ss:Color="#388E3C" ss:Pattern="Solid"/><Font ss:Color="#FFFFFF"/></Style>
  <Style ss:ID="C"><Interior ss:Color="#D32F2F" ss:Pattern="Solid"/><Font ss:Color="#FFFFFF"/></Style>
 </Styles>

 <!-- Onglet Resume -->
 <Worksheet ss:Name="Resume">
  <Table>
   <Column ss:Width="200"/>
   <Column ss:Width="300"/>
   <Row>
    <Cell ss:StyleID="Header"><Data ss:Type="String">Information</Data></Cell>
    <Cell ss:StyleID="Header"><Data ss:Type="String">Valeur</Data></Cell>
   </Row>
   <Row>
    <Cell ss:StyleID="Bold"><Data ss:Type="String">Titre du scrutin</Data></Cell>
    <Cell><Data ss:Type="String"><?php echo xmlEscape($scrutin['titre']); ?></Data></Cell>
   </Row>
   <Row>
    <Cell ss:StyleID="Bold"><Data ss:Type="String">Code</Data></Cell>
    <Cell><Data ss:Type="String"><?php echo xmlEscape($scrutin['code']); ?></Data></Cell>
   </Row>
   <Row>
    <Cell ss:StyleID="Bold"><Data ss:Type="String">Date debut</Data></Cell>
    <Cell><Data ss:Type="String"><?php echo $scrutin['debut_at'] ? date('d/m/Y H:i', strtotime($scrutin['debut_at'])) : '-'; ?></Data></Cell>
   </Row>
   <Row>
    <Cell ss:StyleID="Bold"><Data ss:Type="String">Date fin</Data></Cell>
    <Cell><Data ss:Type="String"><?php echo $scrutin['fin_at'] ? date('d/m/Y H:i', strtotime($scrutin['fin_at'])) : '-'; ?></Data></Cell>
   </Row>
   <Row>
    <Cell ss:StyleID="Bold"><Data ss:Type="String">Nombre de participants</Data></Cell>
    <Cell><Data ss:Type="Number"><?php echo $nbParticipants; ?></Data></Cell>
   </Row>
   <Row>
    <Cell ss:StyleID="Bold"><Data ss:Type="String">Date d'export</Data></Cell>
    <Cell><Data ss:Type="String"><?php echo date('d/m/Y H:i'); ?></Data></Cell>
   </Row>
  </Table>
 </Worksheet>

 <!-- Onglet Votes Bruts (Vote Nuance) -->
 <Worksheet ss:Name="Votes bruts">
  <Table>
   <Column ss:Width="300"/>
<?php foreach ($mentionCodes as $code): ?>
   <Column ss:Width="50"/>
<?php endforeach; ?>
   <Column ss:Width="80"/>
   <Row>
    <Cell ss:StyleID="Header"><Data ss:Type="String">Question</Data></Cell>
<?php foreach ($mentionCodes as $code): ?>
    <Cell ss:StyleID="<?php echo $code; ?>"><Data ss:Type="String"><?php echo $code; ?></Data></Cell>
<?php endforeach; ?>
    <Cell ss:StyleID="Header"><Data ss:Type="String">Total</Data></Cell>
   </Row>
<?php
$rowNum = 1;
$voteNuanceQuestions = [];
foreach ($questions as $q):
    if ($q['type_question'] == 0): // Vote Nuance
        $rowNum++;
        $voteNuanceQuestions[] = ['question' => $q, 'row' => $rowNum];
        $r = $results[$q['id']] ?? [];
?>
   <Row>
    <Cell><Data ss:Type="String"><?php echo xmlEscape($q['titre']); ?></Data></Cell>
<?php foreach ($mentionCodes as $code): ?>
    <Cell ss:StyleID="<?php echo $code; ?>"><Data ss:Type="Number"><?php echo intval($r[strtolower($code)] ?? 0); ?></Data></Cell>
<?php endforeach; ?>
<?php $lastCol = chr(ord('A') + count($mentionCodes)); ?>
    <Cell ss:Formula="=SUM(B<?php echo $rowNum; ?>:<?php echo $lastCol; ?><?php echo $rowNum; ?>)"><Data ss:Type="Number"></Data></Cell>
   </Row>
<?php
    endif;
endforeach;
?>
  </Table>
 </Worksheet>

 <!-- Onglet Calculs avec FORMULES -->
<?php
// Determiner les colonnes selon l'echelle
// 7 mentions: B=AC, C=FC, D=PC, E=SA, F=PP, G=FP, H=AP, I=Total
// 5 mentions: B=FC, C=C, D=SA, E=P, F=FP, G=Total
// 3 mentions: B=C, C=SA, D=P, E=Total
$colMap = [];
$colIdx = 'B';
foreach ($mentionCodes as $code) {
    $colMap[$code] = $colIdx;
    $colIdx = chr(ord($colIdx) + 1);
}
$totalCol = $colIdx;

// Construire les formules selon l'echelle
$saCol = $colMap['SA'] ?? 'E';
switch ($nbMentions) {
    case 3:
        $scoreFormula = function($row) use ($colMap, $saCol) {
            return "='{$colMap['P']}{$row}+({$saCol}{$row}/2)";
        };
        $dep1Header = 'P-C';
        $dep2Header = '';
        $dep3Header = '';
        $scoreLabel = 'P+SA/2';
        break;
    case 5:
        $scoreFormula = function($row) use ($colMap, $saCol) {
            return "='{$colMap['FP']}{$row}+'{$colMap['P']}{$row}+({$saCol}{$row}/2)";
        };
        $dep1Header = 'FP-FC';
        $dep2Header = 'P-C';
        $dep3Header = '';
        $scoreLabel = 'FP+P+SA/2';
        break;
    case 7:
    default:
        $scoreFormula = function($row) use ($colMap, $saCol) {
            return "='{$colMap['AP']}{$row}+'{$colMap['FP']}{$row}+'{$colMap['PP']}{$row}+({$saCol}{$row}/2)";
        };
        $dep1Header = 'AP-AC';
        $dep2Header = 'FP-FC';
        $dep3Header = 'PP-PC';
        $scoreLabel = 'AP+FP+PP+SA/2';
        break;
}
?>
 <Worksheet ss:Name="Calculs Vote Nuance">
  <Table>
   <Column ss:Width="300"/>
   <Column ss:Width="100"/>
   <Column ss:Width="80"/>
   <Column ss:Width="80"/>
   <Column ss:Width="80"/>
   <Column ss:Width="100"/>
   <Row>
    <Cell ss:StyleID="Header"><Data ss:Type="String">Question</Data></Cell>
    <Cell ss:StyleID="Header"><Data ss:Type="String">Score</Data></Cell>
    <Cell ss:StyleID="Header"><Data ss:Type="String"><?php echo $dep1Header; ?></Data></Cell>
    <Cell ss:StyleID="Header"><Data ss:Type="String"><?php echo $dep2Header; ?></Data></Cell>
    <Cell ss:StyleID="Header"><Data ss:Type="String"><?php echo $dep3Header; ?></Data></Cell>
    <Cell ss:StyleID="Header"><Data ss:Type="String">Taux Net %</Data></Cell>
   </Row>
   <Row>
    <Cell ss:StyleID="Bold"><Data ss:Type="String">FORMULES UTILISEES :</Data></Cell>
    <Cell><Data ss:Type="String"><?php echo $scoreLabel; ?></Data></Cell>
    <Cell><Data ss:Type="String"><?php echo $dep1Header; ?></Data></Cell>
    <Cell><Data ss:Type="String"><?php echo $dep2Header; ?></Data></Cell>
    <Cell><Data ss:Type="String"><?php echo $dep3Header; ?></Data></Cell>
    <Cell><Data ss:Type="String">(Pour-Contre)/Total</Data></Cell>
   </Row>
<?php
$calcRowNum = 2;
foreach ($voteNuanceQuestions as $idx => $data):
    $calcRowNum++;
    $srcRow = $data['row'];

    // Formules de score et departage selon echelle
    if ($nbMentions == 3) {
        $scoreF = "='Votes bruts'!{$colMap['P']}{$srcRow}+('Votes bruts'!{$saCol}{$srcRow}/2)";
        $dep1F = "='Votes bruts'!{$colMap['P']}{$srcRow}-'Votes bruts'!{$colMap['C']}{$srcRow}";
        $dep2F = "=0";
        $dep3F = "=0";
        $tauxF = "=IF('Votes bruts'!{$totalCol}{$srcRow}=0,0,(('Votes bruts'!{$colMap['P']}{$srcRow}-'Votes bruts'!{$colMap['C']}{$srcRow})/'Votes bruts'!{$totalCol}{$srcRow})*100)";
    } elseif ($nbMentions == 5) {
        $scoreF = "='Votes bruts'!{$colMap['FP']}{$srcRow}+'Votes bruts'!{$colMap['P']}{$srcRow}+('Votes bruts'!{$saCol}{$srcRow}/2)";
        $dep1F = "='Votes bruts'!{$colMap['FP']}{$srcRow}-'Votes bruts'!{$colMap['FC']}{$srcRow}";
        $dep2F = "='Votes bruts'!{$colMap['P']}{$srcRow}-'Votes bruts'!{$colMap['C']}{$srcRow}";
        $dep3F = "=0";
        $tauxF = "=IF('Votes bruts'!{$totalCol}{$srcRow}=0,0,(('Votes bruts'!{$colMap['FP']}{$srcRow}+'Votes bruts'!{$colMap['P']}{$srcRow}-'Votes bruts'!{$colMap['FC']}{$srcRow}-'Votes bruts'!{$colMap['C']}{$srcRow})/'Votes bruts'!{$totalCol}{$srcRow})*100)";
    } else { // 7 mentions
        $scoreF = "='Votes bruts'!{$colMap['AP']}{$srcRow}+'Votes bruts'!{$colMap['FP']}{$srcRow}+'Votes bruts'!{$colMap['PP']}{$srcRow}+('Votes bruts'!{$saCol}{$srcRow}/2)";
        $dep1F = "='Votes bruts'!{$colMap['AP']}{$srcRow}-'Votes bruts'!{$colMap['AC']}{$srcRow}";
        $dep2F = "='Votes bruts'!{$colMap['FP']}{$srcRow}-'Votes bruts'!{$colMap['FC']}{$srcRow}";
        $dep3F = "='Votes bruts'!{$colMap['PP']}{$srcRow}-'Votes bruts'!{$colMap['PC']}{$srcRow}";
        $tauxF = "=IF('Votes bruts'!{$totalCol}{$srcRow}=0,0,(('Votes bruts'!{$colMap['AP']}{$srcRow}+'Votes bruts'!{$colMap['FP']}{$srcRow}+'Votes bruts'!{$colMap['PP']}{$srcRow}-'Votes bruts'!{$colMap['AC']}{$srcRow}-'Votes bruts'!{$colMap['FC']}{$srcRow}-'Votes bruts'!{$colMap['PC']}{$srcRow})/'Votes bruts'!{$totalCol}{$srcRow})*100)";
    }
?>
   <Row>
    <Cell><Data ss:Type="String"><?php echo xmlEscape($data['question']['titre']); ?></Data></Cell>
    <Cell ss:StyleID="Formula" ss:Formula="<?php echo $scoreF; ?>"><Data ss:Type="Number"></Data></Cell>
    <Cell ss:StyleID="Formula" ss:Formula="<?php echo $dep1F; ?>"><Data ss:Type="Number"></Data></Cell>
    <Cell ss:StyleID="Formula" ss:Formula="<?php echo $dep2F; ?>"><Data ss:Type="Number"></Data></Cell>
    <Cell ss:StyleID="Formula" ss:Formula="<?php echo $dep3F; ?>"><Data ss:Type="Number"></Data></Cell>
    <Cell ss:StyleID="Formula" ss:Formula="<?php echo $tauxF; ?>"><Data ss:Type="Number"></Data></Cell>
   </Row>
<?php endforeach; ?>
  </Table>
 </Worksheet>

 <!-- Onglet QCM -->
<?php
$qcmQuestions = array_filter($questions, function($q) { return $q['type_question'] == 4; });
if (!empty($qcmQuestions)):
?>
 <Worksheet ss:Name="QCM">
  <Table>
   <Column ss:Width="250"/>
   <Column ss:Width="200"/>
   <Column ss:Width="80"/>
   <Column ss:Width="80"/>
   <Row>
    <Cell ss:StyleID="Header"><Data ss:Type="String">Question</Data></Cell>
    <Cell ss:StyleID="Header"><Data ss:Type="String">Reponse</Data></Cell>
    <Cell ss:StyleID="Header"><Data ss:Type="String">Nb votes</Data></Cell>
    <Cell ss:StyleID="Header"><Data ss:Type="String">%</Data></Cell>
   </Row>
<?php
$qcmRowNum = 1;
foreach ($qcmQuestions as $q):
    $reponses = getReponsesPossibles($q['id']);
    $votesQcm = getVotesQcm($q['id']);
    $totalVotes = array_sum(array_column($votesQcm, 'nb_votes'));

    foreach ($reponses as $rep):
        $qcmRowNum++;
        $nbVotes = 0;
        foreach ($votesQcm as $v) {
            if ($v['reponse_id'] == $rep['id']) {
                $nbVotes = $v['nb_votes'];
                break;
            }
        }
?>
   <Row>
    <Cell><Data ss:Type="String"><?php echo xmlEscape($q['titre']); ?></Data></Cell>
    <Cell><Data ss:Type="String"><?php echo xmlEscape($rep['libelle']); ?></Data></Cell>
    <Cell><Data ss:Type="Number"><?php echo $nbVotes; ?></Data></Cell>
    <Cell ss:Formula="=IF(C<?php echo $qcmRowNum; ?>=0,0,(C<?php echo $qcmRowNum; ?>/<?php echo max(1, $totalVotes); ?>)*100)"><Data ss:Type="Number"></Data></Cell>
   </Row>
<?php
    endforeach;
endforeach;
?>
  </Table>
 </Worksheet>
<?php endif; ?>

 <!-- Onglet Reponses ouvertes -->
<?php
$openQuestions = array_filter($questions, function($q) { return $q['type_question'] == 1; });
if (!empty($openQuestions)):
?>
 <Worksheet ss:Name="Reponses ouvertes">
  <Table>
   <Column ss:Width="250"/>
   <Column ss:Width="500"/>
   <Row>
    <Cell ss:StyleID="Header"><Data ss:Type="String">Question</Data></Cell>
    <Cell ss:StyleID="Header"><Data ss:Type="String">Reponse</Data></Cell>
   </Row>
<?php
foreach ($openQuestions as $q):
    $reponsesOuvertes = getReponsesOuvertes($q['id']);
    foreach ($reponsesOuvertes as $rep):
?>
   <Row>
    <Cell><Data ss:Type="String"><?php echo xmlEscape($q['titre']); ?></Data></Cell>
    <Cell><Data ss:Type="String"><?php echo xmlEscape($rep['reponse']); ?></Data></Cell>
   </Row>
<?php
    endforeach;
endforeach;
?>
  </Table>
 </Worksheet>
<?php endif; ?>

 <!-- Onglet Prefere du lot -->
<?php
$prefereQuestions = array_filter($questions, function($q) { return $q['type_question'] == 3; });
if (!empty($prefereQuestions)):
?>
 <Worksheet ss:Name="Prefere du lot">
  <Table>
   <Column ss:Width="100"/>
   <Column ss:Width="250"/>
   <Column ss:Width="80"/>
   <Column ss:Width="80"/>
   <Row>
    <Cell ss:StyleID="Header"><Data ss:Type="String">Lot</Data></Cell>
    <Cell ss:StyleID="Header"><Data ss:Type="String">Option</Data></Cell>
    <Cell ss:StyleID="Header"><Data ss:Type="String">Nb votes</Data></Cell>
    <Cell ss:StyleID="Header"><Data ss:Type="String">%</Data></Cell>
   </Row>
<?php
$prefRowNum = 1;
foreach ($prefereQuestions as $q):
    $votesPrefere = getVotesPrefere($q['id']);
    $totalVotes = array_sum(array_column($votesPrefere, 'nb_votes'));

    foreach ($votesPrefere as $v):
        $prefRowNum++;
?>
   <Row>
    <Cell><Data ss:Type="String">Lot <?php echo intval($q['lot']); ?></Data></Cell>
    <Cell><Data ss:Type="String"><?php echo xmlEscape($v['option_titre']); ?></Data></Cell>
    <Cell><Data ss:Type="Number"><?php echo intval($v['nb_votes']); ?></Data></Cell>
    <Cell ss:Formula="=IF(C<?php echo $prefRowNum; ?>=0,0,(C<?php echo $prefRowNum; ?>/<?php echo max(1, $totalVotes); ?>)*100)"><Data ss:Type="Number"></Data></Cell>
   </Row>
<?php
    endforeach;
endforeach;
?>
  </Table>
 </Worksheet>
<?php endif; ?>

</Workbook>
