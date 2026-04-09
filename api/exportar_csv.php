<?php
// ============================================================
// api/exportar_csv.php
// Salvar em: C:\xampp\htdocs\sistema_csr\api\exportar_csv.php
// ============================================================
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

verificarPerfil(['admin', 'supervisor', 'master']);

$pdo = conectar();

$data_ini   = $_GET['data_ini']    ?? date('Y-m-d');
$data_fim   = $_GET['data_fim']    ?? date('Y-m-d');
$vid_filtro = $_GET['vendedor_id'] ?? '';

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $data_ini)) $data_ini = date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $data_fim))  $data_fim = date('Y-m-d');
if ($data_fim < $data_ini) $data_fim = $data_ini;

$fname = 'movimentacao_' . str_replace('-','', $data_ini) . '_' . str_replace('-','', $data_fim) . '.csv';
header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $fname . '"');
header('Cache-Control: no-cache, no-store');
echo "\xEF\xBB\xBF"; // BOM UTF-8 para Excel

$out = fopen('php://output', 'w');
function dtBR(string $d): string { return $d ? date('d/m/Y', strtotime($d)) : ''; }

$p_s = [':di' => $data_ini, ':df' => $data_fim];
$p_r = [':di' => $data_ini, ':df' => $data_fim];
$p_v = [':di' => $data_ini, ':df' => $data_fim];
$es = $er = $ev = '';
if ($vid_filtro && is_numeric($vid_filtro)) {
    $es = ' AND s.vendedor_id = :vid'; $er = ' AND r.vendedor_id = :vid'; $ev = ' AND v.vendedor_id = :vid';
    $p_s[':vid'] = $p_r[':vid'] = $p_v[':vid'] = (int)$vid_filtro;
}

fputcsv($out, [SISTEMA_NOME . ' — Relatório de Movimentação'], ';');
fputcsv($out, ['Período:', dtBR($data_ini) . ' a ' . dtBR($data_fim)], ';');
fputcsv($out, ['Gerado em:', date('d/m/Y H:i')], ';');
fputcsv($out, ['Usuário:', $_SESSION['usuario_nome'] ?? ''], ';');
fputcsv($out, [], ';');

fputcsv($out, ['=== RESUMO POR VENDEDOR E PRODUTO ==='], ';');
fputcsv($out, ['Vendedor','Código','Produto','Saída','Retorno','Vendido','Saldo','Status'], ';');

$mapa = [];
$st = $pdo->prepare("SELECT s.vendedor_id, s.vendedor, s.codigo, s.produto, SUM(s.quantidade) AS qtd
    FROM reg_saidas s WHERE s.data BETWEEN :di AND :df $es
    GROUP BY s.vendedor_id, s.vendedor, s.codigo, s.produto ORDER BY s.vendedor, s.produto");
$st->execute($p_s);
foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $k = $r['vendedor_id'].'_'.$r['codigo'];
    $mapa[$k] = ['vendedor'=>$r['vendedor'],'codigo'=>$r['codigo'],'produto'=>$r['produto'],
                 'saida'=>(int)$r['qtd'],'retorno'=>0,'vendido'=>0];
}
$st = $pdo->prepare("SELECT r.vendedor_id, r.codigo, SUM(r.quantidade) AS qtd
    FROM reg_retornos r WHERE r.data BETWEEN :di AND :df $er GROUP BY r.vendedor_id, r.codigo");
$st->execute($p_r);
foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $k = $r['vendedor_id'].'_'.$r['codigo'];
    if (isset($mapa[$k])) $mapa[$k]['retorno'] += (int)$r['qtd'];
}
$st = $pdo->prepare("SELECT v.vendedor_id, v.codigo, SUM(v.quantidade) AS qtd
    FROM reg_vendas v WHERE v.data BETWEEN :di AND :df $ev GROUP BY v.vendedor_id, v.codigo");
$st->execute($p_v);
foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $k = $r['vendedor_id'].'_'.$r['codigo'];
    if (isset($mapa[$k])) $mapa[$k]['vendido'] += (int)$r['qtd'];
}

$g_s=$g_r=$g_v=$g_sal=0; $vend_ant='';
foreach ($mapa as $row) {
    $saldo = $row['saida']-$row['retorno']-$row['vendido'];
    $status = $saldo===0 ? 'Zerado' : ($saldo>0 ? 'Em aberto' : 'Verificar (negativo)');
    if ($vend_ant!=='' && $row['vendedor']!==$vend_ant) fputcsv($out,[], ';');
    $vend_ant=$row['vendedor'];
    fputcsv($out,[$row['vendedor'],$row['codigo'],$row['produto'],
                  $row['saida'],$row['retorno'],$row['vendido'],$saldo,$status], ';');
    $g_s+=$row['saida']; $g_r+=$row['retorno']; $g_v+=$row['vendido']; $g_sal+=$saldo;
}
fputcsv($out,[], ';');
fputcsv($out,['TOTAL GERAL','','',$g_s,$g_r,$g_v,$g_sal,
              $g_sal===0?'Zerado':($g_sal>0?'Pendente':'Verificar')], ';');

fputcsv($out,[], ';');
fputcsv($out,['=== EXTRATO CRONOLÓGICO COMPLETO ==='], ';');
fputcsv($out,['Data','Hora','Vendedor','Tipo','Código','Produto','Quantidade','Pedido','Observação'], ';');

$p_ext=[':di1'=>$data_ini,':df1'=>$data_fim,':di2'=>$data_ini,':df2'=>$data_fim,':di3'=>$data_ini,':df3'=>$data_fim];
$es1=$er1=$ev1='';
if ($vid_filtro && is_numeric($vid_filtro)) {
    $es1=' AND s.vendedor_id=:vid1'; $er1=' AND r.vendedor_id=:vid2'; $ev1=' AND v.vendedor_id=:vid3';
    $p_ext[':vid1']=$p_ext[':vid2']=$p_ext[':vid3']=(int)$vid_filtro;
}
$st=$pdo->prepare("
    SELECT 'Saída' AS tipo,s.data,s.hora,s.vendedor,s.codigo,s.produto,s.quantidade,'' AS pedido,COALESCE(s.obs,'') AS obs
    FROM reg_saidas s WHERE s.data BETWEEN :di1 AND :df1 $es1
    UNION ALL
    SELECT 'Retorno',r.data,r.hora,r.vendedor,r.codigo,r.produto,r.quantidade,'',COALESCE(r.obs,'')
    FROM reg_retornos r WHERE r.data BETWEEN :di2 AND :df2 $er1
    UNION ALL
    SELECT 'Venda',v.data,v.hora,v.vendedor,v.codigo,v.produto,v.quantidade,COALESCE(v.pedido,''),COALESCE(v.obs,'')
    FROM reg_vendas v WHERE v.data BETWEEN :di3 AND :df3 $ev1
    ORDER BY data ASC, hora ASC, tipo ASC
");
$st->execute($p_ext);
$data_ant='';
foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
    if ($data_ant!=='' && $r['data']!==$data_ant) fputcsv($out,[], ';');
    $data_ant=$r['data'];
    fputcsv($out,[dtBR($r['data']),substr($r['hora'],0,5),$r['vendedor'],$r['tipo'],
                  $r['codigo'],$r['produto'],(int)$r['quantidade'],$r['pedido'],$r['obs']], ';');
}
fclose($out);
exit;