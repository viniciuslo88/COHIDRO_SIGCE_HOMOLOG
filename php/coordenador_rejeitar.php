<?php
// php/coordenador_rejeitar.php — Versão Final com suporte a coluna 'reason'
if (session_status() === PHP_SESSION_NONE) { session_start(); }
date_default_timezone_set('America/Sao_Paulo');

require __DIR__ . '/require_auth.php';
require __DIR__ . '/session_guard.php';
require_once __DIR__ . '/conn.php';
require_once __DIR__ . '/roles.php';

header('Content-Type: application/json; charset=utf-8');

// Input
$id      = (int)($_POST['id'] ?? 0);
$motivo  = trim((string)($_POST['motivo'] ?? ''));
$revisao = (int)($_POST['revisao'] ?? 0);

// Fallback JSON
if ($id === 0) {
    $in = json_decode(file_get_contents('php://input'), true);
    if ($in) {
        $id = (int)($in['id']??0);
        $motivo = trim((string)($in['motivo']??''));
        $revisao = (int)($in['revisao']??0);
    }
}

if ($id <= 0 || $motivo === '') {
    echo json_encode(['ok'=>false, 'error'=>'Dados incompletos']); exit;
}

$status = ($revisao === 1) ? 'REVISAO_SOLICITADA' : 'REJEITADO';
$user_id = $_SESSION['user_id'] ?? 0;

try {
    $conn->begin_transaction();
    
    // Verifica colunas reais
    $cols = [];
    $rs = $conn->query("SHOW COLUMNS FROM coordenador_inbox");
    while($r=$rs->fetch_assoc()) $cols[] = strtolower($r['Field']);
    
    $sets = ["status='{$status}'"];
    $m = $conn->real_escape_string($motivo);
    
    // AQUI: Prioriza 'reason', depois 'review_notes', depois 'motivo'
    if (in_array('reason', $cols)) {
        $sets[] = "reason='{$m}'";
    } elseif (in_array('review_notes', $cols)) {
        $sets[] = "review_notes='{$m}'";
    } elseif (in_array('motivo_rejeicao', $cols)) {
        $sets[] = "motivo_rejeicao='{$m}'";
    }
    
    if (in_array('processed_by', $cols)) $sets[] = "processed_by={$user_id}";
    if (in_array('processed_at', $cols)) $sets[] = "processed_at=NOW()";
    
    $sql = "UPDATE coordenador_inbox SET ".implode(',', $sets)." WHERE id={$id}";
    if (!$conn->query($sql)) throw new Exception($conn->error);
    
    $conn->commit();
    echo json_encode(['ok'=>true]);
} catch (Exception $e) {
    $conn->rollback();
    echo json_encode(['ok'=>false, 'error'=>$e->getMessage()]);
}