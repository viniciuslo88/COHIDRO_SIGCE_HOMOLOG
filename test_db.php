<?php
// test_db.php — diagnóstico rápido de conexão
require __DIR__ . '/php/conn.php';
header('Content-Type: text/plain; charset=utf-8');
echo "Conectado com sucesso!\n";
echo "Servidor: ".$conn->host_info."\n";
$res = $conn->query('SELECT 1 AS ok');
$row = $res ? $res->fetch_assoc() : null;
var_export($row);
