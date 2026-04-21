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
    SELECT usado, rejeitado, rejeitado_motivo, tipo, vendedor_id, data_ref, expira_em, status
    FROM qr_tokens
    WHERE token = :token
");
$stmt->execute([':token' => $token]);
$tk = $stmt->fetch();

if (!$tk) {
    echo json_encode(['confirmado' => false, 'invalido' => true]);
    exit;
}

// Usa a coluna status como fonte da verdade principal,
// com fallback para as colunas usado/rejeitado por compatibilidade
$eConfirmado = ($tk['status'] === 'confirmado') || ($tk['usado'] && !$tk['rejeitado'] && $tk['status'] !== 'rejeitado');
$eRejeitado  = ($tk['status'] === 'rejeitado')  || (bool)$tk['rejeitado'];
$eExpirado   = !$eConfirmado && !$eRejeitado && strtotime($tk['expira_em']) < time();

echo json_encode([
    'confirmado' => $eConfirmado,
    'rejeitado'  => $eRejeitado,
    'motivo'     => $tk['rejeitado_motivo'] ?? '',
    'expirado'   => $eExpirado,
    'tipo'       => $tk['tipo'],
]);