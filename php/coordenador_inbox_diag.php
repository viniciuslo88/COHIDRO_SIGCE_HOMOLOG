<?php
require_once __DIR__ . '/require_auth.php';
require_once __DIR__ . '/session_guard.php';
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once __DIR__ . '/conn.php';

header('Content-Type: text/html; charset=UTF-8');

$role = (int)($_SESSION['role'] ?? 0);
if ($role < 2){ echo "<b>Acesso negado.</b>"; exit; }

function table_exists(mysqli $c, $t){
  $like = $c->real_escape_string($t);
  $sql = "SHOW TABLES LIKE '".$like."'";
  $rs = $c->query($sql);
  if ($rs && $rs->num_rows > 0){ $rs->free(); return true; }
  if ($rs) $rs->free();
  return false;
}
function prefer_inbox_table(mysqli $c){
  foreach(['coordenador_inbox','emop_change_requests'] as $t){ if (table_exists($c,$t)) return $t; }
  return 'coordenador_inbox';
}
$table = prefer_inbox_table($conn);

if ($table === 'coordenador_inbox'){
  $sql = "SELECT id, contrato_id, diretoria, status, created_at, payload_json FROM coordenador_inbox WHERE UPPER(status)='PENDENTE' ORDER BY id DESC LIMIT 50";
} else {
  $sql = "SELECT id, contrato_id, Diretoria as diretoria, Status as status, created_at, COALESCE(extra_json, changes_json) as payload_json FROM emop_change_requests WHERE UPPER(Status)='PENDENTE' ORDER BY id DESC LIMIT 50";
}

$rs = $conn->query($sql);
$rows = [];
if ($rs){ while($r=$rs->fetch_assoc()){ $rows[]=$r; } $rs->free(); }

echo "<h3>Diag Inbox</h3>";
echo "<p>Tabela atual: <b>{$table}</b> — Pendentes: <b>".count($rows)."</b></p>";

if (!$rows){ echo "<div>Nenhum item pendente.</div>"; exit; }

echo "<table border=1 cellspacing=0 cellpadding=6>";
echo "<tr><th>#</th><th>Contrato</th><th>Diretoria</th><th>Status</th><th>Payload (preview)</th></tr>";
foreach($rows as $r){
  $payload = json_decode((string)($r['payload_json'] ?? ''), true);
  $preview = $payload ? substr(json_encode($payload, JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT), 0, 800) : '(JSON inválido ou vazio)';
  echo "<tr>";
  echo "<td>".(int)$r['id']."</td>";
  echo "<td>".(int)$r['contrato_id']."</td>";
  echo "<td>".htmlspecialchars((string)($r['diretoria']??''), ENT_QUOTES)."</td>";
  echo "<td>".htmlspecialchars((string)($r['status']??''), ENT_QUOTES)."</td>";
  echo "<td><pre style='white-space:pre-wrap'>".htmlspecialchars($preview, ENT_QUOTES)."</pre></td>";
  echo "</tr>";
}
echo "</table>";
