<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';

header('Content-Type: application/json');

echo json_encode([
    'sessao_ok'   => isset($_SESSION['usuario_id']),
    'usuario'     => $_SESSION['usuario_nome'] ?? 'não logado',
    'total_produtos' => conectar()->query("SELECT COUNT(*) FROM produtos WHERE ativo=1")->fetchColumn(),
    'exemplo'     => conectar()->query("SELECT codigo, descricao FROM produtos WHERE ativo=1 LIMIT 3")->fetchAll(),
]);