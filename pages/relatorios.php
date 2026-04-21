<?php
// ============================================================
// pages/relatorios.php — Relatório Unificado de Movimentação
// Salvar em: C:\xampp\htdocs\sistema_csr\pages\relatorios.php
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

// ── Montagem dos dados ───────────────────────────────────────────
$dados = [];
foreach ($vendedores as $v) {
    $dados[$v['id']] = [
        'nome'          => $v['nome'],
        'produtos'      => [],
        'registros'     => [],
        'qr_pendentes'  => 0,
        'tot_saida'     => 0,
        'tot_retorno'   => 0,
        'tot_vendido'   => 0,
        'saldo'         => 0,
        'tem_pendencia' => false,
        'tem_movimento' => false,
    ];
}

function initProd(array &$dados, $vid, $cod, $pnome): void {
    if (!isset($dados[$vid]['produtos'][$cod])) {
        $dados[$vid]['produtos'][$cod] = [
            'produto' => $pnome, 'saida' => 0, 'retorno' => 0, 'vendido' => 0, 'saldo' => 0,
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

// Saídas
$st = $pdo->prepare("SELECT s.vendedor_id, s.vendedor, s.codigo, s.produto,
    SUM(s.quantidade) AS qtd FROM reg_saidas s
    WHERE s.data BETWEEN :di AND :df $es
    GROUP BY s.vendedor_id, s.vendedor, s.codigo, s.produto");
$st->execute($p_s);
foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
    if (!isset($dados[$r['vendedor_id']])) continue;
    initProd($dados, $r['vendedor_id'], $r['codigo'], $r['produto']);
    $dados[$r['vendedor_id']]['produtos'][$r['codigo']]['saida'] += (int)$r['qtd'];
    $dados[$r['vendedor_id']]['tem_movimento'] = true;
}

// Retornos
$st = $pdo->prepare("SELECT r.vendedor_id, r.vendedor, r.codigo, r.produto,
    SUM(r.quantidade) AS qtd FROM reg_retornos r
    WHERE r.data BETWEEN :di AND :df $er
    GROUP BY r.vendedor_id, r.vendedor, r.codigo, r.produto");
$st->execute($p_r);
foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
    if (!isset($dados[$r['vendedor_id']])) continue;
    initProd($dados, $r['vendedor_id'], $r['codigo'], $r['produto']);
    $dados[$r['vendedor_id']]['produtos'][$r['codigo']]['retorno'] += (int)$r['qtd'];
    $dados[$r['vendedor_id']]['tem_movimento'] = true;
}

// Vendas
$st = $pdo->prepare("SELECT v.vendedor_id, v.vendedor, v.codigo, v.produto,
    SUM(v.quantidade) AS qtd FROM reg_vendas v
    WHERE v.data BETWEEN :di AND :df $ev
    GROUP BY v.vendedor_id, v.vendedor, v.codigo, v.produto");
$st->execute($p_v);
foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
    if (!isset($dados[$r['vendedor_id']])) continue;
    initProd($dados, $r['vendedor_id'], $r['codigo'], $r['produto']);
    $dados[$r['vendedor_id']]['produtos'][$r['codigo']]['vendido'] += (int)$r['qtd'];
    $dados[$r['vendedor_id']]['tem_movimento'] = true;
}

// Saldos
foreach ($dados as $vid => &$vend) {
    foreach ($vend['produtos'] as $cod => &$prod) {
        $prod['saldo']        = $prod['saida'] - $prod['retorno'] - $prod['vendido'];
        $vend['tot_saida']   += $prod['saida'];
        $vend['tot_retorno'] += $prod['retorno'];
        $vend['tot_vendido'] += $prod['vendido'];
        $vend['saldo']       += $prod['saldo'];
        if ($prod['saldo'] != 0) $vend['tem_pendencia'] = true;
    }
    unset($prod);
}
unset($vend);

// Extrato cronológico
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
    SELECT 'Saída'   AS tipo, s.data, s.hora, s.codigo, s.produto,
           s.quantidade, COALESCE(s.obs,'') AS obs, '' AS pedido, s.vendedor_id
    FROM reg_saidas s WHERE s.data BETWEEN :di1 AND :df1 $es1
    UNION ALL
    SELECT 'Retorno', r.data, r.hora, r.codigo, r.produto,
           r.quantidade, COALESCE(r.obs,''), '', r.vendedor_id
    FROM reg_retornos r WHERE r.data BETWEEN :di2 AND :df2 $er1
    UNION ALL
    SELECT 'Venda',   v.data, v.hora, v.codigo, v.produto,
           v.quantidade, COALESCE(v.obs,''), COALESCE(v.pedido,''), v.vendedor_id
    FROM reg_vendas v WHERE v.data BETWEEN :di3 AND :df3 $ev1
    ORDER BY data ASC, hora ASC, tipo ASC
");
$st->execute($p_ext);
foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $reg) {
    if (isset($dados[$reg['vendedor_id']])) {
        $dados[$reg['vendedor_id']]['registros'][] = $reg;
    }
}

// QR pendentes
$p_qr  = [':di' => $data_ini, ':df' => $data_fim];
$ex_qr = '';
if ($vid_filtro && is_numeric($vid_filtro)) {
    $ex_qr = ' AND vendedor_id = :vid';
    $p_qr[':vid'] = (int)$vid_filtro;
}
$st = $pdo->prepare("SELECT vendedor_id, COUNT(*) AS qtd FROM qr_tokens
    WHERE DATE(data_ref) BETWEEN :di AND :df AND usado = 0 AND expira_em > NOW()
    $ex_qr GROUP BY vendedor_id");
$st->execute($p_qr);
foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
    if (isset($dados[$r['vendedor_id']])) {
        $dados[$r['vendedor_id']]['qr_pendentes'] = (int)$r['qtd'];
    }
}

// Filtro apenas pendências
if ($so_pend) {
    $dados = array_filter($dados, fn($v) => $v['tem_pendencia'] || $v['qr_pendentes'] > 0);
}

// Ordenação: negativos → pendentes → com movimento → sem movimento → alfabético
uasort($dados, function ($a, $b) {
    $ca = $a['tem_pendencia'] && $a['saldo'] < 0;
    $cb = $b['tem_pendencia'] && $b['saldo'] < 0;
    if ($ca !== $cb) return $cb <=> $ca;
    if ($a['tem_pendencia'] !== $b['tem_pendencia']) return $b['tem_pendencia'] <=> $a['tem_pendencia'];
    if ($a['tem_movimento']  !== $b['tem_movimento'])  return $b['tem_movimento']  <=> $a['tem_movimento'];
    return strcmp($a['nome'], $b['nome']);
});

// Totais gerais
$g_saida   = array_sum(array_column($dados, 'tot_saida'));
$g_retorno = array_sum(array_column($dados, 'tot_retorno'));
$g_vendido = array_sum(array_column($dados, 'tot_vendido'));
$g_saldo   = array_sum(array_column($dados, 'saldo'));
$g_pend    = count(array_filter($dados, fn($v) => $v['tem_pendencia']));
$g_qrpend  = array_sum(array_column($dados, 'qr_pendentes'));

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
function badgeStatus(int $saldo, bool $mov): string {
    if (!$mov) return '<span class="badge badge-cinza">— Sem movimento</span>';
    if ($saldo === 0) return '<span class="badge badge-verde">✓ Zerado</span>';
    if ($saldo > 0)   return '<span class="badge badge-amarelo">⚠ Em aberto</span>';
    return '<span class="badge badge-vermelho">⚠ Verificar</span>';
}
function badgeProd(int $saldo): string {
    if ($saldo === 0) return '<span class="badge badge-verde">✓ OK</span>';
    if ($saldo > 0)   return '<span class="badge badge-amarelo">' . $saldo . ' pendente</span>';
    return '<span class="badge badge-vermelho">' . abs($saldo) . ' negativo</span>';
}
function badgeTipo(string $tipo): string {
    return match($tipo) {
        'Saída'   => '<span class="badge badge-tipo-saida">↑ Saída</span>',
        'Retorno' => '<span class="badge badge-tipo-retorno">↓ Retorno</span>',
        'Venda'   => '<span class="badge badge-tipo-venda">✓ Venda</span>',
        default   => '<span class="badge badge-cinza">' . htmlspecialchars($tipo) . '</span>',
    };
}
function classeLinha(int $saldo, bool $mov): string {
    if (!$mov || $saldo === 0) return '';
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

<!-- ── Alertas ────────────────────────────────────────────────── -->
<?php if ($g_pend > 0): ?>
<div class="alerta alerta-aviso">
    ⚠️ <strong><?= $g_pend ?> vendedor(es) com saldo em aberto</strong>
    no período <strong><?= esc($periodo_label) ?></strong>:
    <?php
        $nomes = array_map(fn($v) => $v['nome'], array_filter($dados, fn($v) => $v['tem_pendencia']));
        echo implode(', ', array_map('esc', $nomes)) . '.';
    ?>
    <?php if ($g_qrpend > 0): ?>
    <br>⏳ Há <strong><?= $g_qrpend ?> QR Code(s) não confirmados</strong> — o saldo pode mudar.
    <?php endif; ?>
</div>
<?php elseif ($g_qrpend > 0): ?>
<div class="alerta alerta-aviso">
    ⏳ <strong><?= $g_qrpend ?> QR Code(s) aguardam confirmação.</strong>
    Aguarde antes de exportar o relatório final.
</div>
<?php elseif ($g_saida > 0): ?>
<div class="alerta alerta-sucesso">
    ✅ <strong>Tudo certo!</strong> Todos os vendedores estão com saldo zerado
    no período <strong><?= esc($periodo_label) ?></strong>.
</div>
<?php endif; ?>

<!-- ── Cards de stats ─────────────────────────────────────────── -->
<div class="grid-stats">
    <div class="card-stat" style="border-left-color:var(--acento)">
        <div class="stat-num" style="color:var(--acento)"><?= $g_saida ?></div>
        <div class="stat-label">📤 Total Saídas</div>
    </div>
    <div class="card-stat" style="border-left-color:#6f42c1">
        <div class="stat-num" style="color:#6f42c1"><?= $g_retorno ?></div>
        <div class="stat-label">📥 Total Retornos</div>
    </div>
    <div class="card-stat" style="border-left-color:var(--verde)">
        <div class="stat-num" style="color:var(--verde)"><?= $g_vendido ?></div>
        <div class="stat-label">✅ Total Vendidos</div>
    </div>
    <?php
        $corSaldo = $g_saldo === 0 ? 'var(--verde)' : ($g_saldo > 0 ? 'var(--amarelo)' : 'var(--vermelho)');
    ?>
    <div class="card-stat" style="border-left-color:<?= $corSaldo ?>">
        <div class="stat-num" style="color:<?= $corSaldo ?>"><?= $g_saldo ?></div>
        <div class="stat-label">⚖️ Saldo Geral</div>
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
                <th class="col-num">QR Pend.</th>
                <th class="col-num">Status</th>
                <th class="col-acao">Detalhes</th>
            </tr>
        </thead>
        <tbody>

        <?php if (empty($dados)): ?>
        <tr><td colspan="8" class="td-vazio">Nenhum registro encontrado para o período selecionado.</td></tr>
        <?php else: ?>

        <?php foreach ($dados as $vid => $vend): ?>

        <tr class="<?= classeLinha($vend['saldo'], $vend['tem_movimento']) ?>" id="row-<?= (int)$vid ?>">
            <td>
                <strong><?= esc($vend['nome']) ?></strong>
                <?php if ($vend['saldo'] < 0): ?>
                    <span class="aviso-negativo">⚠ Saldo negativo — verificar</span>
                <?php endif; ?>
            </td>
            <td class="col-num">
                <?= $vend['tot_saida'] > 0
                    ? '<strong style="color:var(--acento)">' . $vend['tot_saida'] . '</strong>'
                    : '<span class="traco">—</span>' ?>
            </td>
            <td class="col-num">
                <?= $vend['tot_retorno'] > 0
                    ? $vend['tot_retorno']
                    : '<span class="traco">—</span>' ?>
            </td>
            <td class="col-num">
                <?= $vend['tot_vendido'] > 0
                    ? '<strong style="color:var(--verde)">' . $vend['tot_vendido'] . '</strong>'
                    : '<span class="traco">—</span>' ?>
            </td>
            <td class="col-num">
                <?php if ($vend['tem_movimento']): ?>
                    <strong class="<?= classeSaldo($vend['saldo']) ?> saldo-grande"><?= $vend['saldo'] ?></strong>
                <?php else: ?>
                    <span class="traco">—</span>
                <?php endif; ?>
            </td>
            <td class="col-num">
                <?= $vend['qr_pendentes'] > 0
                    ? '<span class="badge badge-amarelo">⏳ ' . $vend['qr_pendentes'] . '</span>'
                    : '<span class="traco">—</span>' ?>
            </td>
            <td class="col-num"><?= badgeStatus($vend['saldo'], $vend['tem_movimento']) ?></td>
            <td class="col-acao">
                <?php if ($vend['tem_movimento']): ?>
                <button class="btn btn-acento btn-pequeno"
                        id="btnD-<?= (int)$vid ?>"
                        onclick="toggleDetalhe(<?= (int)$vid ?>)">▼ Detalhar</button>
                <?php else: ?>
                <span class="traco">—</span>
                <?php endif; ?>
            </td>
        </tr>

        <?php if ($vend['tem_movimento']): ?>
        <tr class="linha-detalhe" id="detalhe-<?= (int)$vid ?>" style="display:none">
            <td colspan="8">
                <div class="detalhe-inner">

                    <div class="detalhe-secao">📦 Saldo por produto — <?= esc($vend['nome']) ?></div>
                    <div class="tabela-wrapper">
                    <table class="tabela-sub">
                        <thead>
                            <tr>
                                <th>Código</th>
                                <th>Produto</th>
                                <th class="col-num">Saída</th>
                                <th class="col-num">Retorno</th>
                                <th class="col-num">Vendido</th>
                                <th class="col-num">Saldo</th>
                                <th class="col-num">Status</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php
                            $sub_s = $sub_r = $sub_v = $sub_sal = 0;
                            foreach ($vend['produtos'] as $cod => $prod):
                                $sub_s += $prod['saida']; $sub_r += $prod['retorno'];
                                $sub_v += $prod['vendido']; $sub_sal += $prod['saldo'];
                        ?>
                        <tr class="<?= classeLinha($prod['saldo'], true) ?>">
                            <td><code class="cod"><?= esc($cod) ?></code></td>
                            <td><?= esc($prod['produto']) ?></td>
                            <td class="col-num"><?= $prod['saida'] ?></td>
                            <td class="col-num"><?= $prod['retorno'] ?></td>
                            <td class="col-num"><?= $prod['vendido'] ?></td>
                            <td class="col-num">
                                <strong class="<?= classeSaldo($prod['saldo']) ?>"><?= $prod['saldo'] ?></strong>
                            </td>
                            <td class="col-num"><?= badgeProd($prod['saldo']) ?></td>
                        </tr>
                        <?php endforeach; ?>
                        <tr class="linha-total">
                            <td colspan="2"><strong>SUBTOTAL</strong></td>
                            <td class="col-num"><?= $sub_s ?></td>
                            <td class="col-num"><?= $sub_r ?></td>
                            <td class="col-num"><?= $sub_v ?></td>
                            <td class="col-num">
                                <strong class="<?= classeSaldo($sub_sal) ?>"><?= $sub_sal ?></strong>
                            </td>
                            <td></td>
                        </tr>
                        </tbody>
                    </table>
                    </div>

                    <?php if (!empty($vend['registros'])): ?>
                    <div class="detalhe-secao" style="margin-top:20px">
                        🕐 Extrato cronológico — <?= count($vend['registros']) ?> registro(s)
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
                        <?php foreach ($vend['registros'] as $reg): ?>
                        <tr>
                            <td class="nowrap"><?= formatarData($reg['data']) ?></td>
                            <td class="nowrap"><?= esc(substr($reg['hora'], 0, 5)) ?></td>
                            <td><?= badgeTipo($reg['tipo']) ?></td>
                            <td><code class="cod"><?= esc($reg['codigo']) ?></code></td>
                            <td><?= esc($reg['produto']) ?></td>
                            <td class="col-num"><strong><?= (int)$reg['quantidade'] ?></strong></td>
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

        <?php if (count($dados) > 1 && $g_saida > 0): ?>
        <tr class="linha-total">
            <td><strong>TOTAL GERAL</strong></td>
            <td class="col-num"><?= $g_saida ?></td>
            <td class="col-num"><?= $g_retorno ?></td>
            <td class="col-num"><?= $g_vendido ?></td>
            <td class="col-num">
                <strong class="<?= classeSaldo($g_saldo) ?> saldo-grande"><?= $g_saldo ?></strong>
            </td>
            <td class="col-num">
                <?= $g_qrpend > 0
                    ? '<span class="badge badge-amarelo">' . $g_qrpend . '</span>'
                    : '<span class="traco">—</span>' ?>
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
            <span class="legenda-bolinha" style="background:#f0fff4; border:1px solid #c3e6cb"></span> Zerado
        </span>
        <span class="legenda-item">
            <span class="legenda-bolinha" style="background:#fffbea; border:1px solid #ffeeba"></span> Em aberto
        </span>
        <span class="legenda-item">
            <span class="legenda-bolinha" style="background:#fff0f0; border:1px solid #f5c6cb"></span> Verificar (negativo)
        </span>
        <span class="legenda-item">
            <span class="legenda-bolinha" style="background:#e9ecef; border:1px solid #ccc"></span> Sem movimento
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
    // Auto-expande vendedores com saldo negativo
    <?php foreach ($dados as $vid => $vend): ?>
    <?php if ($vend['tem_pendencia'] && $vend['saldo'] < 0): ?>
    toggleDetalhe(<?= (int)$vid ?>);
    <?php endif; ?>
    <?php endforeach; ?>
});

document.querySelector('.rel-filtros').addEventListener('submit', function(e) {
    var ini = this.querySelector('[name=data_ini]').value;
    var fim = this.querySelector('[name=data_fim]').value;
    if (ini && fim && fim < ini) {
        alert('⚠ A data fim não pode ser anterior à data início.');
        e.preventDefault();
    }
});
</script>

</body>
</html>