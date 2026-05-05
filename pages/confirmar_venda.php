<?php
// ============================================================
// pages/confirmar_venda.php — Confirmação de Vendas
// Salvar em: C:\xampp\htdocs\sistema_csr\pages\confirmar_venda.php
// ============================================================
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

verificarPerfil(['admin', 'supervisor', 'master']);

$pdo  = conectar();
$erro = '';
$msg  = '';

// ── Horário — definido ANTES do processamento ────────────────────
$statusHorario = verificarHorario('venda');
$bloqueado = ($statusHorario === 'bloqueado'
    && !in_array($_SESSION['usuario_perfil'], ['supervisor', 'master']));

// ── Parâmetros de filtro ─────────────────────────────────────────
$vendedorId = (int)($_GET['vendedor_id'] ?? $_POST['vendedor_id'] ?? 0);
$dataFiltro = $_GET['data'] ?? $_POST['data'] ?? date('Y-m-d');

// ── Lista de vendedores para o select ───────────────────────────
$vendedores = $pdo->query(
    "SELECT id, nome FROM vendedores WHERE ativo = 1 ORDER BY nome ASC"
)->fetchAll();

// ── PROCESSAMENTO: Adicionar pedido ─────────────────────────────
if (!$bloqueado
    && $_SERVER['REQUEST_METHOD'] === 'POST'
    && ($_POST['acao'] ?? '') === 'add_pedido'
) {
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
        $stmtV = $pdo->prepare("SELECT nome FROM vendedores WHERE id = :id");
        $stmtV->execute([':id' => $vendedorId]);
        $nomeVend = $stmtV->fetchColumn();

        $agora   = date('H:i:s');
        $stmtIns = $pdo->prepare("
            INSERT INTO reg_vendas
                (data, hora, vendedor_id, vendedor, codigo, produto, quantidade, pedido, obs)
            VALUES
                (:data, :hora, :vid, :vendedor, :codigo, :produto, :quantidade, :pedido, :obs)
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

// ── PROCESSAMENTO: Editar pedido ────────────────────────────────
if (!$bloqueado
    && $_SERVER['REQUEST_METHOD'] === 'POST'
    && ($_POST['acao'] ?? '') === 'edit_pedido'
) {
    $pedidoEdit    = trim($_POST['pedido_edit']    ?? '');
    $pedidoNovo    = trim($_POST['pedido_novo']    ?? '');
    $vendedorId    = (int)($_POST['vendedor_id']   ?? 0);
    $dataFiltro    = $_POST['data']                ?? date('Y-m-d');
    $codigos       = $_POST['codigo']              ?? [];
    $produtosArr   = $_POST['produto']             ?? [];
    $quantidades   = $_POST['quantidade']          ?? [];
    $obs           = trim($_POST['obs']            ?? '');

    $itensEdit = [];
    for ($i = 0; $i < count($codigos); $i++) {
        $cod = trim($codigos[$i]     ?? '');
        $prd = trim($produtosArr[$i] ?? '');
        $qty = (int)($quantidades[$i] ?? 0);
        if ($cod !== '' && $prd !== '' && $qty > 0) {
            $itensEdit[] = ['codigo' => $cod, 'produto' => $prd, 'quantidade' => $qty];
        }
    }

    if ($pedidoEdit && $vendedorId > 0 && !empty($itensEdit)) {
        $stmtV2 = $pdo->prepare("SELECT nome FROM vendedores WHERE id = :id");
        $stmtV2->execute([':id' => $vendedorId]);
        $nomeVendEdit = $stmtV2->fetchColumn();

        $agora2 = date('H:i:s');
        $pdo->prepare("DELETE FROM reg_vendas WHERE vendedor_id=:vid AND data=:data AND pedido=:pedido")
            ->execute([':vid' => $vendedorId, ':data' => $dataFiltro, ':pedido' => $pedidoEdit]);

        $stmtIns2 = $pdo->prepare("
            INSERT INTO reg_vendas (data, hora, vendedor_id, vendedor, codigo, produto, quantidade, pedido, obs)
            VALUES (:data, :hora, :vid, :vendedor, :codigo, :produto, :quantidade, :pedido, :obs)
        ");
        foreach ($itensEdit as $item) {
            $stmtIns2->execute([
                ':data'       => $dataFiltro,
                ':hora'       => $agora2,
                ':vid'        => $vendedorId,
                ':vendedor'   => $nomeVendEdit,
                ':codigo'     => $item['codigo'],
                ':produto'    => $item['produto'],
                ':quantidade' => $item['quantidade'],
                ':pedido'     => $pedidoNovo ?: $pedidoEdit,
                ':obs'        => $obs,
            ]);
        }
        registrarLog('VENDA_EDITADA',
            "Pedido: $pedidoEdit | Vendedor: $nomeVendEdit | " . count($itensEdit) . " item(ns) | Data: $dataFiltro",
            obterIP());
        $msg = 'Pedido "' . ($pedidoNovo ?: $pedidoEdit) . '" atualizado com sucesso!';
    }
}


if (!$bloqueado
    && $_SERVER['REQUEST_METHOD'] === 'POST'
    && ($_POST['acao'] ?? '') === 'del_pedido'
) {
    $pedidoDel  = trim($_POST['pedido_del'] ?? '');
    $vendedorId = (int)($_POST['vendedor_id'] ?? 0);
    $dataFiltro = $_POST['data'] ?? date('Y-m-d');

    if ($pedidoDel && $vendedorId > 0) {
        $stmtDel = $pdo->prepare("
            DELETE FROM reg_vendas
            WHERE vendedor_id = :vid AND data = :data AND pedido = :pedido
        ");
        $stmtDel->execute([':vid' => $vendedorId, ':data' => $dataFiltro, ':pedido' => $pedidoDel]);
        registrarLog('VENDA_EXCLUIDA',
            "Pedido: $pedidoDel | Vendedor ID: $vendedorId | Data: $dataFiltro",
            obterIP());
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
            'produto' => $row['produto'],
            'saida'   => (int)$row['total'],
            'retorno' => 0,
            'vendido' => 0,
        ];
    }

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

// ── Pedidos já lançados ──────────────────────────────────────────
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
?>
<?php include __DIR__ . '/../includes/header.php'; ?>

<div class="page-top">
    <h2>✅ Confirmar Venda</h2>
    <p>Lançamento de pedidos de venda e fechamento diário por vendedor</p>
</div>

<main>

<?php /* ── TELA BLOQUEADA ──────────────────────────────────── */ ?>
<?php if ($bloqueado): ?>
    <div class="card">
        <div class="alerta alerta-erro" style="font-size:16px; text-align:center; padding:24px">
            🚫 <strong>Confirmação de venda bloqueada</strong><br>
            Permitido das <?= HORA_VENDA_INICIO ?> às <?= HORA_VENDA_FIM ?><br>
            <small style="opacity:.8">Solicite autorização de um supervisor.</small>
        </div>
        <div style="text-align:center; margin-top:16px">
            <a href="<?= BASE_URL ?>/index.php" class="btn btn-secundario">← Voltar</a>
        </div>
    </div>

<?php /* ── CONTEÚDO NORMAL ──────────────────────────────────── */ ?>
<?php else: ?>

    <?php /* Erro e msg são exibidos via modal flutuante no JS abaixo */ ?>
    <?php if ($statusHorario === 'manutencao'): ?>
        <div class="alerta alerta-aviso">⚙️ Operando em modo manutenção — restrições de horário ignoradas.</div>
    <?php elseif (in_array($_SESSION['usuario_perfil'], ['supervisor','master']) && $statusHorario === 'bloqueado'): ?>
        <div class="alerta alerta-aviso">⚠️ Fora do horário padrão (<?= HORA_VENDA_INICIO ?>–<?= HORA_VENDA_FIM ?>). Liberado pelo perfil <?= esc($_SESSION['usuario_perfil']) ?>.</div>
    <?php endif; ?>

    <!-- ── Filtro ───────────────────────────────────────────────── -->
    <div class="card">
        <form method="get" style="display:flex; gap:12px; flex-wrap:wrap; align-items:flex-end">
            <div class="grupo-campo" style="flex:1; min-width:200px">
                <label>Vendedor</label>
                <select name="vendedor_id" class="campo" required>
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
                       value="<?= esc($dataFiltro) ?>">
            </div>
            <button type="submit" class="btn btn-secundario">🔍 Filtrar</button>
        </form>
    </div>

    <?php if ($vendedorId > 0): ?>

    <!-- ── Painel de resumo ─────────────────────────────────────── -->
    <div style="display:grid; grid-template-columns:repeat(auto-fit,minmax(150px,1fr)); gap:12px; margin-bottom:20px">
        <div class="card-stat" style="border-left:4px solid var(--acento)">
            <div class="stat-num" style="color:var(--acento)"><?= $totalSaida ?></div>
            <div class="stat-label">📤 Saídas</div>
        </div>
        <div class="card-stat" style="border-left:4px solid #6f42c1">
            <div class="stat-num" style="color:#6f42c1"><?= $totalRetorno ?></div>
            <div class="stat-label">📥 Retornos</div>
        </div>
        <div class="card-stat" style="border-left:4px solid var(--verde)">
            <div class="stat-num" style="color:var(--verde)"><?= $totalVendido ?></div>
            <div class="stat-label">✅ Vendidos</div>
        </div>
        <?php
            $corSaldo = $totalSaldo == 0 ? 'var(--verde)' : ($totalSaldo > 0 ? 'var(--vermelho)' : 'var(--amarelo)');
            $labelSaldo = $totalSaldo == 0 ? '🟢 Saldo zerado' : ($totalSaldo > 0 ? '🔴 Pendente' : '🟡 Verificar');
        ?>
        <div class="card-stat" style="border-left:4px solid <?= $corSaldo ?>">
            <div class="stat-num" style="color:<?= $corSaldo ?>"><?= $totalSaldo ?></div>
            <div class="stat-label"><?= $labelSaldo ?></div>
        </div>
    </div>

    <!-- ── Tabela de saldo por produto ─────────────────────────── -->
    <?php if (!empty($resumoSaidas)): ?>
    <div class="card">
        <div class="card-titulo">
            📊 Saldo por produto — <?= esc($nomeVendedorSel) ?> — <?= formatarData($dataFiltro) ?>
        </div>
        <div class="tabela-wrapper">
            <table>
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
                        $saldo    = $r['saida'] - $r['retorno'] - $r['vendido'];
                        $corLinha = $saldo == 0 ? 'var(--verde)' : ($saldo > 0 ? 'var(--vermelho)' : 'var(--amarelo)');
                    ?>
                    <tr>
                        <td><small style="color:var(--cinza-texto)"><?= esc($cod) ?></small></td>
                        <td><?= esc($r['produto']) ?></td>
                        <td style="text-align:center"><?= $r['saida'] ?></td>
                        <td style="text-align:center"><?= $r['retorno'] ?></td>
                        <td style="text-align:center"><?= $r['vendido'] ?></td>
                        <td style="text-align:center; font-weight:bold; color:<?= $corLinha ?>">
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
                    <tr style="font-weight:bold; background:var(--cinza-claro)">
                        <td colspan="2">TOTAL GERAL</td>
                        <td style="text-align:center"><?= $totalSaida ?></td>
                        <td style="text-align:center"><?= $totalRetorno ?></td>
                        <td style="text-align:center"><?= $totalVendido ?></td>
                        <td style="text-align:center; color:<?= $corSaldo ?>"><?= $totalSaldo ?></td>
                        <td></td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>
    <?php else: ?>
        <div class="alerta alerta-aviso">
            ⚠️ Nenhuma saída confirmada encontrada para este vendedor nesta data.
        </div>
    <?php endif; ?>

    <!-- ── Pedidos já lançados ──────────────────────────────────── -->
    <?php if (!empty($pedidosLancados)): ?>
    <div class="card">
        <div class="card-titulo">📋 Pedidos lançados</div>
        <div class="tabela-wrapper">
            <table>
                <thead>
                    <tr>
                        <th>Pedido</th>
                        <th>Itens</th>
                        <th style="text-align:center">Total (unid.)</th>
                        <th style="text-align:center">Ação</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($pedidosLancados as $ped):
                        $stmtItens = $pdo->prepare("
                            SELECT codigo, produto, quantidade, obs
                            FROM reg_vendas
                            WHERE vendedor_id = :vid AND data = :data AND pedido = :pedido
                            ORDER BY id ASC
                        ");
                        $stmtItens->execute([':vid' => $vendedorId, ':data' => $dataFiltro, ':pedido' => $ped['pedido']]);
                        $itensDoPedido = $stmtItens->fetchAll();
                        $obsDoP    = htmlspecialchars($itensDoPedido[0]['obs'] ?? '', ENT_QUOTES);
                        $itensJson = htmlspecialchars(json_encode(array_map(fn($i) => [
                            'codigo'     => $i['codigo'],
                            'produto'    => $i['produto'],
                            'quantidade' => $i['quantidade'],
                        ], $itensDoPedido)), ENT_QUOTES);
                    ?>
                    <tr>
                        <td><strong><?= esc($ped['pedido']) ?></strong></td>
                        <td class="td-obs"><?= esc($ped['descricao']) ?></td>
                        <td style="text-align:center"><?= $ped['total_itens'] ?></td>
                        <td style="text-align:center">
                            <div style="display:flex; gap:6px; justify-content:center">
                                <button type="button" class="btn btn-acento btn-pequeno btn-editar-pedido"
                                        data-pedido="<?= esc($ped['pedido']) ?>"
                                        data-itens="<?= $itensJson ?>"
                                        data-obs="<?= $obsDoP ?>">
                                    ✏️ Editar
                                </button>
                                <button type="button" class="btn btn-vermelho btn-pequeno"
                                        onclick="confirmarExclusao('<?= esc(addslashes($ped['pedido'])) ?>')">
                                    🗑 Excluir
                                </button>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>

    <!-- ── Formulário: adicionar pedido ────────────────────────── -->
    <div class="card">
        <div class="card-titulo">➕ Lançar pedido de venda</div>
        <p style="color:var(--cinza-texto); font-size:13px; margin-bottom:16px">
            Vendedor: <strong><?= esc($nomeVendedorSel) ?></strong>
            — Data: <strong><?= formatarData($dataFiltro) ?></strong>
        </p>

        <form method="post" id="form-venda" autocomplete="off">
            <input type="hidden" name="acao"        value="add_pedido">
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
                           maxlength="200"
                           value="<?= isset($_POST['obs']) && $erro ? esc($_POST['obs']) : '' ?>">
                </div>
            </div>

            <div class="grade-header">
                <div>Buscar produto</div>
                <div>Produto selecionado</div>
                <div style="text-align:center">Qtd</div>
                <div></div>
            </div>
            <div id="grade-produtos"></div>

            <button type="button" onclick="adicionarLinha()"
                    class="btn btn-secundario" style="margin-top:10px">
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

    <?php endif; // vendedorId > 0 ?>

<?php endif; // bloqueado ?>

<?php /* ── Modal flutuante de notificação (erro / sucesso) ──────── */ ?>
<div id="modal-notif" class="modal" style="display:none">
    <div class="modal-box" style="max-width:420px; text-align:center">
        <div id="modal-notif-icone" style="font-size:36px; margin-bottom:8px"></div>
        <h3 id="modal-notif-titulo" style="margin-bottom:8px"></h3>
        <p id="modal-notif-msg" style="font-size:14px; color:var(--cinza-texto); margin-bottom:20px"></p>
        <div style="display:flex; justify-content:center">
            <button class="btn btn-primario" onclick="fecharModal('modal-notif')" style="padding: 10px 30px">OK</button>
        </div>
    </div>
</div>

<?php /* ── Modal: Confirmar exclusão ─────────────────────────── */ ?>
<div id="modal-excluir" class="modal" style="display:none">
    <div class="modal-box">
        <h3>🗑 Confirmar Exclusão</h3>
        <p>Tem certeza que deseja excluir o pedido <strong id="excluir-pedido-nome"></strong>?</p>
        <p style="font-size:13px; color:var(--cinza-texto)">Esta ação não pode ser desfeita.</p>
        <form method="post" id="form-excluir">
            <input type="hidden" name="acao"        value="del_pedido">
            <input type="hidden" name="vendedor_id" value="<?= $vendedorId ?>">
            <input type="hidden" name="data"        value="<?= esc($dataFiltro) ?>">
            <input type="hidden" name="pedido_del"  id="excluir-pedido-val">
            <div class="modal-botoes">
                <button type="submit" class="btn btn-vermelho">🗑 Sim, excluir</button>
                <button type="button" class="btn btn-secundario"
                        onclick="fecharModal('modal-excluir')">Cancelar</button>
            </div>
        </form>
    </div>
</div>

<?php /* ── Modal: Editar pedido ────────────────────────────────── */ ?>
<div id="modal-editar" class="modal" style="display:none" data-persistent="true">
    <div class="modal-box" style="max-width:680px; width:95%">
        <h3>✏️ Editar Pedido</h3>

        <?php /* Alerta inline — substitui modal-sobre-modal para erros de validação */ ?>
        <div id="editar-alerta" class="alerta alerta-erro" style="display:none; margin-bottom:12px"></div>

        <form method="post" id="form-editar" autocomplete="off">
            <input type="hidden" name="acao"        value="edit_pedido">
            <input type="hidden" name="vendedor_id" value="<?= $vendedorId ?>">
            <input type="hidden" name="data"        value="<?= esc($dataFiltro) ?>">
            <input type="hidden" name="pedido_edit" id="editar-pedido-original">

            <div class="grupo-campo" style="max-width:320px">
                <label>Nº do Pedido</label>
                <input type="text" name="pedido_novo" id="editar-pedido-novo"
                       class="campo" placeholder="Deixe em branco para manter o mesmo" maxlength="50">
                <div id="editar-dup-aviso" class="alerta alerta-aviso" style="display:none; margin-top:6px; font-size:12px"></div>
            </div>

            <div style="margin-bottom:6px">
                <label>Produtos <span style="color:red">*</span></label>
            </div>
            <div class="grade-header">
                <div>Buscar produto</div>
                <div>Produto selecionado</div>
                <div style="text-align:center">Qtd</div>
                <div></div>
            </div>
            <div id="grade-editar"></div>
            <button type="button" onclick="adicionarLinhaEditar()" class="btn btn-secundario" style="margin-top:8px">
                + Adicionar Produto
            </button>

            <div class="grupo-campo" style="margin-top:16px; max-width:500px">
                <label>Observação</label>
                <input type="text" name="obs" id="editar-obs" class="campo" maxlength="200">
            </div>

            <div class="modal-botoes">
                <button type="submit" class="btn btn-primario">💾 Salvar Alterações</button>
                <button type="button" class="btn btn-secundario"
                        onclick="fecharModal('modal-editar')">Cancelar</button>
            </div>
        </form>
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

// ── Autocomplete de produtos (grade principal e grade de edição) ──
function criarLinhaHTMLGen(gradeId) {
    return `
        <div class="grade-cel-busca">
            <input type="text" class="campo campo-busca"
                   placeholder="Digite o código ou nome..."
                   oninput="buscarProdutoGen(this,'${gradeId}')" autocomplete="off">
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
            <button type="button" onclick="removerLinhaGen(this,'${gradeId}')"
                    class="btn btn-vermelho" style="padding:8px 14px; font-size:16px">✕</button>
        </div>`;
}

function adicionarLinhaGen(gradeId) {
    const grade = document.getElementById(gradeId);
    if (grade.querySelectorAll('.grade-linha').length >= 10) {
        alert('Máximo de 10 produtos por pedido.'); return;
    }
    const div = document.createElement('div');
    div.className = 'grade-linha';
    div.innerHTML = criarLinhaHTMLGen(gradeId);
    grade.appendChild(div);
    div.querySelector('.campo-busca').focus();
}

function removerLinhaGen(btn, gradeId) {
    const grade = document.getElementById(gradeId);
    if (grade.querySelectorAll('.grade-linha').length <= 1) {
        alert('Mantenha pelo menos um produto.'); return;
    }
    btn.closest('.grade-linha').remove();
}

let timerBusca = null;
function buscarProdutoGen(input, gradeId) {
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
                if (!produtos.length) {
                    lista.innerHTML = '<div class="ac-item ac-vazio">Nenhum produto encontrado.</div>';
                } else {
                    produtos.forEach(p => {
                        const item = document.createElement('div');
                        item.className = 'ac-item';
                        item.innerHTML = '<strong>' + p.codigo + '</strong> — ' + p.descricao
                                       + (p.unidade ? ' <em>(' + p.unidade + ')</em>' : '');
                        item.addEventListener('click', () => selecionarProdutoGen(linha, p));
                        lista.appendChild(item);
                    });
                }
                lista.style.display = 'block';
            }).catch(() => { lista.style.display = 'none'; });
    }, 300);
}

function selecionarProdutoGen(linha, produto) {
    linha.querySelector('.campo-busca').value       = produto.codigo + ' — ' + produto.descricao;
    linha.querySelector('[name="produto[]"]').value  = produto.descricao;
    linha.querySelector('[name="codigo[]"]').value   = produto.codigo;
    linha.querySelector('.autocomplete-lista').style.display = 'none';
    linha.querySelector('[name="quantidade[]"]').focus();
}

document.addEventListener('click', e => {
    if (!e.target.closest('.grade-cel-busca'))
        document.querySelectorAll('.autocomplete-lista').forEach(l => l.style.display = 'none');
});

// ── Grade principal ───────────────────────────────────────────────
<?php if ($vendedorId > 0): ?>
function adicionarLinha() { adicionarLinhaGen('grade-produtos'); }
function removerLinha(btn) { removerLinhaGen(btn, 'grade-produtos'); }
function buscarProduto(input) { buscarProdutoGen(input, 'grade-produtos'); }

// Repopula a grade com os itens que vieram do POST (quando há erro)
<?php
$itensPost = [];
if ($erro && !empty($_POST['codigo'])) {
    $codPost = $_POST['codigo']    ?? [];
    $prdPost = $_POST['produto']   ?? [];
    $qtyPost = $_POST['quantidade'] ?? [];
    for ($i = 0; $i < count($codPost); $i++) {
        $c = trim($codPost[$i] ?? '');
        $p = trim($prdPost[$i] ?? '');
        $q = (int)($qtyPost[$i] ?? 0);
        if ($c && $p && $q > 0) {
            $itensPost[] = ['codigo' => $c, 'produto' => $p, 'quantidade' => $q];
        }
    }
}
?>
const _itensPost = <?= json_encode($itensPost) ?>;

document.addEventListener('DOMContentLoaded', function() {
    if (_itensPost.length > 0) {
        _itensPost.forEach(function(item) {
            adicionarLinha();
            const linhas = document.querySelectorAll('#grade-produtos .grade-linha');
            const ultima = linhas[linhas.length - 1];
            ultima.querySelector('.campo-busca').value          = item.codigo + ' — ' + item.produto;
            ultima.querySelector('[name="produto[]"]').value    = item.produto;
            ultima.querySelector('[name="codigo[]"]').value     = item.codigo;
            ultima.querySelector('[name="quantidade[]"]').value = item.quantidade;
        });
    } else {
        adicionarLinha();
    }
});

// Verificação de pedido duplicado ao sair do campo
const campoPedido = document.querySelector('[name="pedido"]');
if (campoPedido) {
    campoPedido.addEventListener('blur', function() {
        const val = this.value.trim();
        if (!val) return;
        verificarPedidoDuplicado(val, <?= $vendedorId ?>, '<?= esc($dataFiltro) ?>', 'dup-aviso', null);
    });
}

document.getElementById('form-venda').addEventListener('submit', function(e) {
    let valido = false;
    let semQtd = false;

    document.querySelectorAll('#grade-produtos .grade-linha').forEach(linha => {
        const cod = linha.querySelector('[name="codigo[]"]').value.trim();
        const qty = parseInt(linha.querySelector('[name="quantidade[]"]').value) || 0;
        if (cod && qty > 0) valido = true;
        if (cod && qty <= 0) semQtd = true;
    });

    if (semQtd) {
        e.preventDefault();
        document.getElementById('modal-notif-icone').textContent  = '⚠️';
        document.getElementById('modal-notif-titulo').textContent = 'Quantidade inválida';
        document.getElementById('modal-notif-msg').textContent    = 'Um ou mais produtos estão sem quantidade. Preencha antes de salvar.';
        abrirModal('modal-notif');
        return;
    }

    if (!valido) {
        e.preventDefault();
        document.getElementById('modal-notif-icone').textContent  = '⚠️';
        document.getElementById('modal-notif-titulo').textContent = 'Nenhum produto';
        document.getElementById('modal-notif-msg').textContent    = 'Adicione pelo menos um produto com código e quantidade válidos.';
        abrirModal('modal-notif');
    }
});
<?php endif; ?>

// ── Modal de notificação (erro/sucesso PHP) ───────────────────────
<?php if ($erro): ?>
document.addEventListener('DOMContentLoaded', function() {
    document.getElementById('modal-notif-icone').textContent = '❌';
    document.getElementById('modal-notif-titulo').textContent = 'Atenção';
    document.getElementById('modal-notif-msg').textContent = <?= json_encode($erro) ?>;
    abrirModal('modal-notif');
});
<?php elseif ($msg): ?>
document.addEventListener('DOMContentLoaded', function() {
    document.getElementById('modal-notif-icone').textContent = '✅';
    document.getElementById('modal-notif-titulo').textContent = 'Sucesso';
    document.getElementById('modal-notif-msg').textContent = <?= json_encode($msg) ?>;
    abrirModal('modal-notif');
});
<?php endif; ?>

// ── Botões Editar via data-attributes (evita JSON inline no onclick) ─
document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('.btn-editar-pedido').forEach(function(btn) {
        btn.addEventListener('click', function() {
            const pedido = this.dataset.pedido;
            const itens  = JSON.parse(this.dataset.itens || '[]');
            const obs    = this.dataset.obs || '';
            abrirModalEditar(pedido, itens, obs);
        });
    });
});

// ── Fix #1: Modal de exclusão ─────────────────────────────────────
function confirmarExclusao(pedido) {
    document.getElementById('excluir-pedido-nome').textContent = '"' + pedido + '"';
    document.getElementById('excluir-pedido-val').value = pedido;
    abrirModal('modal-excluir');
}

// ── Fix #1: Modal de edição ───────────────────────────────────────
function adicionarLinhaEditar() { adicionarLinhaGen('grade-editar'); }

function abrirModalEditar(pedido, itens, obs) {
    document.getElementById('editar-pedido-original').value = pedido;
    document.getElementById('editar-pedido-novo').value     = '';
    document.getElementById('editar-obs').value             = obs || '';
    document.getElementById('editar-dup-aviso').style.display  = 'none';
    document.getElementById('editar-alerta').style.display     = 'none'; // limpa erro anterior

    // Limpa e preenche a grade com os itens do pedido
    const grade = document.getElementById('grade-editar');
    grade.innerHTML = '';
    if (!itens || !itens.length) {
        adicionarLinhaEditar();
    } else {
        itens.forEach(item => {
            adicionarLinhaEditar();
            const linhas = grade.querySelectorAll('.grade-linha');
            const ultima = linhas[linhas.length - 1];
            ultima.querySelector('.campo-busca').value          = item.codigo + ' — ' + item.produto;
            ultima.querySelector('[name="produto[]"]').value    = item.produto;
            ultima.querySelector('[name="codigo[]"]').value     = item.codigo;
            ultima.querySelector('[name="quantidade[]"]').value = item.quantidade;
        });
    }

    abrirModal('modal-editar');
}

// Verifica duplicidade ao alterar o nº do pedido no modal de edição
document.getElementById('editar-pedido-novo')?.addEventListener('blur', function() {
    const val      = this.value.trim();
    const original = document.getElementById('editar-pedido-original').value;
    if (!val || val === original) {
        document.getElementById('editar-dup-aviso').style.display = 'none';
        return;
    }
    verificarPedidoDuplicado(val, <?= $vendedorId ?>, '<?= esc($dataFiltro) ?>', 'editar-dup-aviso', original);
});

document.getElementById('form-editar')?.addEventListener('submit', function(e) {
    let valido = false;
    let semQtd = false;
    const alertaEl = document.getElementById('editar-alerta');

    document.querySelectorAll('#grade-editar .grade-linha').forEach(linha => {
        const cod = linha.querySelector('[name="codigo[]"]').value.trim();
        const qty = parseInt(linha.querySelector('[name="quantidade[]"]').value) || 0;
        if (cod && qty > 0) valido = true;
        if (cod && qty <= 0) semQtd = true;
    });

    const mostrarErroInline = (msg) => {
        alertaEl.textContent = '⚠️ ' + msg;
        alertaEl.style.display = 'block';
        alertaEl.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    };

    if (semQtd) {
        e.preventDefault();
        mostrarErroInline('Um ou mais produtos estão sem quantidade. Preencha antes de salvar.');
        return;
    }
    if (!valido) {
        e.preventDefault();
        mostrarErroInline('Adicione pelo menos um produto com código e quantidade válidos.');
        return;
    }

    // Limpa alerta se tudo ok
    alertaEl.style.display = 'none';
});

// ── Fix #2: Verificação AJAX de pedido duplicado ──────────────────
function verificarPedidoDuplicado(numeroPedido, vendedorId, data, avisoElId, pedidoIgnorar) {
    fetch(BASE_URL + '/api/verificar_pedido.php'
        + '?pedido='   + encodeURIComponent(numeroPedido)
        + '&vid='      + encodeURIComponent(vendedorId)
        + '&data='     + encodeURIComponent(data)
        + (pedidoIgnorar ? '&ignorar=' + encodeURIComponent(pedidoIgnorar) : '')
    )
    .then(r => r.json())
    .then(data => {
        const el = document.getElementById(avisoElId);
        if (!el) return;
        if (data.existe) {
            el.innerHTML = '⚠️ ' + data.msg;
            el.style.display = 'block';
        } else {
            el.style.display = 'none';
        }
    })
    .catch(() => {});
}
</script>

</body>
</html>