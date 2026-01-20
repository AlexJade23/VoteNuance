<?php
/**
 * Page de verification de vote
 * Accessible sans connexion - permet de retrouver un recepisse a partir de la cle secrete
 */
require_once 'config.php';
require_once 'functions.php';

$ballotSecret = trim($_GET['key'] ?? $_POST['key'] ?? '');
$voteData = null;
$error = null;
$scrutin = null;

if (!empty($ballotSecret)) {
    // Valider le format de la cle (64 caracteres hexadecimaux)
    if (!preg_match('/^[a-f0-9]{64}$/i', $ballotSecret)) {
        $error = 'Format de cle invalide. La cle doit contenir 64 caracteres hexadecimaux.';
    } else {
        // Calculer le hash de la cle
        $ballotHash = hash('sha256', $ballotSecret);

        // Rechercher les bulletins correspondants
        $pdo = getDbConnection();
        $stmt = $pdo->prepare('
            SELECT b.*, q.titre as question_titre, q.type_question, s.titre as scrutin_titre, s.code as scrutin_code
            FROM bulletins b
            JOIN questions q ON b.question_id = q.id
            JOIN scrutins s ON b.scrutin_id = s.id
            WHERE b.ballot_hash = ? AND b.est_test = 0
            ORDER BY q.ordre
        ');
        $stmt->execute([$ballotHash]);
        $bulletins = $stmt->fetchAll();

        if (empty($bulletins)) {
            $error = 'Aucun vote trouve avec cette cle. Verifiez que vous avez saisi la cle correctement.';
        } else {
            // Recuperer les infos du scrutin
            $scrutinCode = $bulletins[0]['scrutin_code'];
            $scrutin = getScrutinByCode($scrutinCode);

            // Recuperer les mentions pour decoder les votes
            $mentions = getMentionsByEchelle(1);
            $mentionsMap = [];
            foreach ($mentions as $m) {
                $mentionsMap[$m['rang']] = $m['libelle'];
            }

            // Construire le recapitulatif
            $voteData = [
                'scrutin_titre' => $bulletins[0]['scrutin_titre'],
                'scrutin_code' => $scrutinCode,
                'vote_date' => $bulletins[0]['vote_at'],
                'votes' => []
            ];

            foreach ($bulletins as $b) {
                $reponse = null;
                if ($b['type_question'] == 0 && $b['vote_mention']) {
                    // Vote nuance
                    $reponse = $mentionsMap[$b['vote_mention']] ?? 'Mention ' . $b['vote_mention'];
                } elseif ($b['reponse']) {
                    // Reponse textuelle (QCM, prefere, ouverte)
                    $reponse = $b['reponse'];
                }

                if ($reponse) {
                    $voteData['votes'][] = [
                        'question' => $b['question_titre'],
                        'reponse' => $reponse
                    ];
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verification de vote - Vote Nuance</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            background: #f5f5f5;
            min-height: 100vh;
            padding: 20px;
        }
        .container { max-width: 700px; margin: 0 auto; }

        .header {
            background: white;
            padding: 25px 30px;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            margin-bottom: 20px;
            text-align: center;
        }
        .header h1 { color: #333; font-size: 24px; margin-bottom: 10px; }
        .header p { color: #666; line-height: 1.5; }

        .card {
            background: white;
            padding: 25px 30px;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            margin-bottom: 20px;
        }

        .search-form { text-align: center; }
        .search-form label {
            display: block;
            font-weight: 600;
            margin-bottom: 10px;
            color: #333;
        }
        .search-form input[type="text"] {
            width: 100%;
            padding: 15px;
            font-size: 14px;
            font-family: monospace;
            border: 2px solid #ddd;
            border-radius: 8px;
            margin-bottom: 15px;
        }
        .search-form input[type="text"]:focus {
            outline: none;
            border-color: #667eea;
        }
        .search-form .hint {
            font-size: 13px;
            color: #888;
            margin-bottom: 15px;
        }

        .btn {
            padding: 12px 30px;
            border: none;
            border-radius: 8px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
            display: inline-block;
        }
        .btn-primary { background: #667eea; color: white; }
        .btn-success { background: #28a745; color: white; }
        .btn:hover { opacity: 0.9; }

        .error-box {
            background: #f8d7da;
            color: #721c24;
            padding: 15px 20px;
            border-radius: 8px;
            margin-bottom: 20px;
            border: 1px solid #f5c6cb;
            text-align: center;
        }

        .success-box {
            background: #d4edda;
            color: #155724;
            padding: 15px 20px;
            border-radius: 8px;
            margin-bottom: 20px;
            border: 1px solid #c3e6cb;
            text-align: center;
        }

        /* Recepisse de vote */
        .vote-receipt {
            background: white;
            border: 2px solid #667eea;
            border-radius: 12px;
            overflow: hidden;
        }

        .receipt-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 25px;
            text-align: center;
        }
        .receipt-header h1 { font-size: 24px; margin-bottom: 10px; }
        .receipt-scrutin { font-size: 18px; opacity: 0.9; }
        .receipt-date { font-size: 14px; opacity: 0.8; margin-top: 5px; }

        .receipt-body { padding: 25px; }

        .receipt-section h3 {
            color: #333;
            font-size: 16px;
            margin-bottom: 15px;
            padding-bottom: 8px;
            border-bottom: 2px solid #667eea;
        }

        .receipt-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 25px;
        }
        .receipt-table tr { border-bottom: 1px solid #eee; }
        .receipt-table td { padding: 10px 5px; vertical-align: top; }
        .receipt-question { font-weight: 500; color: #333; width: 60%; }
        .receipt-answer { color: #667eea; font-weight: 600; text-align: right; }

        .receipt-verification {
            display: flex;
            gap: 20px;
            align-items: flex-start;
            background: #f8f9fa;
            padding: 20px;
            border-radius: 8px;
        }
        .receipt-qr { flex-shrink: 0; }
        .receipt-qr img {
            width: 120px;
            height: 120px;
            border: 2px solid #ddd;
            border-radius: 8px;
        }
        .receipt-key { flex: 1; }
        .receipt-key h3 { border: none; padding-bottom: 0; margin-bottom: 8px; }
        .key-description { font-size: 13px; color: #666; margin-bottom: 10px; line-height: 1.4; }
        .key-value {
            font-family: monospace;
            font-size: 11px;
            background: white;
            padding: 10px;
            border-radius: 6px;
            word-break: break-all;
            border: 1px solid #ddd;
            color: #333;
        }

        .receipt-footer {
            background: #f8f9fa;
            padding: 15px;
            text-align: center;
            font-size: 12px;
            color: #888;
            border-top: 1px solid #eee;
        }

        .actions {
            text-align: center;
            margin-top: 20px;
        }
        .actions .btn { margin: 0 5px; }

        .back-link {
            display: inline-block;
            margin-bottom: 20px;
            color: #667eea;
            text-decoration: none;
        }
        .back-link:hover { text-decoration: underline; }

        /* Styles d'impression */
        @media print {
            body { background: white; padding: 0; }
            .no-print { display: none !important; }
            .container { max-width: 100%; }
            .header, .card.search-form-card { display: none; }
            .vote-receipt { border: 1px solid #333; box-shadow: none; max-width: 100%; }
            .receipt-header {
                background: #667eea !important;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }
            @page { size: A4; margin: 15mm; }
        }
        <?php echo getTestBannerCSS(); ?>
    </style>
</head>
<body>
<?php echo renderTestBanner(); ?>
    <div class="container">
        <a href="/" class="back-link no-print">&larr; Retour a l'accueil</a>

        <div class="header no-print">
            <h1>Verification de vote</h1>
            <p>Entrez votre cle de verification pour retrouver votre recepisse de vote.<br>
            Vous pouvez aussi scanner le QR code de votre recepisse.</p>
        </div>

        <?php if ($error): ?>
        <div class="error-box no-print">
            <?php echo htmlspecialchars($error); ?>
        </div>
        <?php endif; ?>

        <?php if (!$voteData): ?>
        <!-- Formulaire de recherche -->
        <div class="card search-form-card">
            <form method="POST" class="search-form">
                <label for="key">Cle de verification :</label>
                <input type="text"
                       id="key"
                       name="key"
                       placeholder="Ex: 1057a8d53785e5be135e221b33b6a40d238f135b9a95e3b075a8b4a7ee28c8a5"
                       value="<?php echo htmlspecialchars($ballotSecret); ?>"
                       autocomplete="off"
                       autofocus>
                <p class="hint">La cle de 64 caracteres qui vous a ete fournie apres votre vote.</p>
                <button type="submit" class="btn btn-primary">Rechercher mon vote</button>
            </form>
        </div>
        <?php else: ?>

        <!-- Vote trouve -->
        <div class="success-box no-print">
            Vote trouve ! Votre participation a bien ete enregistree.
        </div>

        <div class="actions no-print">
            <button onclick="window.print()" class="btn btn-primary">Imprimer / Sauvegarder PDF</button>
            <a href="/<?php echo urlencode($voteData['scrutin_code']); ?>/r/" class="btn btn-success">Voir les resultats</a>
        </div>

        <!-- Recepisse de vote -->
        <div class="vote-receipt" style="margin-top: 20px;">
            <div class="receipt-header">
                <h1>Recepisse de vote</h1>
                <p class="receipt-scrutin"><?php echo htmlspecialchars($voteData['scrutin_titre']); ?></p>
                <p class="receipt-date">Vote enregistre le <?php echo date('d/m/Y a H:i', strtotime($voteData['vote_date'])); ?></p>
            </div>

            <div class="receipt-body">
                <div class="receipt-section">
                    <h3>Recapitulatif de vos choix</h3>
                    <table class="receipt-table">
                        <?php foreach ($voteData['votes'] as $vote): ?>
                        <tr>
                            <td class="receipt-question"><?php echo htmlspecialchars($vote['question']); ?></td>
                            <td class="receipt-answer"><?php echo htmlspecialchars($vote['reponse']); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </table>
                </div>

                <div class="receipt-verification">
                    <div class="receipt-qr">
                        <?php $verifyUrl = 'https://app.decision-collective.fr/verify/' . $ballotSecret; ?>
                        <img src="https://api.qrserver.com/v1/create-qr-code/?size=150x150&data=<?php echo urlencode($verifyUrl); ?>" alt="QR Code de verification">
                    </div>
                    <div class="receipt-key">
                        <h3>Cle de verification</h3>
                        <p class="key-description">Conservez ce recepisse. La cle ci-dessous vous permet de verifier que votre vote a bien ete comptabilise, sans reveler votre identite.</p>
                        <div class="key-value"><?php echo htmlspecialchars($ballotSecret); ?></div>
                    </div>
                </div>
            </div>

            <div class="receipt-footer">
                <p>Vote Nuance - <?php echo htmlspecialchars($voteData['scrutin_code']); ?> - Ce document fait foi de votre participation</p>
            </div>
        </div>

        <div class="actions no-print" style="margin-top: 20px;">
            <a href="/verify" class="btn btn-primary">Nouvelle recherche</a>
        </div>

        <?php endif; ?>
    </div>
</body>
</html>
