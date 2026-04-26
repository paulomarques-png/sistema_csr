<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

verificarLogin();

$pdo  = conectar();
$hoje = date('Y-m-d');

// ── Monta o painel de resumo de HOJE por vendedor ────────────────
function resumoVendedor(PDO $pdo, int $vendedorId, string $data): array
{
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(quantidade), 0) AS total
        FROM reg_saidas WHERE vendedor_id = :vid AND data = :data
    ");
    $stmt->execute([':vid' => $vendedorId, ':data' => $data]);
    $saidas = (int) $stmt->fetchColumn();

    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(quantidade), 0) AS total
        FROM reg_retornos WHERE vendedor_id = :vid AND data = :data
    ");
    $stmt->execute([':vid' => $vendedorId, ':data' => $data]);
    $retornos = (int) $stmt->fetchColumn();

    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(quantidade), 0) AS total
        FROM reg_vendas WHERE vendedor_id = :vid AND data = :data
    ");
    $stmt->execute([':vid' => $vendedorId, ':data' => $data]);
    $vendas = (int) $stmt->fetchColumn();

    $saldo = $saidas - $retornos - $vendas;

    if ($saidas === 0 && $retornos === 0 && $vendas === 0) {
        $status = 'sem_movimento';
    } elseif ($saldo === 0) {
        $status = 'zerado';
    } elseif ($saldo > 0) {
        $status = 'aberto';
    } else {
        $status = 'negativo';
    }

    return compact('saidas', 'retornos', 'vendas', 'saldo', 'status');
}

// ── Busca pendências de dias anteriores (com vendedor_id para link) ──
function buscarPendencias(PDO $pdo, string $hoje): array
{
    $stmt = $pdo->query("
        SELECT
            v.id AS vendedor_id,
            v.nome,
            s.data,
            (
                COALESCE((SELECT SUM(q.quantidade)  FROM reg_saidas   q  WHERE q.vendedor_id  = v.id AND q.data  = s.data), 0) -
                COALESCE((SELECT SUM(r.quantidade)  FROM reg_retornos r  WHERE r.vendedor_id  = v.id AND r.data  = s.data), 0) -
                COALESCE((SELECT SUM(vd.quantidade) FROM reg_vendas   vd WHERE vd.vendedor_id = v.id AND vd.data = s.data), 0)
            ) AS saldo
        FROM vendedores v
        INNER JOIN reg_saidas s ON s.vendedor_id = v.id
        WHERE s.data < '$hoje'
        GROUP BY v.id, v.nome, s.data
        HAVING saldo > 0
        ORDER BY s.data ASC, v.nome ASC
    ");
    return $stmt->fetchAll();
}

// ── QR Codes aguardando confirmação (não expirados) ──────────────
function buscarQrsAguardando(PDO $pdo): array
{
    $stmt = $pdo->query("
        SELECT
            t.token,
            t.tipo,
            t.expira_em,
            t.data_ref,
            t.vendedor_id,
            v.nome AS vendedor
        FROM qr_tokens t
        INNER JOIN vendedores v ON v.id = t.vendedor_id
        WHERE t.usado = 0
          AND t.rejeitado = 0
          AND t.expira_em > NOW()
        ORDER BY t.expira_em ASC
    ");
    return $stmt->fetchAll();
}

// ── QR Codes rejeitados pelo vendedor ────────────────────────────
function buscarQrsRejeitados(PDO $pdo): array
{
    $stmt = $pdo->query("
        SELECT
            t.token,
            t.tipo,
            t.expira_em,
            t.data_ref,
            t.rejeitado_motivo,
            v.nome AS vendedor
        FROM qr_tokens t
        INNER JOIN vendedores v ON v.id = t.vendedor_id
        WHERE t.usado = 0
          AND t.rejeitado = 1
        ORDER BY t.expira_em DESC
    ");
    return $stmt->fetchAll();
}

// ── QR Codes expirados sem confirmação (NOVO) ────────────────────
function buscarQrsExpirados(PDO $pdo): array
{
    $stmt = $pdo->query("
        SELECT
            t.token,
            t.tipo,
            t.expira_em,
            t.data_ref,
            t.vendedor_id,
            v.nome AS vendedor
        FROM qr_tokens t
        INNER JOIN vendedores v ON v.id = t.vendedor_id
        WHERE t.usado = 0
          AND t.rejeitado = 0
          AND t.expira_em <= NOW()
        ORDER BY t.expira_em DESC
        LIMIT 30
    ");
    return $stmt->fetchAll();
}

// ── Movimentação dos últimos 7 dias (NOVO) ───────────────────────
function movimentacaoSemanal(PDO $pdo): array
{
    // Monta array dos 7 dias com valores zerados
    $dias = [];
    for ($i = 6; $i >= 0; $i--) {
        $d = date('Y-m-d', strtotime("-{$i} days"));
        $dias[$d] = ['data' => $d, 'saidas' => 0, 'retornos' => 0, 'vendas' => 0];
    }

    $inicio = date('Y-m-d', strtotime('-6 days'));

    $stmt = $pdo->prepare("
        SELECT data, COALESCE(SUM(quantidade), 0) AS total
        FROM reg_saidas WHERE data >= :ini GROUP BY data
    ");
    $stmt->execute([':ini' => $inicio]);
    foreach ($stmt->fetchAll() as $r) {
        if (isset($dias[$r['data']])) $dias[$r['data']]['saidas'] = (int) $r['total'];
    }

    $stmt = $pdo->prepare("
        SELECT data, COALESCE(SUM(quantidade), 0) AS total
        FROM reg_retornos WHERE data >= :ini GROUP BY data
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

// ── Busca todos os vendedores ativos ─────────────────────────────
$vendedores = $pdo->query("
    SELECT id, nome FROM vendedores WHERE ativo = 1 ORDER BY nome
")->fetchAll();

$pendencias    = buscarPendencias($pdo, $hoje);
$qrsAguardando = buscarQrsAguardando($pdo);
$qrsRejeitados = buscarQrsRejeitados($pdo);
$qrsExpirados  = buscarQrsExpirados($pdo);
$semana        = movimentacaoSemanal($pdo);

// Conta total de alertas QR para exibir no cabeçalho do card
$totalAlertasQR = count($qrsAguardando) + count($qrsRejeitados) + count($qrsExpirados);

// ── Monta resumo de todos os vendedores ──────────────────────────
$resumos = [];
foreach ($vendedores as $v) {
    $resumos[$v['id']] = array_merge($v, resumoVendedor($pdo, (int) $v['id'], $hoje));
}

// ── Status de horário atual ──────────────────────────────────────
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
                        $urlRelatorio = BASE_URL . '/pages/relatorios.php'
                            . '?data_inicio=' . urlencode($p['data'])
                            . '&data_fim='    . urlencode($p['data'])
                            . '&vendedor_id=' . urlencode($p['vendedor_id']);
                    ?>
                        <li>
                            <strong><?= esc($p['nome']) ?></strong>
                            — <?= formatarData($p['data']) ?>
                            — Saldo: <strong><?= $p['saldo'] ?></strong> itens em aberto
                            <a href="<?= $urlRelatorio ?>" class="dash-pendencia-link" title="Ver relatório deste dia">
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
        <a href="<?= BASE_URL ?>/pages/saida.php" class="btn-operacao btn-op-saida">
            <span class="btn-op-icone">📤</span>
            <span class="btn-op-label">Registrar Saída</span>
        </a>
        <a href="<?= BASE_URL ?>/pages/retorno.php" class="btn-operacao btn-op-retorno">
            <span class="btn-op-icone">📥</span>
            <span class="btn-op-label">Registrar Retorno</span>
        </a>
        <?php endif; ?>

        <?php if (in_array($_SESSION['usuario_perfil'], ['admin', 'supervisor', 'master'])): ?>
        <a href="<?= BASE_URL ?>/pages/confirmar_venda.php" class="btn-operacao btn-op-venda">
            <span class="btn-op-icone">✅</span>
            <span class="btn-op-label">Confirmar Venda</span>
        </a>
        <a href="<?= BASE_URL ?>/pages/relatorios.php" class="btn-operacao btn-op-relatorio">
            <span class="btn-op-icone">📊</span>
            <span class="btn-op-label">Gerar Relatório</span>
        </a>
        <?php endif; ?>

        <?php if (in_array($_SESSION['usuario_perfil'], ['supervisor', 'master'])): ?>
        <a href="<?= BASE_URL ?>/pages/cadastros.php" class="btn-operacao btn-op-cadastro">
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

        <?php /* Tabs internas do card QR */ ?>
        <div class="dash-qr-abas">
            <?php if (!empty($qrsAguardando)): ?>
            <div class="dash-qr-secao">
                <div class="dash-qr-secao-titulo dash-qr-aguardando">⏳ Aguardando Confirmação</div>
                <div class="tabela-wrapper">
                    <table>
                        <thead>
                            <tr>
                                <th>Vendedor</th>
                                <th style="text-align:center">Tipo</th>
                                <th style="text-align:center">Data Ref.</th>
                                <th style="text-align:center">Expira em</th>
                                <th style="text-align:center">Ação</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($qrsAguardando as $qr): ?>
                        <tr class="dash-tr-aguardando">
                            <td><strong><?= esc($qr['vendedor']) ?></strong></td>
                            <td style="text-align:center">
                                <?= $qr['tipo'] === 'saida' ? '📤 Saída' : '📥 Retorno' ?>
                            </td>
                            <td style="text-align:center"><?= formatarData($qr['data_ref']) ?></td>
                            <td style="text-align:center; font-size:13px; color:var(--cinza-texto)">
                                <?= formatarDataHora($qr['expira_em']) ?>
                            </td>
                            <td style="text-align:center">
                                <a href="<?= BASE_URL ?>/pages/<?= esc($qr['tipo']) ?>.php?etapa=reenviar&vid=<?= (int)$qr['vendedor_id'] ?>&data=<?= esc($qr['data_ref']) ?>"
                                   class="btn btn-acento btn-pequeno">
                                    📲 Reenviar QR
                                </a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php endif; ?>

            <?php if (!empty($qrsRejeitados)): ?>
            <div class="dash-qr-secao">
                <div class="dash-qr-secao-titulo dash-qr-rejeitado">❌ Rejeitados pelo Vendedor</div>
                <div class="tabela-wrapper">
                    <table>
                        <thead>
                            <tr>
                                <th>Vendedor</th>
                                <th style="text-align:center">Tipo</th>
                                <th style="text-align:center">Data Ref.</th>
                                <th>Motivo da Rejeição</th>
                                <th style="text-align:center">Ação</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($qrsRejeitados as $qr): ?>
                        <tr class="dash-tr-rejeitado">
                            <td><strong><?= esc($qr['vendedor']) ?></strong></td>
                            <td style="text-align:center">
                                <?= $qr['tipo'] === 'saida' ? '📤 Saída' : '📥 Retorno' ?>
                            </td>
                            <td style="text-align:center"><?= formatarData($qr['data_ref']) ?></td>
                            <td style="font-size:13px; color:var(--cinza-texto); font-style:italic">
                                <?= $qr['rejeitado_motivo'] ? esc($qr['rejeitado_motivo']) : '—' ?>
                            </td>
                            <td style="text-align:center">
                                <a href="<?= BASE_URL ?>/pages/<?= esc($qr['tipo']) ?>.php?etapa=corrigir&token=<?= esc($qr['token']) ?>"
                                   class="btn btn-vermelho btn-pequeno">
                                    ✏️ Corrigir
                                </a>
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
                    <span class="dash-qr-expirado-aviso">— O vendedor não escaneou o QR Code a tempo. Reregistre o movimento se necessário.</span>
                </div>
                <div class="tabela-wrapper">
                    <table>
                        <thead>
                            <tr>
                                <th>Vendedor</th>
                                <th style="text-align:center">Tipo</th>
                                <th style="text-align:center">Data Ref.</th>
                                <th style="text-align:center">Expirou em</th>
                                <th style="text-align:center">Ação</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($qrsExpirados as $qr): ?>
                        <tr class="dash-tr-expirado">
                            <td><strong><?= esc($qr['vendedor']) ?></strong></td>
                            <td style="text-align:center">
                                <?= $qr['tipo'] === 'saida' ? '📤 Saída' : '📥 Retorno' ?>
                            </td>
                            <td style="text-align:center"><?= formatarData($qr['data_ref']) ?></td>
                            <td style="text-align:center; font-size:13px; color:var(--cinza-texto)">
                                <?= formatarDataHora($qr['expira_em']) ?>
                            </td>
                            <td style="text-align:center">
                                <a href="<?= BASE_URL ?>/pages/<?= esc($qr['tipo']) ?>.php"
                                   class="btn btn-secundario btn-pequeno">
                                    🔁 Novo registro
                                </a>
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

    <?php /* ── Gráfico: Movimentação dos últimos 7 dias (NOVO) ─────── */ ?>
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
                    <span class="dash-leg-cor" style="background:var(--primaria)"></span> Saídas
                </span>
                <span class="dash-leg-item">
                    <span class="dash-leg-cor" style="background:var(--acento)"></span> Retornos
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
                            'aberto'        => 'linha-aberto',
                            'negativo'      => 'linha-negativo',
                            'sem_movimento' => '',
                            default         => '',
                        };
                        [$badgeClasse, $badgeTexto] = match($r['status']) {
                            'zerado'        => ['badge-verde',    '✔ Zerado'],
                            'aberto'        => ['badge-amarelo',  '⏳ Em aberto'],
                            'negativo'      => ['badge-vermelho', '✖ Verificar'],
                            'sem_movimento' => ['badge-cinza',    '— Sem movimento'],
                            default         => ['badge-cinza',    '—'],
                        };
                    ?>
                    <tr class="<?= $classeLinha ?>">
                        <td><strong><?= esc($r['nome']) ?></strong></td>
                        <td style="text-align:center"><?= $r['saidas'] ?></td>
                        <td style="text-align:center"><?= $r['retornos'] ?></td>
                        <td style="text-align:center"><?= $r['vendas'] ?></td>
                        <td style="text-align:center">
                            <span class="<?= classSaldo($r['saldo']) ?>"><?= $r['saldo'] ?></span>
                        </td>
                        <td style="text-align:center">
                            <span class="badge <?= $badgeClasse ?>"><?= $badgeTexto ?></span>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>

                <?php
                    $totalSaidas   = array_sum(array_column($resumos, 'saidas'));
                    $totalRetornos = array_sum(array_column($resumos, 'retornos'));
                    $totalVendas   = array_sum(array_column($resumos, 'vendas'));
                    $totalSaldo    = array_sum(array_column($resumos, 'saldo'));
                ?>
                <tfoot>
                    <tr style="background:#eef0ff; font-weight:bold;">
                        <td>TOTAL GERAL</td>
                        <td style="text-align:center"><?= $totalSaidas ?></td>
                        <td style="text-align:center"><?= $totalRetornos ?></td>
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
                <span class="legenda-bolinha" style="background:#d4edda"></span> Zerado
            </span>
            <span class="legenda-item">
                <span class="legenda-bolinha" style="background:#fffbea"></span> Em aberto
            </span>
            <span class="legenda-item">
                <span class="legenda-bolinha" style="background:#fff0f0; border:1px solid #f5c6cb"></span> Verificar (saldo negativo)
            </span>
            <span class="legenda-item">
                <span class="legenda-bolinha" style="background:#f4f6fa; border:1px solid #ced4da"></span> Sem movimento
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

    // Dimensões responsivas
    function desenhar() {
        const W = canvas.parentElement.offsetWidth;
        const H = 190;
        canvas.width  = W;
        canvas.height = H;

        const PAD   = { top: 20, bottom: 44, left: 10, right: 10 };
        const chartW = W - PAD.left - PAD.right;
        const chartH = H - PAD.top  - PAD.bottom;

        // Máximo para escala
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

        // Linha base
        ctx.clearRect(0, 0, W, H);
        ctx.strokeStyle = '#CED4DA';
        ctx.lineWidth = 1;
        ctx.beginPath();
        ctx.moveTo(PAD.left, H - PAD.bottom);
        ctx.lineTo(W - PAD.right, H - PAD.bottom);
        ctx.stroke();

        // Linhas de grade horizontais (3 níveis)
        ctx.setLineDash([3, 4]);
        ctx.strokeStyle = '#e8eaf0';
        [0.25, 0.5, 0.75].forEach(frac => {
            const y = PAD.top + chartH * (1 - frac);
            ctx.beginPath();
            ctx.moveTo(PAD.left, y);
            ctx.lineTo(W - PAD.right, y);
            ctx.stroke();

            // Valor na grade
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

            // Destaque do dia atual
            if (eHoje) {
                ctx.fillStyle = 'rgba(43,43,136,0.05)';
                ctx.fillRect(PAD.left + diaW * i, PAD.top, diaW, chartH + 1);
            }

            // Barras
            TIPOS.forEach(function (tipo, j) {
                const val  = d[tipo];
                const barH = val > 0 ? Math.max(3, (val / maxVal) * chartH) : 0;
                const x    = cx - grupoW / 2 + j * (barW + gap);
                const y    = H - PAD.bottom - barH;

                ctx.fillStyle = CORES[j];
                ctx.globalAlpha = eHoje ? 1 : 0.72;
                ctx.fillRect(x, y, barW, barH);
                ctx.globalAlpha = 1;

                // Valor no topo da barra (apenas se barH > 12)
                if (barH > 16 && val > 0) {
                    ctx.fillStyle = '#fff';
                    ctx.font = 'bold 9px Arial, sans-serif';
                    ctx.textAlign = 'center';
                    ctx.fillText(val, x + barW / 2, y + 10);
                }
            });

            // Rótulo do dia
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

            // Marcador "hoje"
            if (eHoje) {
                ctx.fillStyle = '#2B2B88';
                ctx.font = 'bold 9px Arial, sans-serif';
                ctx.fillText('▲ hoje', cx, H - PAD.bottom + 38);
            }
        });
    }

    desenhar();

    // Redesenha ao redimensionar a janela
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