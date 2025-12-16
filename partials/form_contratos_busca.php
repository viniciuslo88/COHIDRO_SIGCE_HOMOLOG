<?php
// partials/form_contratos_busca.php
// Bloco de busca + listagem dos contratos (layout preservado)

if (!function_exists('e')) {
  function e($v) { return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8'); }
}

/**
 * Limita string para exibição em selects, adicionando "..."
 */
if (!function_exists('coh_str_limit')) {
  function coh_str_limit(string $s, int $limit = 60): string {
    if (function_exists('mb_strlen') && function_exists('mb_substr')) {
      if (mb_strlen($s, 'UTF-8') <= $limit) return $s;
      return mb_substr($s, 0, $limit - 3, 'UTF-8') . '...';
    } else {
      if (strlen($s) <= $limit) return $s;
      return substr($s, 0, $limit - 3) . '...';
    }
  }
}

/**
 * Renderiza o Status como uma tag (badge) colorida:
 * EM VIGOR   - verde
 * SUSPENSO   - amarelo
 * ENCERRADO  - azul
 * RESCINDIDO - vermelho
 *
 * Mantém compatibilidade com textos antigos (EM EXECUÇÃO / ENCERRADA)
 */
if (!function_exists('coh_status_badge')) {
  function coh_status_badge($status_raw): string {
    $status = trim((string)$status_raw);

    // Classe base Bootstrap
    $class = 'badge';

    switch ($status) {
      case 'EM VIGOR':
      case 'EM EXECUÇÃO': // compatibilidade legado
        $class .= ' bg-success';
        break;

      case 'SUSPENSO':
        $class .= ' bg-warning text-dark';
        break;

      case 'ENCERRADO':
      case 'ENCERRADA': // compatibilidade legado
        $class .= ' bg-primary';
        break;

      case 'RESCINDIDO':
        $class .= ' bg-danger';
        break;

      default:
        $class .= ' bg-secondary';
        break;
    }

    return '<span class="' . $class . '">' . e($status) . '</span>';
  }
}

if (session_status() === PHP_SESSION_NONE) { session_start(); }
$role     = (int)($_SESSION['role']       ?? 0);
$user_dir =        ($_SESSION['diretoria']?? '');

// Usa $__SCOPE_SQL se vier do pai; senão, vazio (escopo de diretoria, etc.)
$__SCOPE_SQL = isset($__SCOPE_SQL) ? (string)$__SCOPE_SQL : '';

// ----------------------------------------------------------
// Lista suspensa de DIRETORIAS (distintas da emop_contratos)
// respeitando o mesmo escopo de consulta ($__SCOPE_SQL)
// ----------------------------------------------------------
$DIRETORIAS = [];
if (isset($conn) && $conn instanceof mysqli) {
  $sqlDir = "
    SELECT DISTINCT Diretoria
    FROM emop_contratos
    WHERE 1 {$__SCOPE_SQL}
      AND Diretoria IS NOT NULL
      AND TRIM(Diretoria) <> ''
    ORDER BY Diretoria
  ";
  if ($resDir = $conn->query($sqlDir)) {
    while ($rd = $resDir->fetch_assoc()) {
      $DIRETORIAS[] = $rd['Diretoria'];
    }
    $resDir->free();
  }
}
// Fallback caso o banco não retorne nada (evita select vazio)
if (empty($DIRETORIAS)) {
  $DIRETORIAS = ['DIRM', 'DIROB', 'DIRPP'];
}

// Lógica de visibilidade conforme nível de acesso
// Níveis 1, 2 e 3: só veem sua diretoria
if (in_array($role, [1, 2, 3], true) && !empty($user_dir)) {
  $DIRETORIAS = [$user_dir];
}

// ----------------------------------------------------------
// Lista suspensa de STATUS (distintos da emop_contratos)
// respeitando o mesmo escopo de consulta ($__SCOPE_SQL)
// ----------------------------------------------------------
$STATUS_OPTS = [];
if (isset($conn) && $conn instanceof mysqli) {
  $sqlSt = "
    SELECT DISTINCT Status
    FROM emop_contratos
    WHERE 1 {$__SCOPE_SQL}
      AND Status IS NOT NULL
      AND TRIM(Status) <> ''
    ORDER BY Status
  ";
  if ($resSt = $conn->query($sqlSt)) {
    while ($rs = $resSt->fetch_assoc()) {
      $STATUS_OPTS[] = $rs['Status'];
    }
    $resSt->free();
  }
}
// Fallback para os status padronizados, se o banco não trouxer nada
if (empty($STATUS_OPTS)) {
  $STATUS_OPTS = [
    'EM VIGOR',
    'SUSPENSO',
    'ENCERRADO',
    'RESCINDIDO',
  ];
}

// ----------------------------------------------------------
// Lista suspensa de MUNICÍPIOS (distintos da emop_contratos)
// respeitando o mesmo escopo de consulta ($__SCOPE_SQL)
// ----------------------------------------------------------
$MUNICIPIOS = [];
if (isset($conn) && $conn instanceof mysqli) {
  $sqlMun = "
    SELECT DISTINCT Municipio
    FROM emop_contratos
    WHERE 1 {$__SCOPE_SQL}
      AND Municipio IS NOT NULL
      AND TRIM(Municipio) <> ''
    ORDER BY Municipio
  ";
  if ($resMun = $conn->query($sqlMun)) {
    while ($rm = $resMun->fetch_assoc()) {
      $MUNICIPIOS[] = $rm['Municipio'];
    }
    $resMun->free();
  }
}

// ----------------------------------------------------------
// Lista suspensa de EMPRESAS (distintas da emop_contratos)
// respeitando o mesmo escopo de consulta ($__SCOPE_SQL)
// ----------------------------------------------------------
$EMPRESAS = [];
if (isset($conn) && $conn instanceof mysqli) {
  $sqlEmp = "
    SELECT DISTINCT Empresa
    FROM emop_contratos
    WHERE 1 {$__SCOPE_SQL}
      AND Empresa IS NOT NULL
      AND TRIM(Empresa) <> ''
    ORDER BY Empresa
  ";
  if ($resEmp = $conn->query($sqlEmp)) {
    while ($re = $resEmp->fetch_assoc()) {
      $EMPRESAS[] = $re['Empresa'];
    }
    $resEmp->free();
  }
}

// ============================================================

$HIDE_FORM   = $HIDE_FORM   ?? false;
$MAX_RESULTS = (int)($MAX_RESULTS ?? 50);

// Filtros
$cid       = trim((string)($_GET['cid']  ?? ($_POST['cid']  ?? ''))); // ID (filtro, não conflita com ?id=)
$no_con    = trim((string)($_GET['nc']   ?? ($_POST['nc']   ?? ''))); // Nº do contrato
$diretoria = trim((string)($_GET['d']    ?? ($_POST['d']    ?? '')));
$empresa   = trim((string)($_GET['emp']  ?? ($_POST['emp']  ?? '')));
$municipio = trim((string)($_GET['m']    ?? ($_POST['m']    ?? '')));
$status    = trim((string)($_GET['st']   ?? ($_POST['st']   ?? '')));
$q         = trim((string)($_GET['q']    ?? ($_POST['q']    ?? ''))); // Busca livre

// Dispara busca quando tiver algum filtro
$do_search = (
  $cid       !== '' ||
  $no_con    !== '' ||
  $diretoria !== '' ||
  $empresa   !== '' ||
  $municipio !== '' ||
  $status    !== '' ||
  $q         !== ''
);

$results = [];
$search_error = '';

if ($do_search && isset($conn) && $conn instanceof mysqli) {
  try {
    $where = "WHERE 1 {$__SCOPE_SQL}";
    $types = '';
    $vals  = [];

    // Filtro por ID (apenas dígitos)
    if ($cid !== '' && ctype_digit($cid)) {
      $where .= " AND id = ?";
      $types .= 'i';
      $vals[] = (int)$cid;
    }

    // Busca livre em vários campos (inclui SEI)
    if ($q !== '') {
      $where .= " AND (Objeto_Da_Obra LIKE ? 
                   OR Secretaria     LIKE ? 
                   OR Empresa        LIKE ? 
                   OR Gestor_Obra    LIKE ?
                   OR Processo_SEI   LIKE ?)";
      $like = "%{$q}%";
      $types .= 'sssss';
      array_push($vals, $like, $like, $like, $like, $like);
    }

    if ($diretoria !== '') { 
      $where .= " AND Diretoria = ?";          
      $types .= 's'; 
      $vals[] = $diretoria; 
    }
    if ($empresa !== '') {
      $where .= " AND Empresa = ?";
      $types .= 's';
      $vals[] = $empresa;
    }
    if ($municipio !== '') { 
      $where .= " AND Municipio = ?";          
      $types .= 's'; 
      $vals[] = $municipio; 
    }
    if ($status    !== '') { 
      $where .= " AND Status = ?";             
      $types .= 's'; 
      $vals[] = $status; 
    }
    if ($no_con    !== '') { 
      $where .= " AND No_do_Contrato LIKE ?";  
      $types .= 's'; 
      $vals[] = "%{$no_con}%"; 
    }

    $sql = "SELECT id, Diretoria, Municipio, Secretaria, Objeto_Da_Obra, No_do_Contrato, Processo_SEI, Status
            FROM emop_contratos
            $where
            ORDER BY id DESC
            LIMIT ?";
    $types .= 'i';
    $vals[] = $MAX_RESULTS;

    $stmt = $conn->prepare($sql);
    if (!$stmt) { throw new Exception('Falha ao preparar a consulta.'); }
    $stmt->bind_param($types, ...$vals);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) { $results[] = $row; }
    $stmt->close();

  } catch (Throwable $ex) {
    $search_error = $ex->getMessage();
  }
}
?>

<?php if (!$HIDE_FORM): ?>

  <!-- Logo + título exatamente como no layout -->
  <div class="text-center my-3">
    <img src="/assets/emop-cohidro.jpg" alt="EMOP + COHIDRO"
         style="max-width:360px;width:100%;height:auto;">
    <h2 class="mt-3" style="font-weight:600;color:#1f2937;font-size:1.6rem;">
      Informações de Contratos
    </h2>
  </div>
  <br>

  <div class="card mb-2">
    <div class="sec-title" style="background:#1e293b;">
      <span class="dot"></span> BUSCAR CONTRATOS
    </div>

    <div class="card-body">
      <form method="get" action="form_contratos.php">
        <!-- Linha 1: ID + Nº do Contrato -->
        <div class="row g-3">
          <div class="col-md-2">
            <label class="form-label"># ID</label>
            <input class="form-control" name="cid" value="<?= e($cid) ?>" placeholder="ex.: 123">
          </div>

          <div class="col-md-4">
            <label class="form-label">📄 Nº do Contrato</label>
            <input class="form-control" name="nc" value="<?= e($no_con) ?>" placeholder="ex.: 0001/22, 0123/24...">
          </div>

          <!-- Espaço para completar 12 colunas e alinhar com as demais linhas -->
          <div class="col-md-6 d-none d-md-block"></div>
        </div>

        <!-- Linha 2: Diretoria, Empresa, Município, Status -->
        <div class="row g-3 mt-1">
          <div class="col-md-2">
            <label class="form-label">🧭 Diretoria</label>
            <select class="form-select" name="d" <?= in_array($role, [1,2,3], true) ? 'disabled' : '' ?>>
              <option value="">-- Todas --</option>
              <?php foreach ($DIRETORIAS as $dopt): ?>
                <option value="<?= e($dopt) ?>" <?= $diretoria === $dopt || ($diretoria==='' && $user_dir===$dopt) ? 'selected' : '' ?>>
                  <?= e($dopt) ?>
                </option>
              <?php endforeach; ?>
            </select>
            <?php if (in_array($role, [1,2,3], true)): ?>
              <input type="hidden" name="d" value="<?= e($user_dir) ?>">
            <?php endif; ?>
          </div>

          <div class="col-md-4">
            <label class="form-label">🏢 Empresa</label>
            <select class="form-select" name="emp">
              <option value="">-- Todas --</option>
              <?php foreach ($EMPRESAS as $eopt): ?>
                <?php $e_label = coh_str_limit($eopt, 40); ?>
                <option
                  value="<?= e($eopt) ?>"
                  title="<?= e($eopt) ?>"
                  <?= ($empresa === $eopt) ? 'selected' : '' ?>
                >
                  <?= e($e_label) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="col-md-3">
            <label class="form-label">📍 Município</label>
            <select class="form-select" name="m">
              <option value="">-- Todos --</option>
              <?php foreach ($MUNICIPIOS as $mopt): ?>
                <?php $m_label = coh_str_limit($mopt, 40); ?>
                <option
                  value="<?= e($mopt) ?>"
                  title="<?= e($mopt) ?>"
                  <?= ($municipio === $mopt) ? 'selected' : '' ?>
                >
                  <?= e($m_label) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="col-md-3">
            <label class="form-label">📊 Status</label>
            <select class="form-select" name="st">
              <option value="">-- Todos --</option>
              <?php foreach ($STATUS_OPTS as $sopt): ?>
                <option value="<?= e($sopt) ?>" <?= ($status === $sopt) ? 'selected' : '' ?>>
                  <?= e($sopt) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>

        <!-- Linha 3: Busca livre -->
        <div class="row g-3 mt-1">
          <div class="col-md-12">
            <label class="form-label">
              🔍 Busca livre (Objeto / Empresa / Secretaria / Gestor / SEI)
            </label>
            <input class="form-control" name="q" value="<?= e($q) ?>" placeholder="ex.: drenagem, hospital, construção...">
          </div>
        </div>

        <!-- Botões -->
        <div class="row g-3 mt-2">
          <div class="col-12 d-flex align-items-center gap-2">
            <button class="btn btn-primary" type="submit">Buscar</button>
            <a class="btn btn-outline-secondary" href="form_contratos.php">Limpar</a>
          </div>
        </div>
      </form>

      <hr>

      <?php if ($search_error): ?>
        <div class="alert alert-danger mt-2 mb-0"><strong>Erro:</strong> <?= e($search_error) ?></div>
      <?php elseif ($do_search): ?>
        <?php if (empty($results)): ?>
          <div class="alert alert-warning mb-0">Nenhum contrato encontrado para os filtros informados.</div>
        <?php else: ?>
          <div class="table-responsive">
            <table class="table table-sm align-middle">
              <thead class="table-light">
                <tr>
                  <th>ID</th>
                  <th>Diretoria</th>
                  <th>Município</th>
                  <th>Secretaria</th>
                  <th>Objeto</th>
                  <th>Nº Contrato</th>
                  <th>SEI</th>
                  <th>Status</th>
                  <th style="width:90px"></th>
                </tr>
              </thead>
              <tbody>
              <?php foreach ($results as $r): ?>
                <tr>
                  <td><?= e($r['id']) ?></td>
                  <td><?= e($r['Diretoria']) ?></td>
                  <td><?= e($r['Municipio']) ?></td>
                  <td><?= e($r['Secretaria']) ?></td>
                  <td class="text-truncate" style="max-width:360px"><?= e($r['Objeto_Da_Obra']) ?></td>
                  <td><?= e($r['No_do_Contrato']) ?></td>
                  <td><?= e($r['Processo_SEI']) ?></td>
                  <td><?= coh_status_badge($r['Status']) ?></td>
                  <td><a class="btn btn-sm btn-primary" href="form_contratos.php?id=<?= e($r['id']) ?>">Abrir</a></td>
                </tr>
              <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      <?php else: ?>
        <div class="text-muted">Preencha um ou mais filtros acima e clique <strong>Buscar</strong>.</div>
      <?php endif; ?>
    </div>
  </div>

  <div class="d-flex justify-content-end mb-3">
    <a href="form_contratos.php?new=1" class="btn btn-success">+ Novo Contrato</a>
  </div>

<?php endif; ?>
