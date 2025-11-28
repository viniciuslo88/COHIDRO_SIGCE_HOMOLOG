<?php
// Aprovar — aplica `campos` (objeto OU lista) em emop_contratos e insere `novas_medicoes`, `novos_aditivos`, `novos_reajustes`
if (session_status() === PHP_SESSION_NONE) { session_start(); }
date_default_timezone_set('America/Sao_Paulo');
require __DIR__ . '/require_auth.php';
require __DIR__ . '/session_guard.php';
require_once __DIR__ . '/conn.php';
require_once __DIR__ . '/medicoes_lib.php';
require_once __DIR__ . '/aditivos_lib.php';
require_once __DIR__ . '/reajustes_lib.php';
header('Content-Type: application/json; charset=utf-8');

function table_exists(mysqli $c, $t){
  $like = $c->real_escape_string($t);
  $rs = $c->query("SHOW TABLES LIKE '".$like."'"); $ok = ($rs && $rs->num_rows>0); if($rs) $rs->free(); return $ok;
}
function prefer_inbox_table(mysqli $c){
  foreach(['coordenador_inbox','emop_change_requests'] as $t) if(table_exists($c,$t)) return $t;
  return 'coordenador_inbox';
}
function emop_contratos_columns(mysqli $c){
  static $cols=null; if($cols!==null) return $cols;
  $cols=[]; if($rs=$c->query("SHOW COLUMNS FROM emop_contratos")){ while($r=$rs->fetch_assoc()) $cols[]=$r['Field']; $rs->free(); }
  return $cols;
}

$role = (int)($_SESSION['role'] ?? 0);
$user_id = (int)($_SESSION['user_id'] ?? 0);
if (!in_array($role, [2,5], true)){ http_response_code(403); echo json_encode(['ok'=>false,'error'=>'Acesso negado']); exit; }

$id = (int)($_POST['id'] ?? 0);
if ($id <= 0){ http_response_code(400); echo json_encode(['ok'=>false,'error'=>'ID ausente']); exit; }

$conn->begin_transaction();
try{
  $table = prefer_inbox_table($conn);
  if ($table === 'coordenador_inbox'){
    $rs=$conn->query("SELECT * FROM coordenador_inbox WHERE id={$id} AND UPPER(status)='PENDENTE' LIMIT 1");
    $row=$rs?$rs->fetch_assoc():null; if($rs) $rs->free();
    if(!$row){ throw new Exception('Solicitação não encontrada'); }
    $contrato_id=(int)$row['contrato_id']; $payload=json_decode((string)($row['payload_json']??''), true);
  } else {
    $rs=$conn->query("SELECT * FROM emop_change_requests WHERE id={$id} AND UPPER(Status)='PENDENTE' LIMIT 1");
    $row=$rs?$rs->fetch_assoc():null; if($rs) $rs->free();
    if(!$row){ throw new Exception('Solicitação não encontrada'); }
    $contrato_id=(int)$row['contrato_id']; $json=$row['extra_json'] ?? ($row['payload_json'] ?? ($row['changes_json']??'')); $payload=json_decode((string)$json, true);
  }

  if(!is_array($payload)) throw new Exception('Payload inválido');

  $applied=0;
  $cols=emop_contratos_columns($conn);

  // 1) novas_medicoes
  if (isset($payload['novas_medicoes']) && is_array($payload['novas_medicoes']) && count($payload['novas_medicoes'])>0){
    $conn->query("CREATE TABLE IF NOT EXISTS emop_medicoes(
      id INT AUTO_INCREMENT PRIMARY KEY,
      contrato_id INT NOT NULL,
      data_medicao DATE,
      valor_rs DECIMAL(18,2),
      acumulado_rs DECIMAL(18,2),
      percentual DECIMAL(7,4),
      obs TEXT NULL,
      created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
      KEY(contrato_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $stI=$conn->prepare("INSERT INTO emop_medicoes(contrato_id,data_medicao,valor_rs,acumulado_rs,percentual,obs) VALUES (?,?,?,?,?,?)");
    foreach($payload['novas_medicoes'] as $m){
      if(!is_array($m)) continue;
      $data=$m['data']??($m['data_medicao']??null);
      $valor=(float)($m['valor_rs']??($m['valor']??0));
      $acum=(float)($m['acumulado_rs']??0);
      $perc=(float)($m['percentual']??0);
      $obs = isset($m['obs']) ? (string)$m['obs'] : null;
      $stI->bind_param("isddds",$contrato_id,$data,$valor,$acum,$perc,$obs);
      $stI->execute();
    }
    $stI->close();
    $applied++;
  }

  // 1.b) novos_aditivos
  if (isset($payload['novos_aditivos']) && is_array($payload['novos_aditivos']) && count($payload['novos_aditivos'])>0){
    $conn->query("CREATE TABLE IF NOT EXISTS emop_aditivos(
      id INT AUTO_INCREMENT PRIMARY KEY,
      contrato_id INT NOT NULL,
      valor_aditivo_total DECIMAL(18,2) NULL,
      novo_prazo DATE NULL,
      valor_total_apos_aditivo DECIMAL(18,2) NULL,
      created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
      KEY(contrato_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    // usa helpers (normalização BRL/data)
    coh_insert_aditivos_from_array($conn, $contrato_id, $payload['novos_aditivos']);
    $applied++;
  }

  // 1.c) novos_reajustes
  if (isset($payload['novos_reajustes']) && is_array($payload['novos_reajustes']) && count($payload['novos_reajustes'])>0){
    $conn->query("CREATE TABLE IF NOT EXISTS emop_reajustamento(
      id INT AUTO_INCREMENT PRIMARY KEY,
      contrato_id INT NOT NULL,
      reajustes_percentual DECIMAL(7,4) NULL,
      valor_total_apos_reajuste DECIMAL(18,2) NULL,
      created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
      KEY(contrato_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    coh_insert_reajustes_from_array($conn, $contrato_id, $payload['novos_reajustes']);
    $applied++;
  }

  // 2) alterações de campos
  $pairs=[];
  if (isset($payload['campos']) && is_array($payload['campos'])){
    if (array_values($payload['campos']) !== $payload['campos']){ // OBJETO {col:valor}
      foreach($payload['campos'] as $col=>$val){ if(in_array($col,$cols,true)) $pairs[$col]=(string)$val; }
    } else { // LISTA
      foreach($payload['campos'] as $it){
        if(!is_array($it)) continue;
        $col=$it['col']??$it['campo']??null; $val=$it['val']??$it['valor']??null;
        if(!$col) continue;
        if(in_array($col,$cols,true)) $pairs[$col]=(string)$val;
      }
    }
  }
  if (!empty($pairs)){
    $set=[]; $types=''; $vals=[];
    foreach($pairs as $c=>$v){ $set[]="`{$c}`=?"; $types.='s'; $vals[]=$v; }
    $types.='i'; $vals[]=$contrato_id;
    $sql="UPDATE emop_contratos SET ".implode(', ',$set)." WHERE id=?";
    $st=$conn->prepare($sql);
    $st->bind_param($types, ...$vals);
    $st->execute();
    $st->close();
    $applied++;
  }

  // Marca como aprovado
  if ($table === 'coordenador_inbox'){
    $stM=$conn->prepare("UPDATE coordenador_inbox SET status='APROVADO', processed_by=?, processed_at=NOW() WHERE id=?");
    $stM->bind_param("ii",$user_id,$id); $stM->execute(); $stM->close();
  } else {
    $stM=$conn->prepare("UPDATE emop_change_requests SET Status='APROVADO', reviewed_by=?, reviewed_at=NOW() WHERE id=?");
    $stM->bind_param("ii",$user_id,$id); $stM->execute(); $stM->close();
  }

  $conn->commit();
  echo json_encode(['ok'=>true,'applied'=>$applied], JSON_UNESCAPED_UNICODE);
}catch(Throwable $e){
  $conn->rollback();
  http_response_code(400);
  echo json_encode(['ok'=>false,'error'=>$e->getMessage()], JSON_UNESCAPED_UNICODE);
}
