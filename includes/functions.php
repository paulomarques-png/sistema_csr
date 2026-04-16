<?php
/**
 * Funções auxiliares reutilizadas em todo o sistema
 */

require_once __DIR__ . '/../config/config.php';

// ── Verifica se a operação está dentro do horário ────────
// Retorna: 'liberado', 'bloqueado', ou 'manutencao'
function verificarHorario(string $tipo): string {
    if (modoManutencaoAtivo()) return 'manutencao';

    $agora = date('H:i');

    $inicio = match($tipo) {
        'saida'   => HORA_SAIDA_INICIO,
        'retorno' => HORA_RETORNO_INICIO,
        'venda'   => HORA_VENDA_INICIO,
        default   => '00:00',
    };
    $fim = match($tipo) {
        'saida'   => HORA_SAIDA_FIM,
        'retorno' => HORA_RETORNO_FIM,
        'venda'   => HORA_VENDA_FIM,
        default   => '23:59',
    };

    return ($agora >= $inicio && $agora <= $fim) ? 'liberado' : 'bloqueado';
}

// ── Formata data do MySQL para exibição ──────────────────
function formatarData(string $data): string {
    if (empty($data) || $data === '0000-00-00') return '—';
    return date('d/m/Y', strtotime($data));
}

// ── Formata data e hora ──────────────────────────────────
function formatarDataHora(string $dt): string {
    if (empty($dt)) return '—';
    return date('d/m/Y H:i', strtotime($dt));
}

// ── Sanitiza string para exibição segura ─────────────────
function esc(string $str): string {
    return htmlspecialchars($str, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

// ── Retorna classe CSS conforme o saldo ──────────────────
function classSaldo(int $saldo): string {
    if ($saldo === 0) return 'saldo-zero';
    if ($saldo > 0)  return 'saldo-aberto';
    return 'saldo-negativo';
}

// ── Exibe mensagem de feedback (flash message) ───────────
function flash(string $chave): string {
    if (isset($_SESSION['flash'][$chave])) {
        $msg = $_SESSION['flash'][$chave];
        unset($_SESSION['flash'][$chave]);
        return $msg;
    }
    return '';
}

function setFlash(string $chave, string $mensagem): void {
    $_SESSION['flash'][$chave] = $mensagem;
}

// ── Gera a URL da imagem do QR Code (serviço externo, sem biblioteca) ─
function gerarQRImageUrl(string $dados, int $tamanho = 280): string {
    return 'https://api.qrserver.com/v1/create-qr-code/'
         . '?size=' . $tamanho . 'x' . $tamanho
         . '&data=' . urlencode($dados)
         . '&format=png&margin=10';
}

// ── Retorna IP real do cliente ───────────────────────────────────
function obterIP(): string {
    $ip = $_SERVER['HTTP_X_FORWARDED_FOR']
       ?? $_SERVER['HTTP_CLIENT_IP']
       ?? $_SERVER['REMOTE_ADDR']
       ?? '0.0.0.0';
    // X_FORWARDED_FOR pode conter múltiplos IPs — pega o primeiro
    return trim(explode(',', $ip)[0]);
}

// ── Verifica se o modo manutenção está ativo ─────────────────────
function modoManutencaoAtivo(): bool {
    $arquivo = __DIR__ . '/../config/manutencao.json';
    if (!file_exists($arquivo)) return false;
    $dados = json_decode(file_get_contents($arquivo), true);
    if (empty($dados['ativo'])) return false;
    // Verifica se o timeout expirou
    if ((time() - ($dados['ativado_em'] ?? 0)) > MANUTENCAO_TIMEOUT) {
        file_put_contents($arquivo, json_encode(['ativo' => false]));
        return false;
    }
    return true;
}

// ── Registra uma ação no log do sistema ─────────────────────────
function registrarLog(string $tipo, string $descricao, string $ip): void {
    try {
        $pdo  = conectar();
        $stmt = $pdo->prepare(
            "INSERT INTO log_sistema (tipo, descricao, ip)
             VALUES (:tipo, :descricao, :ip)"
        );
        $stmt->execute([
            ':tipo'      => $tipo,
            ':descricao' => $descricao,
            ':ip'        => $ip,
        ]);
    } catch (Exception $e) {
        error_log("Erro ao registrar log: " . $e->getMessage());
    }
}