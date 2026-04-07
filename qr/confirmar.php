<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

$token = trim($_GET['token'] ?? '');
$erro  = '';
$msg   = '';

if (empty($token)) die('Link inválido.');

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

if (!$tk) die('Link inválido ou expirado.');
if (strtotime($tk['expira_em']) < time()) die('Este link expirou. Peça ao operador para reenviar o QR Code.');

$statusAtual = $tk['status']; // pendente | confirmado | rejeitado
$itens = [];

if ($statusAtual === 'pendente') {
    $tabela = $tk['tipo'] === 'saida' ? 'reg_saidas' : 'reg_retornos';
    $stmtI  = $pdo->prepare("
        SELECT codigo, produto, quantidade
        FROM $tabela
        WHERE vendedor_id = :vid AND data = :data AND rejeitado = 0
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
            // Confirma todos os itens
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
            // Rejeita — marca registros e token
            $pdo->prepare("
                UPDATE $tabela
                SET rejeitado = 1
                WHERE vendedor_id = :vid AND data = :data AND confirmado = 0
            ")->execute([':vid' => $tk['vendedor_id'], ':data' => $tk['data_ref']]);

            $pdo->prepare("UPDATE qr_tokens SET usado = 1, rejeitado = 1, status = 'rejeitado' WHERE token = :token")
                ->execute([':token' => $token]);

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
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        :root {
            --primaria: #2B2B88;
            --acento:   #0A7BC4;
            --verde:    #28A745;
            --vermelho: #DC3545;
            --amarelo:  #FFC107;
            --cinza:    #f4f6fa;
        }
        body { font-family: Arial, system-ui, sans-serif; background: var(--cinza); color: #1a1a2e; min-height: 100vh; padding-bottom: 40px; }
        .topo { background: var(--primaria); color: white; padding: 16px 20px; text-align: center; }
        .topo h1 { font-size: 18px; }
        .topo p  { font-size: 13px; opacity: .8; margin-top: 4px; }
        .caixa { background: white; border-radius: 12px; margin: 16px; padding: 20px; box-shadow: 0 2px 12px rgba(0,0,0,.1); }
        .info-vendedor { font-size: 15px; margin-bottom: 16px; padding-bottom: 12px; border-bottom: 1px solid #eee; }
        .info-vendedor strong { color: var(--primaria); font-size: 18px; }
        table { width: 100%; border-collapse: collapse; font-size: 14px; margin-bottom: 20px; }
        th { background: var(--primaria); color: white; padding: 10px 8px; text-align: left; }
        td { padding: 10px 8px; border-bottom: 1px solid #f0f0f0; }
        .campo { width: 100%; padding: 14px 16px; border: 2px solid #ddd; border-radius: 10px; font-size: 18px; text-align: center; letter-spacing: 4px; margin-bottom: 12px; transition: border-color .2s; }
        .campo:focus { outline: none; border-color: var(--acento); }
        label { display: block; font-size: 14px; font-weight: 600; margin-bottom: 6px; color: #444; }
        small { color: #888; font-size: 12px; }
        .alerta { padding: 12px 16px; border-radius: 8px; margin-bottom: 16px; font-size: 14px; font-weight: 500; }
        .alerta-erro    { background: #fff0f0; color: var(--vermelho); border-left: 4px solid var(--vermelho); }
        .alerta-sucesso { background: #f0fff4; color: var(--verde);    border-left: 4px solid var(--verde); }
        .alerta-aviso   { background: #fffbea; color: #856404;         border-left: 4px solid var(--amarelo); }

        /* Botões de ação */
        .acoes { display: flex; flex-direction: column; gap: 12px; margin-top: 8px; }
        .btn-confirmar {
            width: 100%; padding: 16px; background: var(--verde);
            color: white; border: none; border-radius: 10px;
            font-size: 18px; font-weight: bold; cursor: pointer; transition: filter .2s;
        }
        .btn-confirmar:hover { filter: brightness(1.1); }
        .btn-rejeitar {
            width: 100%; padding: 14px; background: white;
            color: var(--vermelho); border: 2px solid var(--vermelho);
            border-radius: 10px; font-size: 15px; font-weight: bold;
            cursor: pointer; transition: background .2s, color .2s;
        }
        .btn-rejeitar:hover { background: var(--vermelho); color: white; }

        /* Aviso de rejeição */
        .aviso-rejeicao {
            background: #fff3cd; border: 1px solid #ffc107;
            border-radius: 10px; padding: 14px 16px;
            margin-bottom: 16px; font-size: 13px; color: #856404;
        }

        /* Tela final */
        .resultado { text-align: center; padding: 32px 20px; }
        .resultado .icone { font-size: 64px; }
        .resultado h2 { margin: 12px 0 8px; font-size: 22px; }
        .resultado p  { color: #555; font-size: 15px; }

        /* Separador de senha */
        .divisor { border: none; border-top: 1px solid #eee; margin: 16px 0; }

        /* Modal de confirmação de rejeição */
        .modal-rejeicao { display: none; position: fixed; inset: 0; background: rgba(0,0,0,.5); align-items: flex-end; justify-content: center; z-index: 100; }
        .modal-rejeicao.aberto { display: flex; }
        .modal-box { background: white; border-radius: 16px 16px 0 0; padding: 24px 20px; width: 100%; max-width: 480px; }
        .modal-box h3 { color: var(--vermelho); margin-bottom: 8px; }
        .modal-box p  { font-size: 14px; color: #555; margin-bottom: 16px; }
    </style>
</head>
<body>

<div class="topo">
    <h1><?= $tipoLabel ?> — <?= esc(SISTEMA_NOME) ?></h1>
    <p><?= esc($tk['vendedor_nome']) ?> — <?= formatarData($tk['data_ref']) ?></p>
</div>

<?php if ($statusAtual === 'confirmado'): ?>
    <div class="caixa">
        <div class="resultado">
            <div class="icone">✅</div>
            <h2 style="color:var(--verde)">Confirmado!</h2>
            <p><?= $msg ?: 'Esta operação já foi confirmada.' ?></p>
        </div>
    </div>

<?php elseif ($statusAtual === 'rejeitado'): ?>
    <div class="caixa">
        <div class="resultado">
            <div class="icone">❌</div>
            <h2 style="color:var(--vermelho)">Registro Rejeitado</h2>
            <p><?= $msg ?: 'Este registro foi rejeitado anteriormente.' ?></p>
            <p style="margin-top:10px; font-size:13px; color:#999">
                O operador foi notificado e deverá corrigir e reenviar um novo QR Code.
            </p>
        </div>
    </div>

<?php else: ?>
    <div class="caixa">
        <div class="info-vendedor">
            <strong><?= esc($tk['vendedor_nome']) ?></strong><br>
            <span style="color:#666"><?= $tipoLabel ?> — <?= formatarData($tk['data_ref']) ?></span>
        </div>

        <!-- Lista de itens -->
        <?php if (!empty($itens)): ?>
        <p style="font-size:13px; color:#666; margin-bottom:8px">
            Confira os itens abaixo antes de confirmar:
        </p>
        <table>
            <thead>
                <tr>
                    <th>Produto</th>
                    <th style="text-align:center; width:60px">Qtd</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($itens as $item): ?>
                <tr>
                    <td>
                        <small style="color:#888"><?= esc($item['codigo']) ?></small><br>
                        <?= esc($item['produto']) ?>
                    </td>
                    <td style="text-align:center; font-weight:bold; font-size:18px">
                        <?= $item['quantidade'] ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php else: ?>
            <div class="alerta alerta-erro">Nenhum item encontrado para esta operação.</div>
        <?php endif; ?>

        <?php if ($erro): ?>
            <div class="alerta alerta-erro"><?= esc($erro) ?></div>
        <?php endif; ?>

        <!-- Formulário com senha única para confirmar ou rejeitar -->
        <form method="post" id="form-confirmacao">
            <hr class="divisor">
            <label for="senha">Sua senha para confirmar ou rejeitar</label>
            <input type="password" id="senha" name="senha" class="campo"
                   placeholder="••••••" required autofocus autocomplete="current-password">
            <small>Digite sua senha e escolha uma das opções abaixo.</small>

            <!-- Botão confirmar -->
            <div class="acoes">
                <button type="submit" name="acao" value="confirmar"
                        class="btn-confirmar"
                        <?= empty($itens) ? 'disabled' : '' ?>>
                    ✅ CONFIRMAR <?= strtoupper($tk['tipo'] === 'saida' ? 'SAÍDA' : 'RETORNO') ?>
                </button>

                <!-- Botão rejeitar abre modal de aviso primeiro -->
                <button type="button" class="btn-rejeitar"
                        onclick="abrirModalRejeicao()"
                        <?= empty($itens) ? 'disabled' : '' ?>>
                    ❌ Não concordo com este registro
                </button>
            </div>
        </form>
    </div>

    <!-- Modal de confirmação de rejeição -->
    <div class="modal-rejeicao" id="modal-rejeicao">
        <div class="modal-box">
            <h3>❌ Rejeitar registro?</h3>
            <p>
                Ao rejeitar, o operador será notificado e deverá corrigir os itens
                e gerar um novo QR Code para você confirmar.<br><br>
                <strong>Tem certeza que deseja rejeitar?</strong>
            </p>
            <div style="display:flex; flex-direction:column; gap:10px">
                <button type="button" onclick="confirmarRejeicao()"
                        style="padding:14px; background:var(--vermelho); color:white;
                               border:none; border-radius:10px; font-size:16px;
                               font-weight:bold; cursor:pointer">
                    Sim, rejeitar
                </button>
                <button type="button" onclick="fecharModalRejeicao()"
                        style="padding:12px; background:#eee; color:#333;
                               border:none; border-radius:10px; font-size:15px; cursor:pointer">
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