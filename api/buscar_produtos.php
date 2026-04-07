<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';

// Exige login — retorna JSON vazio se não autenticado
if (!isset($_SESSION['usuario_id'])) {
    http_response_code(401);
    echo json_encode([]);
    exit;
}

$termo = trim($_GET['q'] ?? '');
if (strlen($termo) < 1) {
    echo json_encode([]);
    exit;
}

$pdo  = conectar();
$stmt = $pdo->prepare("
    SELECT codigo, descricao, unidade
    FROM produtos
    WHERE ativo = 1
      AND (codigo LIKE :termo1 OR descricao LIKE :termo2)
    ORDER BY descricao ASC
    LIMIT 10
");
$stmt->execute([':termo1' => "%$termo%", ':termo2' => "%$termo%"]);

header('Content-Type: application/json');
echo json_encode($stmt->fetchAll());