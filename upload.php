<?php
/**
 * Endpoint pour l'upload d'images
 * Retourne un JSON avec l'URL de l'image uploadée
 */
require_once 'config.php';
require_once 'functions.php';

header('Content-Type: application/json');

// Vérifier authentification
if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['error' => 'Non authentifié']);
    exit;
}

// Vérifier CSRF
if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
    http_response_code(403);
    echo json_encode(['error' => 'Token CSRF invalide']);
    exit;
}

// Vérifier qu'un fichier a été envoyé
if (!isset($_FILES['image']) || $_FILES['image']['error'] !== UPLOAD_ERR_OK) {
    $errorMessages = [
        UPLOAD_ERR_INI_SIZE => 'Fichier trop volumineux (limite serveur)',
        UPLOAD_ERR_FORM_SIZE => 'Fichier trop volumineux',
        UPLOAD_ERR_PARTIAL => 'Upload partiel',
        UPLOAD_ERR_NO_FILE => 'Aucun fichier envoyé',
        UPLOAD_ERR_NO_TMP_DIR => 'Erreur serveur (tmp)',
        UPLOAD_ERR_CANT_WRITE => 'Erreur écriture',
        UPLOAD_ERR_EXTENSION => 'Extension bloquée'
    ];
    $error = $errorMessages[$_FILES['image']['error'] ?? 0] ?? 'Erreur inconnue';
    http_response_code(400);
    echo json_encode(['error' => $error]);
    exit;
}

$file = $_FILES['image'];

// Vérifier le type MIME
$allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
$finfo = finfo_open(FILEINFO_MIME_TYPE);
$mimeType = finfo_file($finfo, $file['tmp_name']);
finfo_close($finfo);

if (!in_array($mimeType, $allowedTypes)) {
    http_response_code(400);
    echo json_encode(['error' => 'Type de fichier non autorisé. Formats acceptés : JPG, PNG, GIF, WebP']);
    exit;
}

// Vérifier la taille (max 5 Mo)
$maxSize = 5 * 1024 * 1024;
if ($file['size'] > $maxSize) {
    http_response_code(400);
    echo json_encode(['error' => 'Fichier trop volumineux (max 5 Mo)']);
    exit;
}

// Vérifier que c'est vraiment une image
$imageInfo = getimagesize($file['tmp_name']);
if ($imageInfo === false) {
    http_response_code(400);
    echo json_encode(['error' => 'Le fichier n\'est pas une image valide']);
    exit;
}

// Créer le dossier uploads s'il n'existe pas
$uploadDir = __DIR__ . '/uploads/';
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0755, true);
}

// Générer un nom de fichier unique
$extension = match($mimeType) {
    'image/jpeg' => 'jpg',
    'image/png' => 'png',
    'image/gif' => 'gif',
    'image/webp' => 'webp',
    default => 'jpg'
};

$filename = bin2hex(random_bytes(16)) . '.' . $extension;
$filepath = $uploadDir . $filename;

// Déplacer le fichier
if (!move_uploaded_file($file['tmp_name'], $filepath)) {
    http_response_code(500);
    echo json_encode(['error' => 'Erreur lors de l\'enregistrement']);
    exit;
}

// Construire l'URL publique
$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'];
$url = $protocol . '://' . $host . '/uploads/' . $filename;

echo json_encode([
    'success' => true,
    'url' => $url,
    'filename' => $filename
]);
