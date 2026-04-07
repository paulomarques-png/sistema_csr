<?php
// ============================================================
// api/exportar_csv.php — Exportação CSV do Relatório
// Salvar em: C:\xampp\htdocs\sistema_csr\api\exportar_csv.php
// ============================================================
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

verificarPerfil(['administrativo', 'supervisor', 'master']);

$pdo = conectar();

// ── Filtros ─────────────────────────────────────────────────
$data_ini   = $_GET['data_ini']    ?? date('Y-m-d');
$data_fim   = $_GET['data_fim']    ?? date('Y-m-d');
$vid_filtro = $_GET['vendedor_id'] ?? '';

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $data_ini)) $data_ini = date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $data_fim))  $data_fim = date('Y-m-d');
if ($data_fim < $data_ini) $data_fim = $data_ini;

// ── Headers do download ─────────────────────────────────────
$fname = 'movimentacao_' . str_replace('-', '', $data_ini)
       . '_' . str_replace('-', '', $data_fim) . '.csv';
header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $fname . '"');
header('Cache-Control: no-cache, no-store');
header('Pragma: no-cache');

// BOM UTF-8 para Excel reconhecer acentos
echo "\xEF\xBB\xBF";

$out = fopen('php://output', 'w');

// Helper CSV: formata datas BR
function dtBR(string $d): string {
    return $d ? date('d/m/Y', strtotime($d)) : '';
}

// ── Params de query ──────────────────────────────────────────
$p_s = [':di' => $data_ini, ':df' => $data_fim];
$p_r = [':di' => $data_ini, ':df' => $data_fim];
$p_v = [':di' => $data_ini, ':df' => $data_fim];
$es = $er = $ev = '';
if ($vid_filtro && is_numeric($vid_filtro)) {
    $es = ' AND s.vendedor_id = :vid';
    $er = ' AND r.vendedor_id = :vid';
    $ev = ' AND v.vendedor_id = :vid';
    $p_s[':vid'] = $p_r[':vid'] = $p_v[':vid'] = (int)$vid_filtro;
}

// ============================================================
// SEÇÃO 1 — Cabeçalho do relatório
// ============================================================
fputcsv($out, ['CONTROLE DE CARGA — RELATÓRIO DE MOVIMENTAÇÃO'], ';');
fputcsv($out, ['Período:', dtBR($data_ini) . ' a ' . dtBR($data_fim)], ';');
fputcsv($out, ['Gerado em:', date('d/m/Y H:i')], ';');
fputcsv($out, ['Usuário:', $_SESSION['nome'] ?? ''], ';');
fputcsv($out, [], ';');

// ============================================================
// SEÇÃO 2 — Resumo por vendedor e produto
// ============================================================
fputcsv($out, ['=== RESUMO POR VENDEDOR E PRODUTO ==='], ';');
fputcsv($out, [
    'Vendedor', 'Código', 'Produto',
    'Saída', 'Retorno', 'Vendido', 'Saldo', 'Status'
], ';');

// Montar mapa: vendedor_id+codigo → dados
$mapa = [];

$st = $pdo->prepare("
    SELECT s.vendedor_id, s.vendedor, s.codigo, s.produto, SUM(s.quantidade) AS qtd
    FROM reg_saidas s
    WHERE s.data BETWEEN :di AND :df $es
    GROUP BY s.vendedor_id, s.vendedor, s.codigo, s.produto
    ORDER BY s.vendedor, s.produto
");
$st->execute($p_s);
foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $k = $r['vendedor_id'] . '_' . $r['codigo'];
    $mapa[$k] = [
        'vendedor' => $r['vendedor'],
        'codigo'   => $r['codigo'],
        'produto'  => $r['produto'],
        'saida'    => (int)$r['qtd'],
        'retorno'  => 0,
        'vendido'  => 0,
    ];
}

$st = $pdo->prepare("
    SELECT r.vendedor_id, r.codigo, r.produto, SUM(r.quantidade) AS qtd
    FROM reg_retornos r
    WHERE r.data BETWEEN :di AND :df $er
    GROUP BY r.vendedor_id, r.codigo, r.produto
");
$st->execute($p_r);
foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $k = $r['vendedor_id'] . '_' . $r['codigo'];
    if (isset($mapa[$k])) {
        $mapa[$k]['retorno'] += (int)$r['qtd'];
    } else {
        $mapa[$k] = [
            'vendedor' => '',
            'codigo'   => $r['codigo'],
            'produto'  => $r['produto'],
            'saida'    => 0,
            'retorno'  => (int)$r['qtd'],
            'vendido'  => 0,
        ];
    }
}

$st = $pdo->prepare("
    SELECT v.vendedor_id, v.codigo, v.produto, SUM(v.quantidade) AS qtd
    FROM reg_vendas v
    WHERE v.data BETWEEN :di AND :df $ev
    GROUP BY v.vendedor_id, v.codigo, v.produto
");
$st->execute($p_v);
foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $k = $r['vendedor_id'] . '_' . $r['codigo'];
    if (isset($mapa[$k])) {
        $mapa[$k]['vendido'] += (int)$r['qtd'];
    } else {
        $mapa[$k] = [
            'vendedor' => '',
            'codigo'   => $r['codigo'],
            'produto'  => $r['produto'],
            'saida'    => 0,
            'retorno'  => 0,
            'vendido'  => (int)$r['qtd'],
        ];
    }
}

$g_s = $g_r = $g_v = $g_sal = 0;
$vend_atual = '';

foreach ($mapa as $row) {
    $saldo = $row['saida'] - $row['retorno'] - $row['vendido'];
    $status = match(true) {
        $saldo === 0 => 'Zerado',
        $saldo > 0   => 'Em aberto',
        default      => 'Verificar (negativo)',
    };

    // Linha em branco ao trocar de vendedor
    if ($vend_atual !== '' && $row['vendedor'] !== $vend_atual) {
        fputcsv($out, [], ';');
    }
    $vend_atual = $row['vendedor'];

    fputcsv($out, [
        $row['vendedor'],
        $row['codigo'],
        $row['produto'],
        $row['saida'],
        $row['retorno'],
        $row['vendido'],
        $saldo,
        $status,
    ], ';');

    $g_s   += $row['saida'];
    $g_r   += $row['retorno'];
    $g_v   += $row['vendido'];
    $g_sal += $saldo;
}

// Linha de total geral
fputcsv($out, [], ';');
fputcsv($out, [
    'TOTAL GERAL', '', '',
    $g_s, $g_r, $g_v, $g_sal,
    $g_sal === 0 ? 'Zerado' : ($g_sal > 0 ? 'Pendente' : 'Verificar'),
], ';');

// ============================================================
// SEÇÃO 3 — Extrato cronológico completo
// ============================================================
fputcsv($out, [], ';');
fputcsv($out, ['=== EXTRATO CRONOLÓGICO COMPLETO ==='], ';');
fputcsv($out, [
    'Data', 'Hora', 'Vendedor', 'Tipo',
    'Código', 'Produto', 'Quantidade', 'Pedido', 'Observação'
], ';');

$p_ext = [
    ':di1' => $data_ini, ':df1' => $data_fim,
    ':di2' => $data_ini, ':df2' => $data_fim,
    ':di3' => $data_ini, ':df3' => $data_fim,
];
$es1 = $er1 = $ev1 = '';
if ($vid_filtro && is_numeric($vid_filtro)) {
    $es1 = ' AND s.vendedor_id = :vid1';
    $er1 = ' AND r.vendedor_id = :vid2';
    $ev1 = ' AND v.vendedor_id = :vid3';
    $p_ext[':vid1'] = $p_ext[':vid2'] = $p_ext[':vid3'] = (int)$vid_filtro;
}

$st = $pdo->prepare("
    SELECT 'Saída'   AS tipo, s.data, s.hora, s.vendedor, s.codigo, s.produto,
           s.quantidade, ''                    AS pedido, COALESCE(s.obs,'') AS obs
    FROM reg_saidas s
    WHERE s.data BETWEEN :di1 AND :df1 $es1
    UNION ALL
    SELECT 'Retorno', r.data, r.hora, r.vendedor, r.codigo, r.produto,
           r.quantidade, '',                             COALESCE(r.obs,'')
    FROM reg_retornos r
    WHERE r.data BETWEEN :di2 AND :df2 $er1
    UNION ALL
    SELECT 'Venda',   v.data, v.hora, v.vendedor, v.codigo, v.produto,
           v.quantidade, COALESCE(v.pedido,''), COALESCE(v.obs,'')
    FROM reg_vendas v
    WHERE v.data BETWEEN :di3 AND :df3 $ev1
    ORDER BY data ASC, hora ASC, tipo ASC
");
$st->execute($p_ext);

$data_ant = '';
foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
    // Linha em branco ao trocar de data
    if ($data_ant !== '' && $r['data'] !== $data_ant) {
        fputcsv($out, [], ';');
    }
    $data_ant = $r['data'];

    fputcsv($out, [
        dtBR($r['data']),
        substr($r['hora'], 0, 5),
        $r['vendedor'],
        $r['tipo'],
        $r['codigo'],
        $r['produto'],
        (int)$r['quantidade'],
        $r['pedido'],
        $r['obs'],
    ], ';');
}

fclose($out);
exit;