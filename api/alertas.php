<?php
// ============================================================
// api/alertas.php — Endpoint de alertas para polling AJAX
// Retorna contagens e resumo sem recarregar a página
// Requer login; não exige perfil específico (filtra por perfil)
// ============================================================
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

// Só responde se houver sessão válida
if (!isset($_SESSION['usuario_id'])) {
    http_response_code(401);
    echo json_encode(['erro' => 'não autenticado']);
    exit;
}

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$pdo    = conectar();
$perfil = $_SESSION['usuario_perfil'] ?? '';
$hoje   = date('Y-m-d');

// ── Perfil vendedor: retorna apenas os próprios dados ────────────
if ($perfil === 'vendedor') {
    $stmtU = $pdo->prepare("SELECT vendedor_id FROM usuarios WHERE id = :uid");
    $stmtU->execute([':uid' => $_SESSION['usuario_id']]);
    $vendedorId = (int)($stmtU->fetchColumn() ?? 0);

    $qrsPend = 0;
    if ($vendedorId > 0) {
        $st = $pdo->prepare("
            SELECT COUNT(*) FROM qr_tokens
            WHERE vendedor_id = :vid AND usado = 0 AND rejeitado = 0 AND expira_em > NOW()
        ");
        $st->execute([':vid' => $vendedorId]);
        $qrsPend = (int)$st->fetchColumn();
    }

    echo json_encode([
        'total'       => $qrsPend,
        'qr_pendente' => $qrsPend,
        'ts'          => time(),
    ]);
    exit;
}

// ── Demais perfis: retorna visão geral do sistema ────────────────

// 1. Pendências de dias anteriores (saldo > 0)
$stmtPend = $pdo->query("
    SELECT COUNT(*) FROM (
        SELECT v.id,
            (
                COALESCE((SELECT SUM(q.quantidade) FROM reg_saidas   q  WHERE q.vendedor_id  = v.id AND q.data  = s.data), 0) -
                COALESCE((SELECT SUM(r.quantidade) FROM reg_retornos r  WHERE r.vendedor_id  = v.id AND r.data  = s.data), 0) -
                COALESCE((SELECT SUM(vd.quantidade)FROM reg_vendas   vd WHERE vd.vendedor_id = v.id AND vd.data = s.data), 0)
            ) AS saldo
        FROM vendedores v
        INNER JOIN reg_saidas s ON s.vendedor_id = v.id
        WHERE s.data < '$hoje'
        GROUP BY v.id, s.data
        HAVING saldo > 0
    ) AS sub
");
$pendencias = (int)$stmtPend->fetchColumn();

// 2. QR Codes rejeitados (sem confirmação)
$st = $pdo->query("
    SELECT COUNT(*) FROM qr_tokens WHERE usado = 0 AND rejeitado = 1
");
$qrRejeitados = (int)$st->fetchColumn();

// 3. QR Codes aguardando (não expirados)
$st = $pdo->query("
    SELECT COUNT(*) FROM qr_tokens
    WHERE usado = 0 AND rejeitado = 0 AND expira_em > NOW()
");
$qrAguardando = (int)$st->fetchColumn();

// 4. QR Codes expirados sem confirmação
$st = $pdo->query("
    SELECT COUNT(*) FROM qr_tokens
    WHERE usado = 0 AND rejeitado = 0 AND expira_em <= NOW()
");
$qrExpirados = (int)$st->fetchColumn();

// 5. Saldo negativo hoje (extravio potencial)
$st = $pdo->query("
    SELECT COUNT(*) FROM (
        SELECT v.id,
            (
                COALESCE((SELECT SUM(s.quantidade) FROM reg_saidas   s  WHERE s.vendedor_id  = v.id AND s.data  = '$hoje'), 0) -
                COALESCE((SELECT SUM(r.quantidade) FROM reg_retornos r  WHERE r.vendedor_id  = v.id AND r.data  = '$hoje'), 0) -
                COALESCE((SELECT SUM(vd.quantidade)FROM reg_vendas   vd WHERE vd.vendedor_id = v.id AND vd.data = '$hoje'), 0)
            ) AS saldo
        FROM vendedores v
        GROUP BY v.id
        HAVING saldo < 0
    ) AS sub
");
$saldoNegativo = (int)$st->fetchColumn();

// ── Monta lista de toasts para novidades ─────────────────────────
// O cliente envia ?ts=<timestamp_da_ultima_checagem>
// A API devolve apenas alertas que mudaram desde então
$tsCliente = (int)($_GET['ts'] ?? 0);
$toasts = [];

// QRs rejeitados recentes (nos últimos X segundos)
if ($tsCliente > 0 && $qrRejeitados > 0) {
    $tsFormatado = date('Y-m-d H:i:s', $tsCliente);
    $st = $pdo->prepare("
        SELECT COUNT(*) FROM qr_tokens t
        INNER JOIN vendedores v ON v.id = t.vendedor_id
        WHERE t.usado = 0 AND t.rejeitado = 1
          AND t.criado_em >= :ts
    ");
    // criado_em pode não existir — usa expira_em como fallback aproximado
    // Tenta com criado_em; se der erro, ignora
    try {
        $st->execute([':ts' => $tsFormatado]);
        $novosRejeitados = (int)$st->fetchColumn();
        if ($novosRejeitados > 0) {
            $toasts[] = [
                'tipo' => 'erro',
                'msg'  => "❌ $novosRejeitados QR Code(s) rejeitado(s) pelo vendedor!",
            ];
        }
    } catch (\PDOException $e) {
        // Coluna criado_em inexistente — ignora toast de novidade
    }
}

if ($saldoNegativo > 0) {
    $toasts[] = [
        'tipo' => 'aviso',
        'msg'  => "⚠️ $saldoNegativo vendedor(es) com saldo negativo hoje — verificar possível extravio.",
    ];
}

$total = $pendencias + $qrRejeitados + $qrExpirados + $saldoNegativo;

echo json_encode([
    'total'          => $total,
    'pendencias'     => $pendencias,
    'qr_rejeitados'  => $qrRejeitados,
    'qr_aguardando'  => $qrAguardando,
    'qr_expirados'   => $qrExpirados,
    'saldo_negativo' => $saldoNegativo,
    'toasts'         => $toasts,
    'ts'             => time(),
]);