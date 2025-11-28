<?php
require_once __DIR__.'/conn.php';
require_once __DIR__.'/require_auth.php';
require_once __DIR__.'/roles.php';

if ((int)$_SESSION['role'] < ROLE_COORDENADOR){
  http_response_code(403); echo "Acesso negado."; exit;
}
function q(mysqli $c, $v){ return $c->real_escape_string(trim((string)$v)); }

$id = (int)($_POST['id'] ?? 0);
$decision = $_POST['decision'] ?? '';
$notes = $_POST['review_notes'] ?? '';

$sql = "SELECT * FROM emop_change_requests WHERE id={$id} AND status='pendente' LIMIT 1";
$res = $conn->query($sql);
if(!$res || $res->num_rows===0){ die("Solicitação inválida ou já processada."); }
$r = $res->fetch_assoc();

if ($decision==='aprovar'){
  $payload = json_decode($r['payload_json'], true) ?? [];
  if (!empty($payload)){
    $allowed = array_map('strtolower',[
      'Valor_Do_Contrato','Valor_Liquidado_Acumulado','Percentual_Executado','Saldo_Contratual_Com_Reajuste_RS',
      'Situacao','Observacoes','Data_Assinatura','Data_Encerramento'
    ]);
    $sets = [];
    foreach($payload as $k=>$v){
      if (in_array(strtolower($k), $allowed, true)){
        $col = preg_replace('/[^a-zA-Z0-9_]/','',$k);
        $sets[] = "`{$col}` = '".q($conn, (string)$v)."'";
      }
    }
    if ($sets){
      $sqlU = "UPDATE emop_contratos SET ".implode(",",$sets)." WHERE id=".(int)$r['contrato_id']." LIMIT 1";
      if(!$conn->query($sqlU)){
        die("Erro ao aplicar no banco: ".$conn->error);
      }
    }
  }
  $conn->query("UPDATE emop_change_requests SET status='aprovado', reviewer_id=".(int)$_SESSION['user_id'].", review_notes='".q($conn,$notes)."' WHERE id={$id}");
} else {
  $conn->query("UPDATE emop_change_requests SET status='reprovado', reviewer_id=".(int)$_SESSION['user_id'].", review_notes='".q($conn,$notes)."' WHERE id={$id}");
}

$msg = "Sua solicitação #{$id} foi ".($decision==='aprovar'?'aprovada':'reprovada').".";
$conn->query("INSERT INTO notifications (user_id, message, link) VALUES (".(int)$r['submitted_by'].", '".q($conn,$msg)."', '/form_contratos.php?contrato_id=".(int)$r['contrato_id']."')");

header('Location: /coordenador_inbox.php?open='.$id);
