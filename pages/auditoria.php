<?php
// ============================================================
// pages/auditoria.php — Auditoria e Log do Sistema
// Salvar em: C:\xampp\htdocs\sistema_csr\pages\auditoria.php
// ============================================================
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

verificarPerfil(['supervisor', 'master']);

$pdo = conectar();

// ── Ações críticas e de aviso (usadas para highlight) ───────────
$acoesCriticas = [
    'LOGIN_BLOQUEADO', 'LOGIN_FALHA', 'MANUTENCAO_ATIVADA',
    'EXCLUSAO', 'SAIDA_FORA_HORARIO', 'ACESSO_NEGADO',
];
$acoesAviso = [
    'SAIDA_CORRIGIDA', 'QR_REENVIADO', 'MANUTENCAO_DESATIVADA',
    'REJEICAO', 'RESET_SESSAO',
];

// ── Filtros ──────────────────────────────────────────────────────
$dataInicio = $_GET['data_inicio'] ?? date('Y-m-d', strtotime('-7 days'));
$dataFim    = $_GET['data_fim']    ?? date('Y-m-d');
$acaoFiltro = trim($_GET['acao']   ?? '');
$busca      = trim($_GET['busca']  ?? '');
$pagina     = max(1, (int)($_GET['pagina'] ?? 1));
$porPagina  = 50;

// Garante formato de data válido
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dataInicio)) {
    $dataInicio = date('Y-m-d', strtotime('-7 days'));
}
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dataFim)) {
    $dataFim = date('Y-m-d');
}
if ($dataFim < $dataInicio) {
    $dataFim = $dataInicio;
}

// ── Monta cláusula WHERE reutilizável ────────────────────────────
$where  = " WHERE DATE(data_hora) BETWEEN :di AND :df ";
$params = [':di' => $dataInicio, ':df' => $dataFim];

if ($acaoFiltro !== '') {
    $where .= " AND tipo = :acao ";
    $params[':acao'] = $acaoFiltro;
}
if ($busca !== '') {
    $where .= " AND (descricao LIKE :busca OR ip LIKE :busca2 OR tipo LIKE :busca3) ";
    $params[':busca']  = "%$busca%";
    $params[':busca2'] = "%$busca%";
    $params[':busca3'] = "%$busca%";
}

// ── Contagem total (para paginação) ─────────────────────────────
$stmtTotal = $pdo->prepare("SELECT COUNT(*) FROM log_sistema" . $where);
$stmtTotal->execute($params);
$total    = (int)$stmtTotal->fetchColumn();
$totalPag = max(1, (int)ceil($total / $porPagina));
$pagina   = min($pagina, $totalPag);
$offset   = ($pagina - 1) * $porPagina;

// ── Busca registros paginados ────────────────────────────────────
$stmtLog = $pdo->prepare(
    "SELECT id, tipo, descricao, ip, data_hora
     FROM log_sistema" . $where .
    " ORDER BY id DESC
     LIMIT :lim OFFSET :off"
);
foreach ($params as $k => $v) {
    $stmtLog->bindValue($k, $v);
}
$stmtLog->bindValue(':lim', $porPagina, PDO::PARAM_INT);
$stmtLog->bindValue(':off', $offset,    PDO::PARAM_INT);
$stmtLog->execute();
$registros = $stmtLog->fetchAll();

// ── Stats: contagem por ação no período ─────────────────────────
$stmtStats = $pdo->prepare(
    "SELECT tipo, COUNT(*) AS qtd
     FROM log_sistema" . $where .
    " GROUP BY tipo
     ORDER BY qtd DESC
     LIMIT 8"
);
$stmtStats->execute($params);
$statsAcoes = $stmtStats->fetchAll();

// ── Conta ações críticas no período ─────────────────────────────
$totalCriticas = 0;
foreach ($registros as $r) {
    // Conta no conjunto total (não só na página atual)
}
// Busca contagem real de críticas no período completo
$inPlaceholders = [];
$paramsCrit = $params;

foreach ($acoesCriticas as $i => $ac) {
    $key = ":crit$i";
    $inPlaceholders[] = $key;
    $paramsCrit[$key] = $ac;
}
$stmtCrit = $pdo->prepare(
    "SELECT COUNT(*) FROM log_sistema" . $where .
    " AND tipo IN (" . implode(',', $inPlaceholders) . ")"
);

$stmtCrit->execute($paramsCrit);
$totalCriticas = (int)$stmtCrit->fetchColumn();

// ── Lista de ações distintas para o select de filtro ────────────
$acoes = $pdo->query(
    "SELECT DISTINCT tipo FROM log_sistema ORDER BY tipo"
)->fetchAll(PDO::FETCH_COLUMN);

// ── Helper: classifica a linha ────────────────────────────────────
function classificarAcao(string $acao, array $criticas, array $avisos): string {
    if (in_array($acao, $criticas)) return 'critica';
    if (in_array($acao, $avisos))   return 'aviso';
    return 'normal';
}

// ── Helper: label amigável para cada ação ───────────────────────
function labelAcao(string $acao): string {
    $labels = [
        'SAIDA'                => 'Saída',
        'SAIDA_CORRIGIDA'      => 'Saída Corrigida',
        'SAIDA_FORA_HORARIO'   => 'Saída Fora Horário',
        'QR_REENVIADO'         => 'QR Reenviado',
        'RETORNO'              => 'Retorno',
        'VENDA'                => 'Venda',
        'LOGIN'                => 'Login',
        'LOGIN_FALHA'          => 'Falha de Login',
        'LOGIN_BLOQUEADO'      => 'Login Bloqueado',
        'LOGOUT'               => 'Logout',
        'MANUTENCAO_ATIVADA'   => 'Manutenção Ativada',
        'MANUTENCAO_DESATIVADA'=> 'Manutenção Desativada',
        'EXCLUSAO'             => 'Exclusão',
        'ACESSO_NEGADO'        => 'Acesso Negado',
        'RESET_SESSAO'         => 'Reset de Sessão',
        'REJEICAO'             => 'Rejeição',
        'CADASTRO'             => 'Cadastro',
        'EDICAO'               => 'Edição',
    ];
    return $labels[$acao] ?? $acao;
}

// ── Monta parâmetros de query para links de paginação/export ────
$queryBase = http_build_query([
    'data_inicio' => $dataInicio,
    'data_fim'    => $dataFim,
    'acao'        => $acaoFiltro,
    'busca'       => $busca,
]);
?>
<?php include __DIR__ . '/../includes/header.php'; ?>

<div class="page-top">
    <h2>🔍 Auditoria e Log do Sistema</h2>
    <p>Histórico de ações registradas — visualização e exportação por período</p>
</div>

<main>

    <?php /* ── FILTROS ──────────────────────────────────────────── */ ?>
    <div class="card">
        <div class="card-titulo">🔎 Filtros</div>
        <form method="get" class="audit-filtros" id="form-filtros">
            <div class="grupo-campo">
                <label>Data início</label>
                <input type="date" name="data_inicio" class="campo"
                       value="<?= esc($dataInicio) ?>" max="<?= date('Y-m-d') ?>">
            </div>
            <div class="grupo-campo">
                <label>Data fim</label>
                <input type="date" name="data_fim" class="campo"
                       value="<?= esc($dataFim) ?>" max="<?= date('Y-m-d') ?>">
            </div>
            <div class="grupo-campo">
                <label>Tipo de ação</label>
                <select name="acao" class="campo">
                    <option value="">— Todas —</option>
                    <?php foreach ($acoes as $ac): ?>
                        <option value="<?= esc($ac) ?>"
                            <?= $acaoFiltro === $ac ? 'selected' : '' ?>>
                            <?= esc(labelAcao($ac)) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="grupo-campo">
                <label>Busca livre</label>
                <input type="text" name="busca" class="campo"
                       placeholder="Descrição ou IP..."
                       value="<?= esc($busca) ?>" maxlength="100">
            </div>
            <div class="audit-filtros-btns">
                <button type="submit" class="btn btn-primario">🔍 Filtrar</button>
                <a href="<?= BASE_URL ?>/pages/auditoria.php" class="btn btn-secundario">✕ Limpar</a>
            </div>
        </form>
    </div>

    <?php /* ── STATS ────────────────────────────────────────────── */ ?>
    <div class="audit-stats">
        <div class="audit-stat-card">
            <div class="audit-stat-num"><?= number_format($total) ?></div>
            <div class="audit-stat-label">Registros no período</div>
        </div>
        <div class="audit-stat-card audit-stat-critica">
            <div class="audit-stat-num"><?= number_format($totalCriticas) ?></div>
            <div class="audit-stat-label">Ações críticas</div>
        </div>
        <div class="audit-stat-card">
            <div class="audit-stat-num">
                <?= formatarData($dataInicio) ?> – <?= formatarData($dataFim) ?>
            </div>
            <div class="audit-stat-label">Período analisado</div>
        </div>
        <div class="audit-stat-card audit-stat-top">
            <div class="audit-stat-num">
                <?= !empty($statsAcoes) ? esc(labelAcao($statsAcoes[0]['tipo'])) : '—' ?>
            </div>
            <div class="audit-stat-label">Ação mais frequente</div>
        </div>
    </div>

    <?php /* ── AÇÕES MAIS FREQUENTES (mini gráfico de barras) ─── */ ?>
    <?php if (!empty($statsAcoes)): ?>
    <div class="card">
        <div class="card-titulo">📊 Distribuição de Ações no Período</div>
        <div class="audit-barras">
            <?php
            $maxQtd = max(array_column($statsAcoes, 'qtd'));
            foreach ($statsAcoes as $s):
                $pct   = $maxQtd > 0 ? round(($s['qtd'] / $maxQtd) * 100) : 0;
                $classe = classificarAcao($s['tipo'], $acoesCriticas, $acoesAviso);
            ?>
            <div class="audit-barra-row">
                <div class="audit-barra-label">
                    <span class="badge audit-badge-<?= $classe ?>">
                        <?= esc(labelAcao($s['tipo'])) ?>
                    </span>
                </div>
                <div class="audit-barra-track">
                    <div class="audit-barra-fill audit-barra-<?= $classe ?>"
                         style="width:<?= $pct ?>%"></div>
                </div>
                <div class="audit-barra-qtd"><?= $s['qtd'] ?></div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <?php /* ── TABELA DE LOG ────────────────────────────────────── */ ?>
    <div class="card">
        <div class="card-titulo" style="display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:10px">
            <span>📋 Registros
                <?php if ($total > 0): ?>
                    <small style="font-weight:normal; color:var(--cinza-texto)">
                        — exibindo <?= ($offset + 1) ?>–<?= min($offset + $porPagina, $total) ?> de <?= number_format($total) ?>
                    </small>
                <?php endif; ?>
            </span>
            <?php if ($total > 0): ?>
            <a href="<?= BASE_URL ?>/api/exportar_log_csv.php?<?= $queryBase ?>"
               class="btn btn-verde" style="font-size:13px; padding:7px 16px">
                ⬇ Exportar CSV
            </a>
            <?php endif; ?>
        </div>

        <?php if (empty($registros)): ?>
            <div class="alerta alerta-info">
                Nenhum registro encontrado para os filtros selecionados.
            </div>
        <?php else: ?>

        <div class="tabela-wrapper">
            <table>
                <thead>
                    <tr>
                        <th style="width:50px">#</th>
                        <th style="width:150px">Data/Hora</th>
                        <th style="width:180px">Ação</th>
                        <th>Descrição</th>
                        <th style="width:130px">IP</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($registros as $reg):
                        $classe = classificarAcao($reg['tipo'], $acoesCriticas, $acoesAviso);
                    ?>
                    <tr class="audit-linha-<?= $classe ?>"
                        title="<?= $classe === 'critica' ? '⚠ Ação crítica' : ($classe === 'aviso' ? '⚡ Ação de aviso' : '') ?>">
                        <td style="color:var(--cinza-texto); font-size:12px"><?= $reg['id'] ?></td>
                        <td style="font-size:13px; white-space:nowrap">
                            <?= esc(formatarDataHora($reg['data_hora'])) ?>
                        </td>
                        <td>
                            <span class="badge audit-badge-<?= $classe ?>">
                                <?php if ($classe === 'critica'): ?>🔴<?php elseif ($classe === 'aviso'): ?>🟡<?php else: ?>🔵<?php endif; ?>
                                <?= esc(labelAcao($reg['tipo'])) ?>
                            </span>
                        </td>
                        <td style="font-size:13px"><?= esc($reg['descricao']) ?></td>
                        <td style="font-size:12px; color:var(--cinza-texto); font-family:monospace">
                            <?= esc($reg['ip'] ?? '—') ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?php /* ── PAGINAÇÃO ─────────────────────────────────────── */ ?>
        <?php if ($totalPag > 1): ?>
        <div class="audit-paginacao">
            <?php if ($pagina > 1): ?>
                <a href="?<?= $queryBase ?>&pagina=1" class="btn btn-secundario" style="padding:6px 12px; font-size:13px">« Primeira</a>
                <a href="?<?= $queryBase ?>&pagina=<?= $pagina - 1 ?>" class="btn btn-secundario" style="padding:6px 12px; font-size:13px">‹ Anterior</a>
            <?php endif; ?>

            <span class="audit-pag-info">
                Página <?= $pagina ?> de <?= $totalPag ?>
            </span>

            <?php if ($pagina < $totalPag): ?>
                <a href="?<?= $queryBase ?>&pagina=<?= $pagina + 1 ?>" class="btn btn-secundario" style="padding:6px 12px; font-size:13px">Próxima ›</a>
                <a href="?<?= $queryBase ?>&pagina=<?= $totalPag ?>" class="btn btn-secundario" style="padding:6px 12px; font-size:13px">Última »</a>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <?php endif; /* fim: registros não vazios */ ?>

        <?php /* ── LEGENDA ───────────────────────────────────────── */ ?>
        <div class="audit-legenda">
            <span><span class="badge audit-badge-critica">🔴 Crítica</span> Requer atenção imediata</span>
            <span><span class="badge audit-badge-aviso">🟡 Aviso</span> Ação relevante</span>
            <span><span class="badge audit-badge-normal">🔵 Normal</span> Operação regular</span>
        </div>
    </div>

</main>

<footer>
    <?= SISTEMA_NOME ?> v<?= SISTEMA_VERSAO ?>
    <?php if ($_SESSION['usuario_perfil'] === 'master'): ?>
        &nbsp;|&nbsp; <a href="<?= BASE_URL ?>/pages/admin.php">⚙ Admin</a>
    <?php endif; ?>
</footer>

<script src="<?= BASE_URL ?>/assets/js/main.js"></script>
<script>
const BASE_URL = '<?= BASE_URL ?>';

// Atalho: Enter no campo busca dispara o form
document.getElementById('form-filtros')
    .querySelectorAll('input')
    .forEach(el => {
        el.addEventListener('keydown', e => {
            if (e.key === 'Enter') {
                e.preventDefault();
                document.getElementById('form-filtros').submit();
            }
        });
    });

// Garante data_fim >= data_inicio
const dInicio = document.querySelector('[name="data_inicio"]');
const dFim    = document.querySelector('[name="data_fim"]');
dInicio.addEventListener('change', () => {
    if (dFim.value < dInicio.value) dFim.value = dInicio.value;
});
dFim.addEventListener('change', () => {
    if (dFim.value < dInicio.value) dInicio.value = dFim.value;
});
</script>

</body>
</html>