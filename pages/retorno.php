<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

verificarPerfil(['operador', 'supervisor', 'master']);

$pdo   = conectar();
$etapa = $_GET['etapa'] ?? 'form';
$erro  = '';

$statusHorario = verificarHorario('retorno');
$bloqueado = ($statusHorario === 'bloqueado'
    && !in_array($_SESSION['usuario_perfil'], ['supervisor', 'master']));

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$bloqueado) {

    $vendedorId  = (int)($_POST['vendedor_id']  ?? 0);
    $codigos     = $_POST['codigo']     ?? [];
    $produtosArr = $_POST['produto']    ?? [];
    $quantidades = $_POST['quantidade'] ?? [];
    $obs         = trim($_POST['obs']   ?? '');

    if ($vendedorId <= 0) {
        $erro = 'Selecione um vendedor.';
    }

    $itens = [];
    if (empty($erro)) {
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
        }
    }

    if (empty($erro)) {
        $stmtV = $pdo->prepare("SELECT nome FROM vendedores WHERE id = :id AND ativo = 1");
        $stmtV->execute([':id' => $vendedorId]);
        $vendedor = $stmtV->fetch();

        if (!$vendedor) {
            $erro = 'Vendedor inválido ou inativo.';
        } else {
            $hoje         = date('Y-m-d');
            $agora        = date('H:i:s');
            $nomeVendedor = $vendedor['nome'];
            $obsGravar    = $obs . (modoManutencaoAtivo() ? ' [MANUTENCAO]' : '');

            // Insere em reg_retornos
            $stmtIns = $pdo->prepare("
                INSERT INTO reg_retornos
                    (data, hora, vendedor_id, vendedor, codigo, produto, quantidade, obs)
                VALUES
                    (:data, :hora, :vid, :vendedor, :codigo, :produto, :quantidade, :obs)
            ");
            foreach ($itens as $item) {
                $stmtIns->execute([
                    ':data'       => $hoje,
                    ':hora'       => $agora,
                    ':vid'        => $vendedorId,
                    ':vendedor'   => $nomeVendedor,
                    ':codigo'     => $item['codigo'],
                    ':produto'    => $item['produto'],
                    ':quantidade' => $item['quantidade'],
                    ':obs'        => $obsGravar,
                ]);
            }

            // Gera token QR para retorno
            $pdo->prepare("
                DELETE FROM qr_tokens
                WHERE vendedor_id = :vid AND data_ref = :data AND tipo = 'retorno' AND usado = 0
            ")->execute([':vid' => $vendedorId, ':data' => $hoje]);

            $token  = bin2hex(random_bytes(32));
            $expira = date('Y-m-d H:i:s', time() + QR_EXPIRACAO_HORAS * 3600);

            $pdo->prepare("
                INSERT INTO qr_tokens (token, tipo, vendedor_id, data_ref, expira_em)
                VALUES (:token, 'retorno', :vid, :data, :expira)
            ")->execute([
                ':token'  => $token,
                ':vid'    => $vendedorId,
                ':data'   => $hoje,
                ':expira' => $expira,
            ]);

            registrarLog(
                'RETORNO',
                "Vendedor: $nomeVendedor | " . count($itens) . " item(ns)",
                obterIP()
            );

            $_SESSION['qr_retorno'] = [
                'token'    => $token,
                'vendedor' => $nomeVendedor,
                'data'     => $hoje,
                'itens'    => $itens,
                'expira'   => $expira,
            ];

            header('Location: ' . BASE_URL . '/pages/retorno.php?etapa=qr');
            exit;
        }
    }
}

$vendedores = $pdo->query(
    "SELECT id, nome FROM vendedores WHERE ativo = 1 ORDER BY nome"
)->fetchAll();

$qrData = null;
if ($etapa === 'qr') {
    $qrData = $_SESSION['qr_retorno'] ?? null;
    if (!$qrData) {
        header('Location: ' . BASE_URL . '/pages/retorno.php');
        exit;
    }
    $confirmUrl = BASE_URL . '/qr/confirmar.php?token=' . $qrData['token'];
    $qrImageUrl = gerarQRImageUrl($confirmUrl);
}
?>
<?php include __DIR__ . '/../includes/header.php'; ?>

<main>

<?php if ($bloqueado): ?>
    <div class="card">
        <div class="alerta alerta-erro" style="font-size:16px; text-align:center; padding:24px">
            🚫 <strong>Retorno bloqueado</strong><br>
            Permitido das <?= HORA_RETORNO_INICIO ?> às <?= HORA_RETORNO_FIM ?><br>
            <small style="opacity:.8">Solicite autorização de um supervisor para operar fora do horário.</small>
        </div>
        <div style="text-align:center; margin-top:16px">
            <a href="<?= BASE_URL ?>/index.php" class="btn btn-secundario">← Voltar</a>
        </div>
    </div>

<?php elseif ($etapa === 'qr' && $qrData): ?>
    <div class="card" style="max-width:700px; margin:0 auto">
        <div class="card-titulo">📥 Retorno Registrado — Aguardando Confirmação</div>

        <div class="qr-layout">
            <div class="qr-resumo">
                <p><strong>Vendedor:</strong> <?= esc($qrData['vendedor']) ?></p>
                <p><strong>Data:</strong> <?= formatarData($qrData['data']) ?></p>
                <p><strong>Itens registrados:</strong></p>
                <table style="margin-top:8px; width:100%">
                    <thead>
                        <tr>
                            <th>Código</th>
                            <th>Produto</th>
                            <th style="text-align:center">Qtd</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($qrData['itens'] as $item): ?>
                        <tr>
                            <td><code><?= esc($item['codigo']) ?></code></td>
                            <td><?= esc($item['produto']) ?></td>
                            <td style="text-align:center"><strong><?= $item['quantidade'] ?></strong></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <div class="qr-codigo-area">
                <p style="font-size:13px; color:var(--cinza-texto); margin-bottom:10px; text-align:center">
                    📱 Vendedor escaneia com o celular
                </p>
                <img src="<?= $qrImageUrl ?>" alt="QR Code" class="qr-imagem"
                     onerror="this.style.display='none'; document.getElementById('qr-link-fallback').style.display='block'">
                <div id="qr-link-fallback" style="display:none; text-align:center; margin-top:8px">
                    <a href="<?= esc($confirmUrl) ?>" target="_blank" class="btn btn-acento">
                        🔗 Abrir link de confirmação
                    </a>
                </div>
                <div id="status-aguardando" class="status-qr status-aguardando">
                    <span class="spinner"></span> Aguardando confirmação do vendedor...
                </div>
                <div id="status-confirmado" class="status-qr status-confirmado" style="display:none">
                    ✅ Confirmado pelo vendedor!
                </div>
                <div id="status-expirado" class="status-qr status-expirado" style="display:none">
                    ⏰ QR Code expirado. <a href="<?= BASE_URL ?>/pages/retorno.php">Novo retorno</a>
                </div>
                <p style="font-size:11px; color:var(--cinza-texto); text-align:center; margin-top:10px">
                    Expira em: <?= formatarDataHora($qrData['expira']) ?>
                </p>
            </div>
        </div>

        <div style="margin-top:20px; text-align:center; display:flex; gap:12px; justify-content:center; flex-wrap:wrap">
            <a href="<?= BASE_URL ?>/pages/retorno.php" class="btn btn-acento">+ Novo Retorno</a>
            <a href="<?= BASE_URL ?>/index.php" class="btn btn-secundario">← Dashboard</a>
        </div>
    </div>

<?php else: ?>
    <div class="card">
        <div class="card-titulo">📥 Registrar Retorno</div>

        <?php if ($erro): ?>
            <div class="alerta alerta-erro"><?= $erro ?></div>
        <?php endif; ?>
        <?php if ($statusHorario === 'manutencao'): ?>
            <div class="alerta alerta-aviso">⚙️ Operando em modo manutenção.</div>
        <?php elseif (in_array($_SESSION['usuario_perfil'], ['supervisor','master']) && $statusHorario === 'bloqueado'): ?>
            <div class="alerta alerta-aviso">⚠️ Fora do horário padrão (<?= HORA_RETORNO_INICIO ?>–<?= HORA_RETORNO_FIM ?>). Liberado pelo perfil <?= esc($_SESSION['usuario_perfil']) ?>.</div>
        <?php endif; ?>

        <form method="post" id="form-retorno" autocomplete="off">
            <div class="grupo-campo" style="max-width:400px">
                <label>Vendedor <span style="color:red">*</span></label>
                <select name="vendedor_id" class="campo" required>
                    <option value="">— Selecione o vendedor —</option>
                    <?php foreach ($vendedores as $v): ?>
                    <option value="<?= $v['id'] ?>"
                        <?= (isset($_POST['vendedor_id']) && $_POST['vendedor_id'] == $v['id']) ? 'selected' : '' ?>>
                        <?= esc($v['nome']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div style="margin-bottom:8px">
                <label>Produtos retornados <span style="color:red">*</span></label>
                <small style="color:var(--cinza-texto)"> — máximo 10 itens</small>
            </div>

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

            <div class="grupo-campo" style="margin-top:20px; max-width:500px">
                <label>Observação <small style="font-weight:normal">(opcional)</small></label>
                <input type="text" name="obs" class="campo" placeholder="Ex: Produto avariado, devolução parcial..." maxlength="200">
            </div>

            <div style="display:flex; gap:12px; margin-top:8px">
                <button type="submit" class="btn btn-acento btn-grande">
                    💾 Registrar e Gerar QR Code
                </button>
                <a href="<?= BASE_URL ?>/index.php" class="btn btn-secundario btn-grande">Cancelar</a>
            </div>
        </form>
    </div>
<?php endif; ?>

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

<?php if ($etapa === 'qr' && $qrData): ?>
const TOKEN_QR  = '<?= $qrData['token'] ?>';
let jaConfirmou = false;

function verificarConfirmacao() {
    if (jaConfirmou) return;
    fetch(BASE_URL + '/api/verificar_confirmacao.php?token=' + TOKEN_QR)
        .then(r => r.json())
        .then(data => {
            if (data.confirmado) {
                jaConfirmou = true;
                document.getElementById('status-aguardando').style.display = 'none';
                document.getElementById('status-confirmado').style.display = 'block';
                clearInterval(pollingInterval);
            } else if (data.expirado) {
                document.getElementById('status-aguardando').style.display = 'none';
                document.getElementById('status-expirado').style.display   = 'block';
                clearInterval(pollingInterval);
            }
        }).catch(() => {});
}
const pollingInterval = setInterval(verificarConfirmacao, 3000);
verificarConfirmacao();

<?php else: ?>
let contadorLinhas = 0;
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
                    class="btn btn-vermelho" style="padding:8px 14px; font-size:16px">✕</button>
        </div>`;
}
function adicionarLinha() {
    const grade = document.getElementById('grade-produtos');
    if (grade.querySelectorAll('.grade-linha').length >= 10) {
        alert('Máximo de 10 produtos por operação.'); return;
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
                                       + (p.unidade ? ' <em>('+p.unidade+')</em>' : '');
                        item.addEventListener('click', () => selecionarProduto(linha, p));
                        lista.appendChild(item);
                    });
                }
                lista.style.display = 'block';
            }).catch(() => { lista.style.display = 'none'; });
    }, 300);
}
function selecionarProduto(linha, produto) {
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
document.getElementById('form-retorno').addEventListener('submit', function(e) {
    let valido = false;
    document.querySelectorAll('#grade-produtos .grade-linha').forEach(linha => {
        if (linha.querySelector('[name="codigo[]"]').value &&
            parseInt(linha.querySelector('[name="quantidade[]"]').value) > 0) valido = true;
    });
    if (!valido) { e.preventDefault(); alert('Adicione pelo menos um produto com código e quantidade válidos.'); }
});
adicionarLinha();
<?php endif; ?>
</script>
</body>
</html>