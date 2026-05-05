<?php
// ============================================================
// api/verificar_pedido.php
// Verifica se um número de pedido já foi lançado no sistema.
// Retorna JSON: { existe: bool, msg: string }
// ============================================================
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';

verificarLogin();
header('Content-Type: application/json');

$pedido  = trim($_GET['pedido']  ?? '');
$vid     = (int)($_GET['vid']    ?? 0);
$data    = $_GET['data']         ?? '';
$ignorar = trim($_GET['ignorar'] ?? ''); // pedido original ao editar — ignora ele mesmo

if (!$pedido) {
    echo json_encode(['existe' => false, 'msg' => '']);
    exit;
}

$pdo = conectar();

// Busca o pedido em qualquer vendedor e qualquer data
$sql  = "SELECT vendedor, data FROM reg_vendas WHERE pedido = :pedido";
$p    = [':pedido' => $pedido];

// Se estiver editando, ignora o próprio pedido original
if ($ignorar) {
    $sql .= " AND pedido != :ignorar";
    $p[':ignorar'] = $ignorar;
}

// Exclui o próprio vendedor+data apenas para o lançamento novo
// (mesma combinação já é bloqueada no PHP da confirmação)
$sql .= " AND NOT (vendedor_id = :vid AND data = :data)";
$p[':vid']  = $vid;
$p[':data'] = $data;

$sql .= " GROUP BY vendedor, data ORDER BY data DESC LIMIT 1";

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
