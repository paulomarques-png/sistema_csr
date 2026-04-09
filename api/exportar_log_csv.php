<?php
// ============================================================
// api/exportar_log_csv.php — Exportação do log de auditoria
// Salvar em: C:\xampp\htdocs\sistema_csr\api\exportar_log_csv.php
// ============================================================
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

verificarPerfil(['supervisor', 'master']);

$pdo = conectar();

// ── Filtros (espelha auditoria.php) ─────────────────────────────
$dataInicio = $_GET['data_inicio'] ?? date('Y-m-d', strtotime('-7 days'));
$dataFim    = $_GET['data_fim']    ?? date('Y-m-d');
$acaoFiltro = trim($_GET['acao']   ?? '');
$busca      = trim($_GET['busca']  ?? '');

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dataInicio)) $dataInicio = date('Y-m-d', strtotime('-7 days'));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dataFim))    $dataFim    = date('Y-m-d');
if ($dataFim < $dataInicio) $dataFim = $dataInicio;

$where  = " WHERE DATE(data_hora) BETWEEN :di AND :df ";
$params = [':di' => $dataInicio, ':df' => $dataFim];

if ($acaoFiltro !== '') {
    $where .= " AND tipo = :acao ";
    $params[':acao'] = $acaoFiltro;
}
if ($busca !== '') {
    $where .= " AND (descricao LIKE :busca OR ip LIKE :busca2 OR tipo LIKE :busca3) ";
    $params[':busca']  = "%$busca%";
    $params[':busca2'] = "%$busca%";
    $params[':busca3'] = "%$busca%";
}

$stmt = $pdo->prepare(
    "SELECT id, data_hora, tipo, descricao, ip
     FROM log_sistema" . $where .
    " ORDER BY id DESC"
);
$stmt->execute($params);
$registros = $stmt->fetchAll();

// ── Cabeçalhos HTTP para download ───────────────────────────────
$nomeArquivo = 'log_auditoria_' . $dataInicio . '_a_' . $dataFim . '.csv';
header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $nomeArquivo . '"');
header('Cache-Control: no-cache, no-store, must-revalidate');

// BOM UTF-8 para Excel reconhecer acentos
echo "\xEF\xBB\xBF";

$out = fopen('php://output', 'w');

// Cabeçalho CSV
fputcsv($out, ['ID', 'Data/Hora', 'Ação', 'Descrição', 'IP'], ';');

foreach ($registros as $r) {
    fputcsv($out, [
        $r['id'],
        date('d/m/Y H:i:s', strtotime($r['data_hora'])),
        $r['tipo'],
        $r['descricao'],
        $r['ip'] ?? '',
    ], ';');
}

fclose($out);
exit;