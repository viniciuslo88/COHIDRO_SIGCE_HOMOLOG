<?php
require_once __DIR__.'/conn.php';
require_once __DIR__.'/require_auth.php';
header('Content-Type: application/json; charset=utf-8');
$user_id = (int)($_SESSION['user_id'] ?? 0);
$sql = "SELECT id, message, link, is_read, created_at
        FROM notifications
        WHERE user_id={$user_id} AND is_read=0
        ORDER BY created_at DESC LIMIT 50";
$res = $conn->query($sql);
$out = [];
if ($res){ while($r = $res->fetch_assoc()){ $out[] = $r; } }
echo json_encode(['ok'=>true,'items'=>$out], JSON_UNESCAPED_UNICODE);
