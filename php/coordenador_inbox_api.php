 <?php
 // php/coordenador_inbox_api.php — API para listar/apresentar inbox do Coordenador
 if (session_status() === PHP_SESSION_NONE) { session_start(); }
 date_default_timezone_set('America/Sao_Paulo');
 require_once __DIR__ . '/conn.php';

 function e($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
 $role = (int)($_SESSION['role'] ?? 0);
 $nome = $_SESSION['nome'] ?? '—';
 $user_id = (int)($_SESSION['user_id'] ?? 0);
 $dir = $_SESSION['diretoria'] ?? null;

 if ($role < 2) {
   http_response_code(403);
   echo "Acesso restrito ao Coordenador."; exit;
 }

 $table = "coordenador_inbox";
 $fn = $_GET['fn'] ?? 'list';

 if ($fn === 'count'){
   $where = "WHERE status='pendente'";
   if ($role < 5 && $dir){ $where .= " AND (diretoria = '" . $conn->real_escape_string($dir) . "')"; }
   $sql = "SELECT COUNT(*) FROM `$table` $where";
   $res = $conn->query($sql);
   $row = $res ? $res->fetch_row() : [0];
   header('Content-Type: application/json; charset=utf-8');
   echo json_encode(['count'=>(int)$row[0]]);
   exit;
 }

 // LIST — HTML
 $where = "WHERE a.status='pendente'";
 if ($role < 5 && $dir){ $where .= " AND a.diretoria = '" . $conn->real_escape_string($dir) . "'"; }

 $sql = "
   SELECT a.id, a.contrato_id, a.tabela, a.campo, a.valor_de, a.valor_para, a.justificativa, a.created_at,
          a.created_by, a.created_by_nome, a.diretoria,
          c.Numero_Contrato, c.Objeto, c.Empresa, c.Municipio, c.Secretaria, c.Diretoria
     FROM `$table` a
LEFT JOIN emop_contratos c ON c.id = a.contrato_id
    $where
 ORDER BY a.created_at DESC, a.id DESC
   LIMIT 500";

 $rows = [];
 if ($res = $conn->query($sql)){
   while($r = $res->fetch_assoc()){ $rows[] = $r; }
 }
 ?>
 <div class="container-fluid">
   <?php if (!$rows): ?>
     <div class="alert alert-success my-3">
       Nenhuma solicitação pendente para aprovar. 👌
     </div>
   <?php else: ?>
     <div class="small text-secondary mb-2">
       Exibindo <?= count($rows) ?> alterações pendentes
       <?php if ($dir): ?> para a Diretoria <strong><?= e($dir) ?></strong><?php endif; ?>.
     </div>
     <div class="list-group">
     <?php foreach ($rows as $r): ?>
       <div class="list-group-item list-group-item-action">
         <div class="d-flex justify-content-between align-items-start">
           <div class="me-3">
             <div class="fw-semibold">Contrato #<?= (int)$r['contrato_id'] ?> — <?= e($r['Numero_Contrato'] ?: 'S/N') ?></div>
             <div class="text-secondary small">
               <span class="me-2"><?= e($r['Empresa'] ?: 'Empresa não informada') ?></span> •
               <span class="ms-2 me-2"><?= e($r['Municipio'] ?: 'Município —') ?></span> •
               <span class="ms-2">Diretoria: <?= e($r['Diretoria'] ?: $r['diretoria'] ?: '—') ?></span>
             </div>
             <div class="mt-2">
               <div class="badge text-bg-light border me-2"><?= e($r['tabela'] ?: 'emop_contratos') ?></div>
               <div class="badge text-bg-primary me-2">Campo: <?= e($r['campo'] ?: '—') ?></div>
               <div class="badge text-bg-warning-subtle text-dark">Solicitado por: <?= e($r['created_by_nome'] ?: ('ID '.$r['created_by'])) ?></div>
             </div>
           </div>
           <div class="text-end small text-secondary" style="min-width:180px">
             <div class="">Enviado em <?= e(date('d/m/Y H:i', strtotime($r['created_at']))) ?></div>
             <div class="">ID Solicitação: #<?= (int)$r['id'] ?></div>
           </div>
         </div>
         <hr class="my-2">
         <div class="row g-3">
           <div class="col-md-6">
             <div class="card border-0 shadow-sm">
               <div class="card-header py-1"><strong>Valor ANTES</strong></div>
               <div class="card-body py-2"><pre class="mb-0" style="white-space:pre-wrap"><?= e($r['valor_de']) ?></pre></div>
             </div>
           </div>
           <div class="col-md-6">
             <div class="card border-0 shadow-sm">
               <div class="card-header py-1"><strong>Valor DEPOIS</strong></div>
               <div class="card-body py-2"><pre class="mb-0" style="white-space:pre-wrap"><?= e($r['valor_para']) ?></pre></div>
             </div>
           </div>
         </div>
         <?php if (!empty($r['justificativa'])): ?>
         <div class="mt-2 small">
           <strong>Justificativa:</strong> <?= e($r['justificativa']) ?>
         </div>
         <?php endif; ?>
         <div class="mt-3 d-flex gap-2 justify-content-end">
           <button data-id="<?= (int)$r['id'] ?>" data-contrato="<?= (int)$r['contrato_id'] ?>" 
                   class="btn btn-sm btn-success aprovacao-approve">
             <i class="bi bi-check2-circle me-1"></i> Aprovar
           </button>
           <button data-id="<?= (int)$r['id'] ?>" data-contrato="<?= (int)$r['contrato_id'] ?>" 
                   class="btn btn-sm btn-outline-danger aprovacao-reject">
             <i class="bi bi-x-circle me-1"></i> Rejeitar
           </button>
           <a href="/form_contratos.php?id=<?= (int)$r['contrato_id'] ?>" class="btn btn-sm btn-outline-secondary">
             <i class="bi bi-pencil-square me-1"></i> Abrir contrato
           </a>
         </div>
       </div>
     <?php endforeach; ?>
     </div>
   <?php endif; ?>
 </div>
