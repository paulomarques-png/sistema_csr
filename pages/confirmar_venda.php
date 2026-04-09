<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

verificarPerfil(['administrativo', 'supervisor', 'master']);

$pdo  = conectar();
$erro = '';
$msg  = '';

// ── Parâmetros de filtro ─────────────────────────────────────────
$vendedorId  = (int)($_GET['vendedor_id'] ?? $_POST['vendedor_id'] ?? 0);
$dataFiltro  = $_GET['data'] ?? $_POST['data'] ?? date('Y-m-d');

// ── Lista de vendedores para o select ───────────────────────────
$vendedores = $pdo->query("SELECT id, nome FROM vendedores WHERE ativo = 1 ORDER BY nome ASC")->fetchAll();

// ── PROCESSAMENTO: Adicionar pedido ─────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['acao']) && $_POST['acao'] === 'add_pedido') {

    $vendedorId  = (int)($_POST['vendedor_id'] ?? 0);
    $dataFiltro  = $_POST['data']   ?? date('Y-m-d');
    $pedidoNum   = trim($_POST['pedido'] ?? '');
    $obs         = trim($_POST['obs']    ?? '');
    $codigos     = $_POST['codigo']      ?? [];
    $produtosArr = $_POST['produto']     ?? [];
    $quantidades = $_POST['quantidade']  ?? [];

    if ($vendedorId <= 0) {
        $erro = 'Selecione um vendedor.';
    } elseif (empty($pedidoNum)) {
        $erro = 'Informe o número do pedido de venda.';
    } else {
        // Monta itens válidos
        $itens = [];
        for ($i = 0; $i < count($codigos); $i++) {
            $cod = trim($codigos[$i]     ?? '');
            $prd = trim($produtosArr[$i] ?? '');
            $qty = (int)($quantidades[$i] ?? 0);
            if ($cod !== '' && $prd !== '' && $qty > 0) {
                $itens[] = ['codigo' => $cod, 'produto' => $prd, 'quantidade' => $qty];
            }
        }

        if (empty($itens)) {
            $erro = 'Adicione pelo menos um produto com quantidade válida.';
        } else {
            // Verifica se pedido já foi lançado para esse vendedor+data
            $stmtChk = $pdo->prepare("
                SELECT COUNT(*) FROM reg_vendas
                WHERE vendedor_id = :vid AND data = :data AND pedido = :pedido
            ");
            $stmtChk->execute([':vid' => $vendedorId, ':data' => $dataFiltro, ':pedido' => $pedidoNum]);
            if ($stmtChk->fetchColumn() > 0) {
                $erro = "O pedido \"$pedidoNum\" já foi lançado para este vendedor nesta data.";
            }
        }
    }

    if (empty($erro) && !empty($itens)) {
        // Busca nome do vendedor
        $stmtV = $pdo->prepare("SELECT nome FROM vendedores WHERE id = :id");
        $stmtV->execute([':id' => $vendedorId]);
        $nomeVend = $stmtV->fetchColumn();

        $agora = date('H:i:s');
        $stmtIns = $pdo->prepare("
            INSERT INTO reg_vendas (data, hora, vendedor_id, vendedor, codigo, produto, quantidade, pedido, obs)
            VALUES (:data, :hora, :vid, :vendedor, :codigo, :produto, :quantidade, :pedido, :obs)
        ");
        foreach ($itens as $item) {
            $stmtIns->execute([
                ':data'       => $dataFiltro,
                ':hora'       => $agora,
                ':vid'        => $vendedorId,
                ':vendedor'   => $nomeVend,
                ':codigo'     => $item['codigo'],
                ':produto'    => $item['produto'],
                ':quantidade' => $item['quantidade'],
                ':pedido'     => $pedidoNum,
                ':obs'        => $obs,
            ]);
        }
        registrarLog('VENDA_CONFIRMADA',
            "Vendedor: $nomeVend | Pedido: $pedidoNum | " . count($itens) . " item(ns) | Data: $dataFiltro",
            obterIP());
        $msg = "Pedido \"$pedidoNum\" lançado com sucesso!";
    }
}

// ── PROCESSAMENTO: Excluir pedido ───────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['acao']) && $_POST['acao'] === 'del_pedido') {
    $pedidoDel  = trim($_POST['pedido_del'] ?? '');
    $vendedorId = (int)($_POST['vendedor_id'] ?? 0);
    $dataFiltro = $_POST['data'] ?? date('Y-m-d');

    if ($pedidoDel && $vendedorId > 0) {
        $stmtDel = $pdo->prepare("
            DELETE FROM reg_vendas WHERE vendedor_id = :vid AND data = :data AND pedido = :pedido
        ");
        $stmtDel->execute([':vid' => $vendedorId, ':data' => $dataFiltro, ':pedido' => $pedidoDel]);
        registrarLog('VENDA_EXCLUIDA', "Pedido: $pedidoDel | Vendedor ID: $vendedorId | Data: $dataFiltro", obterIP());
        $msg = "Pedido \"$pedidoDel\" removido.";
    }
}

// ── Dados do vendedor selecionado ────────────────────────────────
$nomeVendedorSel = '';
if ($vendedorId > 0) {
    $stmtNm = $pdo->prepare("SELECT nome FROM vendedores WHERE id = :id");
    $stmtNm->execute([':id' => $vendedorId]);
    $nomeVendedorSel = $stmtNm->fetchColumn() ?: '';
}

// ── Resumo: Saídas confirmadas ───────────────────────────────────
$resumoSaidas = [];
if ($vendedorId > 0) {
    $stmtS = $pdo->prepare("
        SELECT codigo, produto, SUM(quantidade) AS total
        FROM reg_saidas
        WHERE vendedor_id = :vid AND data = :data AND confirmado = 1 AND rejeitado = 0
        GROUP BY codigo, produto
        ORDER BY produto ASC
    ");
    $stmtS->execute([':vid' => $vendedorId, ':data' => $dataFiltro]);
    foreach ($stmtS->fetchAll() as $row) {
        $resumoSaidas[$row['codigo']] = [
            'produto'  => $row['produto'],
            'saida'    => (int)$row['total'],
            'retorno'  => 0,
            'vendido'  => 0,
        ];
    }

    // ── Resumo: Retornos confirmados ─────────────────────────────
    $stmtR = $pdo->prepare("
        SELECT codigo, SUM(quantidade) AS total
        FROM reg_retornos
        WHERE vendedor_id = :vid AND data = :data AND confirmado = 1 AND rejeitado = 0
        GROUP BY codigo
    ");
    $stmtR->execute([':vid' => $vendedorId, ':data' => $dataFiltro]);
    foreach ($stmtR->fetchAll() as $row) {
        if (isset($resumoSaidas[$row['codigo']])) {
            $resumoSaidas[$row['codigo']]['retorno'] = (int)$row['total'];
        }
    }

    // ── Resumo: Vendas já lançadas ────────────────────────────────
    $stmtVnd = $pdo->prepare("
        SELECT codigo, SUM(quantidade) AS total
        FROM reg_vendas
        WHERE vendedor_id = :vid AND data = :data
        GROUP BY codigo
    ");
    $stmtVnd->execute([':vid' => $vendedorId, ':data' => $dataFiltro]);
    foreach ($stmtVnd->fetchAll() as $row) {
        if (isset($resumoSaidas[$row['codigo']])) {
            $resumoSaidas[$row['codigo']]['vendido'] = (int)$row['total'];
        }
    }
}

// ── Pedidos já lançados (agrupados por número de pedido) ─────────
$pedidosLancados = [];
if ($vendedorId > 0) {
    $stmtPed = $pdo->prepare("
        SELECT pedido, SUM(quantidade) AS total_itens, COUNT(*) AS linhas,
               GROUP_CONCAT(produto, ' (', quantidade, ')' ORDER BY produto SEPARATOR ' | ') AS descricao
        FROM reg_vendas
        WHERE vendedor_id = :vid AND data = :data
        GROUP BY pedido
        ORDER BY pedido ASC
    ");
    $stmtPed->execute([':vid' => $vendedorId, ':data' => $dataFiltro]);
    $pedidosLancados = $stmtPed->fetchAll();
}

// ── Totais gerais ────────────────────────────────────────────────
$totalSaida   = array_sum(array_column($resumoSaidas, 'saida'));
$totalRetorno = array_sum(array_column($resumoSaidas, 'retorno'));
$totalVendido = array_sum(array_column($resumoSaidas, 'vendido'));
$totalSaldo   = $totalSaida - $totalRetorno - $totalVendido;

// ── Horário de acesso ─────────────────────────────────────────────
$statusHorario = verificarHorario('confirmar_venda');
$bloqueado = ($statusHorario === 'bloqueado'
    && !in_array($_SESSION['usuario_perfil'], ['supervisor', 'master']));

require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-top">
    <h2>✅ Confirmar Venda</h2>
    <p>Lançamento de pedidos de venda e fechamento diário por vendedor</p>
</div>

<main>

<div class="page-header">
    <h2>✅ Confirmar Venda</h2>
    <p class="page-sub">Lançamento de pedidos de venda e fechamento diário por vendedor</p>
</div>

<?php if ($msg): ?>
    <div class="alerta alerta-sucesso"><?= esc($msg) ?></div>
<?php endif; ?>
<?php if ($erro): ?>
    <div class="alerta alerta-erro"><?= esc($erro) ?></div>
<?php endif; ?>
<?php if ($bloqueado): ?>
    <div class="alerta alerta-aviso">⏰ Confirmação de vendas fora do horário permitido.</div>
<?php endif; ?>

<!-- ── Filtro ──────────────────────────────────────────────────── -->
<div class="card" style="margin-bottom:20px">
    <form method="get" action="" style="display:flex; gap:12px; flex-wrap:wrap; align-items:flex-end">
        <div class="grupo-campo" style="flex:1; min-width:200px">
            <label>Vendedor</label>
            <select name="vendedor_id" class="campo" required onchange="this.form.submit()">
                <option value="">— Selecione —</option>
                <?php foreach ($vendedores as $v): ?>
                    <option value="<?= $v['id'] ?>"
                        <?= $v['id'] == $vendedorId ? 'selected' : '' ?>>
                        <?= esc($v['nome']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="grupo-campo" style="min-width:160px">
            <label>Data</label>
            <input type="date" name="data" class="campo"
                   value="<?= esc($dataFiltro) ?>" onchange="this.form.submit()">
        </div>
        <button type="submit" class="btn btn-secundario">🔍 Filtrar</button>
    </form>
</div>

<?php if ($vendedorId > 0): ?>

<!-- ── Painel de resumo ────────────────────────────────────────── -->
<div style="display:grid; grid-template-columns:repeat(auto-fit,minmax(150px,1fr)); gap:12px; margin-bottom:20px">
    <div class="card-stat" style="border-left:4px solid #0A7BC4">
        <div class="stat-num" style="color:#0A7BC4"><?= $totalSaida ?></div>
        <div class="stat-label">📤 Saídas</div>
    </div>
    <div class="card-stat" style="border-left:4px solid #6f42c1">
        <div class="stat-num" style="color:#6f42c1"><?= $totalRetorno ?></div>
        <div class="stat-label">📥 Retornos</div>
    </div>
    <div class="card-stat" style="border-left:4px solid #28A745">
        <div class="stat-num" style="color:#28A745"><?= $totalVendido ?></div>
        <div class="stat-label">✅ Vendidos</div>
    </div>
    <div class="card-stat" style="border-left:4px solid <?= $totalSaldo == 0 ? '#28A745' : ($totalSaldo > 0 ? '#DC3545' : '#FFC107') ?>">
        <div class="stat-num" style="color:<?= $totalSaldo == 0 ? '#28A745' : ($totalSaldo > 0 ? '#DC3545' : '#FFC107') ?>">
            <?= $totalSaldo ?>
        </div>
        <div class="stat-label">
            <?= $totalSaldo == 0 ? '🟢 Saldo zerado' : ($totalSaldo > 0 ? '🔴 Pendente' : '🟡 Verificar') ?>
        </div>
    </div>
</div>

<!-- ── Tabela de saldo por produto ────────────────────────────── -->
<?php if (!empty($resumoSaidas)): ?>
<div class="card" style="margin-bottom:20px">
    <h3 style="margin-bottom:12px; color:var(--primaria)">
        📊 Saldo por produto — <?= esc($nomeVendedorSel) ?> — <?= formatarData($dataFiltro) ?>
    </h3>
    <div style="overflow-x:auto">
    <table class="tabela">
        <thead>
            <tr>
                <th>Código</th>
                <th>Produto</th>
                <th style="text-align:center">Saída</th>
                <th style="text-align:center">Retorno</th>
                <th style="text-align:center">Vendido</th>
                <th style="text-align:center">Saldo</th>
                <th style="text-align:center">Status</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($resumoSaidas as $cod => $r):
                $saldo = $r['saida'] - $r['retorno'] - $r['vendido'];
            ?>
            <tr>
                <td><small style="color:#888"><?= esc($cod) ?></small></td>
                <td><?= esc($r['produto']) ?></td>
                <td style="text-align:center"><?= $r['saida'] ?></td>
                <td style="text-align:center"><?= $r['retorno'] ?></td>
                <td style="text-align:center"><?= $r['vendido'] ?></td>
                <td style="text-align:center; font-weight:bold;
                    color:<?= $saldo == 0 ? '#28A745' : ($saldo > 0 ? '#DC3545' : '#FFC107') ?>">
                    <?= $saldo ?>
                </td>
                <td style="text-align:center">
                    <?php if ($saldo == 0): ?>
                        <span class="badge badge-verde">✅ OK</span>
                    <?php elseif ($saldo > 0): ?>
                        <span class="badge badge-vermelho">⚠️ Pendente</span>
                    <?php else: ?>
                        <span class="badge badge-amarelo">🔍 Verificar</span>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
        <tfoot>
            <tr style="font-weight:bold; background:#f4f6fa">
                <td colspan="2">TOTAL GERAL</td>
                <td style="text-align:center"><?= $totalSaida ?></td>
                <td style="text-align:center"><?= $totalRetorno ?></td>
                <td style="text-align:center"><?= $totalVendido ?></td>
                <td style="text-align:center; color:<?= $totalSaldo == 0 ? '#28A745' : ($totalSaldo > 0 ? '#DC3545' : '#FFC107') ?>">
                    <?= $totalSaldo ?>
                </td>
                <td></td>
            </tr>
        </tfoot>
    </table>
    </div>
</div>
<?php elseif($vendedorId > 0): ?>
    <div class="alerta alerta-aviso" style="margin-bottom:20px">
        ⚠️ Nenhuma saída confirmada encontrada para este vendedor nesta data.
    </div>
<?php endif; ?>

<!-- ── Pedidos já lançados ─────────────────────────────────────── -->
<?php if (!empty($pedidosLancados)): ?>
<div class="card" style="margin-bottom:20px">
    <h3 style="margin-bottom:12px; color:var(--primaria)">📋 Pedidos lançados</h3>
    <table class="tabela">
        <thead>
            <tr>
                <th>Pedido</th>
                <th>Itens</th>
                <th>Total (unid.)</th>
                <th style="text-align:center">Ação</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($pedidosLancados as $ped): ?>
            <tr>
                <td><strong><?= esc($ped['pedido']) ?></strong></td>
                <td style="font-size:13px; color:#555; max-width:400px">
                    <?= esc($ped['descricao']) ?>
                </td>
                <td style="text-align:center"><?= $ped['total_itens'] ?></td>
                <td style="text-align:center">
                    <?php if (!$bloqueado): ?>
                    <form method="post" action=""
                          onsubmit="return confirm('Excluir o pedido \'<?= esc($ped['pedido']) ?>\'?')">
                        <input type="hidden" name="acao"       value="del_pedido">
                        <input type="hidden" name="vendedor_id" value="<?= $vendedorId ?>">
                        <input type="hidden" name="data"       value="<?= $dataFiltro ?>">
                        <input type="hidden" name="pedido_del" value="<?= esc($ped['pedido']) ?>">
                        <button type="submit" class="btn btn-vermelho" style="padding:6px 14px; font-size:13px">
                            🗑 Excluir
                        </button>
                    </form>
                    <?php else: ?>
                        <span style="color:#aaa; font-size:12px">—</span>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

<!-- ── Formulário: adicionar pedido ───────────────────────────── -->
<?php if (!$bloqueado): ?>
<div class="card">
    <h3 style="margin-bottom:4px; color:var(--primaria)">➕ Lançar pedido de venda</h3>
    <p style="color:#666; font-size:13px; margin-bottom:16px">
        Vendedor: <strong><?= esc($nomeVendedorSel) ?></strong>
        — Data: <strong><?= formatarData($dataFiltro) ?></strong>
    </p>

    <form method="post" action="" id="form-venda">
        <input type="hidden" name="acao"       value="add_pedido">
        <input type="hidden" name="vendedor_id" value="<?= $vendedorId ?>">
        <input type="hidden" name="data"        value="<?= $dataFiltro ?>">

        <div style="display:flex; gap:12px; flex-wrap:wrap; margin-bottom:16px">
            <div class="grupo-campo" style="flex:1; min-width:200px">
                <label>Número do Pedido <span style="color:red">*</span></label>
                <input type="text" name="pedido" class="campo"
                       placeholder="Ex: PV-001, NF-1234, 00123..."
                       maxlength="100" required autocomplete="off"
                       value="<?= isset($_POST['pedido']) && $erro ? esc($_POST['pedido']) : '' ?>">
            </div>
            <div class="grupo-campo" style="flex:1; min-width:200px">
                <label>Observação <small style="font-weight:normal">(opcional)</small></label>
                <input type="text" name="obs" class="campo"
                       placeholder="Ex: Entrega parcial, cliente X..."
                       maxlength="200">
            </div>
        </div>

        <!-- Grade de produtos -->
        <div class="grade-header">
            <div>Buscar produto</div>
            <div>Produto selecionado</div>
            <div style="text-align:center">Qtd</div>
            <div></div>
        </div>
        <div id="grade-produtos"></div>

        <button type="button" onclick="adicionarLinha()" class="btn btn-secundario" style="margin-top:10px">
            + Adicionar Produto
        </button>

        <div style="display:flex; gap:12px; margin-top:20px">
            <button type="submit" class="btn btn-verde btn-grande">
                💾 Salvar Pedido
            </button>
            <a href="?vendedor_id=<?= $vendedorId ?>&data=<?= $dataFiltro ?>"
               class="btn btn-secundario btn-grande">Cancelar</a>
        </div>
    </form>
</div>
<?php endif; ?>

<?php endif; // vendedorId > 0 ?>

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

// ── Grade de produtos (igual ao padrão do sistema) ────────────────
function criarLinhaHTML() {
    return `
        <div class="grade-cel-busca">
            <input type="text" class="campo campo-busca"
                   placeholder="Digite o código ou nome..."
                   oninput="buscarProduto(this)" autocomplete="off">
            <div class="autocomplete-lista" style="display:none"></div>
        </div>
        <div class="grade-cel-produto">
            <input type="text" name="produto[]" class="campo" readonly placeholder="—">
            <input type="hidden" name="codigo[]" value="">
        </div>
        <div class="grade-cel-qtd">
            <input type="number" name="quantidade[]" class="campo" min="1" placeholder="0">
        </div>
        <div class="grade-cel-acao">
            <button type="button" onclick="removerLinha(this)"
                    class="btn btn-vermelho" style="padding:8px 14px; font-size:16px" title="Remover">✕</button>
        </div>`;
}

function adicionarLinha() {
    const grade = document.getElementById('grade-produtos');
    if (grade.querySelectorAll('.grade-linha').length >= 20) {
        alert('Máximo de 20 produtos por pedido.'); return;
    }
    const div = document.createElement('div');
    div.className = 'grade-linha';
    div.innerHTML = criarLinhaHTML();
    grade.appendChild(div);
    div.querySelector('.campo-busca').focus();
}

function removerLinha(btn) {
    const grade = document.getElementById('grade-produtos');
    if (grade.querySelectorAll('.grade-linha').length <= 1) {
        alert('Mantenha pelo menos um produto.'); return;
    }
    btn.closest('.grade-linha').remove();
}

let timerBusca = null;
function buscarProduto(input) {
    clearTimeout(timerBusca);
    const linha = input.closest('.grade-linha');
    const lista = linha.querySelector('.autocomplete-lista');
    const termo = input.value.trim();
    if (termo.length < 1) { lista.style.display = 'none'; return; }
    timerBusca = setTimeout(() => {
        fetch(BASE_URL + '/api/buscar_produtos.php?q=' + encodeURIComponent(termo))
            .then(r => r.json())
            .then(produtos => {
                lista.innerHTML = '';
                if (produtos.length === 0) {
                    lista.innerHTML = '<div class="ac-item ac-vazio">Nenhum produto encontrado.</div>';
                } else {
                    produtos.forEach(p => {
                        const item = document.createElement('div');
                        item.className = 'ac-item';
                        item.innerHTML = '<strong>' + p.codigo + '</strong> — ' + p.descricao
                                       + (p.unidade ? ' <em>(' + p.unidade + ')</em>' : '');
                        item.addEventListener('click', () => selecionarProduto(linha, p));
                        lista.appendChild(item);
                    });
                }
                lista.style.display = 'block';
            }).catch(() => { lista.style.display = 'none'; });
    }, 300);
}

function selecionarProduto(linha, produto) {
    linha.querySelector('.campo-busca').value      = produto.codigo + ' — ' + produto.descricao;
    linha.querySelector('[name="produto[]"]').value = produto.descricao;
    linha.querySelector('[name="codigo[]"]').value  = produto.codigo;
    linha.querySelector('.autocomplete-lista').style.display = 'none';
    linha.querySelector('[name="quantidade[]"]').focus();
}

document.addEventListener('click', e => {
    if (!e.target.closest('.grade-cel-busca'))
        document.querySelectorAll('.autocomplete-lista').forEach(l => l.style.display = 'none');
});

// Validação antes de submeter
const formVenda = document.getElementById('form-venda');
if (formVenda) {
    formVenda.addEventListener('submit', function(e) {
        let valido = false;
        document.querySelectorAll('#grade-produtos .grade-linha').forEach(linha => {
            const cod = linha.querySelector('[name="codigo[]"]').value;
            const qty = parseInt(linha.querySelector('[name="quantidade[]"]').value) || 0;
            if (cod && qty > 0) valido = true;
        });
        if (!valido) {
            e.preventDefault();
            alert('Adicione pelo menos um produto com código e quantidade válidos.');
        }
    });
}

// Inicia com uma linha em branco
adicionarLinha();
</script>

</body>
</html>