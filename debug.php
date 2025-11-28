<?php
// debug.php
header('Content-Type: text/html; charset=utf-8');
echo '<h1>DEBUG UPDATE CONTRATO</h1>';
echo '<pre>';

echo "SQL:\n";
var_dump($debugData['sql']);

echo "\n\nTypes (bind_param):\n";
var_dump($debugData['types']);

echo "\n\nParams (na ordem que vão pro bind_param):\n";
var_dump($debugData['params']);

echo "\n\nArray \$dbData (coluna => valor):\n";
var_dump($debugData['dbData']);

echo "\n\nID do contrato:\n";
var_dump($debugData['contrato_id']);

echo '</pre>';
