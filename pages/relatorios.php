<?php
// ============================================================
// pages/relatorios.php — Relatório Unificado de Movimentação
// ============================================================
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

verificarPerfil(['admin', 'supervisor', 'master']);

$pdo     = conectar();
$perfil  = $_SESSION['usuario_perfil'] ?? '';
$usuario = $_SESSION['usuario_nome']   ?? 'Usuário';

// ── Filtros ──────────────────────────────────────────────────────
$data_ini   = $_GET['data_ini']    ?? date('Y-m-d');
$data_fim   = $_GET['data_fim']    ?? date('Y-m-d');
$vid_filtro = $_GET['vendedor_id'] ?? '';
$so_pend    = !empty($_GET['apenas_pendencias']);

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $data_ini)) $data_ini = date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $data_fim))  $data_fim = date('Y-m-d');
if ($data_fim < $data_ini) $data_fim = $data_ini;

$vendedores = $pdo->query(
    "SELECT id, nome FROM vendedores WHERE ativo = 1 ORDER BY nome"
)->fetchAll();

// ── Inicializa estrutura de dados ────────────────────────────────
$dados = [];
foreach ($vendedores as $v) {
    $dados[$v['id']] = [
        'nome'              => $v['nome'],
        'produtos'          => [],
        'registros'         => [],
        'tot_saida'         => 0,    // apenas confirmados
        'tot_retorno'       => 0,    // apenas confirmados
        'tot_vendido'       => 0,
        'pend_saida'        => 0,    // aguardando QR (não entram no saldo)
        'pend_retorno'      => 0,    // aguardando QR (não entram no saldo)
        'rej_saida'         => 0,    // rejeitados pelo vendedor (não entram no saldo)
        'rej_retorno'       => 0,    // rejeitados pelo vendedor (não entram no saldo)
        'saldo'             => 0,    // calculado só de confirmados
        'tem_pendencia'     => false, // saldo != 0
        'tem_pend_nao_conf' => false, // tem registros não confirmados
        'tem_rejeitado'     => false, // tem registros rejeitados não corrigidos
        'tem_movimento'     => false,
    ];
}

// CORREÇÃO: initProd agora inclui pend_saida, pend_retorno, rej_saida, rej_retorno por produto
function initProd(array &$dados, $vid, $cod, $pnome): void {
    if (!isset($dados[$vid]['produtos'][$cod])) {
        $dados[$vid]['produtos'][$cod] = [
            'produto'      => $pnome,
            'saida'        => 0,
            'retorno'      => 0,
            'vendido'      => 0,
            'saldo'        => 0,
            'pend_saida'   => 0,
            'pend_retorno' => 0,
            'rej_saida'    => 0,
            'rej_retorno'  => 0,
        ];
    }
}

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

// CORREÇÃO: Saídas — CASE WHEN separa confirmados, pendentes e rejeitados em uma só query
$st = $pdo->prepare("
    SELECT s.vendedor_id, s.vendedor, s.codigo, s.produto,
        SUM(CASE WHEN s.confirmado = 1 AND s.rejeitado = 0 THEN s.quantidade ELSE 0 END) AS qtd_conf,
        SUM(CASE WHEN s.confirmado = 0 AND s.rejeitado = 0 THEN s.quantidade ELSE 0 END) AS qtd_pend,
        SUM(CASE WHEN s.rejeitado  = 1                     THEN s.quantidade ELSE 0 END) AS qtd_rej
    FROM reg_saidas s
    WHERE s.data BETWEEN :di AND :df $es
    GROUP BY s.vendedor_id, s.vendedor, s.codigo, s.produto
    HAVING qtd_conf > 0 OR qtd_pend > 0 OR qtd_rej > 0
");
$st->execute($p_s);
foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
    if (!isset($dados[$r['vendedor_id']])) continue;
    initProd($dados, $r['vendedor_id'], $r['codigo'], $r['produto']);
    $dados[$r['vendedor_id']]['produtos'][$r['codigo']]['saida']      += (int)$r['qtd_conf'];
    $dados[$r['vendedor_id']]['produtos'][$r['codigo']]['pend_saida'] += (int)$r['qtd_pend'];
    $dados[$r['vendedor_id']]['produtos'][$r['codigo']]['rej_saida']  += (int)$r['qtd_rej'];
    $dados[$r['vendedor_id']]['tem_movimento'] = true;
}

// CORREÇÃO: Retornos — mesma lógica
$st = $pdo->prepare("
    SELECT r.vendedor_id, r.vendedor, r.codigo, r.produto,
        SUM(CASE WHEN r.confirmado = 1 AND r.rejeitado = 0 THEN r.quantidade ELSE 0 END) AS qtd_conf,
        SUM(CASE WHEN r.confirmado = 0 AND r.rejeitado = 0 THEN r.quantidade ELSE 0 END) AS qtd_pend,
        SUM(CASE WHEN r.rejeitado  = 1                     THEN r.quantidade ELSE 0 END) AS qtd_rej
    FROM reg_retornos r
    WHERE r.data BETWEEN :di AND :df $er
    GROUP BY r.vendedor_id, r.vendedor, r.codigo, r.produto
    HAVING qtd_conf > 0 OR qtd_pend > 0 OR qtd_rej > 0
");
$st->execute($p_r);
foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
    if (!isset($dados[$r['vendedor_id']])) continue;
    initProd($dados, $r['vendedor_id'], $r['codigo'], $r['produto']);
    $dados[$r['vendedor_id']]['produtos'][$r['codigo']]['retorno']       += (int)$r['qtd_conf'];
    $dados[$r['vendedor_id']]['produtos'][$r['codigo']]['pend_retorno']  += (int)$r['qtd_pend'];
    $dados[$r['vendedor_id']]['produtos'][$r['codigo']]['rej_retorno']   += (int)$r['qtd_rej'];
    $dados[$r['vendedor_id']]['tem_movimento'] = true;
}

// Vendas (não têm QR, sempre confirmadas)
$st = $pdo->prepare("
    SELECT v.vendedor_id, v.vendedor, v.codigo, v.produto,
        SUM(v.quantidade) AS qtd
    FROM reg_vendas v
    WHERE v.data BETWEEN :di AND :df $ev
    GROUP BY v.vendedor_id, v.vendedor, v.codigo, v.produto
");
$st->execute($p_v);
foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
    if (!isset($dados[$r['vendedor_id']])) continue;
    initProd($dados, $r['vendedor_id'], $r['codigo'], $r['produto']);
    $dados[$r['vendedor_id']]['produtos'][$r['codigo']]['vendido'] += (int)$r['qtd'];
    $dados[$r['vendedor_id']]['tem_movimento'] = true;
}

// Saldos e acumulação de totais por vendor
foreach ($dados as $vid => &$vend) {
    foreach ($vend['produtos'] as $cod => &$prod) {
        // Saldo = apenas confirmados
        $prod['saldo']         = $prod['saida'] - $prod['retorno'] - $prod['vendido'];
        $vend['tot_saida']    += $prod['saida'];
        $vend['tot_retorno']  += $prod['retorno'];
        $vend['tot_vendido']  += $prod['vendido'];
        $vend['saldo']        += $prod['saldo'];
        $vend['pend_saida']   += $prod['pend_saida'];
        $vend['pend_retorno'] += $prod['pend_retorno'];
        $vend['rej_saida']    += $prod['rej_saida'];
        $vend['rej_retorno']  += $prod['rej_retorno'];
        if ($prod['saldo'] != 0) $vend['tem_pendencia'] = true;
    }
    unset($prod);
    if ($vend['pend_saida'] > 0 || $vend['pend_retorno'] > 0) {
        $vend['tem_pend_nao_conf'] = true;
    }
    if ($vend['rej_saida'] > 0 || $vend['rej_retorno'] > 0) {
        $vend['tem_rejeitado'] = true;
    }
}
unset($vend);

// CORREÇÃO: Extrato cronológico — confirmados + pendentes + rejeitados
$p_ext = [
    ':di1'  => $data_ini, ':df1'  => $data_fim,
    ':di1b' => $data_ini, ':df1b' => $data_fim,
    ':di1c' => $data_ini, ':df1c' => $data_fim,
    ':di2'  => $data_ini, ':df2'  => $data_fim,
    ':di2b' => $data_ini, ':df2b' => $data_fim,
    ':di2c' => $data_ini, ':df2c' => $data_fim,
    ':di3'  => $data_ini, ':df3'  => $data_fim,
];
$es1 = $er1 = $ev1 = '';
$es1b = $er1b = '';
$es1c = $er1c = '';
if ($vid_filtro && is_numeric($vid_filtro)) {
    $es1  = ' AND s.vendedor_id = :vid1';
    $es1b = ' AND s.vendedor_id = :vid1b';
    $es1c = ' AND s.vendedor_id = :vid1c';
    $er1  = ' AND r.vendedor_id = :vid2';
    $er1b = ' AND r.vendedor_id = :vid2b';
    $er1c = ' AND r.vendedor_id = :vid2c';
    $ev1  = ' AND v.vendedor_id = :vid3';
    $p_ext[':vid1']  = (int)$vid_filtro;
    $p_ext[':vid1b'] = (int)$vid_filtro;
    $p_ext[':vid1c'] = (int)$vid_filtro;
    $p_ext[':vid2']  = (int)$vid_filtro;
    $p_ext[':vid2b'] = (int)$vid_filtro;
    $p_ext[':vid2c'] = (int)$vid_filtro;
    $p_ext[':vid3']  = (int)$vid_filtro;
}

$st = $pdo->prepare("
    SELECT 'Saída'   AS tipo, 'confirmado' AS status_conf,
           s.data, s.hora, s.codigo, s.produto, s.quantidade,
           COALESCE(s.obs,'') AS obs, '' AS pedido, s.vendedor_id
    FROM reg_saidas s
    WHERE s.data BETWEEN :di1 AND :df1 AND s.confirmado = 1 AND s.rejeitado = 0 $es1

    UNION ALL

    SELECT 'Saída', 'pendente',
           s.data, s.hora, s.codigo, s.produto, s.quantidade,
           COALESCE(s.obs,''), '', s.vendedor_id
    FROM reg_saidas s
    WHERE s.data BETWEEN :di1b AND :df1b AND s.confirmado = 0 AND s.rejeitado = 0 $es1b

    UNION ALL

    SELECT 'Saída', 'rejeitado',
           s.data, s.hora, s.codigo, s.produto, s.quantidade,
           COALESCE(s.obs,''), '', s.vendedor_id
    FROM reg_saidas s
    WHERE s.data BETWEEN :di1c AND :df1c AND s.rejeitado = 1 $es1c

    UNION ALL

    SELECT 'Retorno', 'confirmado',
           r.data, r.hora, r.codigo, r.produto, r.quantidade,
           COALESCE(r.obs,''), '', r.vendedor_id
    FROM reg_retornos r
    WHERE r.data BETWEEN :di2 AND :df2 AND r.confirmado = 1 AND r.rejeitado = 0 $er1

    UNION ALL

    SELECT 'Retorno', 'pendente',
           r.data, r.hora, r.codigo, r.produto, r.quantidade,
           COALESCE(r.obs,''), '', r.vendedor_id
    FROM reg_retornos r
    WHERE r.data BETWEEN :di2b AND :df2b AND r.confirmado = 0 AND r.rejeitado = 0 $er1b

    UNION ALL

    SELECT 'Retorno', 'rejeitado',
           r.data, r.hora, r.codigo, r.produto, r.quantidade,
           COALESCE(r.obs,''), '', r.vendedor_id
    FROM reg_retornos r
    WHERE r.data BETWEEN :di2c AND :df2c AND r.rejeitado = 1 $er1c

    UNION ALL

    SELECT 'Venda', 'confirmado',
           v.data, v.hora, v.codigo, v.produto, v.quantidade,
           COALESCE(v.obs,''), COALESCE(v.pedido,''), v.vendedor_id
    FROM reg_vendas v
    WHERE v.data BETWEEN :di3 AND :df3 $ev1

    ORDER BY data ASC, hora ASC, tipo ASC
");
$st->execute($p_ext);
foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $reg) {
    if (isset($dados[$reg['vendedor_id']])) {
        $dados[$reg['vendedor_id']]['registros'][] = $reg;
    }
}

// ── QR tokens pendentes, rejeitados e expirados — SEM filtro de data/vendedor ──
// Esses alertas são globais: devem aparecer independente do filtro do relatório,
// pois precisam ser resolvidos antes de qualquer análise de movimentação.
$qrLinksAg  = [];
$qrLinksRej = [];
$qrLinksExp = [];

$st_tok = $pdo->query("
    SELECT t.token, t.tipo, t.vendedor_id, t.data_ref,
           t.rejeitado, t.status, t.expira_em, v.nome AS vendedor
    FROM qr_tokens t
    INNER JOIN vendedores v ON v.id = t.vendedor_id
    WHERE
        -- Pendentes ainda válidos (não expirados, não rejeitados)
        (t.usado = 0 AND t.rejeitado = 0 AND t.expira_em > NOW())
        OR
        -- Rejeitados não corrigidos
        (t.rejeitado = 1 AND t.status = 'rejeitado'
         AND NOT EXISTS (
             SELECT 1 FROM qr_tokens t2
             WHERE t2.vendedor_id = t.vendedor_id AND t2.data_ref = t.data_ref
               AND t2.tipo = t.tipo AND t2.id > t.id
               AND t2.status IN ('pendente','confirmado')
         ))
        OR
        -- Expirados sem confirmação
        (t.usado = 0 AND t.rejeitado = 0 AND t.expira_em <= NOW())
    ORDER BY t.vendedor_id, t.data_ref
");
foreach ($st_tok->fetchAll(PDO::FETCH_ASSOC) as $r) {
    if (!$r['rejeitado'] && strtotime($r['expira_em']) > time()) {
        // Pendente válido
        $qrLinksAg[$r['vendedor_id']][] = [
            'tipo'        => $r['tipo'],
            'nome'        => $r['vendedor'],
            'data_ref'    => $r['data_ref'],
            'expira_em'   => $r['expira_em'],
            'vendedor_id' => $r['vendedor_id'],
        ];
    } elseif ($r['rejeitado']) {
        $qrLinksRej[$r['vendedor_id']][] = [
            'tipo'     => $r['tipo'],
            'token'    => $r['token'],
            'nome'     => $r['vendedor'],
            'data_ref' => $r['data_ref'],
        ];
    } else {
        // Expirado
        $qrLinksExp[$r['vendedor_id']][] = [
            'tipo'        => $r['tipo'],
            'nome'        => $r['vendedor'],
            'data_ref'    => $r['data_ref'],
            'expira_em'   => $r['expira_em'],
            'vendedor_id' => $r['vendedor_id'],
        ];
    }
}
// ── Pendências de dias anteriores — independente do filtro ──────
// Mesma lógica do dashboard: saldo confirmado > 0 em dias anteriores a hoje
function buscarPendenciasRelatorio(PDO $pdo): array
{
    $hoje = date('Y-m-d');
    $stmt = $pdo->query("
        SELECT
            v.id AS vendedor_id,
            v.nome,
            s.data,
            (
                COALESCE((SELECT SUM(q.quantidade)  FROM reg_saidas   q  WHERE q.vendedor_id  = v.id AND q.data = s.data AND q.confirmado = 1 AND q.rejeitado = 0), 0) -
                COALESCE((SELECT SUM(r.quantidade)  FROM reg_retornos r  WHERE r.vendedor_id  = v.id AND r.data = s.data AND r.confirmado = 1 AND r.rejeitado = 0), 0) -
                COALESCE((SELECT SUM(vd.quantidade) FROM reg_vendas   vd WHERE vd.vendedor_id = v.id AND vd.data = s.data), 0)
            ) AS saldo
        FROM vendedores v
        INNER JOIN reg_saidas s ON s.vendedor_id = v.id AND s.confirmado = 1 AND s.rejeitado = 0
        WHERE s.data < '$hoje'
        GROUP BY v.id, v.nome, s.data
        HAVING saldo > 0
        ORDER BY s.data ASC, v.nome ASC
    ");
    return $stmt->fetchAll();
}
$pendenciasAnteriores = buscarPendenciasRelatorio($pdo);

// Filtro apenas pendências
if ($so_pend) {
    $dados = array_filter($dados, fn($v) => $v['tem_pendencia'] || $v['tem_pend_nao_conf'] || $v['tem_rejeitado']);
}

// Ordenação: negativos → pendentes (saldo) → rejeitados → pend não conf → com movimento → sem movimento → alfa
uasort($dados, function ($a, $b) {
    $ca = $a['tem_pendencia'] && $a['saldo'] < 0;
    $cb = $b['tem_pendencia'] && $b['saldo'] < 0;
    if ($ca !== $cb) return $cb <=> $ca;
    if ($a['tem_pendencia']     !== $b['tem_pendencia'])     return $b['tem_pendencia']     <=> $a['tem_pendencia'];
    if ($a['tem_rejeitado']     !== $b['tem_rejeitado'])     return $b['tem_rejeitado']     <=> $a['tem_rejeitado'];
    if ($a['tem_pend_nao_conf'] !== $b['tem_pend_nao_conf']) return $b['tem_pend_nao_conf'] <=> $a['tem_pend_nao_conf'];
    if ($a['tem_movimento']     !== $b['tem_movimento'])     return $b['tem_movimento']     <=> $a['tem_movimento'];
    return strcmp($a['nome'], $b['nome']);
});

// Filtro explícito por vendedor — garante que só o vendedor selecionado apareça na tabela
if ($vid_filtro && is_numeric($vid_filtro)) {
    $filtroVid = (int)$vid_filtro;
    $dados = array_filter($dados, fn($k) => $k === $filtroVid, ARRAY_FILTER_USE_KEY);
}

// Totais gerais
$g_saida         = array_sum(array_column($dados, 'tot_saida'));
$g_retorno       = array_sum(array_column($dados, 'tot_retorno'));
$g_vendido       = array_sum(array_column($dados, 'tot_vendido'));
$g_saldo         = array_sum(array_column($dados, 'saldo'));
$g_pend          = count(array_filter($dados, fn($v) => $v['tem_pendencia']));
$g_pend_saida    = array_sum(array_column($dados, 'pend_saida'));
$g_pend_retorno  = array_sum(array_column($dados, 'pend_retorno'));
$g_pend_nao_conf = $g_pend_saida + $g_pend_retorno;
$g_rej_saida     = array_sum(array_column($dados, 'rej_saida'));
$g_rej_retorno   = array_sum(array_column($dados, 'rej_retorno'));
$g_rej_total     = $g_rej_saida + $g_rej_retorno;
$g_rejeitados    = count(array_filter($dados, fn($v) => $v['tem_rejeitado']));

$periodo_label = ($data_ini === $data_fim)
    ? formatarData($data_ini)
    : formatarData($data_ini) . ' a ' . formatarData($data_fim);

$csv_qs = http_build_query([
    'data_ini'          => $data_ini,
    'data_fim'          => $data_fim,
    'vendedor_id'       => $vid_filtro,
    'apenas_pendencias' => $so_pend ? '1' : '',
]);

// ── Helpers ──────────────────────────────────────────────────────
function badgeStatus(int $saldo, bool $mov, bool $temPendNaoConf = false, bool $temRejeitado = false, bool $temPendencia = false): string {
    if (!$mov && !$temPendNaoConf && !$temRejeitado && !$temPendencia) return '<span class="badge badge-cinza">— Sem movimento</span>';
    if ($temRejeitado) return '<span class="badge badge-vermelho">❌ Rejeitado</span>';
    // Saldo global zerado mas produtos individuais desequilibrados — falso positivo
    if ($saldo === 0 && $temPendencia) return '<span class="badge badge-vermelho">⚠ Verificar produtos</span>';
    if ($saldo === 0 && !$temPendNaoConf) return '<span class="badge badge-verde">✓ Zerado</span>';
    if ($saldo === 0 && $temPendNaoConf)  return '<span class="badge badge-amarelo">⏳ Aguard. QR</span>';
    if ($saldo > 0) return '<span class="badge badge-amarelo">⚠ Em aberto</span>';
    return '<span class="badge badge-vermelho">⚠ Verificar</span>';
}
function badgeProd(int $saldo): string {
    if ($saldo === 0) return '<span class="badge badge-verde">✓ OK</span>';
    if ($saldo > 0)   return '<span class="badge badge-amarelo">' . $saldo . ' pendente</span>';
    return '<span class="badge badge-vermelho">' . abs($saldo) . ' negativo</span>';
}
// badgeTipo diferencia confirmado, pendente e rejeitado
function badgeTipo(string $tipo, string $statusConf = 'confirmado'): string {
    if ($statusConf === 'pendente') {
        return match($tipo) {
            'Saída'   => '<span class="badge badge-amarelo" title="Aguardando confirmação QR">⏳ Saída</span>',
            'Retorno' => '<span class="badge badge-amarelo" title="Aguardando confirmação QR">⏳ Retorno</span>',
            default   => '<span class="badge badge-cinza">' . htmlspecialchars($tipo) . '</span>',
        };
    }
    if ($statusConf === 'rejeitado') {
        return match($tipo) {
            'Saída'   => '<span class="badge badge-vermelho" title="Rejeitado pelo vendedor — aguarda correção">❌ Saída</span>',
            'Retorno' => '<span class="badge badge-vermelho" title="Rejeitado pelo vendedor — aguarda correção">❌ Retorno</span>',
            default   => '<span class="badge badge-cinza">' . htmlspecialchars($tipo) . '</span>',
        };
    }
    return match($tipo) {
        'Saída'   => '<span class="badge badge-tipo-saida">↑ Saída</span>',
        'Retorno' => '<span class="badge badge-tipo-retorno">↓ Retorno</span>',
        'Venda'   => '<span class="badge badge-tipo-venda">✓ Venda</span>',
        default   => '<span class="badge badge-cinza">' . htmlspecialchars($tipo) . '</span>',
    };
}
function classeLinha(int $saldo, bool $mov, bool $temPendencia = false): string {
    if (!$mov) return '';
    if ($saldo === 0 && $temPendencia) return 'linha-negativo'; // falso zero — produtos desequilibrados
    if ($saldo === 0) return '';
    return $saldo > 0 ? 'linha-aberto' : 'linha-negativo';
}
function classeSaldo(int $saldo): string {
    if ($saldo === 0) return 'saldo-zero';
    return $saldo > 0 ? 'saldo-aberto' : 'saldo-negativo';
}
?>
<?php include __DIR__ . '/../includes/header.php'; ?>

<!-- Cabeçalho visível apenas na impressão -->
<div class="print-header">
    <strong><?= SISTEMA_NOME ?> — Relatório de Movimentação</strong><br>
    <small>Período: <?= esc($periodo_label) ?> &nbsp;|&nbsp; Gerado em: <?= date('d/m/Y H:i') ?> &nbsp;|&nbsp; Por: <?= esc($usuario) ?></small>
</div>

<div class="page-top">
    <h2>📊 Relatório de Movimentação</h2>
    <p>Saídas · Retornos · Vendas · Saldos · Pendências — por vendedor e produto</p>
</div>

<main>

<?php
$totalCardsQR = count($qrLinksRej) + count($qrLinksAg) + count($qrLinksExp);
$colgroup = '<colgroup>
    <col style="width:28%"><col style="width:14%">
    <col style="width:14%"><col style="width:30%"><col style="width:14%">
</colgroup>';
?>

<?php /* ── Pendências de dias anteriores — independente do filtro ── */ ?>
<?php if (!empty($pendenciasAnteriores)): ?>
<div class="alerta alerta-aviso dash-alerta-pendencias" style="margin-bottom:12px">
    <span class="dash-alerta-icone">⚠️</span>
    <div class="dash-alerta-corpo">
        <strong><?= count($pendenciasAnteriores) ?> pendência(s) de dias anteriores:</strong>
        <ul class="dash-pendencias-lista">
            <?php foreach ($pendenciasAnteriores as $p):
                $urlPend = BASE_URL . '/pages/relatorios.php'
                    . '?data_ini='    . urlencode($p['data'])
                    . '&data_fim='    . urlencode($p['data'])
                    . '&vendedor_id=' . urlencode($p['vendedor_id']);
            ?>
            <li>
                <strong><?= esc($p['nome']) ?></strong>
                — <?= formatarData($p['data']) ?>
                — Saldo: <strong><?= $p['saldo'] ?></strong> itens em aberto
                <a href="<?= $urlPend ?>" class="dash-pendencia-link" target="_blank"
                   title="Ver relatório filtrado para este dia">
                    📊 Ver detalhe →
                </a>
            </li>
            <?php endforeach; ?>
        </ul>
    </div>
</div>
<?php endif; ?>

<?php if ($totalCardsQR > 0): ?>
<div class="card dash-card-qr" style="margin-bottom:16px">
    <div class="card-titulo dash-qr-titulo">
        <span>🔔 Confirmações QR Code pendentes</span>
        <span class="dash-qr-badges">
            <?php if (!empty($qrLinksRej)): ?>
                <span class="badge badge-vermelho"><?= array_sum(array_map('count', $qrLinksRej)) ?> rejeitado(s)</span>
            <?php endif; ?>
            <?php if (!empty($qrLinksAg)): ?>
                <span class="badge badge-amarelo"><?= array_sum(array_map('count', $qrLinksAg)) ?> aguardando</span>
            <?php endif; ?>
            <?php if (!empty($qrLinksExp)): ?>
                <span class="badge badge-cinza"><?= array_sum(array_map('count', $qrLinksExp)) ?> expirado(s)</span>
            <?php endif; ?>
        </span>
    </div>
    <p style="font-size:12px; color:var(--cinza-texto); margin: -4px 0 12px 0; padding:0 4px">
        ⚠️ Esses registros estão pendentes no sistema independente do filtro de datas. Resolva-os antes de analisar o relatório.
    </p>

    <div class="dash-qr-abas">

        <?php if (!empty($qrLinksRej)): ?>
        <div class="dash-qr-secao">
            <div class="dash-qr-secao-titulo dash-qr-rejeitado">❌ Rejeitados pelo Vendedor</div>
            <div class="tabela-wrapper">
                <table style="table-layout:fixed; width:100%">
                    <?= $colgroup ?>
                    <thead><tr>
                        <th>Vendedor</th>
                        <th style="text-align:center">Tipo</th>
                        <th style="text-align:center">Data Ref.</th>
                        <th>—</th>
                        <th style="text-align:center">Ação</th>
                    </tr></thead>
                    <tbody>
                    <?php foreach ($qrLinksRej as $vid => $tokens):
                        foreach ($tokens as $tk):
                            $pagina = $tk['tipo'] === 'saida' ? 'saida' : 'retorno';
                            $url    = BASE_URL . '/pages/' . $pagina . '.php?etapa=corrigir&token=' . urlencode($tk['token']);
                    ?>
                    <tr class="dash-tr-rejeitado">
                        <td><strong><?= esc($tk['nome']) ?></strong></td>
                        <td style="text-align:center"><?= $tk['tipo'] === 'saida' ? '📤 Saída' : '📥 Retorno' ?></td>
                        <td style="text-align:center"><?= formatarData($tk['data_ref']) ?></td>
                        <td style="font-size:13px; color:var(--cinza-texto); font-style:italic">Aguarda correção do operador</td>
                        <td style="text-align:center">
                            <a href="<?= $url ?>" class="btn btn-vermelho btn-pequeno" target="_blank">✏️ Corrigir</a>
                        </td>
                    </tr>
                    <?php endforeach; endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>

        <?php if (!empty($qrLinksAg)): ?>
        <div class="dash-qr-secao">
            <div class="dash-qr-secao-titulo dash-qr-aguardando">⏳ Aguardando Confirmação</div>
            <div class="tabela-wrapper">
                <table style="table-layout:fixed; width:100%">
                    <?= $colgroup ?>
                    <thead><tr>
                        <th>Vendedor</th>
                        <th style="text-align:center">Tipo</th>
                        <th style="text-align:center">Data Ref.</th>
                        <th style="text-align:center">Expira em</th>
                        <th style="text-align:center">Ação</th>
                    </tr></thead>
                    <tbody>
                    <?php foreach ($qrLinksAg as $vid => $tokens):
                        foreach ($tokens as $tk):
                            $pagina = $tk['tipo'] === 'saida' ? 'saida' : 'retorno';
                            $url    = BASE_URL . '/pages/' . $pagina . '.php?etapa=reenviar&vid=' . (int)$vid . '&data=' . urlencode($tk['data_ref']);
                    ?>
                    <tr class="dash-tr-aguardando">
                        <td><strong><?= esc($tk['nome']) ?></strong></td>
                        <td style="text-align:center"><?= $tk['tipo'] === 'saida' ? '📤 Saída' : '📥 Retorno' ?></td>
                        <td style="text-align:center"><?= formatarData($tk['data_ref']) ?></td>
                        <td style="text-align:center; font-size:13px; color:var(--cinza-texto)"><?= formatarDataHora($tk['expira_em']) ?></td>
                        <td style="text-align:center">
                            <a href="<?= $url ?>" class="btn btn-acento btn-pequeno" target="_blank">📲 Reenviar QR</a>
                        </td>
                    </tr>
                    <?php endforeach; endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>

        <?php if (!empty($qrLinksExp)): ?>
        <div class="dash-qr-secao">
            <div class="dash-qr-secao-titulo dash-qr-expirado">
                🕒 Expirados sem Confirmação
                <span class="dash-qr-expirado-aviso">— Clique em "Reenviar" para gerar novo QR com os mesmos dados.</span>
            </div>
            <div class="tabela-wrapper">
                <table style="table-layout:fixed; width:100%">
                    <?= $colgroup ?>
                    <thead><tr>
                        <th>Vendedor</th>
                        <th style="text-align:center">Tipo</th>
                        <th style="text-align:center">Data Ref.</th>
                        <th style="text-align:center">Expirou em</th>
                        <th style="text-align:center">Ação</th>
                    </tr></thead>
                    <tbody>
                    <?php foreach ($qrLinksExp as $vid => $tokens):
                        foreach ($tokens as $tk):
                            $pagina = $tk['tipo'] === 'saida' ? 'saida' : 'retorno';
                            $url    = BASE_URL . '/pages/' . $pagina . '.php?etapa=reenviar&vid=' . (int)$vid . '&data=' . urlencode($tk['data_ref']);
                    ?>
                    <tr class="dash-tr-expirado">
                        <td><strong><?= esc($tk['nome']) ?></strong></td>
                        <td style="text-align:center"><?= $tk['tipo'] === 'saida' ? '📤 Saída' : '📥 Retorno' ?></td>
                        <td style="text-align:center"><?= formatarData($tk['data_ref']) ?></td>
                        <td style="text-align:center; font-size:13px; color:var(--cinza-texto)"><?= formatarDataHora($tk['expira_em']) ?></td>
                        <td style="text-align:center">
                            <a href="<?= $url ?>" class="btn btn-secundario btn-pequeno" target="_blank">🔁 Reenviar QR</a>
                        </td>
                    </tr>
                    <?php endforeach; endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>

    </div><!-- /.dash-qr-abas -->
</div>
<?php endif; ?>

<!-- ── Filtros ────────────────────────────────────────────────── -->
<div class="card">
    <div class="card-titulo">🔍 Filtros</div>
    <form method="GET" action="relatorios.php" class="rel-filtros">

        <div class="grupo-campo">
            <label>Data início</label>
            <input type="date" name="data_ini" class="campo" value="<?= esc($data_ini) ?>">
        </div>

        <div class="grupo-campo">
            <label>Data fim</label>
            <input type="date" name="data_fim" class="campo" value="<?= esc($data_fim) ?>">
        </div>

        <div class="grupo-campo">
            <label>Vendedor</label>
            <select name="vendedor_id" class="campo">
                <option value="">— Todos os vendedores —</option>
                <?php foreach ($vendedores as $v): ?>
                <option value="<?= (int)$v['id'] ?>" <?= ($vid_filtro == $v['id']) ? 'selected' : '' ?>>
                    <?= esc($v['nome']) ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="grupo-campo rel-check">
            <label style="visibility:hidden">_</label>
            <label style="display:flex; align-items:center; gap:8px; font-weight:normal; cursor:pointer; margin-bottom:0">
                <input type="checkbox" name="apenas_pendencias" value="1"
                       <?= $so_pend ? 'checked' : '' ?>>
                Apenas pendências
            </label>
        </div>

        <div class="grupo-campo rel-acoes">
            <label style="visibility:hidden">_</label>
            <div style="display:flex; gap:8px; flex-wrap:wrap">
                <button type="submit" class="btn btn-primario">🔍 Filtrar</button>
                <div class="dd-wrap" id="ddExport">
                    <button type="button" class="btn btn-secundario"
                            onclick="toggleDD(event)">📥 Exportar ▾</button>
                    <div class="dd-menu">
                        <a href="<?= BASE_URL ?>/api/exportar_csv.php?<?= $csv_qs ?>" target="_blank">
                            📊 Baixar CSV / Excel
                        </a>
                        <a href="#" onclick="window.print();return false;">
                            🖨️ Imprimir / Salvar PDF
                        </a>
                    </div>
                </div>
            </div>
        </div>

    </form>
</div>

<!-- ── Alerta de saldo ─────────────────────────────────────────── -->
<?php if ($g_pend > 0): ?>
<div class="alerta alerta-aviso">
    ⚠️ <strong><?= $g_pend ?> vendedor(es) com saldo em aberto</strong>
    no período <strong><?= esc($periodo_label) ?></strong>:
    <?php
        $nomes = array_map(fn($v) => $v['nome'], array_filter($dados, fn($v) => $v['tem_pendencia']));
        echo implode(', ', array_map('esc', $nomes)) . '.';
    ?>
    <?php if ($g_pend_nao_conf > 0): ?>
    <br>⏳ Há <strong><?= $g_pend_nao_conf ?> item(ns) aguardando confirmação de QR</strong>
    (<?= $g_pend_saida ?> saída / <?= $g_pend_retorno ?> retorno) — <em>não entram no saldo até confirmados</em>.
    <?php endif; ?>
</div>
<?php elseif ($g_pend_nao_conf > 0 && $totalCardsQR === 0): ?>
<div class="alerta alerta-aviso">
    ⏳ <strong><?= $g_pend_nao_conf ?> item(ns) aguardando confirmação de QR Code</strong>
    (<?= $g_pend_saida ?> saída / <?= $g_pend_retorno ?> retorno).
    O saldo ficará definitivo após confirmação pelo vendedor.
</div>
<?php elseif ($g_saida > 0 && $totalCardsQR === 0 && $g_pend_nao_conf === 0): ?>
<div class="alerta alerta-sucesso">
    ✅ <strong>Tudo certo!</strong> Todos os vendedores estão com saldo zerado
    no período <strong><?= esc($periodo_label) ?></strong>.
</div>
<?php endif; ?>

<!-- ── Cards de stats ─────────────────────────────────────────── -->
<div class="grid-stats">
    <div class="card-stat" style="border-left-color:var(--acento)">
        <div class="stat-num" style="color:var(--acento)"><?= $g_saida ?></div>
        <div class="stat-label">📤 Saídas confirmadas</div>
        <?php if ($g_pend_saida > 0): ?>
        <div style="font-size:11px; color:var(--cinza-texto); margin-top:4px">
            + <?= $g_pend_saida ?> aguardando QR
        </div>
        <?php endif; ?>
    </div>
    <div class="card-stat" style="border-left-color:#6f42c1">
        <div class="stat-num" style="color:#6f42c1"><?= $g_retorno ?></div>
        <div class="stat-label">📥 Retornos confirmados</div>
        <?php if ($g_pend_retorno > 0): ?>
        <div style="font-size:11px; color:var(--cinza-texto); margin-top:4px">
            + <?= $g_pend_retorno ?> aguardando QR
        </div>
        <?php endif; ?>
    </div>
    <div class="card-stat" style="border-left-color:var(--verde)">
        <div class="stat-num" style="color:var(--verde)"><?= $g_vendido ?></div>
        <div class="stat-label">✅ Total Vendidos</div>
    </div>
    <?php
        $g_negativos  = count(array_filter($dados, fn($v) => $v['tem_movimento'] && $v['saldo'] < 0));
        $corPendencia = $g_negativos > 0 ? 'var(--vermelho)' : ($g_pend > 0 ? 'var(--amarelo)' : 'var(--verde)');
        $iconePend    = $g_negativos > 0 ? '🚨' : ($g_pend > 0 ? '⚠️' : '✅');
        $labelPend    = $g_negativos > 0 ? 'com saldo negativo' : ($g_pend > 0 ? 'com saldo em aberto' : 'Todos zerados');
    ?>
    <div class="card-stat" style="border-left-color:<?= $corPendencia ?>">
        <div class="stat-num" style="color:<?= $corPendencia ?>"><?= $g_pend ?></div>
        <div class="stat-label"><?= $iconePend ?> <?= $labelPend ?></div>
    </div>
</div>

<!-- ── Tabela principal ───────────────────────────────────────── -->
<div class="card">

    <div class="card-titulo" style="display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:8px">
        <span>📋 Movimentação por Vendedor — <?= esc($periodo_label) ?></span>
        <small style="color:var(--cinza-texto); font-weight:normal">
            <?= count($dados) ?> vendedor(es)
            <?php if ($g_pend > 0): ?>
                &nbsp;·&nbsp; <strong style="color:var(--vermelho)"><?= $g_pend ?> com pendência</strong>
            <?php endif; ?>
            <?php if ($g_pend_nao_conf > 0): ?>
                &nbsp;·&nbsp; <strong style="color:var(--amarelo-escuro, #856404)"><?= $g_pend_nao_conf ?> item(ns) aguardando QR</strong>
            <?php endif; ?>
        </small>
    </div>

    <div class="tabela-wrapper">
    <table>
        <thead>
            <tr>
                <th>Vendedor</th>
                <th class="col-num">Saídas</th>
                <th class="col-num">Retornos</th>
                <th class="col-num">Vendidos</th>
                <th class="col-num">Saldo</th>
                <th class="col-num">Status</th>
                <th class="col-acao">Detalhes</th>
            </tr>
        </thead>
        <tbody>

        <?php if (empty($dados)): ?>
        <tr><td colspan="7" class="td-vazio">Nenhum registro encontrado para o período selecionado.</td></tr>
        <?php else: ?>

        <?php foreach ($dados as $vid => $vend): ?>

        <tr class="<?= classeLinha($vend['saldo'], $vend['tem_movimento'], $vend['tem_pendencia']) ?>" id="row-<?= (int)$vid ?>">
            <td>
                <strong><?= esc($vend['nome']) ?></strong>
                <?php if ($vend['saldo'] < 0): ?>
                    <span class="aviso-negativo">⚠ Saldo negativo — verificar</span>
                <?php elseif ($vend['saldo'] === 0 && $vend['tem_pendencia']): ?>
                    <span class="aviso-negativo">⚠ Produtos desequilibrados — ver detalhe</span>
                <?php endif; ?>
            </td>
            <td class="col-num">
                <?php if ($vend['tot_saida'] > 0): ?>
                    <strong style="color:var(--acento)"><?= $vend['tot_saida'] ?></strong>
                <?php elseif ($vend['pend_saida'] > 0 || $vend['rej_saida'] > 0): ?>
                    <span class="traco">0</span>
                <?php else: ?>
                    <span class="traco">—</span>
                <?php endif; ?>
                <?php if ($vend['pend_saida'] > 0): ?>
                    <br><span class="badge badge-amarelo" style="font-size:10px; margin-top:2px"
                              title="Saída registrada, QR não confirmado">⏳ +<?= $vend['pend_saida'] ?> ag.</span>
                <?php endif; ?>
                <?php if ($vend['rej_saida'] > 0): ?>
                    <br><span class="badge badge-vermelho" style="font-size:10px; margin-top:2px"
                              title="Saída rejeitada pelo vendedor — aguarda correção">❌ +<?= $vend['rej_saida'] ?> rej.</span>
                <?php endif; ?>
            </td>
            <td class="col-num">
                <?php if ($vend['tot_retorno'] > 0): ?>
                    <?= $vend['tot_retorno'] ?>
                <?php elseif ($vend['pend_retorno'] > 0 || $vend['rej_retorno'] > 0): ?>
                    <span class="traco">0</span>
                <?php else: ?>
                    <span class="traco">—</span>
                <?php endif; ?>
                <?php if ($vend['pend_retorno'] > 0): ?>
                    <br><span class="badge badge-amarelo" style="font-size:10px; margin-top:2px"
                              title="Retorno registrado, QR não confirmado">⏳ +<?= $vend['pend_retorno'] ?> ag.</span>
                <?php endif; ?>
                <?php if ($vend['rej_retorno'] > 0): ?>
                    <br><span class="badge badge-vermelho" style="font-size:10px; margin-top:2px"
                              title="Retorno rejeitado pelo vendedor — aguarda correção">❌ +<?= $vend['rej_retorno'] ?> rej.</span>
                <?php endif; ?>
            </td>
            <td class="col-num">
                <?= $vend['tot_vendido'] > 0
                    ? '<strong style="color:var(--verde)">' . $vend['tot_vendido'] . '</strong>'
                    : '<span class="traco">—</span>' ?>
            </td>
            <td class="col-num">
                <?php if ($vend['tem_movimento'] || $vend['tem_pend_nao_conf'] || $vend['tem_rejeitado']): ?>
                    <strong class="<?= classeSaldo($vend['saldo']) ?> saldo-grande"><?= $vend['saldo'] ?></strong>
                    <?php if ($vend['tem_pend_nao_conf'] || $vend['tem_rejeitado']): ?>
                        <br><small style="color:var(--cinza-texto); font-size:10px">(parcial)</small>
                    <?php endif; ?>
                <?php else: ?>
                    <span class="traco">—</span>
                <?php endif; ?>
            </td>
            <td class="col-num">
                <?= badgeStatus($vend['saldo'], $vend['tem_movimento'], $vend['tem_pend_nao_conf'], $vend['tem_rejeitado'], $vend['tem_pendencia']) ?>
            </td>
            <td class="col-acao">
                <?php if ($vend['tem_movimento'] || $vend['tem_pend_nao_conf'] || $vend['tem_rejeitado']): ?>
                <button class="btn btn-acento btn-pequeno"
                        id="btnD-<?= (int)$vid ?>"
                        onclick="toggleDetalhe(<?= (int)$vid ?>)">▼ Detalhar</button>
                <?php else: ?>
                <span class="traco">—</span>
                <?php endif; ?>
            </td>
        </tr>

        <?php if ($vend['tem_movimento'] || $vend['tem_pend_nao_conf'] || $vend['tem_rejeitado']): ?>
        <tr class="linha-detalhe" id="detalhe-<?= (int)$vid ?>" style="display:none">
            <td colspan="7">
                <div class="detalhe-inner">

                    <div class="detalhe-secao">📦 Saldo por produto — <?= esc($vend['nome']) ?></div>
                    <div class="tabela-wrapper">
                    <table class="tabela-sub">
                        <thead>
                            <tr>
                                <th>Código</th>
                                <th>Produto</th>
                                <th class="col-num">Saída conf.</th>
                                <th class="col-num">Ret. conf.</th>
                                <th class="col-num">Vendido</th>
                                <th class="col-num">Saldo</th>
                                <th class="col-num">Status</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php
                            $sub_s = $sub_r = $sub_v = $sub_sal = 0;
                            foreach ($vend['produtos'] as $cod => $prod):
                                $sub_s   += $prod['saida'];
                                $sub_r   += $prod['retorno'];
                                $sub_v   += $prod['vendido'];
                                $sub_sal += $prod['saldo'];
                                $pPend    = $prod['pend_saida'] + $prod['pend_retorno'];
                                $pRej     = $prod['rej_saida']  + $prod['rej_retorno'];
                        ?>
                        <tr class="<?= classeLinha($prod['saldo'], true, $prod['saldo'] !== 0) ?>">
                            <td><code class="cod"><?= esc($cod) ?></code></td>
                            <td><?= esc($prod['produto']) ?></td>
                            <td class="col-num">
                                <?= $prod['saida'] ?: '<span class="traco">—</span>' ?>
                                <?php if ($prod['pend_saida'] > 0): ?>
                                    <br><span class="badge badge-amarelo" style="font-size:10px">⏳ +<?= $prod['pend_saida'] ?></span>
                                <?php endif; ?>
                                <?php if ($prod['rej_saida'] > 0): ?>
                                    <br><span class="badge badge-vermelho" style="font-size:10px">❌ +<?= $prod['rej_saida'] ?></span>
                                <?php endif; ?>
                            </td>
                            <td class="col-num">
                                <?= $prod['retorno'] ?: '<span class="traco">—</span>' ?>
                                <?php if ($prod['pend_retorno'] > 0): ?>
                                    <br><span class="badge badge-amarelo" style="font-size:10px">⏳ +<?= $prod['pend_retorno'] ?></span>
                                <?php endif; ?>
                                <?php if ($prod['rej_retorno'] > 0): ?>
                                    <br><span class="badge badge-vermelho" style="font-size:10px">❌ +<?= $prod['rej_retorno'] ?></span>
                                <?php endif; ?>
                            </td>
                            <td class="col-num"><?= $prod['vendido'] ?: '<span class="traco">—</span>' ?></td>
                            <td class="col-num">
                                <strong class="<?= classeSaldo($prod['saldo']) ?>"><?= $prod['saldo'] ?></strong>
                                <?php if ($pPend > 0 || $pRej > 0): ?>
                                    <br><small style="color:var(--cinza-texto); font-size:10px">(parcial)</small>
                                <?php endif; ?>
                            </td>
                            <td class="col-num"><?= badgeProd($prod['saldo']) ?></td>
                        </tr>
                        <?php endforeach; ?>
                        <tr class="linha-total">
                            <td colspan="2"><strong>SUBTOTAL</strong></td>
                            <td class="col-num"><?= $sub_s ?></td>
                            <td class="col-num"><?= $sub_r ?></td>
                            <td class="col-num"><?= $sub_v ?></td>
                            <td class="col-num"><strong class="<?= classeSaldo($sub_sal) ?>"><?= $sub_sal ?></strong></td>
                            <td></td>
                        </tr>
                        </tbody>
                    </table>
                    </div>

                    <?php if (!empty($vend['registros'])): ?>
                    <div class="detalhe-secao" style="margin-top:20px">
                        🕐 Extrato cronológico — <?= count($vend['registros']) ?> registro(s)
                        <?php
                            $qtdPendExt = count(array_filter($vend['registros'], fn($r) => ($r['status_conf'] ?? '') === 'pendente'));
                            $qtdRejExt  = count(array_filter($vend['registros'], fn($r) => ($r['status_conf'] ?? '') === 'rejeitado'));
                        ?>
                        <?php if ($qtdPendExt > 0): ?>
                            <span class="badge badge-amarelo" style="font-size:11px; margin-left:8px">
                                ⏳ <?= $qtdPendExt ?> aguardando QR
                            </span>
                        <?php endif; ?>
                        <?php if ($qtdRejExt > 0): ?>
                            <span class="badge badge-vermelho" style="font-size:11px; margin-left:4px">
                                ❌ <?= $qtdRejExt ?> rejeitado(s)
                            </span>
                        <?php endif; ?>
                    </div>
                    <div class="tabela-wrapper">
                    <table class="tabela-extrato">
                        <thead>
                            <tr>
                                <th>Data</th>
                                <th>Hora</th>
                                <th>Tipo</th>
                                <th>Código</th>
                                <th>Produto</th>
                                <th class="col-num">Qtd</th>
                                <th>Pedido</th>
                                <th>Observação</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($vend['registros'] as $reg):
                            $isPendente  = ($reg['status_conf'] ?? 'confirmado') === 'pendente';
                            $isRejeitado = ($reg['status_conf'] ?? 'confirmado') === 'rejeitado';
                            $trStyle = $isRejeitado ? 'style="background:#fff0f0; opacity:.85"'
                                     : ($isPendente ? 'style="background:#fffbea; opacity:.85"' : '');
                        ?>
                        <tr <?= $trStyle ?>>
                            <td class="nowrap"><?= formatarData($reg['data']) ?></td>
                            <td class="nowrap"><?= esc(substr($reg['hora'], 0, 5)) ?></td>
                            <td><?= badgeTipo($reg['tipo'], $reg['status_conf'] ?? 'confirmado') ?></td>
                            <td><code class="cod"><?= esc($reg['codigo']) ?></code></td>
                            <td><?= esc($reg['produto']) ?></td>
                            <td class="col-num">
                                <strong><?= (int)$reg['quantidade'] ?></strong>
                                <?php if ($isPendente): ?>
                                    <br><small style="color:var(--cinza-texto); font-size:10px">ag. QR</small>
                                <?php elseif ($isRejeitado): ?>
                                    <br><small style="color:var(--vermelho); font-size:10px">rejeit.</small>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?= $reg['pedido']
                                    ? '<span class="badge badge-cinza">' . esc($reg['pedido']) . '</span>'
                                    : '<span class="traco">—</span>' ?>
                            </td>
                            <td class="td-obs">
                                <?= $reg['obs'] ? esc($reg['obs']) : '<span class="traco">—</span>' ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                    </div>
                    <?php endif; ?>

                </div>
            </td>
        </tr>
        <?php endif; ?>

        <?php endforeach; ?>

        <?php if (count($dados) > 1 && ($g_saida > 0 || $g_pend_nao_conf > 0 || $g_rej_total > 0)): ?>
        <tr class="linha-total">
            <td><strong>TOTAL GERAL</strong></td>
            <td class="col-num"><?= $g_saida ?></td>
            <td class="col-num"><?= $g_retorno ?></td>
            <td class="col-num"><?= $g_vendido ?></td>
            <td class="col-num">
                <strong class="<?= classeSaldo($g_saldo) ?> saldo-grande"><?= $g_saldo ?></strong>
            </td>
            <td colspan="2"></td>
        </tr>
        <?php endif; ?>

        <?php endif; ?>
        </tbody>
    </table>
    </div>

    <div class="legenda-cores">
        <strong>Legenda:</strong>
        <span class="legenda-item">
            <span class="legenda-bolinha" style="background:#f0fff4; border:1px solid #c3e6cb"></span> Zerado (confirmado)
        </span>
        <span class="legenda-item">
            <span class="legenda-bolinha" style="background:#fffbea; border:1px solid #ffeeba"></span> Em aberto / Aguard. QR
        </span>
        <span class="legenda-item">
            <span class="legenda-bolinha" style="background:#fff0f0; border:1px solid #f5c6cb"></span> Verificar (negativo) / Rejeitado
        </span>
        <span class="legenda-item">
            <span class="legenda-bolinha" style="background:#e9ecef; border:1px solid #ccc"></span> Sem movimento
        </span>
        <span class="legenda-item">
            <span class="badge badge-amarelo" style="font-size:10px">⏳</span> Aguardando QR — não entra no saldo
        </span>
        <span class="legenda-item">
            <span class="badge badge-vermelho" style="font-size:10px">❌</span> Rejeitado pelo vendedor — aguarda correção
        </span>
    </div>

</div>

</main>

<footer>
    <?= SISTEMA_NOME ?> v<?= SISTEMA_VERSAO ?>
    <?php if ($perfil === 'master'): ?>
        &nbsp;|&nbsp; <a href="<?= BASE_URL ?>/pages/admin.php">⚙ Admin</a>
    <?php endif; ?>
</footer>

<script src="<?= BASE_URL ?>/assets/js/main.js"></script>
<script>
const BASE_URL = '<?= BASE_URL ?>';

function toggleDetalhe(vid) {
    var row = document.getElementById('detalhe-' + vid);
    var btn = document.getElementById('btnD-' + vid);
    if (!row || !btn) return;
    var aberto = row.style.display !== 'none' && row.style.display !== '';
    row.style.display = aberto ? 'none' : 'table-row';
    btn.textContent   = aberto ? '▼ Detalhar' : '▲ Fechar';
    btn.className     = aberto ? 'btn btn-acento btn-pequeno' : 'btn btn-primario btn-pequeno';
    if (!aberto) row.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
}

function toggleDD(e) {
    e.stopPropagation();
    document.getElementById('ddExport').classList.toggle('aberto');
}
document.addEventListener('click', function() {
    var dd = document.getElementById('ddExport');
    if (dd) dd.classList.remove('aberto');
});

document.addEventListener('DOMContentLoaded', function() {
    // Auto-expande vendedores com saldo negativo ou produtos desequilibrados
    <?php foreach ($dados as $vid => $vend): ?>
    <?php if ($vend['tem_pendencia']): ?>
    toggleDetalhe(<?= (int)$vid ?>);
    <?php endif; ?>
    <?php endforeach; ?>

    // Fix #5: restaura posição de scroll após filtrar
    var savedScroll = sessionStorage.getItem('rel_scroll');
    if (savedScroll) {
        window.scrollTo(0, parseInt(savedScroll));
        sessionStorage.removeItem('rel_scroll');
    }
});

document.querySelector('.rel-filtros').addEventListener('submit', function(e) {
    var ini = this.querySelector('[name=data_ini]').value;
    var fim = this.querySelector('[name=data_fim]').value;
    if (ini && fim && fim < ini) {
        alert('⚠ A data fim não pode ser anterior à data início.');
        e.preventDefault();
        return;
    }
    // Salva posição atual antes de recarregar
    sessionStorage.setItem('rel_scroll', window.scrollY);
});
</script>

</body>
</html>