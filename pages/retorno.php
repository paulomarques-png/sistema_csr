<?php
// ============================================================
// pages/retorno.php
// Salvar em: C:\xampp\htdocs\sistema_csr\pages\retorno.php
// ============================================================

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

verificarPerfil(['admin', 'operador', 'supervisor', 'master']);

$pdo   = conectar();
$etapa = $_GET['etapa'] ?? 'form';
$erro  = '';

// ── Verifica horário ─────────────────────────────────────────────
$statusHorario = verificarHorario('retorno');
$bloqueado = ($statusHorario === 'bloqueado'
    && !in_array($_SESSION['usuario_perfil'], ['supervisor', 'master']));

// ── PROCESSAMENTO DO FORMULÁRIO (POST) ──────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$bloqueado) {

    $vendedorId         = (int)($_POST['vendedor_id']              ?? 0);
    $codigos            = $_POST['codigo']                         ?? [];
    $produtosArr        = $_POST['produto']                        ?? [];
    $quantidades        = $_POST['quantidade']                     ?? [];
    $obs                = trim($_POST['obs']                       ?? '');
    $tokenCorrigindo    = trim($_POST['token_corrigindo']          ?? '');
    $dataRetroativo     = trim($_POST['data_retroativo']           ?? '');
    $justificativaRetro = trim($_POST['justificativa_retroativo']  ?? '');
    $autorizadoPorId    = (int)($_POST['autorizado_por_id']        ?? 0);
    $autorizadoPorNome  = trim($_POST['autorizado_por_nome']       ?? '');
    $isRetroativo       = false;

    if ($vendedorId <= 0) {
        $erro = 'Selecione um vendedor.';
    }

    $itens = [];
    if (empty($erro)) {
        for ($i = 0; $i < count($codigos); $i++) {
            $cod = trim($codigos[$i]     ?? '');
            $prd = trim($produtosArr[$i] ?? '');
            $qty = (int)($quantidades[$i] ?? 0);
            if ($cod !== '' && $prd !== '' && $qty > 0) {
                $itens[] = ['codigo' => $cod, 'produto' => $prd, 'quantidade' => $qty];
            }
        }
        if (empty($itens)) {
            $erro = 'Adicione pelo menos um produto com quantidade válida.';
        }
    }

    if (empty($erro)) {
        $stmtV = $pdo->prepare("SELECT nome FROM vendedores WHERE id = :id AND ativo = 1");
        $stmtV->execute([':id' => $vendedorId]);
        $vendedor = $stmtV->fetch();

        if (!$vendedor) {
            $erro = 'Vendedor inválido ou inativo.';
        } else {
            $hoje         = date('Y-m-d');
            $agora        = date('H:i:s');
            $nomeVendedor = $vendedor['nome'];
            $obsGravar    = $obs . (modoManutencaoAtivo() ? ' [MANUTENCAO]' : '');

            // Correção de registro rejeitado — mantém data original do token
            if (!empty($tokenCorrigindo)) {
                $stmtTkOld = $pdo->prepare("SELECT vendedor_id, data_ref FROM qr_tokens WHERE token = :t");
                $stmtTkOld->execute([':t' => $tokenCorrigindo]);
                $tkOld = $stmtTkOld->fetch();
                if ($tkOld) {
                    $hoje = $tkOld['data_ref'];
                }

            // Lançamento retroativo — requer autorização de supervisor
            } elseif (!empty($dataRetroativo)
                && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dataRetroativo)
                && $dataRetroativo !== date('Y-m-d')
            ) {
                if ($dataRetroativo > date('Y-m-d')) {
                    $erro = 'Não é permitido lançamento em data futura.';
                } elseif (empty($justificativaRetro)) {
                    $erro = 'Justificativa obrigatória para lançamento retroativo.';
                } elseif ($autorizadoPorId <= 0 || empty($autorizadoPorNome)) {
                    $erro = 'Autorização de supervisor necessária para lançamento retroativo.';
                } else {
                    $hoje         = $dataRetroativo;
                    $isRetroativo = true;
                    $obsGravar   .= " [RETROATIVO | Autorizado: $autorizadoPorNome | $justificativaRetro]";
                }
            }

            if (empty($erro)) {
                // Apaga registros não confirmados do mesmo vendedor+data
                $pdo->prepare("
                    DELETE FROM reg_retornos
                    WHERE vendedor_id = :vid AND data = :data AND confirmado = 0
                ")->execute([':vid' => $vendedorId, ':data' => $hoje]);

                // Invalida tokens pendentes anteriores
                $pdo->prepare("
                    UPDATE qr_tokens SET usado = 1
                    WHERE vendedor_id = :vid AND data_ref = :data
                      AND tipo = 'retorno' AND usado = 0
                ")->execute([':vid' => $vendedorId, ':data' => $hoje]);

                // Insere os novos itens
                $stmtIns = $pdo->prepare("
                    INSERT INTO reg_retornos
                        (data, hora, vendedor_id, vendedor, codigo, produto, quantidade, obs)
                    VALUES
                        (:data, :hora, :vid, :vendedor, :codigo, :produto, :quantidade, :obs)
                ");
                foreach ($itens as $item) {
                    $stmtIns->execute([
                        ':data'       => $hoje,
                        ':hora'       => $agora,
                        ':vid'        => $vendedorId,
                        ':vendedor'   => $nomeVendedor,
                        ':codigo'     => $item['codigo'],
                        ':produto'    => $item['produto'],
                        ':quantidade' => $item['quantidade'],
                        ':obs'        => $obsGravar,
                    ]);
                }

                // Gera novo token QR
                $token  = bin2hex(random_bytes(32));
                $expira = date('Y-m-d H:i:s', time() + QR_EXPIRACAO_HORAS * 3600);

                $pdo->prepare("
                    INSERT INTO qr_tokens (token, tipo, vendedor_id, data_ref, expira_em)
                    VALUES (:token, 'retorno', :vid, :data, :expira)
                ")->execute([
                    ':token'  => $token,
                    ':vid'    => $vendedorId,
                    ':data'   => $hoje,
                    ':expira' => $expira,
                ]);

                // Registra no log de auditoria
                if ($isRetroativo) {
                    registrarLog(
                        'RETORNO_RETROATIVO',
                        "Vendedor: $nomeVendedor | " . count($itens) . " item(ns)"
                        . " | Data ref: $hoje | Autorizado por: $autorizadoPorNome (id:$autorizadoPorId)"
                        . " | Justificativa: $justificativaRetro",
                        obterIP()
                    );
                } else {
                    $acao = empty($tokenCorrigindo) ? 'RETORNO' : 'RETORNO_CORRIGIDO';
                    registrarLog($acao, "Vendedor: $nomeVendedor | " . count($itens) . " item(ns)", obterIP());
                }

                $_SESSION['qr_retorno'] = [
                    'token'          => $token,
                    'vendedor'       => $nomeVendedor,
                    'data'           => $hoje,
                    'itens'          => $itens,
                    'expira'         => $expira,
                    'retroativo'     => $isRetroativo,
                    'autorizado_por' => $isRetroativo ? $autorizadoPorNome : null,
                    'justificativa'  => $isRetroativo ? $justificativaRetro : null,
                ];

                header('Location: ' . BASE_URL . '/pages/retorno.php?etapa=qr');
                exit;
            }
        }
    }
}

// ── Etapa: REENVIAR QR para registro pendente ────────────────────
if ($etapa === 'reenviar') {
    $vidRe  = (int)($_GET['vid']  ?? 0);
    $dataRe = $_GET['data'] ?? date('Y-m-d');

    $stmtRe = $pdo->prepare("
        SELECT codigo, produto, quantidade FROM reg_retornos
        WHERE vendedor_id = :vid AND data = :data AND confirmado = 0
        ORDER BY id ASC
    ");
    $stmtRe->execute([':vid' => $vidRe, ':data' => $dataRe]);
    $itensRe = $stmtRe->fetchAll();

    if (!empty($itensRe)) {
        $stmtVRe = $pdo->prepare("SELECT nome FROM vendedores WHERE id = :id");
        $stmtVRe->execute([':id' => $vidRe]);
        $nomeRe = $stmtVRe->fetchColumn();

        $pdo->prepare("
            UPDATE qr_tokens SET usado = 1
            WHERE vendedor_id = :vid AND data_ref = :data AND tipo = 'retorno' AND usado = 0
        ")->execute([':vid' => $vidRe, ':data' => $dataRe]);

        $token  = bin2hex(random_bytes(32));
        $expira = date('Y-m-d H:i:s', time() + QR_EXPIRACAO_HORAS * 3600);

        $pdo->prepare("
            INSERT INTO qr_tokens (token, tipo, vendedor_id, data_ref, expira_em)
            VALUES (:token, 'retorno', :vid, :data, :expira)
        ")->execute([':token' => $token, ':vid' => $vidRe, ':data' => $dataRe, ':expira' => $expira]);

        registrarLog('QR_REENVIADO', "Retorno reaberto | Vendedor ID: $vidRe | Data: $dataRe", obterIP());

        $_SESSION['qr_retorno'] = [
            'token'      => $token,
            'vendedor'   => $nomeRe,
            'data'       => $dataRe,
            'itens'      => array_map(fn($i) => [
                'codigo'     => $i['codigo'],
                'produto'    => $i['produto'],
                'quantidade' => $i['quantidade'],
            ], $itensRe),
            'expira'     => $expira,
            'retroativo' => false,
        ];

        header('Location: ' . BASE_URL . '/pages/retorno.php?etapa=qr');
        exit;
    }
    $etapa = 'form';
}

// ── Etapa: CORRIGIR registro rejeitado ───────────────────────────
$tkCorr       = null;
$itensCorr    = [];
$vendCorr     = '';
$tokenCorrVal = trim($_GET['token'] ?? '');

if ($etapa === 'corrigir' && $tokenCorrVal) {
    $stmtTk = $pdo->prepare("SELECT * FROM qr_tokens WHERE token = :t AND rejeitado = 1");
    $stmtTk->execute([':t' => $tokenCorrVal]);
    $tkCorr = $stmtTk->fetch();

    if (!$tkCorr) {
        header('Location: ' . BASE_URL . '/pages/saida.php');
        exit;
    }

    $stmtIt = $pdo->prepare("
        SELECT codigo, produto, quantidade FROM reg_retornos
        WHERE vendedor_id = :vid AND data = :data AND confirmado = 0
        ORDER BY id ASC
    ");
    $stmtIt->execute([':vid' => $tkCorr['vendedor_id'], ':data' => $tkCorr['data_ref']]);
    $itensCorr = $stmtIt->fetchAll();

    if (empty($itensCorr)) {
        $stmtIt2 = $pdo->prepare("
            SELECT codigo, produto, quantidade FROM reg_retornos
            WHERE vendedor_id = :vid AND data = :data
            ORDER BY id DESC LIMIT 10
        ");
        $stmtIt2->execute([':vid' => $tkCorr['vendedor_id'], ':data' => $tkCorr['data_ref']]);
        $itensCorr = $stmtIt2->fetchAll();
    }

    $stmtVc = $pdo->prepare("SELECT nome FROM vendedores WHERE id = :id");
    $stmtVc->execute([':id' => $tkCorr['vendedor_id']]);
    $vendCorr = $stmtVc->fetchColumn();
}

// ── Dados para o formulário ──────────────────────────────────────
$vendedores = $pdo->query(
    "SELECT id, nome FROM vendedores WHERE ativo = 1 ORDER BY nome"
)->fetchAll();

// ── Dados da tela QR ─────────────────────────────────────────────
$qrData = null;
if ($etapa === 'qr') {
    $qrData = $_SESSION['qr_retorno'] ?? null;
    if (!$qrData) {
        header('Location: ' . BASE_URL . '/pages/retorno.php');
        exit;
    }
    $confirmUrl = BASE_URL . '/qr/confirmar.php?token=' . $qrData['token'];
    $qrImageUrl = gerarQRImageUrl($confirmUrl);
}
?>
<?php include __DIR__ . '/../includes/header.php'; ?>

<div class="page-top">
    <h2>📥 Registrar Retorno</h2>
    <p>Registro de mercadoria devolvida pelo vendedor</p>
</div>

<main>

<?php /* ── TELA BLOQUEADA ──────────────────────────────────── */ ?>
<?php if ($bloqueado): ?>
    <div class="card">
        <div class="alerta alerta-erro" style="font-size:16px; text-align:center; padding:24px">
            🚫 <strong>Retorno bloqueado</strong><br>
            Permitido das <?= HORA_RETORNO_INICIO ?> às <?= HORA_RETORNO_FIM ?><br>
            <small style="opacity:.8">Solicite autorização de um supervisor.</small>
        </div>
        <div style="text-align:center; margin-top:16px">
            <a href="<?= BASE_URL ?>/index.php" class="btn btn-secundario">← Voltar</a>
        </div>
    </div>

<?php /* ── TELA QR CODE ─────────────────────────────────────── */ ?>
<?php elseif ($etapa === 'qr' && $qrData): ?>
    <div class="card" style="max-width:700px; margin:0 auto">
        <div class="card-titulo">📥 Retorno Registrado — Aguardando Confirmação</div>

        <?php if ($qrData['retroativo'] ?? false): ?>
        <div class="alerta alerta-aviso" style="margin-bottom:16px; font-size:13px">
            🕐 <strong>Registro retroativo</strong> — Data de referência: <?= formatarData($qrData['data']) ?><br>
            <span style="opacity:.8">Autorizado por: <strong><?= esc($qrData['autorizado_por']) ?></strong>
            — <?= esc($qrData['justificativa']) ?></span>
        </div>
        <?php endif; ?>

        <div class="qr-layout">
            <div class="qr-resumo">
                <p><strong>Vendedor:</strong> <?= esc($qrData['vendedor']) ?></p>
                <p><strong>Data:</strong> <?= formatarData($qrData['data']) ?></p>
                <p><strong>Itens registrados:</strong></p>
                <table style="margin-top:8px; width:100%">
                    <thead>
                        <tr>
                            <th>Código</th>
                            <th>Produto</th>
                            <th style="text-align:center">Qtd</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($qrData['itens'] as $item): ?>
                        <tr>
                            <td><code><?= esc($item['codigo']) ?></code></td>
                            <td><?= esc($item['produto']) ?></td>
                            <td style="text-align:center"><strong><?= $item['quantidade'] ?></strong></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <div class="qr-codigo-area">
                <p style="font-size:13px; color:var(--cinza-texto); margin-bottom:10px; text-align:center">
                    📱 Vendedor escaneia com o celular
                </p>
                <img src="<?= $qrImageUrl ?>" alt="QR Code" class="qr-imagem"
                     onerror="this.style.display='none'; document.getElementById('qr-link-fallback').style.display='block'">
                <div id="qr-link-fallback" style="display:none; text-align:center; margin-top:8px">
                    <a href="<?= esc($confirmUrl) ?>" target="_blank" class="btn btn-acento">
                        🔗 Abrir link de confirmação
                    </a>
                </div>

                <div id="status-aguardando" class="status-qr status-aguardando">
                    <span class="spinner"></span> Aguardando confirmação do vendedor...
                </div>
                <div id="status-confirmado" class="status-qr status-confirmado" style="display:none">
                    ✅ Confirmado pelo vendedor!
                </div>
                <div id="status-rejeitado" class="status-qr status-rejeitado" style="display:none">
                    ❌ <strong>Rejeitado pelo vendedor!</strong><br>
                    <span id="motivo-rejeicao" style="font-size:13px; font-style:italic"></span>
                    <div style="margin-top:10px">
                        <a id="link-corrigir" href="#"
                           class="btn btn-vermelho"
                           style="display:inline-block; padding:8px 16px; font-size:13px; text-decoration:none">
                            ✏️ Corrigir Registro
                        </a>
                    </div>
                </div>
                <div id="status-expirado" class="status-qr status-expirado" style="display:none">
                    ⏰ QR Code expirado.
                    <a href="<?= BASE_URL ?>/pages/retorno.php">Novo retorno</a>
                </div>

                <p style="font-size:11px; color:var(--cinza-texto); text-align:center; margin-top:10px">
                    Expira em: <?= formatarDataHora($qrData['expira']) ?>
                </p>
            </div>
        </div>

        <div style="margin-top:20px; text-align:center; display:flex; gap:12px; justify-content:center; flex-wrap:wrap">
            <a href="<?= BASE_URL ?>/pages/retorno.php" class="btn btn-acento">+ Novo Retorno</a>
            <a href="<?= BASE_URL ?>/index.php"         class="btn btn-secundario">← Dashboard</a>
        </div>
    </div>

<?php /* ── TELA CORRIGIR ─────────────────────────────────────── */ ?>
<?php elseif ($etapa === 'corrigir' && $tkCorr): ?>
    <div class="card" style="max-width:700px; margin:0 auto">
        <div class="card-titulo" style="color:var(--vermelho)">
            ✏️ Corrigir Retorno Rejeitado
        </div>
        <div class="alerta alerta-aviso">
            <strong>Vendedor:</strong> <?= esc($vendCorr) ?> —
            <strong>Data:</strong> <?= formatarData($tkCorr['data_ref']) ?><br>
            <?php if ($tkCorr['rejeitado_motivo']): ?>
                <strong>Motivo da rejeição:</strong> <?= esc($tkCorr['rejeitado_motivo']) ?>
            <?php else: ?>
                <em>Nenhum motivo informado pelo vendedor.</em>
            <?php endif; ?>
        </div>

        <?php if ($erro): ?>
            <div class="alerta alerta-erro"><?= $erro ?></div>
        <?php endif; ?>

        <form method="post" id="form-retorno" autocomplete="off">
            <input type="hidden" name="vendedor_id"      value="<?= $tkCorr['vendedor_id'] ?>">
            <input type="hidden" name="token_corrigindo" value="<?= esc($tokenCorrVal) ?>">

            <div class="grupo-campo" style="max-width:400px">
                <label>Vendedor</label>
                <input type="text" class="campo" value="<?= esc($vendCorr) ?>" readonly>
            </div>

            <div style="margin-bottom:8px">
                <label>Produtos retornados <span style="color:red">*</span></label>
                <small style="color:var(--cinza-texto)"> — corrija as quantidades ou produtos</small>
            </div>

            <div class="grade-header">
                <div>Buscar produto</div>
                <div>Produto selecionado</div>
                <div style="text-align:center">Qtd</div>
                <div></div>
            </div>
            <div id="grade-produtos"></div>

            <button type="button" onclick="adicionarLinha()" class="btn btn-secundario" style="margin-top:10px">
                + Adicionar Produto
            </button>

            <div class="grupo-campo" style="margin-top:20px; max-width:500px">
                <label>Observação</label>
                <input type="text" name="obs" class="campo"
                       placeholder="Ex: Corrigido após rejeição" maxlength="200">
            </div>

            <div style="display:flex; gap:12px; margin-top:8px">
                <button type="submit" class="btn btn-acento btn-grande">
                    💾 Salvar Correção e Gerar Novo QR
                </button>
                <a href="<?= BASE_URL ?>/index.php" class="btn btn-secundario btn-grande">Cancelar</a>
            </div>
        </form>
    </div>

<?php /* ── FORMULÁRIO NORMAL ─────────────────────────────────── */ ?>
<?php else: ?>
    <div class="card">
        <div class="card-titulo">📥 Registrar Retorno</div>

        <?php if ($erro): ?>
            <div class="alerta alerta-erro"><?= $erro ?></div>
        <?php endif; ?>
        <?php if ($statusHorario === 'manutencao'): ?>
            <div class="alerta alerta-aviso">⚙️ Operando em modo manutenção.</div>
        <?php elseif (in_array($_SESSION['usuario_perfil'], ['supervisor','master']) && $statusHorario === 'bloqueado'): ?>
            <div class="alerta alerta-aviso">⚠️ Fora do horário padrão (<?= HORA_RETORNO_INICIO ?>–<?= HORA_RETORNO_FIM ?>). Liberado pelo perfil <?= esc($_SESSION['usuario_perfil']) ?>.</div>
        <?php endif; ?>

        <form method="post" id="form-retorno" autocomplete="off">
            <input type="hidden" name="token_corrigindo"         value="">
            <input type="hidden" name="autorizado_por_id"        id="autorizado-por-id"   value="">
            <input type="hidden" name="autorizado_por_nome"      id="autorizado-por-nome" value="">

            <div class="grupo-campo" style="max-width:400px">
                <label>Vendedor <span style="color:red">*</span></label>
                <select name="vendedor_id" class="campo" required>
                    <option value="">— Selecione o vendedor —</option>
                    <?php foreach ($vendedores as $v): ?>
                    <option value="<?= $v['id'] ?>"
                        <?= (isset($_POST['vendedor_id']) && $_POST['vendedor_id'] == $v['id']) ? 'selected' : '' ?>>
                        <?= esc($v['nome']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <?php /* ── Trigger de lançamento retroativo ──────── */ ?>
            <div class="retro-trigger" id="retro-trigger">
                <button type="button" class="retro-trigger-btn" onclick="abrirModalRetroativo()"
                        title="Registrar em data anterior — requer autorização de supervisor">
                    📅🔒 <span>Lançamento retroativo</span>
                </button>
            </div>

            <?php /* ── Campos retroativo (revelados após auth) ── */ ?>
            <div id="retro-campos" class="retro-campos">
                <div class="retro-autorizado-banner">
                    ✅ Autorizado por: <strong id="retro-autorizado-nome"></strong>
                </div>
                <div class="grupo-campo" style="max-width:220px">
                    <label>Data do registro <span style="color:red">*</span></label>
                    <input type="date" name="data_retroativo" id="data-retroativo" class="campo"
                           max="<?= date('Y-m-d', strtotime('-1 day')) ?>">
                    <small style="color:var(--cinza-texto)">Somente datas anteriores a hoje.</small>
                </div>
                <div class="grupo-campo" style="max-width:500px">
                    <label>Justificativa <span style="color:red">*</span></label>
                    <input type="text" name="justificativa_retroativo" class="campo"
                           placeholder="Ex: Retorno não registrado no dia correto" maxlength="300">
                </div>
            </div>

            <div style="margin-bottom:8px">
                <label>Produtos retornados <span style="color:red">*</span></label>
                <small style="color:var(--cinza-texto)"> — máximo 10 itens</small>
            </div>

            <div class="grade-header">
                <div>Buscar produto</div>
                <div>Produto selecionado</div>
                <div style="text-align:center">Qtd</div>
                <div></div>
            </div>
            <div id="grade-produtos"></div>

            <button type="button" onclick="adicionarLinha()" class="btn btn-secundario" style="margin-top:10px">
                + Adicionar Produto
            </button>

            <div class="grupo-campo" style="margin-top:20px; max-width:500px">
                <label>Observação <small style="font-weight:normal">(opcional)</small></label>
                <input type="text" name="obs" class="campo"
                       placeholder="Ex: Produto avariado, devolução parcial..." maxlength="200">
            </div>

            <div style="display:flex; gap:12px; margin-top:8px">
                <button type="submit" class="btn btn-acento btn-grande">
                    💾 Registrar e Gerar QR Code
                </button>
                <a href="<?= BASE_URL ?>/index.php" class="btn btn-secundario btn-grande">Cancelar</a>
            </div>
        </form>
    </div>
<?php endif; ?>

<?php /* ── Modal de autorização retroativa ────────────────────── */ ?>
<div id="modal-retroativo" class="modal" style="display:none">
    <div class="modal-box">
        <h3>📅🔒 Autorizar Lançamento Retroativo</h3>
        <p style="font-size:13px; color:var(--cinza-texto); margin-bottom:16px; line-height:1.5">
            Esta ação requer autorização de um <strong>supervisor</strong> ou <strong>master</strong>.<br>
            O responsável será identificado e registrado no log de auditoria.
        </p>
        <div id="retro-modal-erro" class="alerta alerta-erro" style="display:none"></div>
        <div class="grupo-campo">
            <label>Usuário do supervisor</label>
            <input type="text" id="retro-login" class="campo"
                   placeholder="Login do supervisor" autocomplete="off">
        </div>
        <div class="grupo-campo">
            <label>Senha</label>
            <input type="password" id="retro-senha" class="campo" placeholder="Senha"
                   onkeydown="if(event.key==='Enter') validarSupervisor()">
        </div>
        <div class="modal-botoes">
            <button type="button" class="btn btn-primario" id="btn-autorizar"
                    onclick="validarSupervisor()">
                🔓 Autorizar
            </button>
            <button type="button" class="btn btn-secundario"
                    onclick="fecharModal('modal-retroativo')">
                Cancelar
            </button>
        </div>
    </div>
</div>

</main>

<footer>
    <?= SISTEMA_NOME ?> v<?= SISTEMA_VERSAO ?>
    <?php if ($_SESSION['usuario_perfil'] === 'master'): ?>
        &nbsp;|&nbsp; <a href="<?= BASE_URL ?>/pages/admin.php">⚙ Admin</a>
    <?php endif; ?>
</footer>

<script src="<?= BASE_URL ?>/assets/js/main.js"></script>
<script>
const BASE_URL = '<?= BASE_URL ?>';

<?php if ($etapa === 'qr' && $qrData): ?>
const TOKEN_QR = '<?= $qrData['token'] ?>';

function verificarConfirmacao() {
    fetch(BASE_URL + '/api/verificar_confirmacao.php?token=' + TOKEN_QR)
        .then(r => r.json())
        .then(data => {
            if (data.confirmado) {
                clearInterval(pollingInterval);
                document.getElementById('status-aguardando').style.display = 'none';
                document.getElementById('status-confirmado').style.display = 'block';
            } else if (data.rejeitado) {
                clearInterval(pollingInterval);
                document.getElementById('status-aguardando').style.display = 'none';
                document.getElementById('status-rejeitado').style.display  = 'block';
                if (data.motivo) {
                    document.getElementById('motivo-rejeicao').textContent = '"' + data.motivo + '"';
                }
                document.getElementById('link-corrigir').href =
                    BASE_URL + '/pages/retorno.php?etapa=corrigir&token=<?= $qrData['token'] ?>';
            } else if (data.expirado) {
                clearInterval(pollingInterval);
                document.getElementById('status-aguardando').style.display = 'none';
                document.getElementById('status-expirado').style.display   = 'block';
            }
        }).catch(() => {});
}
const pollingInterval = setInterval(verificarConfirmacao, 3000);
verificarConfirmacao();

<?php else: ?>
// ── Grade de produtos ─────────────────────────────────────────────
function criarLinhaHTML() {
    return `
        <div class="grade-cel-busca">
            <input type="text" class="campo campo-busca"
                   placeholder="Digite o código ou nome..."
                   oninput="buscarProduto(this)" autocomplete="off">
            <div class="autocomplete-lista" style="display:none"></div>
        </div>
        <div class="grade-cel-produto">
            <input type="text" name="produto[]" class="campo" readonly placeholder="—">
            <input type="hidden" name="codigo[]" value="">
        </div>
        <div class="grade-cel-qtd">
            <input type="number" name="quantidade[]" class="campo" min="1" placeholder="0">
        </div>
        <div class="grade-cel-acao">
            <button type="button" onclick="removerLinha(this)"
                    class="btn btn-vermelho" style="padding:8px 14px; font-size:16px">✕</button>
        </div>`;
}

function adicionarLinha() {
    const grade = document.getElementById('grade-produtos');
    if (grade.querySelectorAll('.grade-linha').length >= 10) {
        alert('Máximo de 10 produtos por operação.'); return;
    }
    const div = document.createElement('div');
    div.className = 'grade-linha';
    div.innerHTML = criarLinhaHTML();
    grade.appendChild(div);
    div.querySelector('.campo-busca').focus();
}

function removerLinha(btn) {
    const grade = document.getElementById('grade-produtos');
    if (grade.querySelectorAll('.grade-linha').length <= 1) {
        alert('Mantenha pelo menos um produto.'); return;
    }
    btn.closest('.grade-linha').remove();
}

let timerBusca = null;
function buscarProduto(input) {
    clearTimeout(timerBusca);
    const linha = input.closest('.grade-linha');
    const lista = linha.querySelector('.autocomplete-lista');
    const termo = input.value.trim();
    if (termo.length < 1) { lista.style.display = 'none'; return; }
    timerBusca = setTimeout(() => {
        fetch(BASE_URL + '/api/buscar_produtos.php?q=' + encodeURIComponent(termo))
            .then(r => r.json())
            .then(produtos => {
                lista.innerHTML = '';
                if (produtos.length === 0) {
                    lista.innerHTML = '<div class="ac-item ac-vazio">Nenhum produto encontrado.</div>';
                } else {
                    produtos.forEach(p => {
                        const item = document.createElement('div');
                        item.className = 'ac-item';
                        item.innerHTML = '<strong>' + p.codigo + '</strong> — ' + p.descricao
                                       + (p.unidade ? ' <em>(' + p.unidade + ')</em>' : '');
                        item.addEventListener('click', () => selecionarProduto(linha, p));
                        lista.appendChild(item);
                    });
                }
                lista.style.display = 'block';
            }).catch(() => { lista.style.display = 'none'; });
    }, 300);
}

function selecionarProduto(linha, produto) {
    linha.querySelector('.campo-busca').value      = produto.codigo + ' — ' + produto.descricao;
    linha.querySelector('[name="produto[]"]').value = produto.descricao;
    linha.querySelector('[name="codigo[]"]').value  = produto.codigo;
    linha.querySelector('.autocomplete-lista').style.display = 'none';
    linha.querySelector('[name="quantidade[]"]').focus();
}

document.addEventListener('click', e => {
    if (!e.target.closest('.grade-cel-busca'))
        document.querySelectorAll('.autocomplete-lista').forEach(l => l.style.display = 'none');
});

document.getElementById('form-retorno').addEventListener('submit', function(e) {
    let valido = false;
    document.querySelectorAll('#grade-produtos .grade-linha').forEach(linha => {
        const cod = linha.querySelector('[name="codigo[]"]').value;
        const qty = parseInt(linha.querySelector('[name="quantidade[]"]').value) || 0;
        if (cod && qty > 0) valido = true;
    });
    if (!valido) {
        e.preventDefault();
        alert('Adicione pelo menos um produto com código e quantidade válidos.');
        return;
    }
    // Valida campos retroativos se visíveis
    const camposRetro = document.getElementById('retro-campos');
    if (camposRetro.classList.contains('retro-campos--visivel')) {
        const dataRetro = document.getElementById('data-retroativo').value;
        const just = document.querySelector('[name="justificativa_retroativo"]').value.trim();
        if (!dataRetro) { e.preventDefault(); alert('Informe a data do registro retroativo.'); return; }
        if (!just)      { e.preventDefault(); alert('Justificativa obrigatória para lançamento retroativo.'); return; }
    }
});

// ── Lançamento retroativo ─────────────────────────────────────────
function abrirModalRetroativo() {
    document.getElementById('retro-modal-erro').style.display = 'none';
    document.getElementById('retro-login').value = '';
    document.getElementById('retro-senha').value = '';
    abrirModal('modal-retroativo');
    setTimeout(() => document.getElementById('retro-login').focus(), 100);
}

function validarSupervisor() {
    const login  = document.getElementById('retro-login').value.trim();
    const senha  = document.getElementById('retro-senha').value;
    const erroEl = document.getElementById('retro-modal-erro');
    const btnEl  = document.getElementById('btn-autorizar');

    if (!login || !senha) {
        erroEl.textContent   = 'Preencha usuário e senha do supervisor.';
        erroEl.style.display = 'block';
        return;
    }

    btnEl.textContent = '⏳ Validando...';
    btnEl.disabled    = true;

    fetch(BASE_URL + '/api/validar_supervisor.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'login=' + encodeURIComponent(login) + '&senha=' + encodeURIComponent(senha),
        credentials: 'same-origin'
    })
    .then(r => r.json())
    .then(data => {
        btnEl.textContent = '🔓 Autorizar';
        btnEl.disabled    = false;

        if (data.ok) {
            fecharModal('modal-retroativo');
            document.getElementById('autorizado-por-id').value   = data.id;
            document.getElementById('autorizado-por-nome').value = data.nome;
            document.getElementById('retro-autorizado-nome').textContent =
                data.nome + ' (' + data.perfil + ')';

            const campos = document.getElementById('retro-campos');
            campos.classList.add('retro-campos--visivel');
            document.getElementById('retro-trigger').style.display = 'none';
            setTimeout(() => document.getElementById('data-retroativo').focus(), 420);
        } else {
            erroEl.textContent   = data.msg || 'Credenciais inválidas.';
            erroEl.style.display = 'block';
        }
    })
    .catch(() => {
        btnEl.textContent = '🔓 Autorizar';
        btnEl.disabled    = false;
        erroEl.textContent   = 'Erro de conexão. Tente novamente.';
        erroEl.style.display = 'block';
    });
}

<?php if ($etapa === 'corrigir' && !empty($itensCorr)): ?>
const itensPre = <?= json_encode(array_values($itensCorr)) ?>;
itensPre.forEach(item => {
    adicionarLinha();
    const linhas = document.querySelectorAll('#grade-produtos .grade-linha');
    const ultima = linhas[linhas.length - 1];
    ultima.querySelector('.campo-busca').value          = item.codigo + ' — ' + item.produto;
    ultima.querySelector('[name="produto[]"]').value    = item.produto;
    ultima.querySelector('[name="codigo[]"]').value     = item.codigo;
    ultima.querySelector('[name="quantidade[]"]').value = item.quantidade;
});
<?php else: ?>
adicionarLinha();
<?php endif; ?>

<?php endif; ?>
</script>

</body>
</html>