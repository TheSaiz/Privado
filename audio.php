<?php
$file = basename($_GET['f'] ?? '');
$path = __DIR__ . '/uploads/' . $file;

if (!file_exists($path)) {
    http_response_code(404);
    exit;
}

$ext = pathinfo($path, PATHINFO_EXTENSION);

$types = [
    'webm' => 'audio/webm',
    'mp3'  => 'audio/mpeg',
    'ogg'  => 'audio/ogg',
    'm4a'  => 'audio/mp4',
    'wav'  => 'audio/wav'
];

header('Content-Type: ' . ($types[$ext] ?? 'application/octet-stream'));
header('Content-Length: ' . filesize($path));
header('Accept-Ranges: bytes');
header('Cache-Control: no-store');

readfile($path);
exit;
