<?php
// Descontinuado: fluxo migrado para login_senha.php
header("Location: /login_senha.php" . (isset($_GET['cpf']) ? ('?cpf=' . urlencode($_GET['cpf'])) : ''));
exit;
