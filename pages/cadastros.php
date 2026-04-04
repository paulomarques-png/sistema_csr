<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

verificarPerfil(['supervisor', 'master']);

$pdo = conectar();
$aba = $_GET['aba'] ?? 'vendedores'; // aba ativa: vendedores | produtos | usuarios
$msg = '';
$erro = '';

// ================================================================
// PROCESSAMENTO DOS FORMULÁRIOS (POST)
// ================================================================

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $acao = $_POST['acao'] ?? '';

    // ── VENDEDORES ───────────────────────────────────────────────

    if ($acao === 'novo_vendedor') {
        $nome  = trim($_POST['nome'] ?? '');
        $senha = trim($_POST['senha'] ?? '');
        if (empty($nome) || empty($senha)) {
            $erro = 'Nome e senha são obrigatórios.';
        } else {
            $hash = password_hash($senha, PASSWORD_BCRYPT, ['cost' => 12]);
            $stmt = $pdo->prepare("INSERT INTO vendedores (nome, senha) VALUES (:nome, :senha)");
            $stmt->execute([':nome' => $nome, ':senha' => $hash]);
            registrarLog('CADASTRO', "Novo vendedor: $nome", obterIP());
            setFlash('ok', "Vendedor \"$nome\" cadastrado com sucesso!");
        }
        $aba = 'vendedores';
    }

    if ($acao === 'editar_vendedor') {
        $id    = (int)($_POST['id'] ?? 0);
        $nome  = trim($_POST['nome'] ?? '');
        $senha = trim($_POST['senha'] ?? '');
        if (empty($nome)) {
            $erro = 'Nome é obrigatório.';
        } else {
            if (!empty($senha)) {
                $hash = password_hash($senha, PASSWORD_BCRYPT, ['cost' => 12]);
                $stmt = $pdo->prepare("UPDATE vendedores SET nome=:nome, senha=:senha WHERE id=:id");
                $stmt->execute([':nome' => $nome, ':senha' => $hash, ':id' => $id]);
            } else {
                $stmt = $pdo->prepare("UPDATE vendedores SET nome=:nome WHERE id=:id");
                $stmt->execute([':nome' => $nome, ':id' => $id]);
            }
            registrarLog('EDICAO', "Vendedor editado: id=$id nome=$nome", obterIP());
            setFlash('ok', "Vendedor atualizado com sucesso!");
        }
        $aba = 'vendedores';
    }

    if ($acao === 'toggle_vendedor') {
        $id    = (int)($_POST['id'] ?? 0);
        $ativo = (int)($_POST['ativo'] ?? 0);
        $novo  = $ativo ? 0 : 1;
        $stmt  = $pdo->prepare("UPDATE vendedores SET ativo=:ativo WHERE id=:id");
        $stmt->execute([':ativo' => $novo, ':id' => $id]);
        setFlash('ok', 'Status do vendedor atualizado.');
        $aba = 'vendedores';
    }

    // ── PRODUTOS ─────────────────────────────────────────────────

    if ($acao === 'novo_produto') {
        $codigo   = strtoupper(trim($_POST['codigo']   ?? ''));
        $descricao= trim($_POST['descricao'] ?? '');
        $unidade  = strtoupper(trim($_POST['unidade']  ?? ''));
        if (empty($codigo) || empty($descricao)) {
            $erro = 'Código e descrição são obrigatórios.';
        } else {
            // Verifica código duplicado
            $chk = $pdo->prepare("SELECT id FROM produtos WHERE codigo = :codigo");
            $chk->execute([':codigo' => $codigo]);
            if ($chk->fetch()) {
                $erro = "Código \"$codigo\" já existe.";
            } else {
                $stmt = $pdo->prepare("
                    INSERT INTO produtos (codigo, descricao, unidade)
                    VALUES (:codigo, :descricao, :unidade)
                ");
                $stmt->execute([':codigo' => $codigo, ':descricao' => $descricao, ':unidade' => $unidade]);
                registrarLog('CADASTRO', "Novo produto: $codigo - $descricao", obterIP());
                setFlash('ok', "Produto \"$codigo\" cadastrado com sucesso!");
            }
        }
        $aba = 'produtos';
    }

    if ($acao === 'editar_produto') {
        $id       = (int)($_POST['id'] ?? 0);
        $codigo   = strtoupper(trim($_POST['codigo']    ?? ''));
        $descricao= trim($_POST['descricao']  ?? '');
        $unidade  = strtoupper(trim($_POST['unidade']   ?? ''));
        if (empty($codigo) || empty($descricao)) {
            $erro = 'Código e descrição são obrigatórios.';
        } else {
            // Verifica duplicata (exceto o próprio)
            $chk = $pdo->prepare("SELECT id FROM produtos WHERE codigo = :codigo AND id != :id");
            $chk->execute([':codigo' => $codigo, ':id' => $id]);
            if ($chk->fetch()) {
                $erro = "Código \"$codigo\" já pertence a outro produto.";
            } else {
                $stmt = $pdo->prepare("
                    UPDATE produtos SET codigo=:codigo, descricao=:descricao, unidade=:unidade
                    WHERE id=:id
                ");
                $stmt->execute([':codigo' => $codigo, ':descricao' => $descricao, ':unidade' => $unidade, ':id' => $id]);
                registrarLog('EDICAO', "Produto editado: id=$id codigo=$codigo", obterIP());
                setFlash('ok', "Produto atualizado com sucesso!");
            }
        }
        $aba = 'produtos';
    }

    if ($acao === 'toggle_produto') {
        $id    = (int)($_POST['id'] ?? 0);
        $ativo = (int)($_POST['ativo'] ?? 0);
        $novo  = $ativo ? 0 : 1;
        $stmt  = $pdo->prepare("UPDATE produtos SET ativo=:ativo WHERE id=:id");
        $stmt->execute([':ativo' => $novo, ':id' => $id]);
        setFlash('ok', 'Status do produto atualizado.');
        $aba = 'produtos';
    }

    // ── USUÁRIOS (somente master) ─────────────────────────────────

    if ($acao === 'novo_usuario') {
        verificarPerfil(['master']);
        $nome   = trim($_POST['nome']   ?? '');
        $perfil = trim($_POST['perfil'] ?? '');
        $senha  = trim($_POST['senha']  ?? '');
        $perfisValidos = ['operador','admin','supervisor','master'];
        if (empty($nome) || empty($perfil) || empty($senha)) {
            $erro = 'Todos os campos são obrigatórios.';
        } elseif (!in_array($perfil, $perfisValidos)) {
            $erro = 'Perfil inválido.';
        } else {
            $hash = password_hash($senha, PASSWORD_BCRYPT, ['cost' => 12]);
            $stmt = $pdo->prepare("
                INSERT INTO usuarios (nome, perfil, senha_hash)
                VALUES (:nome, :perfil, :hash)
            ");
            $stmt->execute([':nome' => $nome, ':perfil' => $perfil, ':hash' => $hash]);
            registrarLog('CADASTRO', "Novo usuário: $nome ($perfil)", obterIP());
            setFlash('ok', "Usuário \"$nome\" cadastrado com sucesso!");
        }
        $aba = 'usuarios';
    }

    if ($acao === 'editar_usuario') {
        verificarPerfil(['master']);
        $id     = (int)($_POST['id']     ?? 0);
        $nome   = trim($_POST['nome']    ?? '');
        $perfil = trim($_POST['perfil']  ?? '');
        $senha  = trim($_POST['senha']   ?? '');
        if (empty($nome) || empty($perfil)) {
            $erro = 'Nome e perfil são obrigatórios.';
        } else {
            if (!empty($senha)) {
                $hash = password_hash($senha, PASSWORD_BCRYPT, ['cost' => 12]);
                $stmt = $pdo->prepare("
                    UPDATE usuarios SET nome=:nome, perfil=:perfil, senha_hash=:hash WHERE id=:id
                ");
                $stmt->execute([':nome' => $nome, ':perfil' => $perfil, ':hash' => $hash, ':id' => $id]);
            } else {
                $stmt = $pdo->prepare("UPDATE usuarios SET nome=:nome, perfil=:perfil WHERE id=:id");
                $stmt->execute([':nome' => $nome, ':perfil' => $perfil, ':id' => $id]);
            }
            registrarLog('EDICAO', "Usuário editado: id=$id nome=$nome perfil=$perfil", obterIP());
            setFlash('ok', "Usuário atualizado com sucesso!");
        }
        $aba = 'usuarios';
    }

    if ($acao === 'toggle_usuario') {
        verificarPerfil(['master']);
        $id    = (int)($_POST['id']    ?? 0);
        $ativo = (int)($_POST['ativo'] ?? 0);
        // Impede desativar o próprio usuário logado
        if ($id === (int)$_SESSION['usuario_id']) {
            $erro = 'Você não pode desativar o próprio usuário.';
        } else {
            $novo = $ativo ? 0 : 1;
            $stmt = $pdo->prepare("UPDATE usuarios SET ativo=:ativo WHERE id=:id");
            $stmt->execute([':ativo' => $novo, ':id' => $id]);
            setFlash('ok', 'Status do usuário atualizado.');
        }
        $aba = 'usuarios';
    }

    // Redireciona para evitar reenvio de form (PRG pattern)
    $flashOk   = flash('ok');
    $redirMsg  = $erro   ? '&erro='   . urlencode($erro)    : '';
    $redirMsg .= $flashOk? '&ok='     . urlencode($flashOk) : '';
    header("Location: " . BASE_URL . "/pages/cadastros.php?aba=$aba$redirMsg");
    exit;
}

// Lê mensagens da URL após redirect
if (!empty($_GET['ok']))   $msg  = esc($_GET['ok']);
if (!empty($_GET['erro'])) $erro = esc($_GET['erro']);

// ================================================================
// BUSCA DE DADOS PARA EXIBIÇÃO
// ================================================================

$buscaVendedor = trim($_GET['bv'] ?? '');
$buscaProduto  = trim($_GET['bp'] ?? '');
$buscaUsuario  = trim($_GET['bu'] ?? '');

// Vendedores
$sqlV = "SELECT * FROM vendedores WHERE 1=1";
$parV = [];
if ($buscaVendedor) {
    $sqlV .= " AND nome LIKE :busca";
    $parV[':busca'] = "%$buscaVendedor%";
}
$sqlV .= " ORDER BY ativo DESC, nome ASC";
$stmtV = $pdo->prepare($sqlV);
$stmtV->execute($parV);
$vendedores = $stmtV->fetchAll();

// Produtos
$sqlP = "SELECT * FROM produtos WHERE 1=1";
$parP = [];
if ($buscaProduto) {
    $sqlP .= " AND (codigo LIKE :busca OR descricao LIKE :busca)";
    $parP[':busca'] = "%$buscaProduto%";
}
$sqlP .= " ORDER BY ativo DESC, descricao ASC";
$stmtP = $pdo->prepare($sqlP);
$stmtP->execute($parP);
$produtos = $stmtP->fetchAll();

// Usuários (somente master pode ver)
$usuarios = [];
if ($_SESSION['usuario_perfil'] === 'master') {
    $sqlU = "SELECT * FROM usuarios WHERE 1=1";
    $parU = [];
    if ($buscaUsuario) {
        $sqlU .= " AND (nome LIKE :busca OR perfil LIKE :busca)";
        $parU[':busca'] = "%$buscaUsuario%";
    }
    $sqlU .= " ORDER BY ativo DESC, nome ASC";
    $stmtU = $pdo->prepare($sqlU);
    $stmtU->execute($parU);
    $usuarios = $stmtU->fetchAll();
}
?>
<?php include __DIR__ . '/../includes/header.php'; ?>

<main>

    <?php if ($msg):  ?><div class="alerta alerta-sucesso"><?= $msg  ?></div><?php endif; ?>
    <?php if ($erro): ?><div class="alerta alerta-erro"   ><?= $erro ?></div><?php endif; ?>

    <?php /* ── Abas de navegação ──────────────────────── */ ?>
    <div class="abas">
        <a href="?aba=vendedores" class="aba <?= $aba === 'vendedores' ? 'aba-ativa' : '' ?>">
            👤 Vendedores <span class="aba-badge"><?= count($vendedores) ?></span>
        </a>
        <a href="?aba=produtos" class="aba <?= $aba === 'produtos' ? 'aba-ativa' : '' ?>">
            📦 Produtos <span class="aba-badge"><?= count($produtos) ?></span>
        </a>
        <?php if ($_SESSION['usuario_perfil'] === 'master'): ?>
        <a href="?aba=usuarios" class="aba <?= $aba === 'usuarios' ? 'aba-ativa' : '' ?>">
            🔑 Usuários <span class="aba-badge"><?= count($usuarios) ?></span>
        </a>
        <?php endif; ?>
    </div>

    <?php /* ════════════════════════════════════════════
           ABA VENDEDORES
           ════════════════════════════════════════════ */ ?>
    <?php if ($aba === 'vendedores'): ?>
    <div class="card">
        <div class="card-titulo" style="display:flex; justify-content:space-between; align-items:center;">
            <span>👤 Vendedores</span>
            <button onclick="abrirModal('modal-novo-vendedor')" class="btn btn-primario">
                + Novo Vendedor
            </button>
        </div>

        <!-- Busca -->
        <form method="get" style="display:flex; gap:8px; margin-bottom:16px;">
            <input type="hidden" name="aba" value="vendedores">
            <input type="text" name="bv" value="<?= esc($buscaVendedor) ?>"
                   class="campo" placeholder="🔍 Buscar por nome..." style="max-width:300px">
            <button type="submit" class="btn btn-secundario">Buscar</button>
            <?php if ($buscaVendedor): ?>
                <a href="?aba=vendedores" class="btn btn-secundario">✕ Limpar</a>
            <?php endif; ?>
        </form>

        <!-- Tabela -->
        <div class="tabela-wrapper">
            <table>
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Nome</th>
                        <th style="text-align:center">Status</th>
                        <th style="text-align:center">Cadastrado em</th>
                        <th style="text-align:center">Ações</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($vendedores)): ?>
                    <tr><td colspan="5" style="text-align:center; color:var(--cinza-texto); padding:24px">
                        Nenhum vendedor encontrado.
                    </td></tr>
                    <?php endif; ?>

                    <?php foreach ($vendedores as $v): ?>
                    <tr style="<?= $v['ativo'] ? '' : 'opacity:.55' ?>">
                        <td><?= $v['id'] ?></td>
                        <td><strong><?= esc($v['nome']) ?></strong></td>
                        <td style="text-align:center">
                            <span class="badge <?= $v['ativo'] ? 'badge-verde' : 'badge-cinza' ?>">
                                <?= $v['ativo'] ? 'Ativo' : 'Inativo' ?>
                            </span>
                        </td>
                        <td style="text-align:center"><?= formatarData($v['criado_em']) ?></td>
                        <td style="text-align:center; white-space:nowrap">
                            <!-- Editar -->
                            <button onclick="abrirEditarVendedor(<?= $v['id'] ?>, '<?= esc(addslashes($v['nome'])) ?>')"
                                    class="btn btn-acento" style="padding:4px 12px; font-size:13px">
                                ✏️ Editar
                            </button>
                            <!-- Ativar/Desativar -->
                            <form method="post" style="display:inline"
                                  onsubmit="return confirm('<?= $v['ativo'] ? 'Desativar' : 'Ativar' ?> este vendedor?')">
                                <input type="hidden" name="acao"  value="toggle_vendedor">
                                <input type="hidden" name="id"    value="<?= $v['id'] ?>">
                                <input type="hidden" name="ativo" value="<?= $v['ativo'] ?>">
                                <button type="submit"
                                        class="btn <?= $v['ativo'] ? 'btn-vermelho' : 'btn-verde' ?>"
                                        style="padding:4px 12px; font-size:13px">
                                    <?= $v['ativo'] ? '🔴 Desativar' : '🟢 Ativar' ?>
                                </button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Modal: Novo Vendedor -->
    <div id="modal-novo-vendedor" class="modal" style="display:none">
        <div class="modal-box">
            <h3>👤 Novo Vendedor</h3>
            <form method="post">
                <input type="hidden" name="acao" value="novo_vendedor">
                <div class="grupo-campo">
                    <label>Nome completo</label>
                    <input type="text" name="nome" class="campo" placeholder="Nome do vendedor" required autofocus>
                </div>
                <div class="grupo-campo">
                    <label>Senha QR Code</label>
                    <input type="text" name="senha" class="campo"
                           placeholder="Senha que o vendedor usará para confirmar no celular" required>
                    <small style="color:var(--cinza-texto)">
                        ⚠️ Anote e entregue a senha ao vendedor. Ela não será exibida novamente.
                    </small>
                </div>
                <div class="modal-botoes">
                    <button type="submit" class="btn btn-primario">Cadastrar</button>
                    <button type="button" class="btn btn-secundario"
                            onclick="fecharModal('modal-novo-vendedor')">Cancelar</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Modal: Editar Vendedor -->
    <div id="modal-editar-vendedor" class="modal" style="display:none">
        <div class="modal-box">
            <h3>✏️ Editar Vendedor</h3>
            <form method="post">
                <input type="hidden" name="acao" value="editar_vendedor">
                <input type="hidden" name="id"   id="edit-vend-id">
                <div class="grupo-campo">
                    <label>Nome completo</label>
                    <input type="text" name="nome" id="edit-vend-nome" class="campo" required>
                </div>
                <div class="grupo-campo">
                    <label>Nova Senha QR Code <small style="font-weight:normal">(deixe em branco para manter)</small></label>
                    <input type="text" name="senha" class="campo" placeholder="Nova senha (opcional)">
                </div>
                <div class="modal-botoes">
                    <button type="submit" class="btn btn-primario">Salvar</button>
                    <button type="button" class="btn btn-secundario"
                            onclick="fecharModal('modal-editar-vendedor')">Cancelar</button>
                </div>
            </form>
        </div>
    </div>
    <?php endif; /* fim aba vendedores */ ?>


    <?php /* ════════════════════════════════════════════
           ABA PRODUTOS
           ════════════════════════════════════════════ */ ?>
    <?php if ($aba === 'produtos'): ?>
    <div class="card">
        <div class="card-titulo" style="display:flex; justify-content:space-between; align-items:center;">
            <span>📦 Produtos</span>
            <button onclick="abrirModal('modal-novo-produto')" class="btn btn-primario">
                + Novo Produto
            </button>
        </div>

        <!-- Busca -->
        <form method="get" style="display:flex; gap:8px; margin-bottom:16px;">
            <input type="hidden" name="aba" value="produtos">
            <input type="text" name="bp" value="<?= esc($buscaProduto) ?>"
                   class="campo" placeholder="🔍 Código ou descrição..." style="max-width:300px">
            <button type="submit" class="btn btn-secundario">Buscar</button>
            <?php if ($buscaProduto): ?>
                <a href="?aba=produtos" class="btn btn-secundario">✕ Limpar</a>
            <?php endif; ?>
        </form>

        <!-- Tabela -->
        <div class="tabela-wrapper">
            <table>
                <thead>
                    <tr>
                        <th>Código</th>
                        <th>Descrição</th>
                        <th style="text-align:center">Unidade</th>
                        <th style="text-align:center">Status</th>
                        <th style="text-align:center">Ações</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($produtos)): ?>
                    <tr><td colspan="5" style="text-align:center; color:var(--cinza-texto); padding:24px">
                        Nenhum produto encontrado.
                    </td></tr>
                    <?php endif; ?>

                    <?php foreach ($produtos as $p): ?>
                    <tr style="<?= $p['ativo'] ? '' : 'opacity:.55' ?>">
                        <td><code style="background:#eef; padding:2px 6px; border-radius:4px">
                            <?= esc($p['codigo']) ?>
                        </code></td>
                        <td><?= esc($p['descricao']) ?></td>
                        <td style="text-align:center"><?= esc($p['unidade']) ?: '—' ?></td>
                        <td style="text-align:center">
                            <span class="badge <?= $p['ativo'] ? 'badge-verde' : 'badge-cinza' ?>">
                                <?= $p['ativo'] ? 'Ativo' : 'Inativo' ?>
                            </span>
                        </td>
                        <td style="text-align:center; white-space:nowrap">
                            <button onclick="abrirEditarProduto(<?= $p['id'] ?>, '<?= esc(addslashes($p['codigo'])) ?>', '<?= esc(addslashes($p['descricao'])) ?>', '<?= esc(addslashes($p['unidade'])) ?>')"
                                    class="btn btn-acento" style="padding:4px 12px; font-size:13px">
                                ✏️ Editar
                            </button>
                            <form method="post" style="display:inline"
                                  onsubmit="return confirm('<?= $p['ativo'] ? 'Desativar' : 'Ativar' ?> este produto?')">
                                <input type="hidden" name="acao"  value="toggle_produto">
                                <input type="hidden" name="id"    value="<?= $p['id'] ?>">
                                <input type="hidden" name="ativo" value="<?= $p['ativo'] ?>">
                                <button type="submit"
                                        class="btn <?= $p['ativo'] ? 'btn-vermelho' : 'btn-verde' ?>"
                                        style="padding:4px 12px; font-size:13px">
                                    <?= $p['ativo'] ? '🔴 Desativar' : '🟢 Ativar' ?>
                                </button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Modal: Novo Produto -->
    <div id="modal-novo-produto" class="modal" style="display:none">
        <div class="modal-box">
            <h3>📦 Novo Produto</h3>
            <form method="post">
                <input type="hidden" name="acao" value="novo_produto">
                <div class="grupo-campo">
                    <label>Código</label>
                    <input type="text" name="codigo" class="campo"
                           placeholder="Ex: PROD001" required autofocus
                           style="text-transform:uppercase">
                </div>
                <div class="grupo-campo">
                    <label>Descrição</label>
                    <input type="text" name="descricao" class="campo"
                           placeholder="Nome completo do produto" required>
                </div>
                <div class="grupo-campo">
                    <label>Unidade <small style="font-weight:normal">(opcional)</small></label>
                    <input type="text" name="unidade" class="campo"
                           placeholder="Ex: CX, UN, KG, L"
                           style="text-transform:uppercase; max-width:120px">
                </div>
                <div class="modal-botoes">
                    <button type="submit" class="btn btn-primario">Cadastrar</button>
                    <button type="button" class="btn btn-secundario"
                            onclick="fecharModal('modal-novo-produto')">Cancelar</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Modal: Editar Produto -->
    <div id="modal-editar-produto" class="modal" style="display:none">
        <div class="modal-box">
            <h3>✏️ Editar Produto</h3>
            <form method="post">
                <input type="hidden" name="acao"  value="editar_produto">
                <input type="hidden" name="id"    id="edit-prod-id">
                <div class="grupo-campo">
                    <label>Código</label>
                    <input type="text" name="codigo" id="edit-prod-codigo" class="campo"
                           required style="text-transform:uppercase">
                </div>
                <div class="grupo-campo">
                    <label>Descrição</label>
                    <input type="text" name="descricao" id="edit-prod-descricao" class="campo" required>
                </div>
                <div class="grupo-campo">
                    <label>Unidade</label>
                    <input type="text" name="unidade" id="edit-prod-unidade" class="campo"
                           style="text-transform:uppercase; max-width:120px">
                </div>
                <div class="modal-botoes">
                    <button type="submit" class="btn btn-primario">Salvar</button>
                    <button type="button" class="btn btn-secundario"
                            onclick="fecharModal('modal-editar-produto')">Cancelar</button>
                </div>
            </form>
        </div>
    </div>
    <?php endif; /* fim aba produtos */ ?>


    <?php /* ════════════════════════════════════════════
           ABA USUÁRIOS (somente master)
           ════════════════════════════════════════════ */ ?>
    <?php if ($aba === 'usuarios' && $_SESSION['usuario_perfil'] === 'master'): ?>
    <div class="card">
        <div class="card-titulo" style="display:flex; justify-content:space-between; align-items:center;">
            <span>🔑 Usuários do Sistema</span>
            <button onclick="abrirModal('modal-novo-usuario')" class="btn btn-primario">
                + Novo Usuário
            </button>
        </div>

        <!-- Busca -->
        <form method="get" style="display:flex; gap:8px; margin-bottom:16px;">
            <input type="hidden" name="aba" value="usuarios">
            <input type="text" name="bu" value="<?= esc($buscaUsuario) ?>"
                   class="campo" placeholder="🔍 Nome ou perfil..." style="max-width:300px">
            <button type="submit" class="btn btn-secundario">Buscar</button>
            <?php if ($buscaUsuario): ?>
                <a href="?aba=usuarios" class="btn btn-secundario">✕ Limpar</a>
            <?php endif; ?>
        </form>

        <div class="tabela-wrapper">
            <table>
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Nome</th>
                        <th style="text-align:center">Perfil</th>
                        <th style="text-align:center">Status</th>
                        <th style="text-align:center">Cadastrado em</th>
                        <th style="text-align:center">Ações</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($usuarios)): ?>
                    <tr><td colspan="6" style="text-align:center; color:var(--cinza-texto); padding:24px">
                        Nenhum usuário encontrado.
                    </td></tr>
                    <?php endif; ?>

                    <?php foreach ($usuarios as $u): ?>
                    <tr style="<?= $u['ativo'] ? '' : 'opacity:.55' ?>">
                        <td><?= $u['id'] ?></td>
                        <td>
                            <strong><?= esc($u['nome']) ?></strong>
                            <?php if ($u['id'] == $_SESSION['usuario_id']): ?>
                                <span class="badge badge-azul" style="font-size:11px; background:#e8f4fd; color:var(--acento)">você</span>
                            <?php endif; ?>
                        </td>
                        <td style="text-align:center">
                            <?php
                            $corPerfil = match($u['perfil']) {
                                'master'     => 'badge-vermelho',
                                'supervisor' => 'badge-amarelo',
                                'admin'      => 'badge-verde',
                                default      => 'badge-cinza',
                            };
                            ?>
                            <span class="badge <?= $corPerfil ?>"><?= esc($u['perfil']) ?></span>
                        </td>
                        <td style="text-align:center">
                            <span class="badge <?= $u['ativo'] ? 'badge-verde' : 'badge-cinza' ?>">
                                <?= $u['ativo'] ? 'Ativo' : 'Inativo' ?>
                            </span>
                        </td>
                        <td style="text-align:center"><?= formatarData($u['criado_em']) ?></td>
                        <td style="text-align:center; white-space:nowrap">
                            <button onclick="abrirEditarUsuario(<?= $u['id'] ?>, '<?= esc(addslashes($u['nome'])) ?>', '<?= esc($u['perfil']) ?>')"
                                    class="btn btn-acento" style="padding:4px 12px; font-size:13px">
                                ✏️ Editar
                            </button>
                            <?php if ($u['id'] != $_SESSION['usuario_id']): ?>
                            <form method="post" style="display:inline"
                                  onsubmit="return confirm('<?= $u['ativo'] ? 'Desativar' : 'Ativar' ?> este usuário?')">
                                <input type="hidden" name="acao"  value="toggle_usuario">
                                <input type="hidden" name="id"    value="<?= $u['id'] ?>">
                                <input type="hidden" name="ativo" value="<?= $u['ativo'] ?>">
                                <button type="submit"
                                        class="btn <?= $u['ativo'] ? 'btn-vermelho' : 'btn-verde' ?>"
                                        style="padding:4px 12px; font-size:13px">
                                    <?= $u['ativo'] ? '🔴 Desativar' : '🟢 Ativar' ?>
                                </button>
                            </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Modal: Novo Usuário -->
    <div id="modal-novo-usuario" class="modal" style="display:none">
        <div class="modal-box">
            <h3>🔑 Novo Usuário</h3>
            <form method="post">
                <input type="hidden" name="acao" value="novo_usuario">
                <div class="grupo-campo">
                    <label>Nome</label>
                    <input type="text" name="nome" class="campo" placeholder="Nome completo" required autofocus>
                </div>
                <div class="grupo-campo">
                    <label>Perfil</label>
                    <select name="perfil" class="campo" required>
                        <option value="">Selecione...</option>
                        <option value="operador">Operador — registra saídas e retornos</option>
                        <option value="admin">Admin — confirma vendas e relatórios</option>
                        <option value="supervisor">Supervisor — libera fora do horário</option>
                        <option value="master">Master — acesso total</option>
                    </select>
                </div>
                <div class="grupo-campo">
                    <label>Senha</label>
                    <input type="password" name="senha" class="campo" placeholder="Senha de acesso" required>
                </div>
                <div class="modal-botoes">
                    <button type="submit" class="btn btn-primario">Cadastrar</button>
                    <button type="button" class="btn btn-secundario"
                            onclick="fecharModal('modal-novo-usuario')">Cancelar</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Modal: Editar Usuário -->
    <div id="modal-editar-usuario" class="modal" style="display:none">
        <div class="modal-box">
            <h3>✏️ Editar Usuário</h3>
            <form method="post">
                <input type="hidden" name="acao"   value="editar_usuario">
                <input type="hidden" name="id"     id="edit-usr-id">
                <div class="grupo-campo">
                    <label>Nome</label>
                    <input type="text" name="nome" id="edit-usr-nome" class="campo" required>
                </div>
                <div class="grupo-campo">
                    <label>Perfil</label>
                    <select name="perfil" id="edit-usr-perfil" class="campo" required>
                        <option value="operador">Operador</option>
                        <option value="admin">Admin</option>
                        <option value="supervisor">Supervisor</option>
                        <option value="master">Master</option>
                    </select>
                </div>
                <div class="grupo-campo">
                    <label>Nova Senha <small style="font-weight:normal">(deixe em branco para manter)</small></label>
                    <input type="password" name="senha" class="campo" placeholder="Nova senha (opcional)">
                </div>
                <div class="modal-botoes">
                    <button type="submit" class="btn btn-primario">Salvar</button>
                    <button type="button" class="btn btn-secundario"
                            onclick="fecharModal('modal-editar-usuario')">Cancelar</button>
                </div>
            </form>
        </div>
    </div>
    <?php endif; /* fim aba usuarios */ ?>

</main>

<footer>
    <?= SISTEMA_NOME ?> v<?= SISTEMA_VERSAO ?>
    <?php if ($_SESSION['usuario_perfil'] === 'master'): ?>
        &nbsp;|&nbsp; <a href="<?= BASE_URL ?>/pages/admin.php">⚙ Admin</a>
    <?php endif; ?>
</footer>

<script src="<?= BASE_URL ?>/assets/js/main.js"></script>
<script>
// Preenche modal de edição — Vendedor
function abrirEditarVendedor(id, nome) {
    document.getElementById('edit-vend-id').value   = id;
    document.getElementById('edit-vend-nome').value = nome;
    abrirModal('modal-editar-vendedor');
}

// Preenche modal de edição — Produto
function abrirEditarProduto(id, codigo, descricao, unidade) {
    document.getElementById('edit-prod-id').value       = id;
    document.getElementById('edit-prod-codigo').value   = codigo;
    document.getElementById('edit-prod-descricao').value= descricao;
    document.getElementById('edit-prod-unidade').value  = unidade;
    abrirModal('modal-editar-produto');
}

// Preenche modal de edição — Usuário
function abrirEditarUsuario(id, nome, perfil) {
    document.getElementById('edit-usr-id').value     = id;
    document.getElementById('edit-usr-nome').value   = nome;
    document.getElementById('edit-usr-perfil').value = perfil;
    abrirModal('modal-editar-usuario');
}
</script>

</body>
</html>