<?php
// /php/fiscais_repo.php

if (!function_exists('coh_fetch_fiscais')) {
  function coh_fetch_fiscais(mysqli $conn): array {
    $out = [];
    $sql = "SELECT id, nome FROM emop_fiscais WHERE ativo=1 ORDER BY nome ASC";
    if ($rs = $conn->query($sql)) {
      while ($r = $rs->fetch_assoc()) {
        $id = (int)($r['id'] ?? 0);
        $n  = trim((string)($r['nome'] ?? ''));
        if ($id > 0 && $n !== '') {
          $out[] = ['id' => $id, 'nome' => $n];
        }
      }
      $rs->free();
    }
    return $out;
  }
}
