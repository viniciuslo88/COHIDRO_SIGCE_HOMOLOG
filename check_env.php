<?php
// check_env.php — mostra variáveis que o conn.php leu do .env
require __DIR__ . '/php/conn.php';
header('Content-Type: text/plain; charset=utf-8');
$keys = ['DB_HOST','DB_USER','DB_NAME','DB_PORT','DB_SOCKET','APP_ENV'];
foreach ($keys as $k) {
  $v = $_ENV[$k] ?? '(não definido)';
  echo $k.': '.$v."\n";
}
