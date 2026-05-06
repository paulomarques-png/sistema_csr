<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

$token = trim($_GET['token'] ?? '');
$erro  = '';
$msg   = '';

// ── Função que exibe página de erro estilizada e encerra ─────────
function paginaErroQR(string $tipo): void {
    $configs = [
        'invalido' => [
            'titulo'   => 'Link Inválido',
            'subtitulo'=> 'Este QR Code não existe ou foi gerado incorretamente.',
            'dica'     => 'Peça ao operador para gerar um novo QR Code.',
            'cor'      => '#DC3545',
            'corClara' => '#fff0f0',
            'corBorda' => '#f5c6cb',
            'imagem'   => 'qrcode-invalido.png',
            'imgAlt'   => 'QR Code inválido',
        ],
        'expirado' => [
            'titulo'   => 'QR Code Expirado',
            'subtitulo'=> 'O tempo limite para confirmação deste registro foi atingido.',
            'dica'     => 'Peça ao operador para reenviar ou gerar um novo QR Code.',
            'cor'      => '#856404',
            'corClara' => '#fffbea',
            'corBorda' => '#ffeeba',
            'imagem'   => 'qrcode-expirado.png',
            'imgAlt'   => 'QR Code expirado',
        ],
    ];

    $c        = $configs[$tipo] ?? $configs['invalido'];
    $sistNome = defined('SISTEMA_NOME') ? SISTEMA_NOME : 'Controle de Carga';
    $baseUrl  = defined('BASE_URL')     ? BASE_URL     : '';

    // Apenas as custom properties de cor são inline — necessário pois
    // são valores dinâmicos por tipo de erro. Todos os outros estilos
    // estão em style.css sob as classes qr-erro-*.
    echo <<<HTML
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0">
    <title>{$c['titulo']} — {$sistNome}</title>
    <link rel="stylesheet" href="{$baseUrl}/assets/css/style.css">
    <style>
        :root {
            --qr-cor:       {$c['cor']};
            --qr-cor-clara: {$c['corClara']};
            --qr-cor-borda: {$c['corBorda']};
        }
    </style>
</head>
<body class="qr-erro-body">
<div class="qr-erro-card">
    <img src="{$baseUrl}/assets/img/{$c['imagem']}"
         alt="{$c['imgAlt']}"
         class="qr-erro-ilustracao">
    <div class="qr-erro-badge">{$c['titulo']}</div>
    <h1 class="qr-erro-titulo">{$c['titulo']}</h1>
    <p class="qr-erro-subtitulo">{$c['subtitulo']}</p>
    <div class="qr-erro-dica">{$c['dica']}</div>
    <p class="qr-erro-rodape">{$sistNome}</p>
    <p class="qr-fechar-aviso">Já pode fechar esta página.</p>
</div>
</body>
</html>
HTML;
    exit;
}

// ── Valida token ─────────────────────────────────────────────────
if (empty($token)) paginaErroQR('invalido');

$pdo = conectar();

$stmt = $pdo->prepare("
    SELECT t.*, v.nome AS vendedor_nome, v.senha AS vendedor_senha
    FROM qr_tokens t
    INNER JOIN vendedores v ON v.id = t.vendedor_id
    WHERE t.token = :token
    LIMIT 1
");
$stmt->execute([':token' => $token]);
$tk = $stmt->fetch();

if (!$tk)                                 paginaErroQR('invalido');
if (strtotime($tk['expira_em']) < time()) paginaErroQR('expirado');

$statusAtual = $tk['status'];
$itens = [];

if ($statusAtual === 'pendente') {
    $tabela = $tk['tipo'] === 'saida' ? 'reg_saidas' : 'reg_retornos';
    $stmtI  = $pdo->prepare("
        SELECT codigo, produto, quantidade
        FROM $tabela
        WHERE vendedor_id = :vid AND data = :data AND confirmado = 0 AND rejeitado = 0
        ORDER BY id ASC
    ");
    $stmtI->execute([':vid' => $tk['vendedor_id'], ':data' => $tk['data_ref']]);
    $itens = $stmtI->fetchAll();
}

// ── Processa confirmação ──────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $statusAtual === 'pendente') {
    $acao          = $_POST['acao']  ?? '';
    $senhaDigitada = trim($_POST['senha'] ?? '');

    if (empty($senhaDigitada)) {
        $erro = 'Digite sua senha para continuar.';
    } elseif (!password_verify($senhaDigitada, $tk['vendedor_senha'])) {
        $erro = 'Senha incorreta. Tente novamente.';
    } else {
        $tabela = $tk['tipo'] === 'saida' ? 'reg_saidas' : 'reg_retornos';
        $agora  = date('Y-m-d H:i:s');
        $ip     = obterIP();

        if ($acao === 'confirmar') {
            $pdo->prepare("
                UPDATE $tabela
                SET confirmado = 1, confirmado_em = :agora, confirmado_ip = :ip
                WHERE vendedor_id = :vid AND data = :data AND confirmado = 0 AND rejeitado = 0
            ")->execute([':agora' => $agora, ':ip' => $ip,
                         ':vid'   => $tk['vendedor_id'], ':data' => $tk['data_ref']]);

            $pdo->prepare("UPDATE qr_tokens SET usado = 1, status = 'confirmado' WHERE token = :token")
                ->execute([':token' => $token]);

            registrarLog('QR_CONFIRMADO',
                "Tipo: {$tk['tipo']} | Vendedor: {$tk['vendedor_nome']} | Data: {$tk['data_ref']}", $ip);

            $statusAtual = 'confirmado';
            $msg = 'Confirmação realizada com sucesso!';

        } elseif ($acao === 'rejeitar') {
            $pdo->prepare("
                UPDATE $tabela
                SET rejeitado = 1
                WHERE vendedor_id = :vid AND data = :data AND confirmado = 0
            ")->execute([':vid' => $tk['vendedor_id'], ':data' => $tk['data_ref']]);

            $pdo->prepare("
                UPDATE qr_tokens
                SET usado = 1, rejeitado = 1, status = 'rejeitado'
                WHERE token = :token
            ")->execute([':token' => $token]);

            registrarLog('QR_REJEITADO',
                "Tipo: {$tk['tipo']} | Vendedor: {$tk['vendedor_nome']} | Data: {$tk['data_ref']}", $ip);

            $statusAtual = 'rejeitado';
            $msg = 'Registro rejeitado. O operador será notificado para corrigir.';
        }
    }
}

$tipoLabel = $tk['tipo'] === 'saida' ? '📤 Saída' : '📥 Retorno';
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0">
    <title>Confirmar <?= $tipoLabel ?> — <?= SISTEMA_NOME ?></title>
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/style.css">
</head>
<body>

<div class="qr-topo">
    <h1><?= $tipoLabel ?> — <?= esc(SISTEMA_NOME) ?></h1>
    <p><?= esc($tk['vendedor_nome']) ?> — <?= formatarData($tk['data_ref']) ?></p>
</div>

<?php if ($statusAtual === 'confirmado'): ?>
<div class="qr-caixa">
    <div class="qr-resultado qr-resultado-confirmado">
        <div class="qr-resultado-icone">✅</div>
        <h2><?= $msg ?: 'Esta operação já foi confirmada.' ?></h2>
        <p class="qr-resultado-fechar">Já pode fechar esta página.</p>
    </div>
</div>

<?php elseif ($statusAtual === 'rejeitado'): ?>
<div class="qr-caixa">
    <div class="qr-resultado qr-resultado-rejeitado">
        <div class="qr-resultado-icone">❌</div>
        <h2><?= $msg ?: 'Este registro foi rejeitado anteriormente.' ?></h2>
        <p class="qr-resultado-info">
            O operador foi notificado e deverá corrigir e reenviar um novo QR Code.
        </p>
        <p class="qr-resultado-fechar">Já pode fechar esta página.</p>
    </div>
</div>

<?php else: ?>
<div class="qr-caixa">
    <div class="qr-info-vendedor">
        <strong><?= esc($tk['vendedor_nome']) ?></strong><br>
        <span class="qr-info-vendedor-tipo"><?= $tipoLabel ?> — <?= formatarData($tk['data_ref']) ?></span>
    </div>

    <?php if (!empty($itens)): ?>
    <p class="qr-itens-intro">Confira os itens abaixo antes de confirmar:</p>
    <table class="qr-table">
        <thead>
            <tr>
                <th>Produto</th>
                <th class="th-qtd">Qtd</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($itens as $item): ?>
            <tr>
                <td>
                    <small class="qr-cod-item"><?= esc($item['codigo']) ?></small><br>
                    <?= esc($item['produto']) ?>
                </td>
                <td class="td-qtd"><?= $item['quantidade'] ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php else: ?>
        <div class="qr-alerta qr-alerta-erro">Nenhum item encontrado para esta operação.</div>
    <?php endif; ?>

    <?php if ($erro): ?>
        <div class="qr-alerta qr-alerta-erro"><?= esc($erro) ?></div>
    <?php endif; ?>

    <form method="post" id="form-confirmacao">
        <hr class="qr-divisor">
        <label class="qr-label" for="senha">Sua senha para confirmar ou rejeitar</label>
        <input type="password" id="senha" name="senha" class="qr-campo"
               placeholder="••••••" required autofocus autocomplete="current-password">
        <small class="qr-dica-campo">Digite sua senha e escolha uma das opções abaixo.</small>

        <div class="qr-acoes">
            <button type="submit" name="acao" value="confirmar"
                    class="btn-qr-confirmar"
                    <?= empty($itens) ? 'disabled' : '' ?>>
                ✅ CONFIRMAR <?= strtoupper($tk['tipo'] === 'saida' ? 'SAÍDA' : 'RETORNO') ?>
            </button>
            <button type="button" class="btn-qr-rejeitar"
                    onclick="abrirModalRejeicao()"
                    <?= empty($itens) ? 'disabled' : '' ?>>
                ❌ Não concordo com este registro
            </button>
        </div>
    </form>
</div>

<div class="qr-modal-rejeicao" id="modal-rejeicao">
    <div class="qr-modal-box">
        <h3>❌ Rejeitar registro?</h3>
        <p>
            Ao rejeitar, o operador será notificado e deverá corrigir os itens
            e gerar um novo QR Code para você confirmar.<br><br>
            <strong>Tem certeza que deseja rejeitar?</strong>
        </p>
        <div class="qr-modal-acoes">
            <button type="button" class="btn-qr-sim-rejeitar" onclick="confirmarRejeicao()">
                Sim, rejeitar
            </button>
            <button type="button" class="btn-qr-cancelar" onclick="fecharModalRejeicao()">
                Cancelar
            </button>
        </div>
    </div>
</div>

<?php endif; ?>

<script>
function abrirModalRejeicao() {
    document.getElementById('modal-rejeicao').classList.add('aberto');
}
function fecharModalRejeicao() {
    document.getElementById('modal-rejeicao').classList.remove('aberto');
}
function confirmarRejeicao() {
    const form  = document.getElementById('form-confirmacao');
    const input = document.createElement('input');
    input.type  = 'hidden';
    input.name  = 'acao';
    input.value = 'rejeitar';
    form.appendChild(input);
    form.submit();
}
</script>

</body>
</html>