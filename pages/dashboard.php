<?php
// ============================================================
// pages/dashboard.php
// ============================================================

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

verificarLogin();

$pdo  = conectar();
$hoje = date('Y-m-d');

// ── Resumo do dia por vendedor ───────────────────────────────────
// CORREÇÃO: saldo calculado apenas com registros confirmados.
// Registros com confirmado=0 e rejeitado=0 são exibidos como pendentes.
function resumoVendedor(PDO $pdo, int $vendedorId, string $data): array
{
    // Saídas: confirmadas, aguardando e rejeitadas em uma só query
    $stmt = $pdo->prepare("
        SELECT
            COALESCE(SUM(CASE WHEN confirmado = 1 AND rejeitado = 0 THEN quantidade ELSE 0 END), 0) AS conf,
            COALESCE(SUM(CASE WHEN confirmado = 0 AND rejeitado = 0 THEN quantidade ELSE 0 END), 0) AS pend,
            COALESCE(SUM(CASE WHEN rejeitado  = 1                   THEN quantidade ELSE 0 END), 0) AS rej
        FROM reg_saidas WHERE vendedor_id = :vid AND data = :data
    ");
    $stmt->execute([':vid' => $vendedorId, ':data' => $data]);
    $rs         = $stmt->fetch();
    $saidas     = (int) $rs['conf'];
    $saidasPend = (int) $rs['pend'];
    $saidasRej  = (int) $rs['rej'];

    // Retornos: confirmados, aguardando e rejeitados em uma só query
    $stmt = $pdo->prepare("
        SELECT
            COALESCE(SUM(CASE WHEN confirmado = 1 AND rejeitado = 0 THEN quantidade ELSE 0 END), 0) AS conf,
            COALESCE(SUM(CASE WHEN confirmado = 0 AND rejeitado = 0 THEN quantidade ELSE 0 END), 0) AS pend,
            COALESCE(SUM(CASE WHEN rejeitado  = 1                   THEN quantidade ELSE 0 END), 0) AS rej
        FROM reg_retornos WHERE vendedor_id = :vid AND data = :data
    ");
    $stmt->execute([':vid' => $vendedorId, ':data' => $data]);
    $rr           = $stmt->fetch();
    $retornos     = (int) $rr['conf'];
    $retornosPend = (int) $rr['pend'];
    $retornosRej  = (int) $rr['rej'];

    // Vendas (não precisam de confirmação via QR)
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(quantidade), 0) AS total
        FROM reg_vendas WHERE vendedor_id = :vid AND data = :data
    ");
    $stmt->execute([':vid' => $vendedorId, ':data' => $data]);
    $vendas = (int) $stmt->fetchColumn();

    $saldo   = $saidas - $retornos - $vendas;
    $temPend = ($saidasPend > 0 || $retornosPend > 0);
    $temRej  = ($saidasRej  > 0 || $retornosRej  > 0);

    // Detecta produtos desequilibrados mesmo quando saldo total = 0
    // Ex: saída de produto C e retorno de produto A → saldo global 0, mas produtos não batem
    $produtosDeseq = false;
    if ($saldo === 0 && ($saidas > 0 || $retornos > 0 || $vendas > 0)) {
        $stmtProd = $pdo->prepare("
            SELECT COUNT(*) FROM (
                SELECT codigo,
                    SUM(CASE WHEN tipo='S' AND conf=1 AND rej=0 THEN qtd
                             WHEN tipo='R' AND conf=1 AND rej=0 THEN -qtd
                             WHEN tipo='V' THEN -qtd ELSE 0 END) AS saldo_prod
                FROM (
                    SELECT codigo,'S' tipo,confirmado conf,rejeitado rej,quantidade qtd
                    FROM reg_saidas WHERE vendedor_id=:vid AND data=:data
                    UNION ALL
                    SELECT codigo,'R',confirmado,rejeitado,quantidade
                    FROM reg_retornos WHERE vendedor_id=:vid AND data=:data
                    UNION ALL
                    SELECT codigo,'V',1,0,quantidade
                    FROM reg_vendas WHERE vendedor_id=:vid AND data=:data
                ) mov GROUP BY codigo HAVING saldo_prod != 0
            ) sub
        ");
        $stmtProd->execute([':vid' => $vendedorId, ':data' => $data]);
        $produtosDeseq = ((int)$stmtProd->fetchColumn()) > 0;
    }

    if ($saidas === 0 && $retornos === 0 && $vendas === 0 && !$temPend && !$temRej) {
        $status = 'sem_movimento';
    } elseif ($temRej) {
        $status = 'rejeitado';
    } elseif ($saldo === 0 && $produtosDeseq) {
        $status = 'verificar'; // saldo total zerado mas produtos individuais não batem
    } elseif ($saldo === 0 && !$temPend) {
        $status = 'zerado';
    } elseif ($saldo === 0 && $temPend) {
        $status = 'pendente';
    } elseif ($saldo > 0) {
        $status = 'aberto';
    } else {
        $status = 'negativo';
    }

    return compact('saidas', 'saidasPend', 'saidasRej', 'retornos', 'retornosPend', 'retornosRej', 'vendas', 'saldo', 'status');
}

// ── Pendências de dias anteriores ───────────────────────────────
// CORREÇÃO: saldo calculado apenas sobre registros confirmados.
// CORREÇÃO: URL usa data_ini (parâmetro correto de relatorios.php).
function buscarPendencias(PDO $pdo, string $hoje): array
{
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

// ── QR Codes aguardando confirmação ─────────────────────────────
function buscarQrsAguardando(PDO $pdo): array
{
    $stmt = $pdo->query("
        SELECT t.token, t.tipo, t.expira_em, t.data_ref, t.vendedor_id, v.nome AS vendedor
        FROM qr_tokens t
        INNER JOIN vendedores v ON v.id = t.vendedor_id
        WHERE t.usado = 0 AND t.rejeitado = 0 AND t.expira_em > NOW()
        ORDER BY t.expira_em ASC
    ");
    return $stmt->fetchAll();
}

// ── QR Codes rejeitados ──────────────────────────────────────────
// CORREÇÃO: usado=0 nunca batia — confirmar.php grava usado=1 ao rejeitar.
// Agora busca por status='rejeitado', excluindo os que já foram corrigidos
// (existe token mais novo para o mesmo vendedor+data+tipo).
function buscarQrsRejeitados(PDO $pdo): array
{
    $stmt = $pdo->query("
        SELECT t.token, t.tipo, t.expira_em, t.data_ref, t.rejeitado_motivo,
               t.vendedor_id, v.nome AS vendedor
        FROM qr_tokens t
        INNER JOIN vendedores v ON v.id = t.vendedor_id
        WHERE t.rejeitado = 1 AND t.status = 'rejeitado'
          AND NOT EXISTS (
              SELECT 1 FROM qr_tokens t2
              WHERE t2.vendedor_id = t.vendedor_id
                AND t2.data_ref    = t.data_ref
                AND t2.tipo        = t.tipo
                AND t2.id          > t.id
                AND t2.status      IN ('pendente', 'confirmado')
          )
        ORDER BY t.expira_em DESC
        LIMIT 30
    ");
    return $stmt->fetchAll();
}

// ── QR Codes expirados sem confirmação ──────────────────────────
function buscarQrsExpirados(PDO $pdo): array
{
    $stmt = $pdo->query("
        SELECT t.token, t.tipo, t.expira_em, t.data_ref, t.vendedor_id, v.nome AS vendedor
        FROM qr_tokens t
        INNER JOIN vendedores v ON v.id = t.vendedor_id
        WHERE t.usado = 0 AND t.rejeitado = 0 AND t.expira_em <= NOW()
        ORDER BY t.expira_em DESC
        LIMIT 30
    ");
    return $stmt->fetchAll();
}

// ── Movimentação dos últimos 7 dias ─────────────────────────────
// CORREÇÃO: apenas registros confirmados para refletir realidade.
function movimentacaoSemanal(PDO $pdo): array
{
    $dias = [];
    for ($i = 6; $i >= 0; $i--) {
        $d = date('Y-m-d', strtotime("-{$i} days"));
        $dias[$d] = ['data' => $d, 'saidas' => 0, 'retornos' => 0, 'vendas' => 0];
    }
    $inicio = date('Y-m-d', strtotime('-6 days'));

    $stmt = $pdo->prepare("
        SELECT data, COALESCE(SUM(quantidade), 0) AS total
        FROM reg_saidas
        WHERE data >= :ini AND confirmado = 1 AND rejeitado = 0
        GROUP BY data
    ");
    $stmt->execute([':ini' => $inicio]);
    foreach ($stmt->fetchAll() as $r) {
        if (isset($dias[$r['data']])) $dias[$r['data']]['saidas'] = (int) $r['total'];
    }

    $stmt = $pdo->prepare("
        SELECT data, COALESCE(SUM(quantidade), 0) AS total
        FROM reg_retornos
        WHERE data >= :ini AND confirmado = 1 AND rejeitado = 0
        GROUP BY data
    ");
    $stmt->execute([':ini' => $inicio]);
    foreach ($stmt->fetchAll() as $r) {
        if (isset($dias[$r['data']])) $dias[$r['data']]['retornos'] = (int) $r['total'];
    }

    $stmt = $pdo->prepare("
        SELECT data, COALESCE(SUM(quantidade), 0) AS total
        FROM reg_vendas WHERE data >= :ini GROUP BY data
    ");
    $stmt->execute([':ini' => $inicio]);
    foreach ($stmt->fetchAll() as $r) {
        if (isset($dias[$r['data']])) $dias[$r['data']]['vendas'] = (int) $r['total'];
    }

    return array_values($dias);
}

// ── Busca dados ──────────────────────────────────────────────────
$vendedores = $pdo->query(
    "SELECT id, nome FROM vendedores WHERE ativo = 1 ORDER BY nome"
)->fetchAll();

$pendencias    = buscarPendencias($pdo, $hoje);
$qrsAguardando = buscarQrsAguardando($pdo);
$qrsRejeitados = buscarQrsRejeitados($pdo);
$qrsExpirados  = buscarQrsExpirados($pdo);
$semana        = movimentacaoSemanal($pdo);

$totalAlertasQR = count($qrsAguardando) + count($qrsRejeitados) + count($qrsExpirados);

$resumos = [];
foreach ($vendedores as $v) {
    $resumos[$v['id']] = array_merge($v, resumoVendedor($pdo, (int) $v['id'], $hoje));
}

$statusSaida   = verificarHorario('saida');
$statusRetorno = verificarHorario('retorno');
$statusVenda   = verificarHorario('venda');
?>
<?php include __DIR__ . '/../includes/header.php'; ?>

<main>

    <?php /* ── Alerta de pendências de dias anteriores ─────────────── */ ?>
    <?php if (in_array($_SESSION['usuario_perfil'], ['admin', 'supervisor', 'master'])): ?>
        <?php if (!empty($pendencias)): ?>
        <div class="alerta alerta-aviso dash-alerta-pendencias">
            <span class="dash-alerta-icone">⚠️</span>
            <div class="dash-alerta-corpo">
                <strong><?= count($pendencias) ?> pendência(s) de dias anteriores:</strong>
                <ul class="dash-pendencias-lista">
                    <?php foreach ($pendencias as $p):
                        // CORREÇÃO: parâmetro data_ini (era data_inicio — não funcionava)
                        $urlRelatorio = BASE_URL . '/pages/relatorios.php'
                            . '?data_ini='    . urlencode($p['data'])
                            . '&data_fim='    . urlencode($p['data'])
                            . '&vendedor_id=' . urlencode($p['vendedor_id']);
                    ?>
                        <li>
                            <strong><?= esc($p['nome']) ?></strong>
                            — <?= formatarData($p['data']) ?>
                            — Saldo: <strong><?= $p['saldo'] ?></strong> itens em aberto
                            <a href="<?= $urlRelatorio ?>" class="dash-pendencia-link" target="_blank" title="Ver relatório deste dia">
                                📊 Ver relatório →
                            </a>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>
        <?php endif; ?>
    <?php endif; ?>

    <?php /* ── Banner de status de horário ─────────────────────────── */ ?>
    <div class="banners-horario">

        <div class="banner-status <?= $statusSaida === 'liberado' ? 'banner-liberado' : ($statusSaida === 'manutencao' ? 'banner-manut-info' : 'banner-bloqueado') ?>">
            📤 SAÍDA
            <?php if ($statusSaida === 'liberado'): ?>
                LIBERADA — até <?= HORA_SAIDA_FIM ?>
            <?php elseif ($statusSaida === 'manutencao'): ?>
                LIBERADA (Modo Manutenção)
            <?php else: ?>
                BLOQUEADA — Permitido das <?= HORA_SAIDA_INICIO ?> às <?= HORA_SAIDA_FIM ?>
            <?php endif; ?>
        </div>

        <div class="banner-status <?= $statusRetorno === 'liberado' ? 'banner-liberado' : ($statusRetorno === 'manutencao' ? 'banner-manut-info' : 'banner-bloqueado') ?>">
            📥 RETORNO
            <?php if ($statusRetorno === 'liberado'): ?>
                LIBERADO — até <?= HORA_RETORNO_FIM ?>
            <?php elseif ($statusRetorno === 'manutencao'): ?>
                LIBERADO (Modo Manutenção)
            <?php else: ?>
                BLOQUEADO — Permitido das <?= HORA_RETORNO_INICIO ?> às <?= HORA_RETORNO_FIM ?>
            <?php endif; ?>
        </div>

        <div class="banner-status <?= $statusVenda === 'liberado' ? 'banner-liberado' : ($statusVenda === 'manutencao' ? 'banner-manut-info' : 'banner-bloqueado') ?>">
            ✅ CONFIRMAR VENDA
            <?php if ($statusVenda === 'liberado'): ?>
                LIBERADO — até <?= HORA_VENDA_FIM ?>
            <?php elseif ($statusVenda === 'manutencao'): ?>
                LIBERADO (Modo Manutenção)
            <?php else: ?>
                BLOQUEADO — Permitido das <?= HORA_VENDA_INICIO ?> às <?= HORA_VENDA_FIM ?>
            <?php endif; ?>
        </div>

    </div>

    <?php /* ── Botões de operação ─────────────────────────────────── */ ?>
    <div class="botoes-operacao">

        <?php if (in_array($_SESSION['usuario_perfil'], ['operador', 'supervisor', 'master'])): ?>
        <a href="<?= BASE_URL ?>/pages/saida.php" class="btn-operacao btn-op-saida" target="_blank">
            <span class="btn-op-icone">📤</span>
            <span class="btn-op-label">Registrar Saída</span>
        </a>
        <a href="<?= BASE_URL ?>/pages/retorno.php" class="btn-operacao btn-op-retorno" target="_blank">
            <span class="btn-op-icone">📥</span>
            <span class="btn-op-label">Registrar Retorno</span>
        </a>
        <?php endif; ?>

        <?php if (in_array($_SESSION['usuario_perfil'], ['admin', 'supervisor', 'master'])): ?>
        <a href="<?= BASE_URL ?>/pages/confirmar_venda.php" class="btn-operacao btn-op-venda" target="_blank">
            <span class="btn-op-icone">✅</span>
            <span class="btn-op-label">Confirmar Venda</span>
        </a>
        <a href="<?= BASE_URL ?>/pages/relatorios.php" class="btn-operacao btn-op-relatorio" target="_blank">
            <span class="btn-op-icone">📊</span>
            <span class="btn-op-label">Gerar Relatório</span>
        </a>
        <?php endif; ?>

        <?php if (in_array($_SESSION['usuario_perfil'], ['supervisor', 'master'])): ?>
        <a href="<?= BASE_URL ?>/pages/cadastros.php" class="btn-operacao btn-op-cadastro" target="_blank">
            <span class="btn-op-icone">📋</span>
            <span class="btn-op-label">Cadastros</span>
        </a>
        <?php endif; ?>

    </div>

    <?php /* ── Card de alertas de QR Code ──────────────────────────── */ ?>
    <?php if ($totalAlertasQR > 0): ?>
    <div class="card dash-card-qr">
        <div class="card-titulo dash-qr-titulo">
            <span>🔔 Confirmações QR Code</span>
            <span class="dash-qr-badges">
                <?php if (count($qrsAguardando) > 0): ?>
                    <span class="badge badge-amarelo"><?= count($qrsAguardando) ?> aguardando</span>
                <?php endif; ?>
                <?php if (count($qrsRejeitados) > 0): ?>
                    <span class="badge badge-vermelho"><?= count($qrsRejeitados) ?> rejeitado(s)</span>
                <?php endif; ?>
                <?php if (count($qrsExpirados) > 0): ?>
                    <span class="badge badge-cinza"><?= count($qrsExpirados) ?> expirado(s)</span>
                <?php endif; ?>
            </span>
        </div>

        <?php
        // Macro para colgroup idêntico nas 3 tabelas — garante alinhamento visual entre seções
        // Vendedor 28% | Tipo 14% | Data Ref. 14% | Col. variável 30% | Ação 14%
        $colgroup = '<colgroup>
            <col style="width:28%"><col style="width:14%">
            <col style="width:14%"><col style="width:30%"><col style="width:14%">
        </colgroup>';
        ?>
        <div class="dash-qr-abas">

            <?php /* Rejeitados primeiro — são urgentes (operador precisa corrigir) */ ?>
            <?php if (!empty($qrsRejeitados)): ?>
            <div class="dash-qr-secao">
                <div class="dash-qr-secao-titulo dash-qr-rejeitado">❌ Rejeitados pelo Vendedor</div>
                <div class="tabela-wrapper">
                    <table style="table-layout:fixed; width:100%">
                        <?= $colgroup ?>
                        <thead><tr>
                            <th>Vendedor</th>
                            <th style="text-align:center">Tipo</th>
                            <th style="text-align:center">Data Ref.</th>
                            <th>Motivo da Rejeição</th>
                            <th style="text-align:center">Ação</th>
                        </tr></thead>
                        <tbody>
                        <?php foreach ($qrsRejeitados as $qr): ?>
                        <tr class="dash-tr-rejeitado">
                            <td><strong><?= esc($qr['vendedor']) ?></strong></td>
                            <td style="text-align:center"><?= $qr['tipo'] === 'saida' ? '📤 Saída' : '📥 Retorno' ?></td>
                            <td style="text-align:center"><?= formatarData($qr['data_ref']) ?></td>
                            <td style="font-size:13px; color:var(--cinza-texto); font-style:italic; overflow:hidden; text-overflow:ellipsis; white-space:nowrap">
                                <?= $qr['rejeitado_motivo'] ? esc($qr['rejeitado_motivo']) : '—' ?>
                            </td>
                            <td style="text-align:center">
                                <a href="<?= BASE_URL ?>/pages/<?= esc($qr['tipo']) ?>.php?etapa=corrigir&token=<?= esc($qr['token']) ?>"
                                   class="btn btn-vermelho btn-pequeno" target="_blank">✏️ Corrigir</a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php endif; ?>

            <?php if (!empty($qrsAguardando)): ?>
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
                        <?php foreach ($qrsAguardando as $qr): ?>
                        <tr class="dash-tr-aguardando">
                            <td><strong><?= esc($qr['vendedor']) ?></strong></td>
                            <td style="text-align:center"><?= $qr['tipo'] === 'saida' ? '📤 Saída' : '📥 Retorno' ?></td>
                            <td style="text-align:center"><?= formatarData($qr['data_ref']) ?></td>
                            <td style="text-align:center; font-size:13px; color:var(--cinza-texto)"><?= formatarDataHora($qr['expira_em']) ?></td>
                            <td style="text-align:center">
                                <a href="<?= BASE_URL ?>/pages/<?= esc($qr['tipo']) ?>.php?etapa=reenviar&vid=<?= (int)$qr['vendedor_id'] ?>&data=<?= esc($qr['data_ref']) ?>"
                                   class="btn btn-acento btn-pequeno" target="_blank">📲 Reenviar QR</a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php endif; ?>

            <?php if (!empty($qrsExpirados)): ?>
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
                        <?php foreach ($qrsExpirados as $qr): ?>
                        <tr class="dash-tr-expirado">
                            <td><strong><?= esc($qr['vendedor']) ?></strong></td>
                            <td style="text-align:center"><?= $qr['tipo'] === 'saida' ? '📤 Saída' : '📥 Retorno' ?></td>
                            <td style="text-align:center"><?= formatarData($qr['data_ref']) ?></td>
                            <td style="text-align:center; font-size:13px; color:var(--cinza-texto)"><?= formatarDataHora($qr['expira_em']) ?></td>
                            <td style="text-align:center">
                                <a href="<?= BASE_URL ?>/pages/<?= esc($qr['tipo']) ?>.php?etapa=reenviar&vid=<?= (int)$qr['vendedor_id'] ?>&data=<?= esc($qr['data_ref']) ?>"
                                   class="btn btn-secundario btn-pequeno" target="_blank">🔁 Reenviar QR</a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php endif; ?>

        </div><!-- /.dash-qr-abas -->
    </div>
    <?php endif; ?>

    <?php /* ── Gráfico: Movimentação dos últimos 7 dias ─────────────── */ ?>
    <div class="card">
        <div class="card-titulo">📈 Movimentação — Últimos 7 Dias</div>
        <div class="dash-grafico-wrapper">
            <canvas id="grafico-semana"
                    class="dash-grafico-canvas"
                    data-semana="<?= esc(json_encode($semana)) ?>"
                    data-hoje="<?= $hoje ?>">
            </canvas>
            <div class="dash-grafico-legenda">
                <span class="dash-leg-item">
                    <span class="dash-leg-cor" style="background:var(--primaria)"></span> Saídas conf.
                </span>
                <span class="dash-leg-item">
                    <span class="dash-leg-cor" style="background:var(--acento)"></span> Retornos conf.
                </span>
                <span class="dash-leg-item">
                    <span class="dash-leg-cor" style="background:var(--verde)"></span> Vendas conf.
                </span>
            </div>
        </div>
    </div>

    <?php /* ── Painel de resumo por vendedor ─────────────────────── */ ?>
    <div class="card">
        <div class="card-titulo" style="display:flex; justify-content:space-between; align-items:center;">
            <span>📊 Resumo do Dia — <?= date('d/m/Y') ?></span>
            <button onclick="location.reload()" class="btn btn-secundario" style="padding:5px 12px; font-size:13px;">
                🔄 Atualizar
            </button>
        </div>

        <?php if (empty($vendedores)): ?>
            <div class="alerta alerta-info">
                Nenhum vendedor cadastrado ainda.
                <?php if (in_array($_SESSION['usuario_perfil'], ['supervisor', 'master'])): ?>
                    <a href="<?= BASE_URL ?>/pages/cadastros.php">Cadastrar agora →</a>
                <?php endif; ?>
            </div>
        <?php else: ?>

        <div class="tabela-wrapper">
            <table>
                <thead>
                    <tr>
                        <th>Vendedor</th>
                        <th style="text-align:center">Saídas</th>
                        <th style="text-align:center">Retornos</th>
                        <th style="text-align:center">Vendas Conf.</th>
                        <th style="text-align:center">Saldo</th>
                        <th style="text-align:center">Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($resumos as $r):
                        $classeLinha = match($r['status']) {
                            'zerado'        => 'linha-zero',
                            'pendente'      => 'linha-aberto',
                            'aberto'        => 'linha-aberto',
                            'rejeitado'     => 'linha-negativo',
                            'verificar'     => 'linha-negativo',
                            'negativo'      => 'linha-negativo',
                            'sem_movimento' => '',
                            default         => '',
                        };
                        [$badgeClasse, $badgeTexto] = match($r['status']) {
                            'zerado'        => ['badge-verde',    '✔ Zerado'],
                            'pendente'      => ['badge-amarelo',  '⏳ Aguard. QR'],
                            'aberto'        => ['badge-amarelo',  '⏳ Em aberto'],
                            'rejeitado'     => ['badge-vermelho', '❌ Rejeitado'],
                            'verificar'     => ['badge-vermelho', '⚠ Verificar produtos'],
                            'negativo'      => ['badge-vermelho', '✖ Verificar'],
                            'sem_movimento' => ['badge-cinza',    '— Sem movimento'],
                            default         => ['badge-cinza',    '—'],
                        };
                    ?>
                    <tr class="<?= $classeLinha ?>">
                        <td><strong><?= esc($r['nome']) ?></strong></td>

                        <td style="text-align:center; vertical-align:middle">
                            <?= $r['saidas'] > 0 ? $r['saidas'] : ($r['saidasPend'] > 0 || $r['saidasRej'] > 0 ? '0' : '—') ?>
                            <?php if ($r['saidasPend'] > 0): ?>
                                <br><span class="badge badge-amarelo" style="font-size:10px; margin-top:3px"
                                          title="Saída registrada, aguardando confirmação do vendedor via QR">
                                    ⏳ +<?= $r['saidasPend'] ?> pend.
                                </span>
                            <?php endif; ?>
                            <?php if ($r['saidasRej'] > 0): ?>
                                <br><span class="badge badge-vermelho" style="font-size:10px; margin-top:3px"
                                          title="Saída rejeitada pelo vendedor — aguarda correção">
                                    ❌ +<?= $r['saidasRej'] ?> rej.
                                </span>
                            <?php endif; ?>
                        </td>

                        <td style="text-align:center; vertical-align:middle">
                            <?= $r['retornos'] > 0 ? $r['retornos'] : ($r['retornosPend'] > 0 || $r['retornosRej'] > 0 ? '0' : '—') ?>
                            <?php if ($r['retornosPend'] > 0): ?>
                                <br><span class="badge badge-amarelo" style="font-size:10px; margin-top:3px"
                                          title="Retorno registrado, aguardando confirmação do vendedor via QR">
                                    ⏳ +<?= $r['retornosPend'] ?> pend.
                                </span>
                            <?php endif; ?>
                            <?php if ($r['retornosRej'] > 0): ?>
                                <br><span class="badge badge-vermelho" style="font-size:10px; margin-top:3px"
                                          title="Retorno rejeitado pelo vendedor — aguarda correção">
                                    ❌ +<?= $r['retornosRej'] ?> rej.
                                </span>
                            <?php endif; ?>
                        </td>

                        <td style="text-align:center; vertical-align:middle"><?= $r['vendas'] ?: '—' ?></td>

                        <td style="text-align:center; vertical-align:middle">
                            <span class="<?= classSaldo($r['saldo']) ?>"><?= $r['saldo'] ?></span>
                            <?php if ($r['saidasPend'] > 0 || $r['retornosPend'] > 0): ?>
                                <br><small style="color:var(--cinza-texto); font-size:10px"
                                           title="Saldo definitivo após confirmação dos QRs pendentes">
                                    (parcial)
                                </small>
                            <?php endif; ?>
                        </td>

                        <td style="text-align:center; vertical-align:middle">
                            <span class="badge <?= $badgeClasse ?>"><?= $badgeTexto ?></span>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>

                <?php
                    $totalSaidas       = array_sum(array_column($resumos, 'saidas'));
                    $totalSaidasPend   = array_sum(array_column($resumos, 'saidasPend'));
                    $totalRetornos     = array_sum(array_column($resumos, 'retornos'));
                    $totalRetornosPend = array_sum(array_column($resumos, 'retornosPend'));
                    $totalVendas       = array_sum(array_column($resumos, 'vendas'));
                    $totalSaldo        = array_sum(array_column($resumos, 'saldo'));
                ?>
                <tfoot>
                    <tr style="background:#eef0ff; font-weight:bold;">
                        <td>TOTAL GERAL</td>
                        <td style="text-align:center">
                            <?= $totalSaidas ?>
                            <?php if ($totalSaidasPend > 0): ?>
                                <br><span style="font-weight:normal; font-size:10px; color:var(--cinza-texto)">
                                    +<?= $totalSaidasPend ?> pend.
                                </span>
                            <?php endif; ?>
                        </td>
                        <td style="text-align:center">
                            <?= $totalRetornos ?>
                            <?php if ($totalRetornosPend > 0): ?>
                                <br><span style="font-weight:normal; font-size:10px; color:var(--cinza-texto)">
                                    +<?= $totalRetornosPend ?> pend.
                                </span>
                            <?php endif; ?>
                        </td>
                        <td style="text-align:center"><?= $totalVendas ?></td>
                        <td style="text-align:center">
                            <span class="<?= classSaldo($totalSaldo) ?>"><?= $totalSaldo ?></span>
                        </td>
                        <td></td>
                    </tr>
                </tfoot>
            </table>
        </div>

        <div class="legenda-cores">
            <span class="legenda-item">
                <span class="legenda-bolinha" style="background:#d4edda"></span> Zerado (confirmado)
            </span>
            <span class="legenda-item">
                <span class="legenda-bolinha" style="background:#fffbea"></span> Em aberto / Aguard. QR
            </span>
            <span class="legenda-item">
                <span class="legenda-bolinha" style="background:#fff0f0; border:1px solid #f5c6cb"></span> Verificar (saldo negativo)
            </span>
            <span class="legenda-item">
                <span class="legenda-bolinha" style="background:#f4f6fa; border:1px solid #ced4da"></span> Sem movimento
            </span>
            <span class="legenda-item">
                <span class="badge badge-amarelo" style="font-size:10px">⏳ +X pend.</span> Aguardando confirmação QR — não entra no saldo
            </span>
        </div>

        <?php endif; ?>
    </div>

</main>

<footer>
    <?= SISTEMA_NOME ?> v<?= SISTEMA_VERSAO ?>
    &nbsp;|&nbsp;
    <?= date('d/m/Y') ?>
    &nbsp;|&nbsp;
    <?php if ($_SESSION['usuario_perfil'] === 'master'): ?>
        <a href="<?= BASE_URL ?>/pages/admin.php" title="Painel administrativo">⚙ Admin</a>
    <?php endif; ?>
</footer>

<script src="<?= BASE_URL ?>/assets/js/main.js"></script>
<script>
const BASE_URL = '<?= BASE_URL ?>';

// ── Gráfico de movimentação semanal ─────────────────────────────
(function () {
    const canvas = document.getElementById('grafico-semana');
    if (!canvas || !canvas.getContext) return;

    const dados = JSON.parse(canvas.dataset.semana);
    const hoje  = canvas.dataset.hoje;
    const ctx   = canvas.getContext('2d');

    function desenhar() {
        const W = canvas.parentElement.offsetWidth;
        const H = 190;
        canvas.width  = W;
        canvas.height = H;

        const PAD   = { top: 20, bottom: 44, left: 10, right: 10 };
        const chartW = W - PAD.left - PAD.right;
        const chartH = H - PAD.top  - PAD.bottom;

        const maxVal = Math.max(
            1,
            ...dados.flatMap(d => [d.saidas, d.retornos, d.vendas])
        );

        const CORES = ['#2B2B88', '#0A7BC4', '#28A745'];
        const TIPOS = ['saidas', 'retornos', 'vendas'];
        const diaW  = chartW / dados.length;
        const barW  = Math.max(5, Math.min(14, diaW / 5));
        const gap   = 2;
        const grupoW = barW * 3 + gap * 2;

        ctx.clearRect(0, 0, W, H);
        ctx.strokeStyle = '#CED4DA';
        ctx.lineWidth = 1;
        ctx.beginPath();
        ctx.moveTo(PAD.left, H - PAD.bottom);
        ctx.lineTo(W - PAD.right, H - PAD.bottom);
        ctx.stroke();

        ctx.setLineDash([3, 4]);
        ctx.strokeStyle = '#e8eaf0';
        [0.25, 0.5, 0.75].forEach(frac => {
            const y = PAD.top + chartH * (1 - frac);
            ctx.beginPath();
            ctx.moveTo(PAD.left, y);
            ctx.lineTo(W - PAD.right, y);
            ctx.stroke();
            const val = Math.round(maxVal * frac);
            ctx.setLineDash([]);
            ctx.fillStyle = '#aaa';
            ctx.font = '9px Arial, sans-serif';
            ctx.textAlign = 'right';
            ctx.fillText(val, PAD.left + 22, y + 3);
            ctx.setLineDash([3, 4]);
        });
        ctx.setLineDash([]);

        const diasSemana = ['Dom', 'Seg', 'Ter', 'Qua', 'Qui', 'Sex', 'Sáb'];

        dados.forEach(function (d, i) {
            const cx = PAD.left + diaW * i + diaW / 2;
            const eHoje = (d.data === hoje);

            if (eHoje) {
                ctx.fillStyle = 'rgba(43,43,136,0.05)';
                ctx.fillRect(PAD.left + diaW * i, PAD.top, diaW, chartH + 1);
            }

            TIPOS.forEach(function (tipo, j) {
                const val  = d[tipo];
                const barH = val > 0 ? Math.max(3, (val / maxVal) * chartH) : 0;
                const x    = cx - grupoW / 2 + j * (barW + gap);
                const y    = H - PAD.bottom - barH;
                ctx.fillStyle = CORES[j];
                ctx.globalAlpha = eHoje ? 1 : 0.72;
                ctx.fillRect(x, y, barW, barH);
                ctx.globalAlpha = 1;
                if (barH > 16 && val > 0) {
                    ctx.fillStyle = '#fff';
                    ctx.font = 'bold 9px Arial, sans-serif';
                    ctx.textAlign = 'center';
                    ctx.fillText(val, x + barW / 2, y + 10);
                }
            });

            const dataObj = new Date(d.data + 'T00:00:00');
            const nomeDia = diasSemana[dataObj.getDay()];
            const diaMes  = d.data.slice(8) + '/' + d.data.slice(5, 7);

            ctx.fillStyle = eHoje ? '#2B2B88' : '#6C757D';
            ctx.font = eHoje ? 'bold 10px Arial, sans-serif' : '10px Arial, sans-serif';
            ctx.textAlign = 'center';
            ctx.fillText(nomeDia, cx, H - PAD.bottom + 14);
            ctx.font = '9px Arial, sans-serif';
            ctx.fillStyle = '#aaa';
            ctx.fillText(diaMes, cx, H - PAD.bottom + 26);
            if (eHoje) {
                ctx.fillStyle = '#2B2B88';
                ctx.font = 'bold 9px Arial, sans-serif';
                ctx.fillText('▲ hoje', cx, H - PAD.bottom + 38);
            }
        });
    }

    desenhar();
    let debounce;
    window.addEventListener('resize', function () {
        clearTimeout(debounce);
        debounce = setTimeout(desenhar, 120);
    });
})();

// ── Atualização automática a cada 60 segundos ───────────────────
setTimeout(function () { location.reload(); }, 60000);
</script>

</body>
</html>