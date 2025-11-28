<?php
require_once __DIR__.'/conn.php';
require_once __DIR__.'/require_auth.php';
header('Content-Type: application/json; charset=utf-8');
$user_id = (int)($_SESSION['user_id'] ?? 0);
$nid = (int)($_POST['id'] ?? 0);
if ($nid>0){
  $conn->query("UPDATE notifications SET is_read=1 WHERE id={$nid} AND user_id={$user_id}");
  echo json_encode(['ok'=>true]); exit;
}
echo json_encode(['ok'=>false, 'error'=>'id inválido']);
