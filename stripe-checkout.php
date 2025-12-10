<?php
/**
 * Stripe Checkout - Creation de session de paiement
 * Redirige vers Stripe pour le paiement des jetons
 */

require_once 'config.php';
require_once 'functions.php';

// Verifier que Stripe est configure
if (!isStripeConfigured()) {
    header('HTTP/1.1 503 Service Unavailable');
    echo 'Le systeme de paiement n\'est pas disponible.';
    exit;
}

// Verifier que l'utilisateur est connecte
if (!isLoggedIn()) {
    header('HTTP/1.1 401 Unauthorized');
    echo 'Non authentifie';
    exit;
}

$user = getCurrentUser();

// Verifier la methode POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('HTTP/1.1 405 Method Not Allowed');
    echo 'Requete invalide.';
    exit;
}

// Verifier le token CSRF
if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
    header('HTTP/1.1 403 Forbidden');
    echo 'Session expiree. Veuillez recharger la page.';
    exit;
}

// Recuperer les parametres
$scrutinId = intval($_POST['scrutin_id'] ?? 0);
$nbJetons = intval($_POST['nb_jetons'] ?? 0);

// Validation
if ($scrutinId <= 0) {
    header('HTTP/1.1 400 Bad Request');
    echo 'Scrutin invalide.';
    exit;
}

if ($nbJetons < 1 || $nbJetons > 500) {
    header('HTTP/1.1 400 Bad Request');
    echo 'Nombre de jetons invalide (1-500).';
    exit;
}

// Verifier que l'utilisateur est proprietaire du scrutin
$scrutin = getScrutinById($scrutinId);
if (!$scrutin || $scrutin['owner_id'] != $user['id']) {
    header('HTTP/1.1 403 Forbidden');
    echo 'Acces refuse.';
    exit;
}

// Verifier que le scrutin est prive (les jetons sont pour les scrutins prives)
if ($scrutin['est_public']) {
    header('HTTP/1.1 400 Bad Request');
    echo 'Les jetons sont uniquement disponibles pour les scrutins prives.';
    exit;
}

// Construire les URLs de retour
$baseUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'];
$successUrl = $baseUrl . '/stripe-success.php?session_id={CHECKOUT_SESSION_ID}';
$cancelUrl = $baseUrl . '/' . $scrutin['code'] . '/v/';

// Creer la session Stripe Checkout
$result = createStripeCheckoutSession($scrutinId, $user['id'], $nbJetons, $successUrl, $cancelUrl);

if (!$result) {
    header('HTTP/1.1 500 Internal Server Error');
    echo 'Une erreur est survenue. Veuillez reessayer.';
    exit;
}

// Rediriger vers Stripe Checkout
header('Location: ' . $result['checkout_url']);
exit;
