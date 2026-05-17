<?php
// ============================================================
// api/verificar_pedido.php
// Verifica se um número de pedido já foi lançado no sistema.
// Verificação GLOBAL — independente de vendedor e data.
// Retorna JSON: { existe: bool, msg: string }
// ============================================================
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';

verificarLogin();
header('Content-Type: application/json');

$pedido  = trim($_GET['pedido']  ?? '');
$ignorar = trim($_GET['ignorar'] ?? ''); // pedido original ao editar — ignora ele mesmo

if (!$pedido) {
    echo json_encode(['existe' => false, 'msg' => '']);
    exit;
}

$pdo = conectar();

// ✅ Verificação global — busca em qualquer vendedor e qualquer data
$sql = "SELECT vendedor, data FROM reg_vendas WHERE pedido = :pedido";
$p   = [':pedido' => $pedido];

// Ao editar: ignora o próprio pedido original para não bloquear a si mesmo
if ($ignorar) {
    $sql .= " AND pedido != :ignorar";
    $p[':ignorar'] = $ignorar;
}

$sql .= " ORDER BY data DESC LIMIT 1";

$stmt = $pdo->prepare($sql);
$stmt->execute($p);
$row  = $stmt->fetch();

if ($row) {
    echo json_encode([
        'existe' => true,
        'msg'    => "Pedido já lançado para <strong>{$row['vendedor']}</strong>"
                  . " em " . date('d/m/Y', strtotime($row['data']))
                  . ". Verifique antes de confirmar.",
    ]);
} else {
    echo json_encode(['existe' => false, 'msg' => '']);
}