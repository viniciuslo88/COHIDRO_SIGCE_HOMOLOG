<?php
// php/solicitar_reset_action.php — cria pedido 'pending' e aciona notificação para nível 5
if (session_status() === PHP_SESSION_NONE) { session_start(); }
date_default_timezone_set('America/Sao_Paulo');
require_once __DIR__ . "/conn.php";

function only_digits($s){ return preg_replace('/\D/','',(string)$s); }
function back($ok=null,$err=null,$location='/php/solicitar_reset.php'){
  if($ok){ $_SESSION['flash_ok']=$ok; }
  if($err){ $_SESSION['flash_err']=$err; }
  header("Location: $location"); exit;
}

$cpf = only_digits($_POST['cpf'] ?? '');
if ($cpf===''){ back(null, "Informe o CPF."); }

// Localiza usuário
$sql = "SELECT id, nome, email, cpf FROM usuarios_cohidro_sigce
        WHERE LPAD(REPLACE(REPLACE(REPLACE(IFNULL(cpf,''),'.',''),'-',''),' ',''),11,'0') = LPAD(?,11,'0')
        LIMIT 1";
$st = $conn->prepare($sql);
if(!$st){ back(null,"Falha ao preparar consulta: ".$conn->error); }
$st->bind_param("s",$cpf);
$st->execute();
$user = $st->get_result()->fetch_assoc();
$st->close();

if(!$user){ back(null,"CPF não encontrado."); }

// Verifica se já há pending
$uid = (int)$user['id'];
$chk = $conn->prepare("SELECT COUNT(*) FROM senha_reset_pedidos WHERE user_id=? AND status='pending'");
$chk->bind_param("i",$uid); $chk->execute(); $chk->bind_result($qty); $chk->fetch(); $chk->close();
if ($qty > 0){ back(null,"Já existe uma solicitação pendente. Aguarde o administrador."); }

// Insere pedido
$ip = $_SERVER['REMOTE_ADDR'] ?? null;
$ins = $conn->prepare("INSERT INTO senha_reset_pedidos (user_id, cpf, nome, email, created_ip) VALUES (?,?,?,?,?)");
$ins->bind_param("issss", $uid, $user['cpf'], $user['nome'], $user['email'], $ip);
$ok = $ins->execute();
$ins->close();

if(!$ok){ back(null,"Não foi possível registrar o pedido. Tente novamente."); }

back("Solicitação enviada. Aguarde a aprovação do Administrador.", null, "/php/solicitar_reset.php?cpf=".$cpf);
