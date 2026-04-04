<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';

if (!isset($_SESSION['usuario_id'])) {
    echo json_encode(['confirmado' => false]);
    exit;
}

$token = trim($_GET['token'] ?? '');
if (empty($token)) {
    echo json_encode(['confirmado' => false]);
    exit;
}

$pdo  = conectar();
$stmt = $pdo->prepare("
    SELECT usado, tipo, vendedor_id, data_ref, expira_em
    FROM qr_tokens
    WHERE token = :token
");
$stmt->execute([':token' => $token]);
$tk = $stmt->fetch();

header('Content-Type: application/json');

if (!$tk) {
    echo json_encode(['confirmado' => false, 'invalido' => true]);
    exit;
}

// Verifica expiração
$expirado = strtotime($tk['expira_em']) < time();

echo json_encode([
    'confirmado' => (bool)$tk['usado'],
    'expirado'   => $expirado,
    'tipo'       => $tk['tipo'],
]);