<?php
// ============================================================
// api/validar_supervisor.php
// Valida credenciais de supervisor para autorizar retroativo
// ============================================================
ob_start();
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
ob_clean();

header('Content-Type: application/json; charset=utf-8');

// Exige sessão ativa (o operador deve estar logado)
if (!isset($_SESSION['usuario_id'])) {
    echo json_encode(['ok' => false, 'msg' => 'Sessão inválida.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'msg' => 'Método inválido.']);
    exit;
}

$login = trim($_POST['login'] ?? '');
$senha = trim($_POST['senha'] ?? '');

if (empty($login) || empty($senha)) {
    echo json_encode(['ok' => false, 'msg' => 'Preencha usuário e senha.']);
    exit;
}

$pdo  = conectar();
$stmt = $pdo->prepare("
    SELECT id, nome, perfil, senha_hash, ativo
    FROM usuarios
    WHERE LOWER(nome) = LOWER(:login)
    LIMIT 1
");
$stmt->execute([':login' => $login]);
$usuario = $stmt->fetch();

if (!$usuario || !$usuario['ativo']) {
    registrarLog('RETRO_AUTH_FALHA', "Usuário não encontrado: $login | Solicitado por: {$_SESSION['usuario_nome']}", obterIP());
    echo json_encode(['ok' => false, 'msg' => 'Usuário não encontrado ou inativo.']);
    exit;
}

if (!password_verify($senha, $usuario['senha_hash'])) {
    registrarLog('RETRO_AUTH_FALHA', "Senha incorreta para: {$usuario['nome']} | Solicitado por: {$_SESSION['usuario_nome']}", obterIP());
    echo json_encode(['ok' => false, 'msg' => 'Senha incorreta.']);
    exit;
}

if (!in_array($usuario['perfil'], ['supervisor', 'master'])) {
    registrarLog('RETRO_AUTH_NEGADO', "Perfil insuficiente: {$usuario['nome']} ({$usuario['perfil']})", obterIP());
    echo json_encode(['ok' => false, 'msg' => 'Apenas supervisores e masters podem autorizar lançamentos retroativos.']);
    exit;
}

registrarLog(
    'RETRO_AUTH_OK',
    "Supervisor: {$usuario['nome']} ({$usuario['perfil']}) autorizou retroativo | Operador: {$_SESSION['usuario_nome']}",
    obterIP()
);

echo json_encode([
    'ok'     => true,
    'id'     => $usuario['id'],
    'nome'   => $usuario['nome'],
    'perfil' => $usuario['perfil'],
]);