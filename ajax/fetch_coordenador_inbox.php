<?php
// ajax/fetch_coordenador_inbox.php — lista pendências para Coordenador (role >= 2) + valores atuais do contrato
if (session_status() === PHP_SESSION_NONE) { session_start(); }
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../php/conn.php';

// --- COMPATIBILIDADE PHP 5.3+ ---
$role = isset($_SESSION['role']) ? (int)$_SESSION['role'] : 0;
$diretoria = isset($_SESSION['diretoria']) ? $_SESSION['diretoria'] : null;
$user_id = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;
// --- FIM COMPATIBILIDADE ---

if ($role < 2){
  http_response_code(403);
  echo json_encode(['error'=>'Apenas Coordenador (2) ou superior.']); exit;
}

$sql = "CREATE TABLE IF NOT EXISTS coordenador_inbox (
  id INT AUTO_INCREMENT PRIMARY KEY,
  contrato_id INT NOT NULL,
  diretoria VARCHAR(100),
  fiscal_id INT,
  payload_json LONGTEXT,
  status VARCHAR(20) DEFAULT 'PENDENTE',
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  processed_by INT NULL,
  processed_at DATETIME NULL,
  reason TEXT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
$conn->query($sql);

// Pega pendentes da mesma diretoria do coordenador
if ($diretoria){
  $stmt = $conn->prepare("SELECT a.id, a.contrato_id, a.diretoria, a.fiscal_id, COALESCE(u.nome, '-') AS fiscal_nome,
                                  a.payload_json, a.status, a.created_at
                            FROM coordenador_inbox a
                            LEFT JOIN usuarios_cohidro_sigce u ON u.id = a.fiscal_id 
                            WHERE status='PENDENTE' AND (diretoria = ? OR diretoria IS NULL)
                            ORDER BY created_at DESC");
  $stmt->bind_param('s', $diretoria);
} else {
  $stmt = $conn->prepare("SELECT a.id, a.contrato_id, a.diretoria, a.fiscal_id, COALESCE(u.nome, '-') AS fiscal_nome,
                                  a.payload_json, a.status, a.created_at
                            FROM coordenador_inbox a
                            LEFT JOIN usuarios_cohidro_sigce u ON u.id = a.fiscal_id 
                            WHERE status='PENDENTE'
                            ORDER BY created_at DESC");
}
$stmt->execute();
$res = $stmt->get_result();

$out = array();
while($r = $res->fetch_assoc()){
  $r['current_values'] = new stdClass(); // default; vira objeto vazio no JSON
  
  // Decodifica payload
  // --- COMPATIBILIDADE PHP 5.3+ ---
  $payload_json = isset($r['payload_json']) ? $r['payload_json'] : "{}";
  $payload = json_decode($payload_json, true) ?: array();
  $campos = isset($payload['campos']) ? $payload['campos'] : array();
  // --- FIM COMPATIBILIDADE ---

  $cols = array();
  foreach($campos as $col => $nv){
    if (preg_match('/^[A-Za-z0-9_]+$/', $col)){
      $cols[] = $col;
    }
  }
  
  // Busca valores atuais para as colunas alteradas
  if (!empty($cols) && (int)$r['contrato_id'] > 0){
    $cols_esc = array_map(function($c){ return "`$c`"; }, $cols);
    $sqlCur = "SELECT " . implode(", ", $cols_esc) . " FROM emop_contratos WHERE id = " . (int)$r['contrato_id'] . " LIMIT 1";
    if ($resCur = $conn->query($sqlCur)){
      if ($rowCur = $resCur->fetch_assoc()){
        $r['current_values'] = (object)$rowCur;
      }
      $resCur->close();
    }
  }
  
  // ===== AJUSTE: Corrigir a contagem de itens alterados (já compatível) =====
  $cnt = 0;
  if (isset($payload['campos']) && is_array($payload['campos'])) {
      $cnt += count($payload['campos']);
  }
  if (isset($payload['novas_medicoes']) && is_array($payload['novas_medicoes'])) {
      $cnt += count($payload['novas_medicoes']);
  }
  if (!empty($payload['delete_medicao_id'])) {
      $cnt += 1;
  }
  $r['itens'] = $cnt;
  // ===== FIM DO AJUSTE =====

  $out[] = $r;
}

echo json_encode(array('count'=>count($out), 'items'=>$out), JSON_UNESCAPED_UNICODE);