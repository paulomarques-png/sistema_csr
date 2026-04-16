<?php
// ============================================================
// api/backup_banco.php — Gera backup SQL do banco de dados
// Salvar em: C:\xampp\htdocs\sistema_csr\api\backup_banco.php
// ============================================================
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

verificarPerfil(['master']);

$pdo = conectar();

$nomeArquivo = 'backup_' . DB_NAME . '_' . date('Y-m-d_H-i-s') . '.sql';

header('Content-Type: application/octet-stream');
header('Content-Disposition: attachment; filename="' . $nomeArquivo . '"');
header('Cache-Control: no-cache, no-store, must-revalidate');

$out = fopen('php://output', 'w');

// ── Cabeçalho do arquivo SQL ─────────────────────────────────────
fwrite($out, "-- ============================================================\n");
fwrite($out, "-- Backup: " . DB_NAME . "\n");
fwrite($out, "-- Gerado em: " . date('d/m/Y H:i:s') . "\n");
fwrite($out, "-- Sistema: " . SISTEMA_NOME . " v" . SISTEMA_VERSAO . "\n");
fwrite($out, "-- ============================================================\n\n");
fwrite($out, "SET FOREIGN_KEY_CHECKS=0;\n");
fwrite($out, "SET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';\n");
fwrite($out, "SET NAMES utf8mb4;\n\n");

// ── Lista todas as tabelas ───────────────────────────────────────
$tabelas = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);

foreach ($tabelas as $tabela) {

    fwrite($out, "-- ------------------------------------------------------------\n");
    fwrite($out, "-- Tabela: `$tabela`\n");
    fwrite($out, "-- ------------------------------------------------------------\n\n");

    // DROP + CREATE TABLE
    fwrite($out, "DROP TABLE IF EXISTS `$tabela`;\n");
    $create = $pdo->query("SHOW CREATE TABLE `$tabela`")->fetch();
    fwrite($out, $create['Create Table'] . ";\n\n");

    // Dados
    $stmt = $pdo->query("SELECT * FROM `$tabela`");
    $rows = $stmt->fetchAll(PDO::FETCH_NUM);

    if (!empty($rows)) {
        // Cabeçalho do INSERT com nomes das colunas
        $colunas = $pdo->query("SHOW COLUMNS FROM `$tabela`")
                       ->fetchAll(PDO::FETCH_COLUMN);
        $colStr  = implode('`, `', $colunas);

        fwrite($out, "INSERT INTO `$tabela` (`$colStr`) VALUES\n");

        $total = count($rows);
        foreach ($rows as $i => $row) {
            $valores = array_map(function ($v) use ($pdo) {
                if ($v === null)        return 'NULL';
                if (is_numeric($v))     return $v;
                return "'" . addslashes($v) . "'";
            }, $row);

            $linha = '(' . implode(', ', $valores) . ')';
            $linha .= ($i < $total - 1) ? ',' : ';';
            fwrite($out, $linha . "\n");
        }
        fwrite($out, "\n");
    }
}

fwrite($out, "SET FOREIGN_KEY_CHECKS=1;\n");
fwrite($out, "\n-- Fim do backup\n");

fclose($out);

registrarLog('BACKUP', 'Backup do banco gerado por: ' . ($_SESSION['usuario_nome'] ?? '—'), obterIP());
exit;
