<?php
// /php/historico_gerenciamento.php — Histórico do Fale Conosco (Gerenciamento)
// Página completa: filtros + KPIs + tabela + paginação

header('Content-Type: text/html; charset=UTF-8');
header('X-Content-Type-Options: nosniff');

require_once __DIR__ . '/require_auth.php';
require_once __DIR__ . '/session_guard.php';
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once __DIR__ . '/conn.php';
date_default_timezone_set('America/Sao_Paulo');

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

$role = (int)($_SESSION['role'] ?? $_SESSION['access_level'] ?? $_SESSION['nivel'] ?? $_SESSION['user_level'] ?? 0);
if ($role < 5) {
  http_response_code(403);
  echo '<div class="alert alert-danger m-3">Acesso negado.</div>';
  exit;
}

// ===== Entradas (filtros) =====
$de      = trim((string)($_GET['de'] ?? ''));
$ate     = trim((string)($_GET['ate'] ?? ''));
$status  = strtolower(trim((string)($_GET['status'] ?? 'todos'))); // open/in_progress/answered/closed/todos
$categ   = strtoupper(trim((string)($_GET['categoria'] ?? 'TODAS')));
$q       = trim((string)($_GET['q'] ?? ''));

$p       = (int)($_GET['p'] ?? 1);
if ($p < 1) $p = 1;
$per     = 25;
$off     = ($p - 1) * $per;

$validStatus = ['todos','open','in_progress','answered','closed'];
if (!in_array($status, $validStatus, true)) $status = 'todos';

$validCats = ['TODAS','DUVIDA','SOLICITACAO','ERRO','SUGESTAO','ACESSO','OUTROS'];
if (!in_array($categ, $validCats, true)) $categ = 'TODAS';

// ===== Monta WHERE =====
$where = [];
$params = [];
$types = '';

if ($status !== 'todos') {
  $where[] = "m.status = ?";
  $types  .= 's';
  $params[] = $status;
}
if ($categ !== 'TODAS') {
  $where[] = "m.categoria = ?";
  $types  .= 's';
  $params[] = $categ;
}
if ($de !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $de)) {
  $where[] = "DATE(m.created_at) >= ?";
  $types  .= 's';
  $params[] = $de;
}
if ($ate !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $ate)) {
  $where[] = "DATE(m.created_at) <= ?";
  $types  .= 's';
  $params[] = $ate;
}
if ($q !== '') {
  // tenta capturar número p/ comparar contrato_id/id também
  $qNum = preg_replace('/\D/', '', $q);
  $like = '%'.$q.'%';

  $sub = [];
  $sub[] = "m.assunto LIKE ?";
  $sub[] = "m.mensagem LIKE ?";
  $sub[] = "m.nome LIKE ?";
  $sub[] = "m.cpf LIKE ?";
  $sub[] = "m.numero_contrato LIKE ?";
  $sub[] = "m.pagina LIKE ?";

  $types  .= 'ssssss';
  $params[] = $like;
  $params[] = $like;
  $params[] = $like;
  $params[] = $like;
  $params[] = $like;
  $params[] = $like;

  if ($qNum !== '') {
    $sub[] = "m.id = ?";
    $sub[] = "m.contrato_id = ?";
    $types  .= 'ii';
    $params[] = (int)$qNum;
    $params[] = (int)$qNum;
  }

  $where[] = "(".implode(" OR ", $sub).")";
}

$whereSql = $where ? ("WHERE ".implode(" AND ", $where)) : "";

// ===== KPIs (mesmos filtros) =====
$kpi = [
  'total'=>0,'abertas'=>0,'analise'=>0,'respondidas'=>0,'encerradas'=>0,'tempo_medio'=>0
];

$kpiSql = "
  SELECT
    COUNT(*) AS total,
    SUM(m.status='open') AS abertas,
    SUM(m.status='in_progress') AS analise,
    SUM(m.status='answered') AS respondidas,
    SUM(m.status='closed') AS encerradas,
    AVG(CASE WHEN m.status='answered' AND m.handled_at IS NOT NULL
             THEN TIMESTAMPDIFF(SECOND, m.created_at, m.handled_at)
        END) AS tempo_medio
  FROM gerenciamento_mensagens m
  $whereSql
";

$stK = $conn->prepare($kpiSql);
if ($stK) {
  if ($types !== '') $stK->bind_param($types, ...$params);
  $stK->execute();
  $rs = $stK->get_result();
  if ($rs && ($r = $rs->fetch_assoc())) {
    $kpi['total']      = (int)($r['total'] ?? 0);
    $kpi['abertas']    = (int)($r['abertas'] ?? 0);
    $kpi['analise']    = (int)($r['analise'] ?? 0);
    $kpi['respondidas']= (int)($r['respondidas'] ?? 0);
    $kpi['encerradas'] = (int)($r['encerradas'] ?? 0);
    $kpi['tempo_medio']= (int)round((float)($r['tempo_medio'] ?? 0));
  }
  $stK->close();
}

function fmtTempo($seg){
  $seg = (int)$seg;
  if ($seg <= 0) return '—';
  $d = intdiv($seg, 86400); $seg %= 86400;
  $h = intdiv($seg, 3600);  $seg %= 3600;
  $m = intdiv($seg, 60);
  $p = [];
  if ($d>0) $p[] = $d.'d';
  if ($h>0) $p[] = $h.'h';
  if ($m>0) $p[] = $m.'min';
  return $p ? implode(' ', $p) : 'menos de 1 min';
}

// ===== Total para paginação =====
$total = $kpi['total'];
$pages = max(1, (int)ceil($total / $per));
if ($p > $pages) $p = $pages;

// ===== Lista =====
$list = [];
$listSql = "
  SELECT
    m.id, m.created_at, m.updated_at,
    m.status, m.categoria, m.assunto, m.mensagem,
    m.nome, m.cpf, m.diretoria, m.role,
    m.contrato_id, m.numero_contrato, m.pagina,
    m.resposta, m.handled_at
  FROM gerenciamento_mensagens m
  $whereSql
  ORDER BY m.created_at DESC, m.id DESC
  LIMIT $per OFFSET $off
";
$stL = $conn->prepare($listSql);
if ($stL) {
  if ($types !== '') $stL->bind_param($types, ...$params);
  $stL->execute();
  $rs = $stL->get_result();
  while ($rs && ($r = $rs->fetch_assoc())) $list[] = $r;
  $stL->close();
}

// ===== HTML =====
ob_start();
?>
<link href="/assets/bootstrap.min.css" rel="stylesheet">
<style>
  :root{
    --muted:#6b7280;
    --kpi-bg:#f9fafb;
    --kpi-bd:#e5e7eb;
  }
  .page { max-width: 1180px; margin: 1rem auto; }
  .sticky-head{ position: sticky; top:0; z-index:5; background:#fff; padding:.5rem 0 .25rem; }
  .kpi-grid{
    display:grid;
    grid-template-columns: repeat(5, minmax(0,1fr));
    gap:.75rem;
    margin:1rem 0 1.25rem;
  }
  @media (max-width: 991.98px){ .kpi-grid{ grid-template-columns: repeat(2, minmax(0,1fr)); } }
  @media (max-width: 575.98px){ .kpi-grid{ grid-template-columns: minmax(0,1fr); } }
  .kpi-card{
    border-radius:.75rem;
    border:1px solid var(--kpi-bd);
    background:var(--kpi-bg);
    padding:.6rem .75rem;
    display:flex;
    flex-direction:column;
    gap:.15rem;
  }
  .kpi-label{ font-size:.75rem; text-transform:uppercase; letter-spacing:.04em; color:var(--muted); }
  .kpi-value{ font-size:1.25rem; font-weight:700; }
  .kpi-sub{ font-size:.75rem; color:var(--muted); }
  .msg{
    max-width: 620px;
    white-space: normal;
  }
</style>

<div class="page">
  <div class="sticky-head">
    <div class="d-flex justify-content-between align-items-center">
      <h5 class="mb-2">Histórico — Fale Conosco (Gerenciamento)</h5>
      <a class="btn btn-sm btn-outline-secondary" href="/index.php">
        <i class="bi bi-arrow-left me-1"></i>Voltar
      </a>
    </div>

    <form class="row g-2 align-items-end" method="get">
      <div class="col-auto">
        <label class="form-label mb-0 small">De</label>
        <input type="date" class="form-control form-control-sm" name="de" value="<?= h($de) ?>">
      </div>
      <div class="col-auto">
        <label class="form-label mb-0 small">Até</label>
        <input type="date" class="form-control form-control-sm" name="ate" value="<?= h($ate) ?>">
      </div>
      <div class="col-auto">
        <label class="form-label mb-0 small">Status</label>
        <select class="form-select form-select-sm" name="status">
          <option value="todos" <?= $status==='todos'?'selected':'' ?>>Todos</option>
          <option value="open" <?= $status==='open'?'selected':'' ?>>Aberta</option>
          <option value="in_progress" <?= $status==='in_progress'?'selected':'' ?>>Em análise</option>
          <option value="answered" <?= $status==='answered'?'selected':'' ?>>Respondida</option>
          <option value="closed" <?= $status==='closed'?'selected':'' ?>>Encerrada</option>
        </select>
      </div>
      <div class="col-auto">
        <label class="form-label mb-0 small">Categoria</label>
        <select class="form-select form-select-sm" name="categoria">
          <?php foreach (['TODAS','DUVIDA','SOLICITACAO','ERRO','SUGESTAO','ACESSO','OUTROS'] as $opt): ?>
            <option value="<?= $opt ?>" <?= $categ===$opt?'selected':'' ?>><?= $opt ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col">
        <label class="form-label mb-0 small">Busca livre</label>
        <input type="text" class="form-control form-control-sm" name="q"
               placeholder="Assunto, mensagem, nome, CPF, nº contrato, ID..."
               value="<?= h($q) ?>">
      </div>
      <div class="col-auto">
        <button class="btn btn-primary btn-sm">
          <i class="bi bi-funnel me-1"></i>Aplicar
        </button>
      </div>
    </form>
  </div>

  <div class="kpi-grid">
    <div class="kpi-card">
      <div class="kpi-label">Total</div>
      <div class="kpi-value"><?= (int)$kpi['total'] ?></div>
      <div class="kpi-sub">com filtros aplicados</div>
    </div>
    <div class="kpi-card">
      <div class="kpi-label">Abertas</div>
      <div class="kpi-value"><?= (int)$kpi['abertas'] ?></div>
      <div class="kpi-sub">status open</div>
    </div>
    <div class="kpi-card">
      <div class="kpi-label">Em análise</div>
      <div class="kpi-value"><?= (int)$kpi['analise'] ?></div>
      <div class="kpi-sub">status in_progress</div>
    </div>
    <div class="kpi-card">
      <div class="kpi-label">Respondidas</div>
      <div class="kpi-value"><?= (int)$kpi['respondidas'] ?></div>
      <div class="kpi-sub">status answered</div>
    </div>
    <div class="kpi-card">
      <div class="kpi-label">Tempo médio resposta</div>
      <div class="kpi-value"><?= h(fmtTempo($kpi['tempo_medio'])) ?></div>
      <div class="kpi-sub">answered: created → handled</div>
    </div>
  </div>

  <?php if (!$list): ?>
    <div class="alert alert-info">Nenhum registro encontrado.</div>
  <?php else: ?>
    <div class="table-responsive">
      <table class="table table-sm table-hover align-middle">
        <thead class="table-light">
          <tr>
            <th style="width:70px;">#</th>
            <th style="width:150px;">Data</th>
            <th style="width:130px;">Status</th>
            <th style="width:120px;">Categoria</th>
            <th>Mensagem</th>
            <th style="width:220px;">Usuário</th>
            <th style="width:170px;">Contrato</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($list as $r): ?>
            <tr>
              <td><?= (int)$r['id'] ?></td>
              <td class="small text-muted"><?= h($r['created_at'] ?? '') ?></td>
              <td>
                <?php
                  $st = (string)($r['status'] ?? 'open');
                  echo ($st==='answered' ? '<span class="badge bg-success">Respondida</span>' :
                        ($st==='in_progress' ? '<span class="badge bg-warning text-dark">Em análise</span>' :
                        ($st==='closed' ? '<span class="badge bg-dark">Encerrada</span>' :
                                          '<span class="badge bg-secondary">Aberta</span>')));
                ?>
              </td>
              <td><span class="badge bg-light text-dark border"><?= h($r['categoria'] ?? '') ?></span></td>
              <td class="msg">
                <div class="fw-semibold"><?= h($r['assunto'] ?? '') ?></div>
                <div class="small text-muted"><?= nl2br(h($r['mensagem'] ?? '')) ?></div>

                <?php if (!empty($r['resposta'])): ?>
                  <div class="mt-2 p-2 bg-light border rounded small">
                    <b>Resposta:</b><br><?= nl2br(h($r['resposta'])) ?>
                  </div>
                <?php endif; ?>
              </td>
              <td class="small">
                <div class="fw-semibold"><?= h($r['nome'] ?? '') ?></div>
                <div class="text-muted"><?= h($r['cpf'] ?? '') ?></div>
                <div class="text-muted"><?= h($r['diretoria'] ?? '') ?> • nível <?= h($r['role'] ?? '') ?></div>
              </td>
              <td class="small">
                <div>ID: <?= h($r['contrato_id'] ?? '') ?></div>
                <div>Nº: <?= h($r['numero_contrato'] ?? '') ?></div>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <?php if ($pages > 1): ?>
      <nav class="mt-3">
        <ul class="pagination pagination-sm flex-wrap">
          <?php
            // preserva querystring
            $qs = $_GET;
            for ($i=1; $i<=$pages; $i++):
              $qs['p'] = $i;
              $url = '?'.http_build_query($qs);
          ?>
            <li class="page-item <?= ($i===$p?'active':'') ?>">
              <a class="page-link" href="<?= h($url) ?>"><?= $i ?></a>
            </li>
          <?php endfor; ?>
        </ul>
      </nav>
    <?php endif; ?>

  <?php endif; ?>
</div>
<?php
$__CONTENT__ = ob_get_clean();

$pageTitle = 'Histórico — Fale Conosco (Gerenciamento)';
$header = __DIR__ . '/../partials/header.php';
$footer = __DIR__ . '/../partials/footer.php';

if (is_file($header) && is_file($footer)) {
  include $header;
  echo $__CONTENT__;
  include $footer;
} else {
  ?><!doctype html><html lang="pt-br"><head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= h($pageTitle) ?></title>
    <link href="/assets/bootstrap.min.css" rel="stylesheet">
  </head><body><?= $__CONTENT__ ?></body></html><?php
}
