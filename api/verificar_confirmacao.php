<?php
ob_start();
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
ob_clean();

header('Content-Type: application/json; charset=utf-8');

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
    SELECT usado, rejeitado, rejeitado_motivo, tipo, vendedor_id, data_ref, expira_em
    FROM qr_tokens
    WHERE token = :token
");
$stmt->execute([':token' => $token]);
$tk = $stmt->fetch();

if (!$tk) {
    echo json_encode(['confirmado' => false, 'invalido' => true]);
    exit;
}

echo json_encode([
    'confirmado' => ($tk['usado'] && !$tk['rejeitado']),
    'rejeitado'  => (bool)$tk['rejeitado'],
    'motivo'     => $tk['rejeitado_motivo'] ?? '',
    'expirado'   => strtotime($tk['expira_em']) < time(),
    'tipo'       => $tk['tipo'],
]);