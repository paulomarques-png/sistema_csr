<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

verificarLogin();

$pdo  = conectar();
$hoje = date('Y-m-d');

// ── Busca todos os vendedores ativos ─────────────────────────────
$vendedores = $pdo->query("
    SELECT id, nome FROM vendedores WHERE ativo = 1 ORDER BY nome
")->fetchAll();

// ── Monta o painel de resumo de HOJE por vendedor ────────────────
// Para cada vendedor: soma saídas, retornos e vendas confirmadas do dia
function resumoVendedor(PDO $pdo, int $vendedorId, string $data): array {
    // Total de itens saídos hoje (confirmados ou não)
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(quantidade), 0) AS total
        FROM reg_saidas
        WHERE vendedor_id = :vid AND data = :data
    ");
    $stmt->execute([':vid' => $vendedorId, ':data' => $data]);
    $saidas = (int) $stmt->fetchColumn();

    // Total retornado hoje
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(quantidade), 0) AS total
        FROM reg_retornos
        WHERE vendedor_id = :vid AND data = :data
    ");
    $stmt->execute([':vid' => $vendedorId, ':data' => $data]);
    $retornos = (int) $stmt->fetchColumn();

    // Total de vendas confirmadas hoje
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(quantidade), 0) AS total
        FROM reg_vendas
        WHERE vendedor_id = :vid AND data = :data
    ");
    $stmt->execute([':vid' => $vendedorId, ':data' => $data]);
    $vendas = (int) $stmt->fetchColumn();

    // Saldo = saídas - retornos - vendas confirmadas
    $saldo = $saidas - $retornos - $vendas;

    // Status baseado no saldo e movimento
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

// ── Busca pendências de dias anteriores ──────────────────────────
// Vendedores que têm saldo > 0 em datas anteriores a hoje
function buscarPendencias(PDO $pdo, string $hoje): array {
    $stmt = $pdo->query("
        SELECT
            v.nome,
            s.data,
            (
                COALESCE((SELECT SUM(q.quantidade) FROM reg_saidas   q WHERE q.vendedor_id = v.id AND q.data = s.data), 0) -
                COALESCE((SELECT SUM(r.quantidade) FROM reg_retornos r WHERE r.vendedor_id = v.id AND r.data = s.data), 0) -
                COALESCE((SELECT SUM(vd.quantidade)FROM reg_vendas   vd WHERE vd.vendedor_id = v.id AND vd.data = s.data), 0)
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

$pendencias = buscarPendencias($pdo, $hoje);

// ── Monta resumo de todos os vendedores ──────────────────────────
$resumos = [];
foreach ($vendedores as $v) {
    $resumos[$v['id']] = array_merge(
        $v,
        resumoVendedor($pdo, (int)$v['id'], $hoje)
    );
}

// ── Status de horário atual ──────────────────────────────────────
$statusSaida   = verificarHorario('saida');
$statusRetorno = verificarHorario('retorno');
$statusVenda   = verificarHorario('venda');
?>
<?php include __DIR__ . '/../includes/header.php'; ?>

<main>

    <?php /* ── Alerta de pendências ────────────────────────── */ ?>
    <?php if (!empty($pendencias)): ?>
    <div class="alerta alerta-aviso" style="display:flex; align-items:flex-start; gap:10px;">
        <span style="font-size:22px; line-height:1">⚠️</span>
        <div>
            <strong><?= count($pendencias) ?> pendência(s) de dias anteriores:</strong>
            <ul style="margin-top:6px; padding-left:18px; font-size:13px;">
                <?php foreach ($pendencias as $p): ?>
                    <li>
                        <strong><?= esc($p['nome']) ?></strong>
                        — <?= formatarData($p['data']) ?>
                        — Saldo: <strong><?= $p['saldo'] ?></strong> itens em aberto
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
    </div>
    <?php endif; ?>

    <?php /* ── Banner de status de horário ─────────────────── */ ?>
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

    <?php /* ── Botões de operação ────────────────────────────── */ ?>
    <div class="botoes-operacao">

        <?php if (in_array($_SESSION['usuario_perfil'], ['operador','supervisor','master'])): ?>
        <a href="<?= BASE_URL ?>/pages/saida.php" class="btn-operacao btn-op-saida">
            <span class="btn-op-icone">📤</span>
            <span class="btn-op-label">Registrar Saída</span>
        </a>
        <a href="<?= BASE_URL ?>/pages/retorno.php" class="btn-operacao btn-op-retorno">
            <span class="btn-op-icone">📥</span>
            <span class="btn-op-label">Registrar Retorno</span>
        </a>
        <?php endif; ?>

        <?php if (in_array($_SESSION['usuario_perfil'], ['admin','supervisor','master'])): ?>
        <a href="<?= BASE_URL ?>/pages/venda.php" class="btn-operacao btn-op-venda">
            <span class="btn-op-icone">✅</span>
            <span class="btn-op-label">Confirmar Venda</span>
        </a>
        <a href="<?= BASE_URL ?>/pages/relatorio.php" class="btn-operacao btn-op-relatorio">
            <span class="btn-op-icone">📊</span>
            <span class="btn-op-label">Gerar Relatório</span>
        </a>
        <?php endif; ?>

        <?php if (in_array($_SESSION['usuario_perfil'], ['supervisor','master'])): ?>
        <a href="<?= BASE_URL ?>/pages/cadastros.php" class="btn-operacao btn-op-cadastro">
            <span class="btn-op-icone">📋</span>
            <span class="btn-op-label">Cadastros</span>
        </a>
        <?php endif; ?>

    </div>

    <?php /* ── Painel de resumo por vendedor ─────────────────── */ ?>
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
                <?php if (in_array($_SESSION['usuario_perfil'], ['supervisor','master'])): ?>
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
                        // Define classe da linha conforme status
                        $classeLinha = match($r['status']) {
                            'zerado'       => 'linha-zero',
                            'aberto'       => 'linha-aberto',
                            'negativo'     => 'linha-negativo',
                            'sem_movimento'=> '',
                            default        => '',
                        };

                        // Badge de status
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
                            <span class="<?= classSaldo($r['saldo']) ?>">
                                <?= $r['saldo'] ?>
                            </span>
                        </td>
                        <td style="text-align:center">
                            <span class="badge <?= $badgeClasse ?>"><?= $badgeTexto ?></span>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>

                <?php /* ── Linha de totais gerais ───────── */ ?>
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

        <?php /* ── Legenda de cores ──────────────────────── */ ?>
        <div class="legenda-cores">
            <span class="legenda-item">
                <span class="legenda-bolinha" style="background:#d4edda"></span> Zerado
            </span>
            <span class="legenda-item">
                <span class="legenda-bolinha" style="background:#fffbea"></span> Em aberto
            </span>
            <span class="legenda-item">
                <span class="legenda-bolinha" style="background:#fff0f0"></span> Verificar (saldo negativo)
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
// Atualiza o painel automaticamente a cada 60 segundos
setTimeout(function() { location.reload(); }, 60000);
</script>

</body>
</html>