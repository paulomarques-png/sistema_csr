<?php
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

// Processa o logout
if (isset($_GET['logout'])) {
    encerrarSessao('Você saiu do sistema.');
}

// ── Helper: redireciona para a página correta por perfil ─────────
function redirecionarPorPerfil(): void {
    $destino = ($_SESSION['usuario_perfil'] === 'vendedor')
        ? BASE_URL . '/pages/vendedor_app.php'
        : BASE_URL . '/pages/dashboard.php';
    header('Location: ' . $destino);
    exit;
}

// Se já estiver logado, redireciona para a página correta
if (isset($_SESSION['usuario_id'])) {
    redirecionarPorPerfil();
}

$erro    = '';
$sucesso = '';

if (!empty($_GET['msg'])) {
    $sucesso = esc($_GET['msg']);
}
if (!empty($_GET['erro'])) {
    $erro = match($_GET['erro']) {
        'sem_permissao' => 'Você não tem permissão para acessar essa área.',
        default         => esc($_GET['erro']),
    };
}

// Processa o formulário de login
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nome  = trim($_POST['nome']  ?? '');
    $senha = trim($_POST['senha'] ?? '');

    if (empty($nome) || empty($senha)) {
        $erro = 'Preencha o usuário e a senha.';
    } else {
        $resultado = fazerLogin($nome, $senha);

        if ($resultado['ok']) {
            redirecionarPorPerfil(); // ✅ usa o perfil gravado na sessão
        } else {
            $erro = $resultado['msg'];
        }
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login — <?= SISTEMA_NOME ?></title>
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/style.css">
</head>
<body>

<div class="login-container">
    <div class="login-box">

        <?php
        $logoPath = __DIR__ . '/assets/img/logo.png';
        $logoUrl  = BASE_URL . '/assets/img/logo.png';
        if (file_exists($logoPath)):
        ?>
            <img src="<?= $logoUrl ?>" alt="Logo" style="max-height:70px; margin-bottom:16px;">
        <?php else: ?>
            <div style="font-size:48px; margin-bottom:8px;">📦</div>
        <?php endif; ?>

        <h2><?= SISTEMA_NOME ?></h2>
        <p class="subtitulo">Acesso restrito a usuários autorizados</p>

        <?php if ($erro): ?>
            <div class="alerta alerta-erro"><?= $erro ?></div>
        <?php endif; ?>

        <?php if ($sucesso): ?>
            <div class="alerta alerta-sucesso"><?= $sucesso ?></div>
        <?php endif; ?>

        <form method="post" autocomplete="off">
            <div class="grupo-campo">
                <label for="nome">Usuário</label>
                <input type="text"
                       id="nome"
                       name="nome"
                       class="campo"
                       placeholder="Nome de usuário"
                       value="<?= esc($_POST['nome'] ?? '') ?>"
                       required
                       autofocus>
            </div>

            <div class="grupo-campo">
                <label for="senha">Senha</label>
                <div style="position:relative">
                    <input type="password"
                           id="senha"
                           name="senha"
                           class="campo"
                           placeholder="Senha"
                           required
                           style="padding-right:44px">
                    <button type="button"
                            onclick="toggleSenha('senha')"
                            style="position:absolute;right:8px;top:50%;transform:translateY(-50%);
                                   background:none;border:none;cursor:pointer;font-size:16px;"
                            title="Mostrar/ocultar senha">👁️</button>
                </div>
            </div>

            <button type="submit" class="btn btn-primario btn-login">
                Entrar
            </button>
        </form>

        <footer style="margin-top:24px; padding:0">
            <a href="<?= BASE_URL ?>/pages/admin.php" title="Área administrativa">⚙</a>
        </footer>

    </div>
</div>

<script src="<?= BASE_URL ?>/assets/js/main.js"></script>
</body>
</html>