<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

verificarPerfil(['operador', 'supervisor', 'master']);

$pdo   = conectar();
$etapa = $_GET['etapa'] ?? 'form';
$erro  = '';

// ── Verifica horário ─────────────────────────────────────────────
$statusHorario = verificarHorario('saida');
$bloqueado = ($statusHorario === 'bloqueado'
    && !in_array($_SESSION['usuario_perfil'], ['supervisor', 'master']));

// ── PROCESSAMENTO DO FORMULÁRIO ──────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$bloqueado) {

    $vendedorId  = (int)($_POST['vendedor_id']  ?? 0);
    $codigos     = $_POST['codigo']     ?? [];
    $produtosArr = $_POST['produto']    ?? [];
    $quantidades = $_POST['quantidade'] ?? [];
    $obs         = trim($_POST['obs']   ?? '');

    // Validação: vendedor
    if ($vendedorId <= 0) {
        $erro = 'Selecione um vendedor.';
    }

    // Monta itens válidos (código + produto + quantidade > 0)
    $itens = [];
    if (empty($erro)) {
        $total = count($codigos);
        for ($i = 0; $i < $total; $i++) {
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
        // Busca nome do vendedor
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

            // Insere cada item em reg_saidas
            $stmtIns = $pdo->prepare("
                INSERT INTO reg_saidas
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

            // Remove token anterior (não usado) do mesmo vendedor/data
            $pdo->prepare("
                DELETE FROM qr_tokens
                WHERE vendedor_id = :vid AND data_ref = :data AND tipo = 'saida' AND usado = 0
            ")->execute([':vid' => $vendedorId, ':data' => $hoje]);

            // Gera novo token único
            $token  = bin2hex(random_bytes(32));
            $expira = date('Y-m-d H:i:s', time() + QR_EXPIRACAO_HORAS * 3600);

            $pdo->prepare("
                INSERT INTO qr_tokens (token, tipo, vendedor_id, data_ref, expira_em)
                VALUES (:token, 'saida', :vid, :data, :expira)
            ")->execute([
                ':token'  => $token,
                ':vid'    => $vendedorId,
                ':data'   => $hoje,
                ':expira' => $expira,
            ]);

            registrarLog(
                'SAIDA',
                "Vendedor: $nomeVendedor | " . count($itens) . " item(ns)",
                obterIP()
            );

            // Guarda na sessão para exibir na tela de QR
            $_SESSION['qr_saida'] = [
                'token'    => $token,
                'vendedor' => $nomeVendedor,
                'data'     => $hoje,
                'itens'    => $itens,
                'expira'   => $expira,
            ];

            header('Location: ' . BASE_URL . '/pages/saida.php?etapa=qr');
            exit;
        }
    }
}

// ── Dados para o formulário ──────────────────────────────────────
$vendedores = $pdo->query(
    "SELECT id, nome FROM vendedores WHERE ativo = 1 ORDER BY nome"
)->fetchAll();

// ── Dados da tela QR ─────────────────────────────────────────────
$qrData = null;
if ($etapa === 'qr') {
    $qrData = $_SESSION['qr_saida'] ?? null;
    if (!$qrData) {
        header('Location: ' . BASE_URL . '/pages/saida.php');
        exit;
    }
    $confirmUrl = BASE_URL . '/qr/confirmar.php?token=' . $qrData['token'];
    $qrImageUrl = gerarQRImageUrl($confirmUrl);
}
?>
<?php include __DIR__ . '/../includes/header.php'; ?>

<main>

<?php /* ── TELA BLOQUEADA ──────────────────────────────────── */ ?>
<?php if ($bloqueado): ?>
    <div class="card">
        <div class="alerta alerta-erro" style="font-size:16px; text-align:center; padding:24px">
            🚫 <strong>Saída bloqueada</strong><br>
            Permitido das <?= HORA_SAIDA_INICIO ?> às <?= HORA_SAIDA_FIM ?><br>
            <small style="opacity:.8">Solicite autorização de um supervisor para operar fora do horário.</small>
        </div>
        <div style="text-align:center; margin-top:16px">
            <a href="<?= BASE_URL ?>/index.php" class="btn btn-secundario">← Voltar</a>
        </div>
    </div>

<?php /* ── TELA QR CODE ─────────────────────────────────────── */ ?>
<?php elseif ($etapa === 'qr' && $qrData): ?>
    <div class="card" style="max-width:700px; margin:0 auto">
        <div class="card-titulo">📤 Saída Registrada — Aguardando Confirmação</div>

        <div class="qr-layout">

            <!-- Resumo do que foi registrado -->
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

            <!-- QR Code -->
            <div class="qr-codigo-area">
                <p style="font-size:13px; color:var(--cinza-texto); margin-bottom:10px; text-align:center">
                    📱 Vendedor escaneia com o celular
                </p>
                <img src="<?= $qrImageUrl ?>"
                     alt="QR Code"
                     class="qr-imagem"
                     onerror="this.style.display='none'; document.getElementById('qr-link-fallback').style.display='block'">

                <!-- Fallback se imagem não carregar (sem internet) -->
                <div id="qr-link-fallback" style="display:none; text-align:center; margin-top:8px">
                    <a href="<?= esc($confirmUrl) ?>" target="_blank" class="btn btn-acento">
                        🔗 Abrir link de confirmação
                    </a>
                </div>

                <!-- Status de confirmação -->
                <div id="status-aguardando" class="status-qr status-aguardando">
                    <span class="spinner"></span> Aguardando confirmação do vendedor...
                </div>
                <div id="status-confirmado" class="status-qr status-confirmado" style="display:none">
                    ✅ Confirmado pelo vendedor!
                </div>
                <div id="status-expirado" class="status-qr status-expirado" style="display:none">
                    ⏰ QR Code expirado. <a href="<?= BASE_URL ?>/pages/saida.php">Nova saída</a>
                </div>

                <p style="font-size:11px; color:var(--cinza-texto); text-align:center; margin-top:10px">
                    Expira em: <?= formatarDataHora($qrData['expira']) ?>
                </p>
            </div>
        </div>

        <div style="margin-top:20px; text-align:center; display:flex; gap:12px; justify-content:center; flex-wrap:wrap">
            <a href="<?= BASE_URL ?>/pages/saida.php" class="btn btn-primario">
                + Nova Saída
            </a>
            <a href="<?= BASE_URL ?>/index.php" class="btn btn-secundario">
                ← Dashboard
            </a>
        </div>
    </div>

<?php /* ── FORMULÁRIO ──────────────────────────────────────── */ ?>
<?php else: ?>
    <div class="card">
        <div class="card-titulo">📤 Registrar Saída</div>

        <?php if ($erro): ?>
            <div class="alerta alerta-erro"><?= $erro ?></div>
        <?php endif; ?>

        <?php if ($statusHorario === 'manutencao'): ?>
            <div class="alerta alerta-aviso">⚙️ Operando em modo manutenção — restrições de horário ignoradas.</div>
        <?php elseif (in_array($_SESSION['usuario_perfil'], ['supervisor', 'master']) && $statusHorario === 'bloqueado'): ?>
            <div class="alerta alerta-aviso">⚠️ Fora do horário padrão (<?= HORA_SAIDA_INICIO ?>–<?= HORA_SAIDA_FIM ?>). Você pode continuar por ter perfil <?= esc($_SESSION['usuario_perfil']) ?>.</div>
        <?php endif; ?>

        <form method="post" id="form-saida" autocomplete="off">

            <!-- Vendedor -->
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

            <!-- Grade de produtos -->
            <div style="margin-bottom:8px">
                <label>Produtos <span style="color:red">*</span></label>
                <small style="color:var(--cinza-texto)"> — máximo 10 itens por operação</small>
            </div>

            <div class="grade-header">
                <div>Buscar produto</div>
                <div>Produto selecionado</div>
                <div style="text-align:center">Qtd</div>
                <div></div>
            </div>

            <div id="grade-produtos">
                <!-- Linha inicial inserida pelo JS -->
            </div>

            <button type="button" onclick="adicionarLinha()" class="btn btn-secundario" style="margin-top:10px">
                + Adicionar Produto
            </button>

            <!-- Observação -->
            <div class="grupo-campo" style="margin-top:20px; max-width:500px">
                <label>Observação <small style="font-weight:normal">(opcional)</small></label>
                <input type="text" name="obs" class="campo" placeholder="Ex: Carga extra, urgência..." maxlength="200">
            </div>

            <!-- Botões -->
            <div style="display:flex; gap:12px; margin-top:8px">
                <button type="submit" class="btn btn-primario btn-grande">
                    💾 Registrar e Gerar QR Code
                </button>
                <a href="<?= BASE_URL ?>/index.php" class="btn btn-secundario btn-grande">
                    Cancelar
                </a>
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
// ── Polling: verifica se vendedor confirmou ──────────────────────
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
        })
        .catch(() => { /* falha silenciosa, tenta de novo */ });
}

const pollingInterval = setInterval(verificarConfirmacao, 3000);
verificarConfirmacao(); // Verifica imediatamente

<?php else: ?>
// ── Grade de produtos ─────────────────────────────────────────────
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
                    class="btn btn-vermelho" style="padding:8px 14px; font-size:16px" title="Remover">✕</button>
        </div>`;
}

function adicionarLinha() {
    const grade = document.getElementById('grade-produtos');
    const linhas = grade.querySelectorAll('.grade-linha');
    if (linhas.length >= 10) {
        alert('Máximo de 10 produtos por operação.');
        return;
    }
    const div = document.createElement('div');
    div.className = 'grade-linha';
    div.innerHTML = criarLinhaHTML();
    grade.appendChild(div);
    div.querySelector('.campo-busca').focus();
    contadorLinhas++;
}

function removerLinha(btn) {
    const grade = document.getElementById('grade-produtos');
    if (grade.querySelectorAll('.grade-linha').length <= 1) {
        alert('Mantenha pelo menos um produto na lista.');
        return;
    }
    btn.closest('.grade-linha').remove();
}

// ── Autocomplete ─────────────────────────────────────────────────
let timerBusca = null;

function buscarProduto(input) {
    clearTimeout(timerBusca);
    const linha = input.closest('.grade-linha');
    const lista = linha.querySelector('.autocomplete-lista');
    const termo = input.value.trim();

    if (termo.length < 1) {
        lista.style.display = 'none';
        return;
    }

    timerBusca = setTimeout(() => {
        fetch(BASE_URL + '/api/buscar_produtos.php?q=' + encodeURIComponent(termo))
            .then(r => r.json())
            .then(produtos => {
                lista.innerHTML = '';

                if (produtos.length === 0) {
                    lista.innerHTML = '<div class="ac-item ac-vazio">Nenhum produto encontrado.</div>';
                    lista.style.display = 'block';
                    return;
                }

                produtos.forEach(p => {
                    const item = document.createElement('div');
                    item.className = 'ac-item';
                    item.innerHTML = '<strong>' + p.codigo + '</strong> — ' + p.descricao
                                   + (p.unidade ? ' <em>(' + p.unidade + ')</em>' : '');
                    item.addEventListener('click', () => selecionarProduto(linha, p));
                    lista.appendChild(item);
                });

                lista.style.display = 'block';
            })
            .catch(() => { lista.style.display = 'none'; });
    }, 300);
}

function selecionarProduto(linha, produto) {
    linha.querySelector('.campo-busca').value      = produto.codigo + ' — ' + produto.descricao;
    linha.querySelector('[name="produto[]"]').value = produto.descricao;
    linha.querySelector('[name="codigo[]"]').value  = produto.codigo;
    linha.querySelector('.autocomplete-lista').style.display = 'none';
    linha.querySelector('[name="quantidade[]"]').focus();
}

// Fecha autocomplete ao clicar fora da grade
document.addEventListener('click', e => {
    if (!e.target.closest('.grade-cel-busca')) {
        document.querySelectorAll('.autocomplete-lista')
                .forEach(l => l.style.display = 'none');
    }
});

// Validação antes de enviar
document.getElementById('form-saida').addEventListener('submit', function(e) {
    const linhas = document.querySelectorAll('#grade-produtos .grade-linha');
    let valido = false;
    linhas.forEach(linha => {
        const cod = linha.querySelector('[name="codigo[]"]').value;
        const qty = parseInt(linha.querySelector('[name="quantidade[]"]').value) || 0;
        if (cod && qty > 0) valido = true;
    });
    if (!valido) {
        e.preventDefault();
        alert('Adicione pelo menos um produto com código e quantidade válidos.');
    }
});

// Inicia com uma linha
adicionarLinha();
<?php endif; ?>
</script>

</body>
</html>