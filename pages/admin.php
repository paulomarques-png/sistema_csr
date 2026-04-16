<?php
// ============================================================
// pages/admin.php — Painel Administrativo
// Salvar em: C:\xampp\htdocs\sistema_csr\pages\admin.php
// ============================================================
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

verificarPerfil(['master']);

$pdo    = conectar();
$erro   = '';
$sucesso = '';

// ── Verifica senha master ────────────────────────────────────────
function verificarSenhaMaster(PDO $pdo, string $senha): bool {
    $stmt = $pdo->prepare(
        "SELECT senha_hash FROM usuarios WHERE perfil = 'master' AND ativo = 1 LIMIT 1"
    );
    $stmt->execute();
    $hash = $stmt->fetchColumn();
    return $hash && password_verify($senha, $hash);
}

// ══════════════════════════════════════════════════════════════
// PROCESSAMENTO DE AÇÕES POST
// ══════════════════════════════════════════════════════════════

$acao = trim($_POST['acao'] ?? '');

// ── Ativar modo manutenção ───────────────────────────────────────
if ($acao === 'ativar_manutencao') {
    $dados = ['ativo' => true, 'ativado_em' => time(), 'ativado_por' => $_SESSION['usuario_nome']];
    file_put_contents(__DIR__ . '/../config/manutencao.json', json_encode($dados));
    registrarLog('MANUTENCAO_ATIVADA', 'Ativado por: ' . $_SESSION['usuario_nome'], obterIP());
    $sucesso = 'Modo manutenção ativado. Timeout: ' . (MANUTENCAO_TIMEOUT / 60) . ' minutos.';
}

// ── Desativar modo manutenção ────────────────────────────────────
if ($acao === 'desativar_manutencao') {
    file_put_contents(__DIR__ . '/../config/manutencao.json', json_encode(['ativo' => false]));
    registrarLog('MANUTENCAO_DESATIVADA', 'Desativado por: ' . $_SESSION['usuario_nome'], obterIP());
    $sucesso = 'Modo manutenção desativado.';
}

// ── Trocar logo ──────────────────────────────────────────────────
if ($acao === 'trocar_logo') {
    $senhaMaster = trim($_POST['senha_master'] ?? '');
    if (!verificarSenhaMaster($pdo, $senhaMaster)) {
        $erro = 'Senha master incorreta.';
    } elseif (empty($_FILES['nova_logo']['tmp_name'])) {
        $erro = 'Nenhum arquivo enviado.';
    } else {
        $file    = $_FILES['nova_logo'];
        $allowed = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp'];
        $mime    = mime_content_type($file['tmp_name']);
        if (!isset($allowed[$mime])) {
            $erro = 'Formato inválido. Use PNG, JPG ou WEBP.';
        } elseif ($file['size'] > 500 * 1024) {
            $erro = 'Arquivo muito grande. Máximo: 500 KB.';
        } else {
            $destino = __DIR__ . '/../assets/img/logo.png';
            // Mantém sempre como logo.png (converte se necessário)
            if (move_uploaded_file($file['tmp_name'], $destino)) {
                registrarLog('LOGO_TROCADA', 'Formato: ' . $allowed[$mime], obterIP());
                $sucesso = 'Logo atualizada com sucesso!';
            } else {
                $erro = 'Erro ao salvar o arquivo. Verifique as permissões da pasta assets/img/.';
            }
        }
    }
}

// ── Reset de dados ───────────────────────────────────────────────
if ($acao === 'reset_dados') {
    $senhaMaster = trim($_POST['senha_master'] ?? '');
    $confirmacao = trim($_POST['confirmacao']  ?? '');

    if ($confirmacao !== 'CONFIRMAR RESET') {
        $erro = 'Texto de confirmação incorreto.';
    } elseif (!verificarSenhaMaster($pdo, $senhaMaster)) {
        $erro = 'Senha master incorreta. Reset cancelado.';
    } else {
        try {
            // DELETEs dentro da transação
            $pdo->beginTransaction();
            $pdo->exec("DELETE FROM reg_saidas");
            $pdo->exec("DELETE FROM reg_retornos");
            $pdo->exec("DELETE FROM reg_vendas");
            $pdo->exec("DELETE FROM qr_tokens");
            $pdo->exec("DELETE FROM log_sistema");
            $pdo->commit();

            // ALTER TABLE fora da transação (causa commit implícito no MySQL)
            $pdo->exec("ALTER TABLE reg_saidas  AUTO_INCREMENT = 1");
            $pdo->exec("ALTER TABLE reg_retornos AUTO_INCREMENT = 1");
            $pdo->exec("ALTER TABLE reg_vendas   AUTO_INCREMENT = 1");
            $pdo->exec("ALTER TABLE qr_tokens    AUTO_INCREMENT = 1");
            $pdo->exec("ALTER TABLE log_sistema  AUTO_INCREMENT = 1");

            registrarLog('RESET_DADOS', 'Reset completo realizado por: ' . $_SESSION['usuario_nome'], obterIP());
            $sucesso = 'Reset concluído! Todos os registros operacionais foram apagados.';
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $erro = 'Erro durante o reset: ' . $e->getMessage();
        }
    }
}

// ══════════════════════════════════════════════════════════════
// DADOS PARA EXIBIÇÃO
// ══════════════════════════════════════════════════════════════

$manutencaoAtiva = modoManutencaoAtivo();

// Lê dados do arquivo de manutenção para exibir quando ativado
$dadosManutencao = [];
$arquivoManut    = __DIR__ . '/../config/manutencao.json';
if (file_exists($arquivoManut)) {
    $dadosManutencao = json_decode(file_get_contents($arquivoManut), true) ?? [];
}
$tempoRestante = '';
if ($manutencaoAtiva && !empty($dadosManutencao['ativado_em'])) {
    $expira         = $dadosManutencao['ativado_em'] + MANUTENCAO_TIMEOUT;
    $segundosRest   = $expira - time();
    $tempoRestante  = gmdate('i\m s\s', max(0, $segundosRest));
}

// Contagem de registros para o card de reset
$contagens = [];
foreach (['reg_saidas','reg_retornos','reg_vendas','qr_tokens','log_sistema'] as $tabela) {
    $contagens[$tabela] = (int)$pdo->query("SELECT COUNT(*) FROM `$tabela`")->fetchColumn();
}
?>
<?php include __DIR__ . '/../includes/header.php'; ?>

<div class="page-top">
    <h2>⚙️ Painel Administrativo</h2>
    <p>Configurações do sistema — acesso restrito ao perfil master</p>
</div>

<main>

<?php if ($erro): ?>
    <div class="alerta alerta-erro">❌ <?= esc($erro) ?></div>
<?php endif; ?>
<?php if ($sucesso): ?>
    <div class="alerta alerta-sucesso">✅ <?= esc($sucesso) ?></div>
<?php endif; ?>

<?php /* ── MODO MANUTENÇÃO ─────────────────────────────────── */ ?>
<div class="card">
    <div class="card-titulo">🔧 Modo Manutenção</div>
    <p class="admin-descricao">
        Quando ativo, as restrições de horário são ignoradas para todas as operações.
        Timeout automático: <strong><?= MANUTENCAO_TIMEOUT / 60 ?> minutos</strong>.
    </p>

    <?php if ($manutencaoAtiva): ?>
        <div class="alerta alerta-aviso">
            ⚠️ <strong>Modo manutenção ATIVO</strong>
            <?php if (!empty($dadosManutencao['ativado_por'])): ?>
                — ativado por <strong><?= esc($dadosManutencao['ativado_por']) ?></strong>
            <?php endif; ?>
            <?php if ($tempoRestante): ?>
                — expira em <strong id="cronometro"><?= $tempoRestante ?></strong>
            <?php endif; ?>
        </div>
        <form method="post">
            <input type="hidden" name="acao" value="desativar_manutencao">
            <button type="submit" class="btn btn-vermelho">⏹ Desativar Manutenção</button>
        </form>
    <?php else: ?>
        <div class="alerta alerta-sucesso">✅ Sistema operando normalmente.</div>
        <form method="post">
            <input type="hidden" name="acao" value="ativar_manutencao">
            <button type="submit" class="btn btn-acento">▶ Ativar Modo Manutenção</button>
        </form>
    <?php endif; ?>
</div>

<?php /* ── TROCAR LOGO ──────────────────────────────────────── */ ?>
<div class="card">
    <div class="card-titulo">🖼️ Logo do Sistema</div>
    <p class="admin-descricao">
        A logo é exibida no cabeçalho de todas as páginas.
        Formatos aceitos: PNG, JPG ou WEBP. Tamanho máximo: 500 KB.
        A imagem será salva sempre como <code>logo.png</code>.
    </p>

    <div class="admin-logo-preview">
        <?php
        $logoPath = __DIR__ . '/../assets/img/logo.png';
        $logoUrl  = BASE_URL . '/assets/img/logo.png';
        ?>
        <?php if (file_exists($logoPath)): ?>
            <img src="<?= $logoUrl ?>?v=<?= filemtime($logoPath) ?>"
                 alt="Logo atual" class="admin-logo-img">
            <p style="font-size:12px; color:var(--cinza-texto); margin-top:6px">Logo atual</p>
        <?php else: ?>
            <div class="admin-logo-vazia">Nenhuma logo cadastrada</div>
        <?php endif; ?>
    </div>

    <form method="post" enctype="multipart/form-data" class="admin-logo-form">
        <input type="hidden" name="acao" value="trocar_logo">
        <div class="grupo-campo" style="max-width:320px">
            <label>Nova logo</label>
            <input type="file" name="nova_logo" class="campo"
                   accept="image/png,image/jpeg,image/webp" required>
        </div>
        <div class="grupo-campo" style="max-width:320px">
            <label>Senha master <span style="color:red">*</span></label>
            <input type="password" name="senha_master" class="campo"
                   placeholder="Digite a senha master" required>
        </div>
        <button type="submit" class="btn btn-primario">💾 Salvar Nova Logo</button>
    </form>
</div>

<?php /* ── BACKUP DO BANCO ──────────────────────────────────── */ ?>
<div class="card">
    <div class="card-titulo">💾 Backup do Banco de Dados</div>
    <p class="admin-descricao">
        Gera um arquivo <code>.sql</code> com todas as tabelas e dados do banco
        <strong><?= DB_NAME ?></strong>. Recomendado antes de qualquer reset.
    </p>
    <div class="admin-backup-info">
        <?php foreach ($contagens as $tabela => $qtd): ?>
            <div class="admin-backup-row">
                <span class="admin-backup-tabela"><?= $tabela ?></span>
                <span class="badge badge-cinza"><?= number_format($qtd) ?> registros</span>
            </div>
        <?php endforeach; ?>
    </div>
    <a href="<?= BASE_URL ?>/api/backup_banco.php"
       class="btn btn-verde" style="margin-top:16px">
        ⬇ Gerar e Baixar Backup
    </a>
</div>

<?php /* ── RESET DE DADOS ───────────────────────────────────── */ ?>
<div class="card admin-card-perigo">
    <div class="card-titulo" style="color:var(--vermelho)">🗑️ Reset de Dados Operacionais</div>
    <p class="admin-descricao">
        Apaga <strong>permanentemente</strong> todos os registros de saídas, retornos,
        vendas, tokens QR e log do sistema. Os cadastros de vendedores, produtos e
        usuários <strong>não serão afetados</strong>. Esta ação não pode ser desfeita.
    </p>

    <div class="admin-reset-contagens">
        <div class="admin-reset-row">
            <span>Saídas (reg_saidas)</span>
            <span class="badge <?= $contagens['reg_saidas'] > 0 ? 'badge-amarelo' : 'badge-verde' ?>">
                <?= number_format($contagens['reg_saidas']) ?>
            </span>
        </div>
        <div class="admin-reset-row">
            <span>Retornos (reg_retornos)</span>
            <span class="badge <?= $contagens['reg_retornos'] > 0 ? 'badge-amarelo' : 'badge-verde' ?>">
                <?= number_format($contagens['reg_retornos']) ?>
            </span>
        </div>
        <div class="admin-reset-row">
            <span>Vendas (reg_vendas)</span>
            <span class="badge <?= $contagens['reg_vendas'] > 0 ? 'badge-amarelo' : 'badge-verde' ?>">
                <?= number_format($contagens['reg_vendas']) ?>
            </span>
        </div>
        <div class="admin-reset-row">
            <span>Tokens QR (qr_tokens)</span>
            <span class="badge <?= $contagens['qr_tokens'] > 0 ? 'badge-amarelo' : 'badge-verde' ?>">
                <?= number_format($contagens['qr_tokens']) ?>
            </span>
        </div>
        <div class="admin-reset-row">
            <span>Log do sistema (log_sistema)</span>
            <span class="badge <?= $contagens['log_sistema'] > 0 ? 'badge-amarelo' : 'badge-verde' ?>">
                <?= number_format($contagens['log_sistema']) ?>
            </span>
        </div>
    </div>

    <!-- Formulário oculto — preenchido pelo JS após confirmação tripla -->
    <form method="post" id="form-reset" style="display:none">
        <input type="hidden" name="acao"         value="reset_dados">
        <input type="hidden" name="confirmacao"  id="input-confirmacao">
        <input type="hidden" name="senha_master" id="input-senha-reset">
    </form>

    <button type="button" class="btn btn-vermelho btn-grande" style="margin-top:16px"
            onclick="iniciarResetEtapa1()">
        🗑️ Iniciar Reset de Dados
    </button>
</div>

<?php /* ── MODAIS DE CONFIRMAÇÃO TRIPLA ───────────────────── */ ?>

<!-- Etapa 1: Aviso geral -->
<div id="modal-reset-1" class="modal" style="display:none">
    <div class="modal-box">
        <h3 style="color:var(--vermelho)">⚠️ Reset de Dados — Atenção</h3>
        <p style="margin-bottom:12px">Esta operação apagará <strong>TODOS</strong> os registros de:</p>
        <ul class="admin-reset-lista">
            <li>Reg_Saidas</li>
            <li>Reg_Retornos</li>
            <li>Reg_Vendas</li>
            <li>Tokens QR</li>
            <li>Log do sistema</li>
        </ul>
        <p style="margin-top:12px">Os cadastros de vendedores e produtos <strong>não serão afetados</strong>.</p>
        <p style="margin-top:10px; color:var(--vermelho); font-weight:600">
            Esta ação NÃO pode ser desfeita. Deseja continuar?
        </p>
        <div class="modal-botoes">
            <button type="button" class="btn btn-vermelho"
                    onclick="avancarResetEtapa2()">Sim, continuar</button>
            <button type="button" class="btn btn-secundario"
                    onclick="fecharTodosModais()">Não, cancelar</button>
        </div>
    </div>
</div>

<!-- Etapa 2: Confirmação textual -->
<div id="modal-reset-2" class="modal" style="display:none">
    <div class="modal-box">
        <h3>Confirmação Final — Digite o texto</h3>
        <p style="margin-bottom:10px">Para confirmar o reset, digite exatamente:</p>
        <p style="font-size:18px; font-weight:800; letter-spacing:1px; color:var(--vermelho); margin-bottom:14px">
            CONFIRMAR RESET
        </p>
        <p style="font-size:12px; color:var(--cinza-texto); margin-bottom:10px">
            (Texto em letras maiúsculas, sem aspas)
        </p>
        <input type="text" id="campo-confirmacao" class="campo"
               placeholder="Digite aqui..." autocomplete="off">
        <div id="erro-confirmacao" class="alerta alerta-erro" style="display:none; margin-top:8px">
            Texto incorreto. Digite exatamente: CONFIRMAR RESET
        </div>
        <div class="modal-botoes">
            <button type="button" class="btn btn-vermelho"
                    onclick="avancarResetEtapa3()">OK</button>
            <button type="button" class="btn btn-secundario"
                    onclick="fecharTodosModais()">Cancelar</button>
        </div>
    </div>
</div>

<!-- Etapa 3: Senha master -->
<div id="modal-reset-3" class="modal" style="display:none">
    <div class="modal-box">
        <h3>Autorização Final</h3>
        <p style="margin-bottom:14px">Digite a senha master para autorizar o reset:</p>
        <input type="password" id="campo-senha-reset" class="campo"
               placeholder="Senha master" autocomplete="off">
        <div id="erro-senha-reset" class="alerta alerta-erro" style="display:none; margin-top:8px">
            Senha não pode estar vazia.
        </div>
        <div class="modal-botoes">
            <button type="button" class="btn btn-vermelho"
                    onclick="concluirReset()">OK — Executar Reset</button>
            <button type="button" class="btn btn-secundario"
                    onclick="fecharTodosModais()">Cancelar</button>
        </div>
    </div>
</div>

</main>

<footer>
    <?= SISTEMA_NOME ?> v<?= SISTEMA_VERSAO ?>
    &nbsp;|&nbsp; <a href="<?= BASE_URL ?>/pages/admin.php">⚙ Admin</a>
</footer>

<script src="<?= BASE_URL ?>/assets/js/main.js"></script>
<script>
const BASE_URL = '<?= BASE_URL ?>';

// ── Cronômetro de manutenção ─────────────────────────────────────
<?php if ($manutencaoAtiva && !empty($dadosManutencao['ativado_em'])): ?>
const expiraEm  = <?= $dadosManutencao['ativado_em'] + MANUTENCAO_TIMEOUT ?>;
const cronEl    = document.getElementById('cronometro');
function atualizarCronometro() {
    const restante = Math.max(0, expiraEm - Math.floor(Date.now() / 1000));
    const min = String(Math.floor(restante / 60)).padStart(2, '0');
    const seg = String(restante % 60).padStart(2, '0');
    if (cronEl) cronEl.textContent = min + 'm ' + seg + 's';
    if (restante === 0) location.reload();
}
atualizarCronometro();
setInterval(atualizarCronometro, 1000);
<?php endif; ?>

// ── Funções do modal de logo (chamada do header.php) ─────────────
function abrirTrocarLogo() {
    // No admin.php a troca de logo é feita inline — o header chama essa função
    // mas aqui ignoramos o modal do header pois já temos o formulário na página
}
function fecharModal(id) {
    document.getElementById(id).style.display = 'none';
}

// ── Reset triplo ─────────────────────────────────────────────────
function iniciarResetEtapa1() {
    fecharTodosModais();
    document.getElementById('modal-reset-1').style.display = 'flex';
}

function avancarResetEtapa2() {
    document.getElementById('modal-reset-1').style.display = 'none';
    document.getElementById('campo-confirmacao').value     = '';
    document.getElementById('erro-confirmacao').style.display = 'none';
    document.getElementById('modal-reset-2').style.display = 'flex';
    setTimeout(() => document.getElementById('campo-confirmacao').focus(), 100);
}

function avancarResetEtapa3() {
    const val = document.getElementById('campo-confirmacao').value.trim();
    if (val !== 'CONFIRMAR RESET') {
        document.getElementById('erro-confirmacao').style.display = 'block';
        return;
    }
    document.getElementById('erro-confirmacao').style.display = 'none';
    document.getElementById('modal-reset-2').style.display    = 'none';
    document.getElementById('campo-senha-reset').value        = '';
    document.getElementById('erro-senha-reset').style.display = 'none';
    document.getElementById('modal-reset-3').style.display    = 'flex';
    setTimeout(() => document.getElementById('campo-senha-reset').focus(), 100);
}

function concluirReset() {
    const senha = document.getElementById('campo-senha-reset').value;
    if (!senha) {
        document.getElementById('erro-senha-reset').style.display = 'block';
        return;
    }
    // Preenche o form oculto e envia
    document.getElementById('input-confirmacao').value  = 'CONFIRMAR RESET';
    document.getElementById('input-senha-reset').value  = senha;
    document.getElementById('form-reset').submit();
}

function fecharTodosModais() {
    ['modal-reset-1','modal-reset-2','modal-reset-3'].forEach(id => {
        document.getElementById(id).style.display = 'none';
    });
}

// Fecha modais ao clicar fora
document.querySelectorAll('.modal').forEach(modal => {
    modal.addEventListener('click', e => {
        if (e.target === modal) fecharTodosModais();
    });
});

// Enter nos campos avança etapa
document.getElementById('campo-confirmacao')?.addEventListener('keydown', e => {
    if (e.key === 'Enter') avancarResetEtapa3();
});
document.getElementById('campo-senha-reset')?.addEventListener('keydown', e => {
    if (e.key === 'Enter') concluirReset();
});
</script>

</body>
</html>
