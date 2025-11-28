<?php
// php/flash.php — utilitário simples de flash messages (sessão)
if (session_status() === PHP_SESSION_NONE) { session_start(); }
function flash_set(string $type, string $msg){
  $_SESSION['__flash__'][] = ['type'=>$type,'msg'=>$msg];
}
function flash_out(){
  if (empty($_SESSION['__flash__'])) return;
  foreach($_SESSION['__flash__'] as $f){
    $type = htmlspecialchars($f['type']);
    $msg  = $f['msg']; // já vem com HTML controlado
    echo '<div class="alert alert-' . $type . ' shadow-lg fw-semibold lh-sm" role="alert" style="font-size:1rem">';
    echo $msg;
    echo '</div>';
  }
  unset($_SESSION['__flash__']);
}
