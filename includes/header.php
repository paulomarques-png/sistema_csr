<?php
/**
 * Header HTML — incluído em todas as páginas internas
 * Requer que a sessão já esteja iniciada e o usuário logado
 */
$paginaAtual = basename($_SERVER['PHP_SELF'], '.php');
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= SISTEMA_NOME ?></title>
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/style.css">
    <?php if (!empty($cssExtra)): ?>
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/<?= $cssExtra ?>.css">
    <?php endif; ?>
</head>
<body>

<?php if (modoManutencaoAtivo()): ?>
<div class="banner-manutencao">
    ⚙️ MODO MANUTENÇÃO ATIVO — Operando fora das restrições de horário
    <form method="post" action="<?= BASE_URL ?>/pages/admin.php" style="display:inline">
        <input type="hidden" name="acao" value="desativar_manutencao">
        <button type="submit" class="btn-banner-fechar">Desativar</button>
    </form>
</div>
<?php endif; ?>

<header class="site-header">
    <?php
    $logoPath = __DIR__ . '/../assets/img/logo.png';
    $logoUrl  = BASE_URL . '/assets/img/logo.png';
    ?>
    <div class="header-logo">
        <?php if (file_exists($logoPath)): ?>
            <img src="<?= $logoUrl ?>" alt="Logo" class="logo-img"
                onclick="abrirTrocarLogo()" title="Clique para alterar a logo">
        <?php else: ?>
            <span class="logo-texto" onclick="abrirTrocarLogo()" title="Clique para adicionar logo">
              📦  <?= SISTEMA_NOME ?>
            </span>
        <?php endif; ?>
    </div>

    <?php if (file_exists($logoPath)): ?>
    <div class="logo-texto">
        <h1>📦  <?= SISTEMA_NOME ?></h1>
    </div>
    <?php endif; ?>

    <div class="header-info">
        <div class="relogio" id="relogio">--:--:--</div>
        <div class="usuario-info">
            👤 <?= esc($_SESSION['usuario_nome'] ?? '') ?>
            <span class="badge-perfil"><?= esc($_SESSION['usuario_perfil'] ?? '') ?></span>
        </div>
        <a href="<?= BASE_URL ?>/index.php?logout=1" class="btn-logout" title="Sair">Sair</a>
    </div>
</header>

<nav class="site-nav">
    <a href="<?= BASE_URL ?>/index.php"
       class="<?= $paginaAtual === 'index' ? 'ativo' : '' ?>">
        🏠 Início
    </a>

    <?php if (in_array($_SESSION['usuario_perfil'] ?? '', ['operador','supervisor','master'])): ?>
    <a href="<?= BASE_URL ?>/pages/saida.php"
       class="<?= $paginaAtual === 'saida' ? 'ativo' : '' ?>">
        📤 Saída
    </a>
    <a href="<?= BASE_URL ?>/pages/retorno.php"
       class="<?= $paginaAtual === 'retorno' ? 'ativo' : '' ?>">
        📥 Retorno
    </a>
    <?php endif; ?>

    <?php if (in_array($_SESSION['usuario_perfil'] ?? '', ['admin','supervisor','master'])): ?>
    <a href="<?= BASE_URL ?>/pages/confirmar_venda.php"
       class="<?= $paginaAtual === 'venda' ? 'ativo' : '' ?>">
        ✅ Confirmar Venda
    </a>
    <?php endif; ?>

    <?php if (in_array($_SESSION['usuario_perfil'] ?? '', ['admin','supervisor','master'])): ?>
    <a href="<?= BASE_URL ?>/pages/relatorios.php"
       class="<?= $paginaAtual === 'relatorios' ? 'ativo' : '' ?>">
        📊 Relatórios
    </a>
    <?php endif; ?>

    <?php if (in_array($_SESSION['usuario_perfil'] ?? '', ['supervisor','master'])): ?>
    <a href="<?= BASE_URL ?>/pages/cadastros.php"
       class="<?= $paginaAtual === 'cadastros' ? 'ativo' : '' ?>">
        📋 Cadastros
    </a>
    <?php endif; ?>
</nav>

<!-- Modal troca de logo (só aparece quando clica na logo) -->
<div id="modal-logo" class="modal" style="display:none">
    <div class="modal-box">
        <h3>Alterar Logo</h3>
        <p>Digite a senha master para continuar:</p>
        <form method="post" action="<?= BASE_URL ?>/pages/admin.php"
              enctype="multipart/form-data">
            <input type="hidden" name="acao" value="trocar_logo">
            <input type="password" name="senha_master"
                   placeholder="Senha master" class="campo" required>
            <input type="file" name="nova_logo"
                   accept="image/png,image/jpeg,image/webp"
                   class="campo" required>
            <div class="modal-botoes">
                <button type="submit" class="btn btn-primario">Trocar Logo</button>
                <button type="button" class="btn btn-secundario"
                        onclick="fecharModal('modal-logo')">Cancelar</button>
            </div>
        </form>
    </div>
</div>