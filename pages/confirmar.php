<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';      // Para obterIP() e registrarLog()
require_once __DIR__ . '/../includes/functions.php';
// ⚠️ Não chama verificarLogin() — esta página é pública (protegida pelo token)

$token = trim($_GET['token'] ?? '');
$erro  = '';
$msg   = '';

if (empty($token)) {
    die('Link inválido.');
}

$pdo = conectar();

// Busca token + dados do vendedor
$stmt = $pdo->prepare("
    SELECT t.*, v.nome AS vendedor_nome, v.senha AS vendedor_senha
    FROM qr_tokens t
    INNER JOIN vendedores v ON v.id = t.vendedor_id
    WHERE t.token = :token
    LIMIT 1
");
$stmt->execute([':token' => $token]);
$tk = $stmt->fetch();

if (!$tk) {
    die('Link inválido ou expirado.');
}

if (strtotime($tk['expira_em']) < time()) {
    die('Este link expirou. Peça ao operador para gerar um novo.');
}

$jaConfirmado = (bool)$tk['usado'];
$itens        = [];

if (!$jaConfirmado) {
    // Busca os itens a confirmar
    $tabela = $tk['tipo'] === 'saida' ? 'reg_saidas' : 'reg_retornos';
    $stmtI  = $pdo->prepare("
        SELECT codigo, produto, quantidade
        FROM $tabela
        WHERE vendedor_id = :vid AND data = :data
        ORDER BY id ASC
    ");
    $stmtI->execute([':vid' => $tk['vendedor_id'], ':data' => $tk['data_ref']]);
    $itens = $stmtI->fetchAll();
}

// ── Processa confirmação ──────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$jaConfirmado) {
    $senhaDigitada = trim($_POST['senha'] ?? '');

    if (empty($senhaDigitada)) {
        $erro = 'Digite sua senha para confirmar.';
    } elseif (!password_verify($senhaDigitada, $tk['vendedor_senha'])) {
        $erro = 'Senha incorreta. Tente novamente.';
    } else {
        $tabela = $tk['tipo'] === 'saida' ? 'reg_saidas' : 'reg_retornos';
        $agora  = date('Y-m-d H:i:s');
        $ip     = obterIP();

        // Marca todos os itens como confirmados
        $pdo->prepare("
            UPDATE $tabela
            SET confirmado = 1, confirmado_em = :agora, confirmado_ip = :ip
            WHERE vendedor_id = :vid AND data = :data AND confirmado = 0
        ")->execute([
            ':agora' => $agora,
            ':ip'    => $ip,
            ':vid'   => $tk['vendedor_id'],
            ':data'  => $tk['data_ref'],
        ]);

        // Marca token como usado
        $pdo->prepare("UPDATE qr_tokens SET usado = 1 WHERE token = :token")
            ->execute([':token' => $token]);

        registrarLog(
            'QR_CONFIRMADO',
            "Tipo: {$tk['tipo']} | Vendedor: {$tk['vendedor_nome']} | Data: {$tk['data_ref']}",
            $ip
        );

        $jaConfirmado = true;
        $msg = 'Confirmação realizada com sucesso!';
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
        /* Layout mobile-first — página independente */
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        :root {
            --primaria: #2B2B88;
            --acento:   #0A7BC4;
            --verde:    #28A745;
            --vermelho: #DC3545;
            --cinza:    #f4f6fa;
        }

        body {
            font-family: Arial, system-ui, sans-serif;
            background: var(--cinza);
            color: #1a1a2e;
            min-height: 100vh;
            padding: 0 0 40px;
        }

        .topo {
            background: var(--primaria);
            color: white;
            padding: 16px 20px;
            text-align: center;
        }
        .topo h1 { font-size: 18px; }
        .topo p  { font-size: 13px; opacity: .8; margin-top: 4px; }

        .caixa {
            background: white;
            border-radius: 12px;
            margin: 16px;
            padding: 20px;
            box-shadow: 0 2px 12px rgba(0,0,0,.1);
        }

        .info-vendedor {
            font-size: 15px;
            margin-bottom: 16px;
            padding-bottom: 12px;
            border-bottom: 1px solid #eee;
        }
        .info-vendedor strong { color: var(--primaria); font-size: 18px; }

        table { width: 100%; border-collapse: collapse; font-size: 14px; margin-bottom: 20px; }
        th { background: var(--primaria); color: white; padding: 10px 8px; text-align: left; }
        td { padding: 10px 8px; border-bottom: 1px solid #f0f0f0; }

        .campo {
            width: 100%;
            padding: 14px 16px;
            border: 2px solid #ddd;
            border-radius: 10px;
            font-size: 18px;
            text-align: center;
            letter-spacing: 4px;
            margin-bottom: 12px;
            transition: border-color .2s;
        }
        .campo:focus { outline: none; border-color: var(--acento); }

        .btn-confirmar {
            width: 100%;
            padding: 16px;
            background: var(--verde);
            color: white;
            border: none;
            border-radius: 10px;
            font-size: 18px;
            font-weight: bold;
            cursor: pointer;
            transition: filter .2s;
        }
        .btn-confirmar:hover   { filter: brightness(1.1); }
        .btn-confirmar:active  { filter: brightness(.9); }
        .btn-confirmar:disabled { background: #aaa; cursor: default; }

        .alerta {
            padding: 12px 16px;
            border-radius: 8px;
            margin-bottom: 16px;
            font-size: 14px;
            font-weight: 500;
        }
        .alerta-erro    { background: #fff0f0; color: var(--vermelho); border-left: 4px solid var(--vermelho); }
        .alerta-sucesso { background: #f0fff4; color: var(--verde);    border-left: 4px solid var(--verde); }

        .sucesso-grande {
            text-align: center;
            padding: 32px 20px;
        }
        .sucesso-grande .icone   { font-size: 64px; }
        .sucesso-grande h2       { color: var(--verde); margin: 12px 0 8px; font-size: 22px; }
        .sucesso-grande p        { color: #555; font-size: 15px; }

        label { display: block; font-size: 14px; font-weight: 600; margin-bottom: 6px; color: #444; }
        small { color: #888; font-size: 12px; }
    </style>
</head>
<body>

<div class="topo">
    <h1><?= $tipoLabel ?> — <?= esc(SISTEMA_NOME) ?></h1>
    <p>Confirme a operação com sua senha</p>
</div>

<?php if ($jaConfirmado): ?>
    <!-- ── JÁ CONFIRMADO ────────────────────────────── -->
    <div class="caixa">
        <div class="sucesso-grande">
            <div class="icone">✅</div>
            <h2>Confirmado!</h2>
            <p>
                <?php if ($msg): ?>
                    <?= esc($msg) ?>
                <?php else: ?>
                    Esta operação já foi confirmada anteriormente.
                <?php endif; ?>
            </p>
            <p style="margin-top:12px; font-size:13px; color:#999">
                <?= $tipoLabel ?> — <?= esc($tk['vendedor_nome']) ?><br>
                <?= formatarData($tk['data_ref']) ?>
            </p>
        </div>
    </div>

<?php else: ?>
    <!-- ── FORMULÁRIO DE CONFIRMAÇÃO ────────────────── -->
    <div class="caixa">
        <div class="info-vendedor">
            <strong><?= esc($tk['vendedor_nome']) ?></strong><br>
            <?= $tipoLabel ?> de <?= formatarData($tk['data_ref']) ?>
        </div>

        <?php if (!empty($itens)): ?>
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

        <form method="post">
            <label for="senha">Sua senha de confirmação</label>
            <input type="password"
                   id="senha"
                   name="senha"
                   class="campo"
                   placeholder="••••••"
                   required
                   autofocus
                   autocomplete="current-password">
            <small>Digite a senha que o operador cadastrou para você.</small>

            <br><br>

            <button type="submit" class="btn-confirmar"
                    <?= empty($itens) ? 'disabled' : '' ?>>
                ✅ CONFIRMAR <?= strtoupper($tk['tipo'] === 'saida' ? 'SAÍDA' : 'RETORNO') ?>
            </button>
        </form>
    </div>

<?php endif; ?>

</body>
</html>