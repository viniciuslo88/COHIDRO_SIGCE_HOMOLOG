<?php
// php/lgpd_guard.php
if (!isset($conn)) { require_once __DIR__ . '/conn.php'; }
if (session_status() === PHP_SESSION_NONE) { session_start(); }

function lgpd_onlyDigits($s){ return preg_replace('/\D/', '', $s ?? ''); }
function lgpd_formatCPF($cpf) {
  $cpf = lgpd_onlyDigits($cpf);
  return (strlen($cpf)===11) ? preg_replace('/(\d{3})(\d{3})(\d{3})(\d{2})/','$1.$2.$3-$4',$cpf) : $cpf;
}

$cpf_raw   = lgpd_onlyDigits($_SESSION['cpf'] ?? $_GET['cpf'] ?? '');
$nome      = $_SESSION['nome'] ?? '';
$diretoria = $_SESSION['diretoria'] ?? '';
$cpf_fmt   = lgpd_formatCPF($cpf_raw);

$TERMO_PATH = dirname(__DIR__) . '/assets/lgpd/termo.html';
$termo_tpl = is_readable($TERMO_PATH) ? file_get_contents($TERMO_PATH) :
  '<h2>Termo LGPD</h2><p>Conteúdo do termo não encontrado. Contate o administrador.</p>';

$version_hash = sha1(preg_replace('/\s+/', '', strip_tags($termo_tpl)));

$termo_renderizado = str_replace(
  ['[[NOME]]','[[CPF]]'],
  [htmlspecialchars($nome, ENT_QUOTES, 'UTF-8'), htmlspecialchars($cpf_fmt, ENT_QUOTES, 'UTF-8')],
  $termo_tpl
);

$SHOW_LGPD_MODAL = false;
if ($cpf_raw) {
  $stmt = $conn->prepare("SELECT 1 FROM lgpd_aceites_sigce WHERE cpf = ? AND version_hash = ? LIMIT 1");
  $stmt->bind_param("ss", $cpf_raw, $version_hash);
  $stmt->execute();
  $stmt->store_result();
  $SHOW_LGPD_MODAL = ($stmt->num_rows === 0);
  $stmt->close();
}

?>
<style>
  .lgpd-modal{display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:2000;}
  .lgpd-modal.show{display:block;}
  .lgpd-card{position:relative;background:#fff;border-radius:16px;box-shadow:0 20px 60px rgba(0,0,0,.25);overflow:hidden;}
  .no-scroll{overflow:hidden;}
  @media (min-width: 768px){ .lgpd-wrap{max-width:860px;margin:48px auto 0;} }
  @media (max-width: 767px){ .lgpd-wrap{margin:24px 16px 0;} }
</style>

<div id="lgpdModal" class="lgpd-modal<?php echo $SHOW_LGPD_MODAL ? ' show' : ''; ?>" aria-hidden="<?php echo $SHOW_LGPD_MODAL ? 'false':'true'; ?>">
  <div class="lgpd-wrap">
    <div class="lgpd-card">
      <div style="background:linear-gradient(90deg,#0891b2,#1d4ed8);padding:18px 22px;color:#fff;display:flex;align-items:center;gap:12px">
        <img src="/logo_emop_cohidro.jpg" alt="COHIDRO / EMOP" style="height:44px;width:44px;border-radius:8px;background:#fff;object-fit:contain;padding:4px;box-shadow:0 2px 8px rgba(0,0,0,.15)">
        <div style="flex:1 1 auto">
          <div style="font-weight:700;font-size:18px;line-height:1.1">Termo LGPD (Consentimento e Compromisso)</div>
          <div style="opacity:.85;font-size:12px">Leitura obrigatória — aceite para continuar</div>
        </div>
      </div>

      <div style="padding:18px 22px">
        <div style="height:360px;overflow:auto;border:1px solid #e5e7eb;border-radius:10px;padding:14px;background:#f8fafc;color:#111827">
          <?php echo $termo_renderizado; ?>
        </div>
        <label style="display:flex;gap:10px;align-items:flex-start;margin-top:14px;color:#374151;font-size:14px">
          <input id="lgpdAgree" type="checkbox" style="margin-top:3px">
          <span>Declaro que li e concordo com os termos acima, comprometendo-me a cumpri-los integralmente.</span>
        </label>
      </div>

      <div style="padding:0 22px 20px 22px;display:flex;justify-content:flex-end;gap:10px">
        <button id="btnLgpdAccept" style="background:#0891b2;color:#fff;border:0;border-radius:10px;padding:10px 16px;font-weight:600;opacity:.6;cursor:not-allowed">
          Li e aceito
        </button>
      </div>
    </div>
  </div>
</div>

<script>
(function(){
  var mustShow = <?php echo $SHOW_LGPD_MODAL ? 'true' : 'false'; ?>;
  var modal = document.getElementById('lgpdModal');
  var agree = document.getElementById('lgpdAgree');
  var btn   = document.getElementById('btnLgpdAccept');

  if (mustShow) { document.body.classList.add('no-scroll'); }
  if (agree) {
    agree.addEventListener('change', function(){
      if (agree.checked) {
        btn.style.opacity = '1';
        btn.style.cursor = 'pointer';
        btn.disabled = false;
      } else {
        btn.style.opacity = '.6';
        btn.style.cursor = 'not-allowed';
        btn.disabled = true;
      }
    });
  }
  if (btn) {
    btn.addEventListener('click', async function(){
      if (btn.disabled) return;
      btn.disabled = true;
      btn.style.opacity = '.6'; btn.style.cursor = 'not-allowed';

      var fd = new FormData();
      fd.append('agree', '1');
      fd.append('cpf', <?php echo json_encode($cpf_raw, JSON_UNESCAPED_UNICODE); ?>);
      fd.append('nome', <?php echo json_encode($nome, JSON_UNESCAPED_UNICODE); ?>);
      fd.append('diretoria', <?php echo json_encode($diretoria, JSON_UNESCAPED_UNICODE); ?>);
      fd.append('version_hash', <?php echo json_encode($version_hash, JSON_UNESCAPED_UNICODE); ?>);
      fd.append('termo_text', <?php echo json_encode($termo_renderizado, JSON_UNESCAPED_UNICODE); ?>);

      try{
        var resp = await fetch('/php/lgpd_accept.php', { method:'POST', body: fd });
        var j = await resp.json();
        if (!j.ok) { alert(j.msg || 'Não foi possível registrar o aceite.'); btn.disabled=false; return; }
        modal.classList.remove('show');
        document.body.classList.remove('no-scroll');
      }catch(e){
        alert('Erro de rede ao registrar o aceite.');
        btn.disabled=false;
      }
    });
  }
})();
</script>
