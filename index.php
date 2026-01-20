<?php
require_once 'config.php';
require_once 'functions.php';
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Vote Nuancé</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, sans-serif;
            background: #fafafa;
            color: #333;
            line-height: 1.6;
            min-height: 100vh;
        }

        .container {
            max-width: 600px;
            margin: 0 auto;
            padding: 40px 20px;
        }

        header {
            text-align: center;
            margin-bottom: 50px;
        }

        h1 {
            font-size: 2rem;
            font-weight: 300;
            letter-spacing: 0.3em;
            color: #222;
            margin-bottom: 20px;
        }

        .tagline {
            font-size: 0.95rem;
            color: #666;
            max-width: 500px;
            margin: 0 auto;
        }

        .principle {
            background: #fff;
            border-left: 3px solid #333;
            padding: 20px;
            margin: 30px 0;
            font-style: italic;
            color: #555;
        }

        nav {
            display: flex;
            flex-direction: column;
            gap: 12px;
            margin: 40px 0;
        }

        .nav-btn {
            display: block;
            padding: 16px 24px;
            background: #fff;
            border: 1px solid #ddd;
            text-decoration: none;
            color: #333;
            font-size: 0.95rem;
            transition: all 0.2s ease;
        }

        .nav-btn:hover {
            background: #333;
            color: #fff;
            border-color: #333;
        }

        .nav-btn span {
            font-weight: 600;
            display: block;
            margin-bottom: 4px;
        }

        .nav-btn small {
            color: #888;
            font-size: 0.8rem;
        }

        .nav-btn:hover small {
            color: #ccc;
        }

        .vote-section {
            background: #fff;
            border: 1px solid #ddd;
            padding: 30px;
            margin: 40px 0;
        }

        .vote-section h2 {
            font-size: 1rem;
            font-weight: 600;
            margin-bottom: 20px;
            text-transform: uppercase;
            letter-spacing: 0.1em;
        }

        .vote-form {
            display: flex;
            gap: 10px;
        }

        .vote-form input {
            flex: 1;
            padding: 14px 16px;
            border: 1px solid #ddd;
            font-size: 1rem;
            outline: none;
        }

        .vote-form input:focus {
            border-color: #333;
        }

        .vote-form button {
            padding: 14px 28px;
            background: #333;
            color: #fff;
            border: none;
            font-size: 0.9rem;
            cursor: pointer;
            transition: background 0.2s ease;
        }

        .vote-form button:hover {
            background: #555;
        }

        .info {
            margin-top: 50px;
            padding-top: 30px;
            border-top: 1px solid #eee;
        }

        .info h3 {
            font-size: 0.85rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.1em;
            margin-bottom: 15px;
            color: #999;
        }

        .info p {
            font-size: 0.9rem;
            color: #666;
            margin-bottom: 10px;
        }

        .info ul {
            list-style: none;
            font-size: 0.85rem;
            color: #888;
        }

        .info li {
            padding: 5px 0;
            padding-left: 15px;
            position: relative;
        }

        .info li:before {
            content: "—";
            position: absolute;
            left: 0;
            color: #ccc;
        }

        footer {
            margin-top: 60px;
            text-align: center;
            font-size: 0.8rem;
            color: #aaa;
        }

        footer a {
            color: #888;
            text-decoration: none;
        }

        footer a:hover {
            color: #333;
        }
        <?php echo getTestBannerCSS(); ?>
    </style>
</head>
<body>
<?php echo renderTestBanner(); ?>
    <div class="container">
        <header>
            <a href="https://decision-collective.fr/" target="_blank" title="Découvrir le concept, la vidéo et les ressources">
                <img src="https://decision-collective.fr/wp-content/uploads/2021/12/logov7long.png" alt="Décision Collective" style="height: 60px; width: auto; margin-bottom: 20px;">
            </a>
            <h1>VOTE NUANCÉ</h1>
            <p class="tagline">
                Votez pour ceux que vous approuvez et contre ceux que vous désapprouvez,
                avec 7 nuances : 3 positives, 3 négatives et une neutre.
            </p>
        </header>

        <div class="principle">
            Le gagnant est celui qui totalise le plus de partisans et le moins d'opposants.
        </div>

        <section class="vote-section">
            <h2>Participer à un vote</h2>
            <form class="vote-form" action="vote.php" method="GET">
                <input type="text" name="code" placeholder="Code organisateur" required>
                <button type="submit">Voter</button>
            </form>
        </section>

        <nav>
            <a href="login.php" class="nav-btn">
                <span>Se connecter</span>
                <small>Accéder à vos scrutins</small>
            </a>
            <a href="login.php?action=register" class="nav-btn">
                <span>Créer un compte</span>
                <small>Organiser vos propres votes</small>
            </a>
            <a href="dashboard.php" class="nav-btn">
                <span>Tableau de bord</span>
                <small>Gérer vos scrutins</small>
            </a>
        </nav>

        <div class="info">
            <h3>Comment ça marche</h3>
            <p>Le vote nuancé permet une expression plus fine que le vote binaire classique.</p>
            <ul>
                <li>Exprimez votre niveau d'approbation ou de désapprobation</li>
                <li>Le départage se fait par différence partisans/opposants</li>
                <li>En cas d'égalité, le nombre de partisans prévaut</li>
            </ul>
        </div>

        <footer style="margin-top: 60px; text-align: center; font-size: 13px; color: #888; border-top: 1px solid #eee; padding-top: 20px;">
            <div>
                <a href="https://decision-collective.fr/" target="_blank" style="color: #667eea; text-decoration: none;">Découvrir le concept</a> ·
                <a href="my-data.php" style="color: #667eea; text-decoration: none;">Mes données</a>
            </div>
            <a href="https://buy.stripe.com/aEUeWy74mgRwc2Q8wB" target="_blank" style="display: inline-block; margin-top: 12px; padding: 10px 20px; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; border-radius: 20px; font-weight: 500; text-decoration: none;">
                Soutenir le projet
            </a>
        </footer>
    </div>
</body>
</html>
