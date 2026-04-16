<?php
/**
 * Autenticação, sessões e controle de acesso
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

// ── Verifica se o usuário está logado ───────────────────
function verificarLogin(): void {
    if (!isset($_SESSION['usuario_id'])) {
        header('Location: ' . BASE_URL . '/index.php');
        exit;
    }

    // Verifica timeout de inatividade
    if (isset($_SESSION['ultimo_acesso'])) {
        $inativo = time() - $_SESSION['ultimo_acesso'];
        if ($inativo > SESSAO_TIMEOUT) {
            encerrarSessao('Sessão expirada por inatividade.');
        }
    }

    // Atualiza o timestamp de último acesso
    $_SESSION['ultimo_acesso'] = time();
}

// ── Verifica se o perfil tem permissão ──────────────────
// Uso: verificarPerfil(['master', 'admin'])
function verificarPerfil(array $perfisPermitidos): void {
    verificarLogin();

    if (!in_array($_SESSION['usuario_perfil'], $perfisPermitidos)) {
        header('Location: ' . BASE_URL . '/index.php?erro=sem_permissao');
        exit;
    }
}

// ── Realiza o login ──────────────────────────────────────
function fazerLogin(string $nome, string $senha): array {
    $pdo = conectar();
    $ip  = obterIP();

    // Verifica bloqueio por tentativas excessivas
    if (estaBloqueado($nome)) {
        registrarLog('LOGIN_BLOQUEADO', "Tentativa bloqueada: $nome", $ip);
        return ['ok' => false, 'msg' => 'Acesso bloqueado por ' . BLOQUEIO_MINUTOS . ' minutos devido a múltiplas tentativas incorretas.'];
    }

    // Busca o usuário pelo nome (case-insensitive)
    $stmt = $pdo->prepare("
        SELECT id, nome, perfil, senha_hash, ativo
        FROM usuarios
        WHERE LOWER(nome) = LOWER(:nome)
        LIMIT 1
    ");
    $stmt->execute([':nome' => trim($nome)]);
    $usuario = $stmt->fetch();

    // Valida usuário e senha
    if (!$usuario || !password_verify($senha, $usuario['senha_hash'])) {
        registrarTentativaFalha($nome);
        registrarLog('LOGIN_FALHA', "Nome: $nome", $ip);
        $restantes = MAX_TENTATIVAS_LOGIN - contarTentativas($nome);
        $msg = $restantes > 0
            ? "Usuário ou senha incorretos. Tentativas restantes: $restantes"
            : "Acesso bloqueado por " . BLOQUEIO_MINUTOS . " minutos.";
        return ['ok' => false, 'msg' => $msg];
    }

    if (!$usuario['ativo']) {
        return ['ok' => false, 'msg' => 'Usuário inativo. Contate o administrador.'];
    }

    // Login bem-sucedido — regenera ID da sessão por segurança
    session_regenerate_id(true);
    limparTentativas($nome);

    $_SESSION['usuario_id']     = $usuario['id'];
    $_SESSION['usuario_nome']   = $usuario['nome'];
    $_SESSION['usuario_perfil'] = $usuario['perfil'];
    $_SESSION['ultimo_acesso']  = time();

    registrarLog('LOGIN_OK', "Usuário: {$usuario['nome']} ({$usuario['perfil']})", $ip);

    return ['ok' => true, 'perfil' => $usuario['perfil']];
}

// ── Encerra a sessão ─────────────────────────────────────
function encerrarSessao(string $motivo = ''): void {
    if (isset($_SESSION['usuario_nome'])) {
        registrarLog('LOGOUT', "Usuário: {$_SESSION['usuario_nome']}. $motivo", obterIP());
    }
    $_SESSION = [];
    session_destroy();
    header('Location: ' . BASE_URL . '/index.php?msg=' . urlencode($motivo));
    exit;
}

// ── Controle de tentativas de login (via sessão) ─────────
function registrarTentativaFalha(string $nome): void {
    $chave = 'tentativas_' . md5(strtolower(trim($nome)));
    if (!isset($_SESSION[$chave])) {
        $_SESSION[$chave] = ['count' => 0, 'primeiro' => time()];
    }
    $_SESSION[$chave]['count']++;
    $_SESSION[$chave]['ultimo'] = time();
}

function contarTentativas(string $nome): int {
    $chave = 'tentativas_' . md5(strtolower(trim($nome)));
    return $_SESSION[$chave]['count'] ?? 0;
}

function estaBloqueado(string $nome): bool {
    $chave = 'tentativas_' . md5(strtolower(trim($nome)));
    if (!isset($_SESSION[$chave])) return false;

    $dados = $_SESSION[$chave];
    if ($dados['count'] < MAX_TENTATIVAS_LOGIN) return false;

    // Verifica se o tempo de bloqueio já passou
    $segundosBloqueio = BLOQUEIO_MINUTOS * 60;
    if (time() - $dados['ultimo'] > $segundosBloqueio) {
        limparTentativas($nome);
        return false;
    }
    return true;
}

function limparTentativas(string $nome): void {
    $chave = 'tentativas_' . md5(strtolower(trim($nome)));
    unset($_SESSION[$chave]);
}