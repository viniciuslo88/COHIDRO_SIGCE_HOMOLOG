<?php
// ajuda_faq.php — Ajuda & Perguntas Frequentes (SIGCE / EMOP)

header('Content-Type: text/html; charset=UTF-8');
header('X-Content-Type-Options: nosniff');

if (session_status() === PHP_SESSION_NONE) { session_start(); }
date_default_timezone_set('America/Sao_Paulo');

// Roles (padrão do projeto)
require_once __DIR__ . '/php/roles.php';

// ===== Auth / Guards =====
require_once __DIR__ . '/php/require_auth.php';
require_once __DIR__ . '/php/session_guard.php';

if (empty($_SESSION['cpf']) && empty($_SESSION['user_id'])) {
  header('Location: /login_senha.php'); exit;
}

// ===== Identidade do usuário (fallbacks) =====
$user_name  = (string)($_SESSION['nome'] ?? $_SESSION['user_name'] ?? $_SESSION['usuario_nome'] ?? 'Usuário');
$user_level = (int)($_SESSION['access_level'] ?? $_SESSION['nivel'] ?? $_SESSION['user_level'] ?? 0);

$roleMap = [
  1 => ['Fiscal',       'bi-person-badge'],
  2 => ['Coordenador',  'bi-diagram-3'],
  3 => ['Diretor',      'bi-building'],
  4 => ['Presidente',   'bi-award'],
  5 => ['Admin',        'bi-shield-lock'],
  6 => ['Dev',          'bi-code-slash'],
];
[$roleLabel, $roleIcon] = $roleMap[$user_level] ?? ['Usuário', 'bi-person'];

// ===== Arquivos de ajuda =====
$manual_url = '/assets/ajuda/manual_usuario.pdf';
$fluxo_url  = '/assets/ajuda/fluxograma_aprovacao.pdf';

$manual_fs = __DIR__ . $manual_url;
$fluxo_fs  = __DIR__ . $fluxo_url;

$manual_ok = file_exists($manual_fs);
$fluxo_ok  = file_exists($fluxo_fs);

// ===== FAQ =====
$faq = [
  'Primeiros passos' => [
    [
      'q' => 'Como faço login no sistema?',
      'a' => 'Use seu CPF e senha. Se estiver em primeiro acesso ou sem senha, solicite ao Administrador/gestão a criação/atualização do cadastro. Após login, seu nível de acesso define o que você pode editar/aprovar.'
    ],
    [
      'q' => 'O que significam os níveis/perfis (Fiscal, Coordenador, Diretor, etc.)?',
      'a' => 'O sistema segue uma hierarquia de trabalho e aprovação. Em geral: Fiscal (lança rascunhos e solicita aprovação), Coordenador (aprova/rejeita/solicita revisão), Diretor/Presidente (níveis superiores para validação), Admin/Gerência (pode salvar direto e gerenciar cadastros).'
    ],
    [
      'q' => 'Por que não vejo alguns contratos/diretorias?',
      'a' => 'A visualização é filtrada por Diretoria e nível de acesso. Se você não estiver vinculado à Diretoria do contrato, ele pode não aparecer. Se acredita que deveria ver, peça ajuste de permissão/cadastro.'
    ],
  ],

  'Busca e contratos' => [
    [
      'q' => 'Como buscar um contrato rapidamente?',
      'a' => 'Use os filtros (Nº do Contrato, Diretoria, Empresa, Município, Status) e, se necessário, a busca livre. Combine filtros para refinar. Ao abrir um contrato, você verá as seções (Medições, Aditivos, Reajustes, etc.).'
    ],
    [
      'q' => 'Qual a diferença entre “Valor do Contrato” e “Valor Total do Contrato (Novo)”?',
      'a' => '“Valor do Contrato” é o valor base/original cadastrado. “Valor Total do Contrato (Novo)” normalmente reflete o valor atualizado considerando aditivos e/ou reajustes (dependendo da regra aplicada na sua tela).'
    ],
    [
      'q' => 'Por que alguns valores aparecem “R$” e outros não?',
      'a' => 'Campos exibidos na interface usam formatação (R$, separadores). Já no banco os valores devem ficar numéricos padronizados. Se notar valor “estourado” ou estranho, costuma ser conversão/normalização incorreta.'
    ],
  ],

  'Medições' => [
    [
      'q' => 'Como lançar uma medição?',
      'a' => 'No contrato, vá em “Medições”, clique em adicionar/editar e informe Data, Valor (R$) e observação quando aplicável. O sistema calcula acumulado e percentuais conforme a base do contrato.'
    ],
    [
      'q' => 'O que é “acumulado” e “percentual executado”?',
      'a' => 'Acumulado é a soma das medições até o momento. Percentual executado é o acumulado dividido pelo valor base (valor total vigente), apresentado em %.'
    ],
    [
      'q' => 'Por que não consigo editar uma medição antiga?',
      'a' => 'Pode haver regra de travamento por tempo (ex.: 24h) e/ou por autoria (somente quem criou dentro do prazo). Se precisar corrigir, solicite revisão/ajuste via fluxo de aprovação.'
    ],
  ],

  'Aditivos' => [
    [
      'q' => 'Como cadastrar um aditivo?',
      'a' => 'No contrato, abra “Aditivos”, clique em adicionar e preencha número do aditivo, tipo, data, valor total e/ou novo prazo. O sistema pode recalcular o valor total após aditivo.'
    ],
    [
      'q' => 'Salvei “rascunho” no aditivo. Onde vejo?',
      'a' => 'Rascunhos ficam na própria seção do contrato (ou na listagem do modal) até serem enviados para aprovação/salvos definitivamente, conforme seu nível de acesso.'
    ],
    [
      'q' => 'O valor do contrato não aumentou após inserir aditivo. Por quê?',
      'a' => 'Geralmente acontece quando o contrato exibe “valor vigente” calculado por regra específica (ex.: usa valor_total_apos_aditivo da última linha aprovada). Verifique se o aditivo ficou como rascunho/pendente (não aprovado) ou se o campo de cálculo está preenchido.'
    ],
  ],

  'Reajustes' => [
    [
      'q' => 'Como lançar um reajuste?',
      'a' => 'Na seção “Reajustes”, informe índice, percentual, data-base e observação. O sistema calcula/guarda o valor total após reajuste (conforme sua regra de cálculo).'
    ],
    [
      'q' => 'Reajuste é aplicado sobre qual valor?',
      'a' => 'Depende da regra do seu sistema: pode ser sobre o valor base do contrato ou sobre o valor vigente após aditivos. Se houver divergência, confirme na regra adotada pela sua implantação.'
    ],
  ],

  'Fluxo de aprovação e histórico' => [
    [
      'q' => 'Como solicitar aprovação de alterações?',
      'a' => 'Após preencher as alterações (medições/aditivos/reajustes ou dados do contrato), use o botão “Solicitar Aprovação”. Isso envia para a inbox do nível responsável (geralmente Coordenador) com o comparativo antes/depois.'
    ],
    [
      'q' => 'Onde vejo o que está pendente, aprovado ou rejeitado?',
      'a' => 'Use a página de Inbox do seu perfil (Fiscal/Coordenador) e/ou a página de Histórico de Alterações. Lá você acompanha status, quem aprovou/rejeitou, datas e observações.'
    ],
    [
      'q' => 'O que significa “Revisão solicitada”?',
      'a' => 'Significa que o superior não rejeitou definitivamente, mas pediu ajustes. O solicitante deve revisar os dados e reenviar para aprovação.'
    ],
    [
      'q' => 'Por que meu nome não aparece como solicitante no histórico?',
      'a' => 'O histórico usa o usuário logado (user_id) como origem das solicitações. Se algum registro veio de importação ou ajuste direto, pode aparecer sem solicitante explícito.'
    ],
  ],

  'Problemas comuns' => [
    [
      'q' => 'O horário está aparecendo diferente (UTC / “horário mundial”). Como resolver?',
      'a' => 'O projeto deve usar timezone “America/Sao_Paulo” no PHP e, se necessário, ajustar o timezone do MySQL e a forma de exibir no front-end. Se ainda aparecer UTC, normalmente é conversão no JavaScript (Date) ou no SELECT (timezone do servidor).'
    ],
    [
      'q' => 'Cadastrei uma nova Diretoria/Empresa e não aparece no filtro.',
      'a' => 'Os selects precisam carregar dinamicamente do banco (DISTINCT). Se estiver “fixo” no código, não atualiza. Também verifique permissões por diretoria.'
    ],
    [
      'q' => 'Um valor ficou enorme após salvar (ex.: aditivo/reajuste).',
      'a' => 'Isso costuma ser conversão errada de string (R$, vírgula/ponto) para número. Garanta que o back-end normalize para decimal (ponto como separador) e armazene em campo numérico no banco.'
    ],
  ],

  'Uso no celular' => [
    [
      'q' => 'Posso instalar como aplicativo no celular/desktop?',
      'a' => 'Sim. Use a opção “Instalar” do navegador (Chrome/Edge). O sistema funciona como app e abre mais rápido. Alguns recursos offline dependem da configuração do service worker.'
    ],
  ],
];

// Itens extras para níveis >= 2 (coordenador+)
if ($user_level >= 2) {
  $faq['Aprovação (Coordenador+)'] = [
    [
      'q' => 'Como aprovar/rejeitar uma alteração recebida?',
      'a' => 'Acesse sua Inbox, abra o item, analise o comparativo antes/depois e escolha Aprovar, Rejeitar ou Solicitar Revisão. Sempre registre observação quando houver divergência.'
    ],
    [
      'q' => 'Como medir tempo médio de aprovação?',
      'a' => 'O sistema pode calcular pela diferença entre created_at (solicitação) e decided_at (aprovação/rejeição), consolidando KPIs no período filtrado.'
    ],
  ];
}

// ===== Helpers =====
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function slug($s){
  $s = strtolower(trim($s));
  $s = preg_replace('~[^\pL\d]+~u', '-', $s);
  $s = preg_replace('~[^-\w]+~', '', $s);
  return trim($s, '-');
}

$currentPage = 'ajuda_faq.php';
$page_title  = 'Ajuda & FAQ — SIGCE'; // se seu header usar título dinâmico

// ===== Partials (padrão do projeto) =====
// Abre <html><head> com favicon e abre <div class="coh-content">
require_once __DIR__ . '/partials/header.php';
?>

<style>
  .help-hero{
    background: linear-gradient(135deg, rgba(0,150,136,.18), rgba(33,150,243,.14));
    border: 1px solid rgba(0,0,0,.06);
    border-radius: 18px;
  }
  .help-card{
    border-radius: 18px;
    border: 1px solid rgba(0,0,0,.08);
  }
  .faq-muted{ color: rgba(0,0,0,.65); }
  .chip{
    display:inline-flex; align-items:center; gap:.5rem;
    padding:.35rem .6rem; border-radius: 999px;
    border: 1px solid rgba(0,0,0,.12);
    background: rgba(255,255,255,.7);
    font-size: .85rem;
  }
  .btn-soft{
    border: 1px solid rgba(0,0,0,.10);
    background: rgba(255,255,255,.85);
  }
  .btn-soft:hover{ background: rgba(255,255,255,1); }
  .pdf-frame{ width:100%; height: 72vh; border:0; }
  .kbd{
    font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace;
    background: rgba(0,0,0,.06);
    padding: .1rem .35rem;
    border-radius: .35rem;
    border: 1px solid rgba(0,0,0,.08);
  }
</style>

<div class="coh-page d-flex flex-column min-vh-100">

  <div class="container-fluid py-3">

    <div class="help-hero p-4 mb-4">
      <div class="d-flex flex-column flex-lg-row align-items-start align-items-lg-center justify-content-between gap-3">
        <div>
          <h1 class="h3 mb-1">Ajuda & Perguntas Frequentes</h1>
          <div class="faq-muted">
            Consulte o manual, o fluxograma e as dúvidas mais comuns do sistema.
          </div>
          <div class="mt-2 d-flex flex-wrap gap-2">
            <span class="chip"><i class="bi <?=h($roleIcon)?>"></i> <?=h($roleLabel)?> • <?=h($user_name)?></span>
            <span class="chip"><i class="bi bi-clock"></i> Horário: <?=date('d/m/Y H:i')?></span>
          </div>
        </div>

        <div class="w-100 w-lg-auto" style="max-width:520px;">
          <div class="input-group">
            <span class="input-group-text bg-white"><i class="bi bi-search"></i></span>
            <input id="faqSearch" type="text" class="form-control" placeholder="Buscar dúvida… (ex.: aprovação, aditivo, medição, status)">
            <button class="btn btn-soft" type="button" id="btnClear"><i class="bi bi-x-lg"></i></button>
          </div>
          <div class="small faq-muted mt-1">
            Dica: pressione <span class="kbd">Ctrl</span> + <span class="kbd">F</span> para busca do navegador.
          </div>
        </div>
      </div>
    </div>

    <div class="row g-3 mb-4">
      <div class="col-12 col-lg-6">
        <div class="card help-card">
          <div class="card-body">
            <div class="d-flex align-items-start justify-content-between gap-3">
              <div>
                <div class="d-flex align-items-center gap-2 mb-1">
                  <i class="bi bi-book fs-4"></i>
                  <h2 class="h5 mb-0">Manual do Usuário</h2>
                </div>
                <div class="faq-muted">Documento completo com orientações de uso.</div>
                <?php if (!$manual_ok): ?>
                  <div class="alert alert-warning mt-3 mb-0">
                    Arquivo não encontrado em <code><?=h($manual_url)?></code>.
                    Envie o PDF para <code>/assets/ajuda/</code>.
                  </div>
                <?php endif; ?>
              </div>
              <div class="d-flex gap-2 flex-wrap justify-content-end">
                <button class="btn btn-primary" <?= $manual_ok ? '' : 'disabled' ?>
                        data-bs-toggle="modal" data-bs-target="#docModal"
                        data-doc-title="Manual do Usuário" data-doc-url="<?=h($manual_url)?>">
                  <i class="bi bi-eye"></i> Visualizar
                </button>
                <a class="btn btn-soft <?= $manual_ok ? '' : 'disabled' ?>" href="<?=h($manual_url)?>" download>
                  <i class="bi bi-download"></i> Baixar
                </a>
              </div>
            </div>
          </div>
        </div>
      </div>

      <div class="col-12 col-lg-6">
        <div class="card help-card">
          <div class="card-body">
            <div class="d-flex align-items-start justify-content-between gap-3">
              <div>
                <div class="d-flex align-items-center gap-2 mb-1">
                  <i class="bi bi-diagram-3 fs-4"></i>
                  <h2 class="h5 mb-0">Fluxograma</h2>
                </div>
                <div class="faq-muted">Entenda o fluxo de operação do sistema.</div>
                <?php if (!$fluxo_ok): ?>
                  <div class="alert alert-warning mt-3 mb-0">
                    Arquivo não encontrado em <code><?=h($fluxo_url)?></code>.
                    Envie o PDF/PNG para <code>/assets/ajuda/</code>.
                  </div>
                <?php endif; ?>
              </div>
              <div class="d-flex gap-2 flex-wrap justify-content-end">
                <button class="btn btn-success" <?= $fluxo_ok ? '' : 'disabled' ?>
                        data-bs-toggle="modal" data-bs-target="#docModal"
                        data-doc-title="Fluxograma de Aprovação" data-doc-url="<?=h($fluxo_url)?>">
                  <i class="bi bi-eye"></i> Visualizar
                </button>
                <a class="btn btn-soft <?= $fluxo_ok ? '' : 'disabled' ?>" href="<?=h($fluxo_url)?>" download>
                  <i class="bi bi-download"></i> Baixar
                </a>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- FAQ -->
    <div class="card help-card">
      <div class="card-body">
        <div class="d-flex align-items-center justify-content-between gap-2 mb-2">
          <h2 class="h5 mb-0"><i class="bi bi-question-circle me-2"></i>Perguntas Frequentes</h2>
          <span class="small faq-muted" id="faqCount"></span>
        </div>

        <div class="accordion" id="faqAccordion">
          <?php
          $i = 0;
          foreach ($faq as $cat => $items):
            $catId = 'cat_' . slug($cat);
          ?>
            <div class="mt-3 mb-2">
              <div class="fw-semibold text-uppercase small faq-muted"><?=h($cat)?></div>
            </div>

            <?php foreach ($items as $it):
              $i++;
              $qid = $catId . '_q' . $i;
              $heading = $qid.'_h';
              $collapse = $qid.'_c';
            ?>
              <div class="accordion-item faq-item" data-q="<?=h($it['q'])?>" data-a="<?=h($it['a'])?>" data-cat="<?=h($cat)?>">
                <h2 class="accordion-header" id="<?=h($heading)?>">
                  <button class="accordion-button collapsed" type="button"
                          data-bs-toggle="collapse" data-bs-target="#<?=h($collapse)?>"
                          aria-expanded="false" aria-controls="<?=h($collapse)?>">
                    <?=h($it['q'])?>
                  </button>
                </h2>
                <div id="<?=h($collapse)?>" class="accordion-collapse collapse"
                     aria-labelledby="<?=h($heading)?>" data-bs-parent="#faqAccordion">
                  <div class="accordion-body">
                    <?=nl2br(h($it['a']))?>
                  </div>
                </div>
              </div>
            <?php endforeach; ?>
          <?php endforeach; ?>
        </div>

        <div class="mt-3 small faq-muted">
          Se algo não bater com sua realidade (ex.: cálculo de valores, regras de edição, permissões), registre o <b>ID do contrato</b> e envie para a equipe de suporte/gestão com print da tela.
        </div>
      </div>
    </div>

  </div> <!-- /container-fluid -->

  <!-- Modal de Documento (DENTRO DO BODY, antes do footer) -->
  <div class="modal fade" id="docModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title" id="docModalTitle">Documento</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
        </div>
        <div class="modal-body p-0">
          <iframe id="docFrame" class="pdf-frame" src="" loading="lazy"></iframe>
        </div>
        <div class="modal-footer">
          <a id="docDownload" class="btn btn-soft" href="#" download>
            <i class="bi bi-download"></i> Baixar
          </a>
          <button type="button" class="btn btn-primary" data-bs-dismiss="modal">
            <i class="bi bi-check2"></i> Fechar
          </button>
        </div>
      </div>
    </div>
  </div>

  <script>
    (function(){
      const input = document.getElementById('faqSearch');
      const btnClear = document.getElementById('btnClear');
      const items = Array.from(document.querySelectorAll('.faq-item'));
      const count = document.getElementById('faqCount');

      function norm(s){
        return (s || '').toString().toLowerCase().normalize('NFD').replace(/\p{Diacritic}/gu,'');
      }

      function applyFilter(){
        const q = norm(input.value.trim());
        let visible = 0;

        items.forEach(el => {
          const hay = norm(el.dataset.q + ' ' + el.dataset.a + ' ' + el.dataset.cat);
          const ok = !q || hay.includes(q);
          el.style.display = ok ? '' : 'none';
          if (ok) visible++;
        });
      }

      if (input) input.addEventListener('input', applyFilter);
      if (btnClear) btnClear.addEventListener('click', () => { input.value=''; applyFilter(); input.focus(); });

      applyFilter();

      // Modal docs
      const modal = document.getElementById('docModal');
      const title = document.getElementById('docModalTitle');
      const frame = document.getElementById('docFrame');
      const dwn   = document.getElementById('docDownload');

      if (modal) {
        modal.addEventListener('show.bs.modal', function(ev){
          const btn = ev.relatedTarget;
          const docTitle = btn?.getAttribute('data-doc-title') || 'Documento';
          const docUrl   = btn?.getAttribute('data-doc-url') || '';

          if (title) title.textContent = docTitle;
          if (frame) frame.src = docUrl ? (docUrl + '#toolbar=1&navpanes=0') : '';
          if (dwn) dwn.href = docUrl || '#';
        });

        modal.addEventListener('hidden.bs.modal', function(){
          if (frame) frame.src = '';
        });
      }
    })();
  </script>

  <div class="mt-auto">
    <?php require_once __DIR__ . '/partials/footer.php'; ?>
  </div>

</div> <!-- /coh-page -->
