<?php
// ajax/delete_contract_item.php

ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../php/require_auth.php';
require_once __DIR__ . '/../php/conn.php';
require_once __DIR__ . '/../php/medicoes_lib.php';
require_once __DIR__ . '/../php/aditivos_lib.php';
require_once __DIR__ . '/../php/reajustes_lib.php';

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') throw new Exception('Método inválido.');

    $tipo = $_POST['type'] ?? '';
    $id   = (int)($_POST['id'] ?? 0);

    if ($id <= 0 || empty($tipo)) throw new Exception('Dados inválidos.');

    // 1. Descobrir contrato_id
    $contrato_id = 0;
    $tabela = '';
    if ($tipo === 'medicao') $tabela = 'emop_medicoes';
    elseif ($tipo === 'aditivo') $tabela = 'emop_aditivos';
    elseif ($tipo === 'reajuste') $tabela = 'emop_reajustamento';
    else throw new Exception('Tipo desconhecido.');

    $st = $conn->prepare("SELECT contrato_id FROM {$tabela} WHERE id = ? LIMIT 1");
    $st->bind_param('i', $id); $st->execute();
    $st->bind_result($contrato_id); $st->fetch(); $st->close();

    if (!$contrato_id) throw new Exception('Item não encontrado.');

    // 2. Excluir
    if ($tipo === 'medicao') coh_delete_medicao($conn, $contrato_id, $id);
    elseif ($tipo === 'aditivo') {
        if(function_exists('coh_delete_aditivo')) coh_delete_aditivo($conn, $contrato_id, $id);
        else $conn->query("DELETE FROM emop_aditivos WHERE id=$id AND contrato_id=$contrato_id");
    }
    elseif ($tipo === 'reajuste') {
        if(function_exists('coh_delete_reajuste')) coh_delete_reajuste($conn, $contrato_id, $id);
        else $conn->query("DELETE FROM emop_reajustamento WHERE id=$id AND contrato_id=$contrato_id");
    }

    // 3. Recalcular Totais (Lógica unificada do form_contratos)
    $baseContrato = 0.0;
    if ($stB = $conn->prepare("SELECT COALESCE(Valor_Do_Contrato, 0) FROM emop_contratos WHERE id=?")) {
        $stB->bind_param('i', $contrato_id); $stB->execute(); $stB->bind_result($baseContrato); $stB->fetch(); $stB->close();
    }

    $totalAditivos = 0.0; $valorAposAditivos = 0.0;
    if ($stA = $conn->prepare("SELECT valor_aditivo_total, valor_total_apos_aditivo FROM emop_aditivos WHERE contrato_id=? ORDER BY created_at ASC, id ASC")) {
        $stA->bind_param('i', $contrato_id); $stA->execute(); $resA = $stA->get_result();
        while ($ra = $resA->fetch_assoc()) {
            $totalAditivos += (float)($ra['valor_aditivo_total'] ?? 0);
            $vApos = (float)($ra['valor_total_apos_aditivo'] ?? 0);
            if ($vApos > 0) $valorAposAditivos = $vApos;
        }
        $stA->close();
    }
    if ($valorAposAditivos <= 0 && ($baseContrato > 0 || $totalAditivos > 0)) $valorAposAditivos = $baseContrato + $totalAditivos;

    $valorAposReajustes = 0.0;
    if ($stR = $conn->prepare("SELECT valor_total_apos_reajuste FROM emop_reajustamento WHERE contrato_id=? ORDER BY created_at ASC, id ASC")) {
        $stR->bind_param('i', $contrato_id); $stR->execute(); $resR = $stR->get_result();
        while ($rr = $resR->fetch_assoc()) {
            $vAposR = (float)($rr['valor_total_apos_reajuste'] ?? 0);
            if ($vAposR > 0) $valorAposReajustes = $vAposR;
        }
        $stR->close();
    }
    $totalReajustes = 0.0;
    if ($valorAposReajustes > 0) {
        $totalReajustes = ($valorAposAditivos > 0) ? ($valorAposReajustes - $valorAposAditivos) : ($valorAposReajustes - ($baseContrato + $totalAditivos));
        if ($totalReajustes < 0) $totalReajustes = 0.0;
    }

    $valorTotalAtualContrato = ($valorAposReajustes > 0) ? $valorAposReajustes : (($valorAposAditivos > 0) ? $valorAposAditivos : ($baseContrato + $totalAditivos + $totalReajustes));

    $vtc = $valorTotalAtualContrato > 0 ? $valorTotalAtualContrato : $baseContrato;
    $ult = coh_fetch_medicoes_with_prev($conn, $contrato_id);
    
    $vlr_med = 0.0; $acum = 0.0; $perc_exec = 0.0;
    if (!empty($ult)) {
        foreach ($ult as $mm) $acum += (float)($mm['valor_rs'] ?? 0);
        $last = end($ult);
        $vlr_med = (float)($last['valor_rs'] ?? 0);
        $perc_exec = $vtc > 0 ? ($acum / $vtc) * 100.0 : 0.0;
    }

    $sqlFinal = "UPDATE emop_contratos SET 
                    Valor_Liquidado_Na_Medicao_RS = ?, Valor_Liquidado_Acumulado = ?, Percentual_Executado = ?,
                    Aditivos_RS = ?, Contrato_Apos_Aditivo_Valor_Total_RS = ?, Valor_Dos_Reajustes_RS = ?, Valor_Total_Do_Contrato_Novo = ?
                 WHERE id = ?";
    if ($stUp = $conn->prepare($sqlFinal)) {
        $p1=number_format($vlr_med,2,'.',''); $p2=number_format($acum,2,'.',''); $p3=number_format($perc_exec,2,'.','');
        $p4=number_format($totalAditivos,2,'.',''); $p5=number_format($valorAposAditivos,2,'.',''); $p6=number_format($totalReajustes,2,'.','');
        $p7=number_format($valorTotalAtualContrato,2,'.','');
        $stUp->bind_param('sssssssi', $p1,$p2,$p3,$p4,$p5,$p6,$p7, $contrato_id);
        $stUp->execute(); $stUp->close();
    }

    echo json_encode(['success' => true]);

} catch (Throwable $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}