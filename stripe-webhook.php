<?php
/**
 * Stripe Webhook - Reception des evenements Stripe
 * Confirme les paiements et genere les jetons
 *
 * URL a configurer dans Stripe Dashboard:
 * https://app.decision-collective.fr/stripe-webhook.php
 *
 * Evenements a ecouter:
 * - checkout.session.completed
 * - checkout.session.expired
 */

require_once 'config.php';
require_once 'functions.php';

// Desactiver l'affichage des erreurs (securite)
ini_set('display_errors', 0);
error_reporting(0);

// Verifier que Stripe est configure
if (!isStripeConfigured()) {
    http_response_code(503);
    exit;
}

// Lire le payload brut
$payload = file_get_contents('php://input');
$sigHeader = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';

// Verifier la signature
if (!verifyStripeWebhookSignature($payload, $sigHeader)) {
    http_response_code(400);
    error_log('Stripe webhook: signature invalide');
    exit;
}

// Decoder l'evenement
$event = json_decode($payload, true);

if (!$event || !isset($event['type'])) {
    http_response_code(400);
    error_log('Stripe webhook: payload invalide');
    exit;
}

// Log de l'evenement recu
error_log('Stripe webhook: ' . $event['type'] . ' - ' . ($event['data']['object']['id'] ?? 'unknown'));

// Traiter selon le type d'evenement
switch ($event['type']) {
    case 'checkout.session.completed':
        handleCheckoutSessionCompleted($event['data']['object']);
        break;

    case 'checkout.session.expired':
        handleCheckoutSessionExpired($event['data']['object']);
        break;

    default:
        // Evenement non gere, mais on repond 200 pour eviter les retries
        error_log('Stripe webhook: evenement ignore - ' . $event['type']);
        break;
}

http_response_code(200);
echo 'OK';

/**
 * Traiter un paiement reussi
 */
function handleCheckoutSessionCompleted($session) {
    $sessionId = $session['id'] ?? null;
    $paymentIntent = $session['payment_intent'] ?? null;
    $paymentStatus = $session['payment_status'] ?? null;

    if (!$sessionId) {
        error_log('Stripe webhook: session ID manquant');
        return;
    }

    // Verifier que le paiement est effectivement recu
    if ($paymentStatus !== 'paid') {
        error_log('Stripe webhook: paiement non finalise - status: ' . $paymentStatus);
        return;
    }

    // Recuperer l'achat correspondant
    $achat = getAchatByStripeSession($sessionId);

    if (!$achat) {
        error_log('Stripe webhook: achat non trouve pour session ' . $sessionId);
        return;
    }

    if ($achat['status'] === 'paid') {
        error_log('Stripe webhook: achat deja traite - ' . $sessionId);
        return;
    }

    // Marquer comme paye et generer les jetons
    $tokens = markAchatAsPaid($achat['id'], $paymentIntent);

    if ($tokens === false) {
        error_log('Stripe webhook: erreur lors du traitement de l\'achat ' . $achat['id']);
        return;
    }

    error_log('Stripe webhook: ' . count($tokens) . ' jetons generes pour achat ' . $achat['id']);
}

/**
 * Traiter une session expiree
 */
function handleCheckoutSessionExpired($session) {
    $sessionId = $session['id'] ?? null;

    if (!$sessionId) {
        return;
    }

    // Recuperer l'achat correspondant
    $achat = getAchatByStripeSession($sessionId);

    if (!$achat || $achat['status'] !== 'pending') {
        return;
    }

    // Marquer comme echoue
    markAchatAsFailed($achat['id']);

    error_log('Stripe webhook: session expiree - achat ' . $achat['id']);
}
