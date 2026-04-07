<?php
/**
 * Configurações gerais do sistema
 * Aqui ficam as constantes que controlam o comportamento
 */

// ── Informações do sistema ──────────────────────────────
define('SISTEMA_NOME',    'Controle de Carga');
define('SISTEMA_VERSAO',  '1.0');
define('BASE_URL',        'http://192.168.0.102/sistema_csr'); // Troque em produção

// ── Horários de operação ────────────────────────────────
define('HORA_SAIDA_INICIO',   '06:00');
define('HORA_SAIDA_FIM',      '12:00');
define('HORA_RETORNO_INICIO', '12:00');
define('HORA_RETORNO_FIM',    '20:00');
define('HORA_VENDA_INICIO',   '08:00');
define('HORA_VENDA_FIM',      '18:00');

// ── Segurança ───────────────────────────────────────────
define('SESSAO_TIMEOUT',      1800);   // 30 minutos em segundos
define('MAX_TENTATIVAS_LOGIN', 3);     // Tentativas antes de bloquear
define('BLOQUEIO_MINUTOS',    15);     // Minutos de bloqueio
define('QR_EXPIRACAO_HORAS',   2);     // Validade do token QR
define('MANUTENCAO_TIMEOUT',  900);   // 15 min de timeout do modo manutenção

// ── Cores do sistema (para uso no PHP quando necessário) ─
define('COR_PRIMARIA',   '#2B2B88');
define('COR_SECUNDARIA', '#8ED8F8');
define('COR_ACENTO',     '#0A7BC4');

// ── Fuso horário ────────────────────────────────────────
date_default_timezone_set('America/Sao_Paulo');

// ── Inicialização da sessão ─────────────────────────────
// Configurações de segurança ANTES de session_start()
ini_set('session.cookie_httponly', 1);
ini_set('session.use_only_cookies', 1);
ini_set('session.cookie_samesite', 'Strict');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}