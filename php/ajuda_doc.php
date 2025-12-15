<?php
// /php/ajuda_doc.php — serve documentos da ajuda (inline no iframe ou download)
// Uso:
//  /php/ajuda_doc.php?f=Manual_do_Usuario_SIGCE.pdf
//  /php/ajuda_doc.php?f=Fluxograma_SIGCE.pdf
//  /php/ajuda_doc.php?download=1&f=Manual_do_Usuario_SIGCE.pdf

ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

if (session_status() === PHP_SESSION_NONE) { session_start(); }
date_default_timezone_set('America/Sao_Paulo');

require_once __DIR__ . '/require_auth.php';
require_once __DIR__ . '/session_guard.php';

if (empty($_SESSION['cpf']) && empty($_SESSION['user_id'])) {
  http_response_code(401);
  exit('Não autenticado.');
}

$allowed = [
  'Manual_do_Usuario_SIGCE.pdf',
  'Fluxograma_SIGCE.pdf',
  // se você tiver outros no futuro, inclua aqui (somente nomes de arquivo)
];

$f = (string)($_GET['f'] ?? '');
$f = basename($f); // evita path traversal

if ($f === '' || !in_array($f, $allowed, true)) {
  http_response_code(404);
  exit('Documento inválido.');
}

$baseDir = realpath(__DIR__ . '/../assets/ajuda');
if (!$baseDir) {
  http_response_code(500);
  exit('Diretório de ajuda não encontrado.');
}

$path = realpath($baseDir . DIRECTORY_SEPARATOR . $f);
if (!$path || strpos($path, $baseDir) !== 0 || !is_file($path)) {
  http_response_code(404);
  exit('Arquivo não encontrado.');
}

$download = (int)($_GET['download'] ?? 0);

// MIME por extensão
$ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
$mime = 'application/octet-stream';
if ($ext === 'pdf') $mime = 'application/pdf';
if ($ext === 'png') $mime = 'image/png';
if ($ext === 'jpg' || $ext === 'jpeg') $mime = 'image/jpeg';
if ($ext === 'webp') $mime = 'image/webp';

while (ob_get_level() > 0) { @ob_end_clean(); }

header('X-Content-Type-Options: nosniff');
header('Content-Type: '.$mime);
header('Content-Length: '.filesize($path));
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

$disposition = $download ? 'attachment' : 'inline';
header('Content-Disposition: '.$disposition.'; filename="'.rawurlencode($f).'"');

readfile($path);
exit;
