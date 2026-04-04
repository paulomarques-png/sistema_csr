<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

verificarLogin(); // Redireciona para login se não autenticado
?>
<?php include __DIR__ . '/../includes/header.php'; ?>

<main>
    <div class="card">
        <div class="card-titulo">✅ Login funcionando!</div>
        <p>Bem-vindo, <strong><?= esc($_SESSION['usuario_nome']) ?></strong>!</p>
        <p>Perfil: <span class="badge badge-verde"><?= esc($_SESSION['usuario_perfil']) ?></span></p>
        <p style="margin-top:12px; color:var(--cinza-texto)">
            O Dashboard completo será construído na Parte 2.
        </p>
    </div>
</main>

<footer>
    <?= SISTEMA_NOME ?> v<?= SISTEMA_VERSAO ?> —
    <a href="<?= BASE_URL ?>/index.php?logout=1">Sair</a>
</footer>
<script src="<?= BASE_URL ?>/assets/js/main.js"></script>
</body>
</html>