<?php
// ============================================================
// pages/vendedor_app.php — App Mobile do Vendedor
// ============================================================
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

verificarPerfil(['vendedor']);

$pdo  = conectar();
$hoje = date('Y-m-d');

// ── Obtém o vendedor_id vinculado ao login ───────────────────────
$stmtU = $pdo->prepare("SELECT vendedor_id FROM usuarios WHERE id = :uid LIMIT 1");
$stmtU->execute([':uid' => $_SESSION['usuario_id']]);
$vendedorId = (int)($stmtU->fetchColumn() ?? 0);

if ($vendedorId <= 0) {
    include __DIR__ . '/../includes/header.php';
    echo '<main><div class="card vapp-sem-vinculo">'
       . '<div class="vapp-sem-vinculo-icone">🔗</div>'
       . '<h2>Conta não vinculada</h2>'
       . '<p>Seu acesso ainda não está vinculado a um vendedor.<br>'
       . 'Solicite ao administrador que faça o vínculo em <strong>Cadastros → Usuários</strong>.</p>'
       . '</div></main>'
       . '<footer>' . SISTEMA_NOME . ' v' . SISTEMA_VERSAO . '</footer>'
       . '<script src="' . BASE_URL . '/assets/js/main.js"></script></body></html>';
    exit;
}

// ── Busca nome do vendedor ───────────────────────────────────────
$stmtV = $pdo->prepare("SELECT nome FROM vendedores WHERE id = :id AND ativo = 1");
$stmtV->execute([':id' => $vendedorId]);
$nomeVendedor = $stmtV->fetchColumn();

if (!$nomeVendedor) {
    include __DIR__ . '/../includes/header.php';
    echo '<main><div class="card vapp-sem-vinculo">'
       . '<div class="vapp-sem-vinculo-icone">⚠️</div>'
       . '<h2>Vendedor inativo</h2>'
       . '<p>O vendedor vinculado à sua conta está inativo. Contate o administrador.</p>'
       . '</div></main>'
       . '<footer>' . SISTEMA_NOME . ' v' . SISTEMA_VERSAO . '</footer>'
       . '<script src="' . BASE_URL . '/assets/js/main.js"></script></body></html>';
    exit;
}

// ── Saldo do dia (só confirmados) ────────────────────────────────
function saldoDia(PDO $pdo, int $vid, string $data): array
{
    $p = [':vid' => $vid, ':data' => $data];

    $st = $pdo->prepare("SELECT COALESCE(SUM(quantidade),0) FROM reg_saidas
        WHERE vendedor_id=:vid AND data=:data AND confirmado=1 AND rejeitado=0");
    $st->execute($p); $saidas = (int)$st->fetchColumn();

    $st = $pdo->prepare("SELECT COALESCE(SUM(quantidade),0) FROM reg_retornos
        WHERE vendedor_id=:vid AND data=:data AND confirmado=1 AND rejeitado=0");
    $st->execute($p); $retornos = (int)$st->fetchColumn();

    $st = $pdo->prepare("SELECT COALESCE(SUM(quantidade),0) FROM reg_vendas
        WHERE vendedor_id=:vid AND data=:data");
    $st->execute($p); $vendas = (int)$st->fetchColumn();

    $saldo = $saidas - $retornos - $vendas;
    return compact('saidas', 'retornos', 'vendas', 'saldo');
}

// ── Saldo por produto hoje (só confirmados) ──────────────────────
function saldoPorProduto(PDO $pdo, int $vid, string $data): array
{
    $p     = [':vid' => $vid, ':data' => $data];
    $prods = [];

    $st = $pdo->prepare("SELECT codigo, produto, SUM(quantidade) AS q FROM reg_saidas
        WHERE vendedor_id=:vid AND data=:data AND confirmado=1 AND rejeitado=0 GROUP BY codigo, produto");
    $st->execute($p);
    foreach ($st->fetchAll() as $r)
        $prods[$r['codigo']] = ['produto'=>$r['produto'], 'saida'=>(int)$r['q'], 'retorno'=>0, 'vendido'=>0, 'saldo'=>0];

    $st = $pdo->prepare("SELECT codigo, produto, SUM(quantidade) AS q FROM reg_retornos
        WHERE vendedor_id=:vid AND data=:data AND confirmado=1 AND rejeitado=0 GROUP BY codigo, produto");
    $st->execute($p);
    foreach ($st->fetchAll() as $r) {
        if (!isset($prods[$r['codigo']])) $prods[$r['codigo']] = ['produto'=>$r['produto'],'saida'=>0,'retorno'=>0,'vendido'=>0,'saldo'=>0];
        $prods[$r['codigo']]['retorno'] += (int)$r['q'];
    }

    $st = $pdo->prepare("SELECT codigo, produto, SUM(quantidade) AS q FROM reg_vendas
        WHERE vendedor_id=:vid AND data=:data GROUP BY codigo, produto");
    $st->execute($p);
    foreach ($st->fetchAll() as $r) {
        if (!isset($prods[$r['codigo']])) $prods[$r['codigo']] = ['produto'=>$r['produto'],'saida'=>0,'retorno'=>0,'vendido'=>0,'saldo'=>0];
        $prods[$r['codigo']]['vendido'] += (int)$r['q'];
    }

    foreach ($prods as &$p2) $p2['saldo'] = $p2['saida'] - $p2['retorno'] - $p2['vendido'];
    unset($p2);
    uasort($prods, fn($a, $b) => abs($b['saldo']) <=> abs($a['saldo']));
    return $prods;
}

// ── Histórico dos últimos 7 dias (só confirmados) ────────────────
function historico7Dias(PDO $pdo, int $vid): array
{
    $inicio = date('Y-m-d', strtotime('-6 days'));
    $hoje   = date('Y-m-d');
    $dias   = [];
    for ($i = 6; $i >= 0; $i--) {
        $d = date('Y-m-d', strtotime("-{$i} days"));
        $dias[$d] = ['data'=>$d,'saidas'=>0,'retornos'=>0,'vendas'=>0,'saldo'=>0,'tem_movimento'=>false];
    }
    $p = [':vid' => $vid, ':ini' => $inicio];

    $st = $pdo->prepare("SELECT data, SUM(quantidade) AS q FROM reg_saidas
        WHERE vendedor_id=:vid AND data>=:ini AND confirmado=1 AND rejeitado=0 GROUP BY data");
    $st->execute($p);
    foreach ($st->fetchAll() as $r)
        if (isset($dias[$r['data']])) { $dias[$r['data']]['saidas']=(int)$r['q']; $dias[$r['data']]['tem_movimento']=true; }

    $st = $pdo->prepare("SELECT data, SUM(quantidade) AS q FROM reg_retornos
        WHERE vendedor_id=:vid AND data>=:ini AND confirmado=1 AND rejeitado=0 GROUP BY data");
    $st->execute($p);
    foreach ($st->fetchAll() as $r)
        if (isset($dias[$r['data']])) { $dias[$r['data']]['retornos']=(int)$r['q']; $dias[$r['data']]['tem_movimento']=true; }

    $st = $pdo->prepare("SELECT data, SUM(quantidade) AS q FROM reg_vendas
        WHERE vendedor_id=:vid AND data>=:ini GROUP BY data");
    $st->execute($p);
    foreach ($st->fetchAll() as $r)
        if (isset($dias[$r['data']])) { $dias[$r['data']]['vendas']=(int)$r['q']; $dias[$r['data']]['tem_movimento']=true; }

    foreach ($dias as &$d) $d['saldo'] = $d['saidas'] - $d['retornos'] - $d['vendas'];
    unset($d);
    return array_values($dias);
}

// ── QR pendentes deste vendedor (3 estados) ──────────────────────
function qrsDoVendedor(PDO $pdo, int $vid): array
{
    // Aguardando confirmação do vendedor
    $st = $pdo->prepare("
        SELECT token, tipo, data_ref, expira_em
        FROM qr_tokens
        WHERE vendedor_id = :vid AND usado = 0 AND rejeitado = 0 AND expira_em > NOW()
        ORDER BY expira_em ASC
    ");
    $st->execute([':vid' => $vid]);
    $ag = $st->fetchAll();

    // Rejeitados pelo vendedor, ainda sem correção
    $st = $pdo->prepare("
        SELECT token, tipo, data_ref, rejeitado_motivo
        FROM qr_tokens
        WHERE vendedor_id = :vid AND rejeitado = 1 AND status = 'rejeitado'
          AND NOT EXISTS (
              SELECT 1 FROM qr_tokens t2
              WHERE t2.vendedor_id = :vid2
                AND t2.data_ref = qr_tokens.data_ref
                AND t2.tipo     = qr_tokens.tipo
                AND t2.id       > qr_tokens.id
                AND t2.status   IN ('pendente','confirmado')
          )
        ORDER BY data_ref DESC
    ");
    $st->execute([':vid' => $vid, ':vid2' => $vid]);
    $rej = $st->fetchAll();

    // Expirados sem confirmação
    $st = $pdo->prepare("
        SELECT token, tipo, data_ref, expira_em
        FROM qr_tokens
        WHERE vendedor_id = :vid AND usado = 0 AND rejeitado = 0 AND expira_em <= NOW()
        ORDER BY expira_em DESC
        LIMIT 10
    ");
    $st->execute([':vid' => $vid]);
    $exp = $st->fetchAll();

    return compact('ag', 'rej', 'exp');
}

// ── Monta os dados ───────────────────────────────────────────────
$resumo    = saldoDia($pdo, $vendedorId, $hoje);
$produtos  = saldoPorProduto($pdo, $vendedorId, $hoje);
$historico = historico7Dias($pdo, $vendedorId);
$qrs       = qrsDoVendedor($pdo, $vendedorId);

$temQrPendente = !empty($qrs['ag']) || !empty($qrs['rej']) || !empty($qrs['exp']);
$temPendencia  = $resumo['saldo'] != 0;
?>
<?php include __DIR__ . '/../includes/header.php'; ?>

<main class="vapp-main">

    <?php /* ── Nome do vendedor ─────────────────────────────────── */ ?>
    <div class="vapp-saldo-header" style="padding-bottom:0; border-bottom:none">
        <div class="vapp-saldo-nome"><?= esc($nomeVendedor) ?></div>
    </div>

    <?php /* ── Pendências QR entre nome e data ────────────────────
           Ordem: rejeitados (urgente) → aguardando → expirados    */ ?>
    <?php if ($temQrPendente): ?>
    <div class="vapp-qr-secoes">

        <?php if (!empty($qrs['rej'])): ?>
        <div class="vapp-qr-grupo vapp-qr-grupo--rejeitado">
            <div class="vapp-qr-grupo-titulo">❌ Rejeitado por você — operador vai corrigir</div>
            <?php foreach ($qrs['rej'] as $qr): ?>
            <div class="vapp-qr-item">
                <div class="vapp-qr-item-info">
                    <span class="vapp-qr-item-tipo"><?= $qr['tipo'] === 'saida' ? '📤 Saída' : '📥 Retorno' ?></span>
                    <span class="vapp-qr-item-data"> <?= formatarData($qr['data_ref']) ?></span>
                    <?php if ($qr['rejeitado_motivo']): ?>
                        <br><span class="vapp-qr-item-motivo">"<?= esc($qr['rejeitado_motivo']) ?>"</span>
                    <?php endif; ?>
                </div>
                <span class="vapp-qr-item-badge vapp-qr-item-badge--rej">Aguard. correção</span>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <?php if (!empty($qrs['ag'])): ?>
        <div class="vapp-qr-grupo vapp-qr-grupo--aguardando">
            <div class="vapp-qr-grupo-titulo">⏳ Aguardando sua confirmação</div>
            <?php foreach ($qrs['ag'] as $qr): ?>
            <div class="vapp-qr-item">
                <div class="vapp-qr-item-info">
                    <span class="vapp-qr-item-tipo"><?= $qr['tipo'] === 'saida' ? '📤 Saída' : '📥 Retorno' ?></span>
                    <span class="vapp-qr-item-data"> <?= formatarData($qr['data_ref']) ?></span>
                    <br><span class="vapp-qr-item-expira" style="font-size:11px; color:var(--cinza-texto)">Expira: <?= formatarDataHora($qr['expira_em']) ?></span>
                </div>
                <a href="<?= BASE_URL ?>/qr/confirmar.php?token=<?= esc($qr['token']) ?>"
                   class="vapp-qr-item-badge vapp-qr-item-badge--ag">Confirmar →</a>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <?php if (!empty($qrs['exp'])): ?>
        <div class="vapp-qr-grupo vapp-qr-grupo--expirado">
            <div class="vapp-qr-grupo-titulo">🕒 QR expirado — aguardando reenvio do operador</div>
            <?php foreach ($qrs['exp'] as $qr): ?>
            <div class="vapp-qr-item">
                <div class="vapp-qr-item-info">
                    <span class="vapp-qr-item-tipo"><?= $qr['tipo'] === 'saida' ? '📤 Saída' : '📥 Retorno' ?></span>
                    <span class="vapp-qr-item-data"> <?= formatarData($qr['data_ref']) ?></span>
                    <br><span class="vapp-qr-item-expira" style="font-size:11px; color:var(--cinza-texto)">Expirou: <?= formatarDataHora($qr['expira_em']) ?></span>
                </div>
                <span class="vapp-qr-item-badge vapp-qr-item-badge--exp">Expirado</span>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

    </div><!-- /.vapp-qr-secoes -->
    <?php endif; ?>

    <?php /* ── Separador visual + data — isola as pendências acima ─ */ ?>
    <div class="vapp-separador-data"></div>
    <div class="vapp-saldo-data">📅 Hoje, <?= date('d/m/Y') ?></div>

    <?php /* ── Card de saldo ──────────────────────────────────────── */ ?>
    <?php
        $corSaldo   = $resumo['saldo'] === 0 ? 'vapp-card-saldo--zero'
            : ($resumo['saldo'] > 0 ? 'vapp-card-saldo--aberto' : 'vapp-card-saldo--negativo');
        $iconeSaldo = $resumo['saldo'] === 0 ? '✅' : ($resumo['saldo'] > 0 ? '⏳' : '⚠️');
        $textoSaldo = $resumo['saldo'] === 0 ? 'Tudo zerado!'
            : ($resumo['saldo'] > 0 ? 'Saldo em aberto' : 'Verificar — saldo negativo');
    ?>
    <div class="vapp-card-saldo <?= $corSaldo ?>">
        <div class="vapp-card-saldo-num"><?= $iconeSaldo ?> <?= $resumo['saldo'] ?></div>
        <div class="vapp-card-saldo-label">itens em saldo — <?= $textoSaldo ?></div>
    </div>

    <div class="vapp-grid-stats">
        <div class="vapp-stat">
            <div class="vapp-stat-num vapp-stat-saida"><?= $resumo['saidas'] ?></div>
            <div class="vapp-stat-label">📤 Saídas</div>
        </div>
        <div class="vapp-stat">
            <div class="vapp-stat-num vapp-stat-retorno"><?= $resumo['retornos'] ?></div>
            <div class="vapp-stat-label">📥 Retornos</div>
        </div>
        <div class="vapp-stat">
            <div class="vapp-stat-num vapp-stat-venda"><?= $resumo['vendas'] ?></div>
            <div class="vapp-stat-label">✅ Vendidos</div>
        </div>
    </div>

    <?php /* ── Saldo por produto ──────────────────────────────────── */ ?>
    <?php if (!empty($produtos)): ?>
    <div class="vapp-secao">
        <div class="vapp-secao-titulo">📦 Saldo por Produto — Hoje</div>
        <div class="vapp-lista-produtos">
            <?php foreach ($produtos as $cod => $prod):
                $classeProd = $prod['saldo'] === 0 ? 'vapp-prod--zero'
                    : ($prod['saldo'] > 0 ? 'vapp-prod--aberto' : 'vapp-prod--negativo');
                $badgeSaldo = $prod['saldo'] === 0 ? '<span class="badge badge-verde">✓ OK</span>'
                    : ($prod['saldo'] > 0
                        ? '<span class="badge badge-amarelo">' . $prod['saldo'] . ' pendente</span>'
                        : '<span class="badge badge-vermelho">' . abs($prod['saldo']) . ' negativo</span>');
            ?>
            <div class="vapp-prod <?= $classeProd ?>">
                <div class="vapp-prod-info">
                    <div class="vapp-prod-nome"><?= esc($prod['produto']) ?></div>
                    <div class="vapp-prod-cod"><?= esc($cod) ?></div>
                    <div class="vapp-prod-nums">
                        <span>↑<?= $prod['saida'] ?></span>
                        <span>↓<?= $prod['retorno'] ?></span>
                        <span>✓<?= $prod['vendido'] ?></span>
                    </div>
                </div>
                <div class="vapp-prod-saldo"><?= $badgeSaldo ?></div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php else: ?>
    <div class="vapp-secao">
        <div class="vapp-vazio">
            <div class="vapp-vazio-icone">📭</div>
            <p>Nenhum movimento registrado hoje.</p>
        </div>
    </div>
    <?php endif; ?>

    <?php /* ── Histórico 7 dias ──────────────────────────────────── */ ?>
    <div class="vapp-secao">
        <div class="vapp-secao-titulo">📅 Últimos 7 Dias</div>
        <div class="vapp-historico">
            <?php
            $diasSemana = ['Dom','Seg','Ter','Qua','Qui','Sex','Sáb'];
            foreach ($historico as $dia):
                $dataObj  = new DateTime($dia['data']);
                $nomeDia  = $diasSemana[$dataObj->format('w')];
                $eHoje    = ($dia['data'] === $hoje);
                $classDia = $eHoje ? 'vapp-dia vapp-dia--hoje' : 'vapp-dia';
                if ($dia['tem_movimento']) {
                    if ($dia['saldo'] === 0)   $classDia .= ' vapp-dia--zero';
                    elseif ($dia['saldo'] > 0) $classDia .= ' vapp-dia--aberto';
                    else                       $classDia .= ' vapp-dia--negativo';
                }
            ?>
            <div class="<?= $classDia ?>">
                <div class="vapp-dia-nome"><?= $nomeDia ?><?= $eHoje ? ' <span class="vapp-dia-hoje-tag">hoje</span>' : '' ?></div>
                <div class="vapp-dia-data"><?= $dataObj->format('d/m') ?></div>
                <?php if ($dia['tem_movimento']): ?>
                    <div class="vapp-dia-saldo">
                        <?php if ($dia['saldo'] === 0): ?>
                            <span style="color:var(--verde); font-size:18px">✓</span>
                        <?php else: ?>
                            <span class="vapp-dia-saldo-num"><?= $dia['saldo'] ?></span>
                        <?php endif; ?>
                    </div>
                    <div class="vapp-dia-detalhe">
                        <span title="Saídas">↑<?= $dia['saidas'] ?></span>
                        <span title="Retornos">↓<?= $dia['retornos'] ?></span>
                        <span title="Vendas">✓<?= $dia['vendas'] ?></span>
                    </div>
                <?php else: ?>
                    <div class="vapp-dia-vazio">—</div>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
        <div class="vapp-historico-legenda">
            <span><span class="vapp-leg-cor" style="background:#d4edda"></span> Zerado</span>
            <span><span class="vapp-leg-cor" style="background:#fffbea"></span> Em aberto</span>
            <span><span class="vapp-leg-cor" style="background:#fff0f0"></span> Verificar</span>
        </div>
    </div>

    <?php /* ── Rodapé ───────────────────────────────────────────── */ ?>
    <div class="vapp-footer-info">
        <button onclick="location.reload()" class="vapp-btn-atualizar">🔄 Atualizar</button>
        <span class="vapp-footer-hora">Atualizado às <?= date('H:i') ?></span>
    </div>

</main>

<footer><?= SISTEMA_NOME ?> v<?= SISTEMA_VERSAO ?></footer>

<script src="<?= BASE_URL ?>/assets/js/main.js"></script>
<script>
setTimeout(function () { location.reload(); }, 120000);
</script>

</body>
</html>