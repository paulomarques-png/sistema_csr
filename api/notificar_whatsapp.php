<?php
// ============================================================
// api/notificar_whatsapp.php — Scaffold para integração WhatsApp
// ============================================================
// ESTADO ATUAL: estrutura pronta, aguardando configuração de
// provedor (Ex: Z-API, Evolution API, WPPConnect, Meta Cloud API)
//
// COMO ATIVAR:
// 1. Escolha um provedor de WhatsApp API
// 2. Preencha as constantes abaixo em config/config.php:
//    define('WHATSAPP_ATIVO',    true);
//    define('WHATSAPP_ENDPOINT', 'https://api.z-api.io/instances/SEU_ID/token/SEU_TOKEN/send-text');
//    define('WHATSAPP_NUMERO',   '5511999999999'); // número do supervisor
// 3. Esta função já é chamada automaticamente pelos gatilhos abaixo
// ============================================================
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

// Só aceita chamadas internas (POST) com sessão válida ou chave interna
$chaveInterna = $_POST['chave'] ?? $_GET['chave'] ?? '';
$chaveEsperada = defined('WHATSAPP_CHAVE_INTERNA') ? WHATSAPP_CHAVE_INTERNA : 'sistema_csr_interno';

if ($chaveInterna !== $chaveEsperada && !isset($_SESSION['usuario_id'])) {
    http_response_code(403);
    echo json_encode(['erro' => 'acesso negado']);
    exit;
}

header('Content-Type: application/json; charset=utf-8');

// ── Função principal de envio ────────────────────────────────────
function enviarWhatsApp(string $mensagem, string $numero = ''): array
{
    // Verifica se o WhatsApp está configurado e ativo
    if (!defined('WHATSAPP_ATIVO') || !WHATSAPP_ATIVO) {
        return ['ok' => false, 'msg' => 'WhatsApp não configurado. Ative em config/config.php.'];
    }

    if (!defined('WHATSAPP_ENDPOINT') || !defined('WHATSAPP_NUMERO')) {
        return ['ok' => false, 'msg' => 'WHATSAPP_ENDPOINT ou WHATSAPP_NUMERO não definidos.'];
    }

    $destino = $numero ?: WHATSAPP_NUMERO;

    // Payload padrão (compatível com Z-API e Evolution API)
    $payload = json_encode([
        'phone'   => $destino,
        'message' => $mensagem,
    ]);

    $ch = curl_init(WHATSAPP_ENDPOINT);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Accept: application/json',
        ],
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);

    $resposta = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErro = curl_error($ch);
    curl_close($ch);

    if ($curlErro) {
        registrarLog('WHATSAPP_ERRO', "cURL: $curlErro", obterIP());
        return ['ok' => false, 'msg' => "Erro de conexão: $curlErro"];
    }

    $ok = ($httpCode >= 200 && $httpCode < 300);
    if ($ok) {
        registrarLog('WHATSAPP_OK', "Mensagem enviada para $destino", obterIP());
    } else {
        registrarLog('WHATSAPP_ERRO', "HTTP $httpCode | Resp: $resposta", obterIP());
    }

    return ['ok' => $ok, 'http' => $httpCode, 'resposta' => $resposta];
}

// ── Templates de mensagem ─────────────────────────────────────────
function msgPendencia(string $vendedor, string $data, int $saldo): string
{
    return "⚠️ *" . SISTEMA_NOME . "* — Pendência em aberto\n"
         . "Vendedor: *$vendedor*\n"
         . "Data: $data\n"
         . "Saldo: *$saldo itens* ainda em aberto.\n"
         . "Acesse o sistema para regularizar.";
}

function msgQrRejeitado(string $vendedor, string $tipo, string $motivo = ''): string
{
    $tipoLabel = $tipo === 'saida' ? 'Saída' : 'Retorno';
    $txt = "❌ *" . SISTEMA_NOME . "* — QR Code rejeitado\n"
         . "Vendedor: *$vendedor*\n"
         . "Operação: $tipoLabel\n";
    if ($motivo) $txt .= "Motivo: \"$motivo\"\n";
    $txt .= "Corrija o registro no sistema.";
    return $txt;
}

function msgSaldoNegativo(string $vendedor, int $saldo): string
{
    return "🚨 *" . SISTEMA_NOME . "* — Saldo negativo!\n"
         . "Vendedor: *$vendedor*\n"
         . "Saldo: *$saldo itens* (possível extravio)\n"
         . "Verifique imediatamente no sistema.";
}

// ── Processamento da requisição ───────────────────────────────────
$tipo     = $_POST['tipo']     ?? $_GET['tipo']     ?? '';
$dados    = $_POST['dados']    ?? [];
$resultado = ['ok' => false, 'msg' => 'Tipo não reconhecido.'];

switch ($tipo) {
    case 'pendencia':
        $resultado = enviarWhatsApp(
            msgPendencia($dados['vendedor'] ?? '?', $dados['data'] ?? '?', (int)($dados['saldo'] ?? 0))
        );
        break;

    case 'qr_rejeitado':
        $resultado = enviarWhatsApp(
            msgQrRejeitado($dados['vendedor'] ?? '?', $dados['tipo_qr'] ?? 'saida', $dados['motivo'] ?? '')
        );
        break;

    case 'saldo_negativo':
        $resultado = enviarWhatsApp(
            msgSaldoNegativo($dados['vendedor'] ?? '?', (int)($dados['saldo'] ?? 0))
        );
        break;

    case 'teste':
        // Permite testar o envio diretamente pelo admin
        if (($_SESSION['usuario_perfil'] ?? '') === 'master') {
            $resultado = enviarWhatsApp(
                "✅ *" . SISTEMA_NOME . "* — Teste de notificação\nIntegração WhatsApp funcionando!"
            );
        } else {
            $resultado = ['ok' => false, 'msg' => 'Apenas o perfil master pode testar.'];
        }
        break;
}

echo json_encode($resultado);