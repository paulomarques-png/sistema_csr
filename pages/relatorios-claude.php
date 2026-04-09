<?php
// ============================================================
// pages/relatorios.php — Relatório Unificado de Movimentação
// Salvar em: C:\xampp\htdocs\sistema_csr\pages\relatorios.php
// Acesso: administrativo, supervisor, master
// ============================================================
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

verificarPerfil(['administrativo', 'supervisor', 'master']);

$pdo     = conectar();
$perfil  = $_SESSION['usuario_perfil'] ?? '';
$usuario = $_SESSION['usuario_nome']   ?? 'Usuário';

// ── Filtros ─────────────────────────────────────────────────
$data_ini   = $_GET['data_ini']         ?? date('Y-m-d');
$data_fim   = $_GET['data_fim']         ?? date('Y-m-d');
$vid_filtro = $_GET['vendedor_id']      ?? '';
$so_pend    = !empty($_GET['apenas_pendencias']);

// Sanitizar datas
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $data_ini)) $data_ini = date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $data_fim))  $data_fim = date('Y-m-d');
if ($data_fim < $data_ini) $data_fim = $data_ini;

// Vendedores ativos (para o filtro)
$vendedores = $pdo->query("SELECT id, nome FROM vendedores WHERE ativo = 1 ORDER BY nome")
                  ->fetchAll(PDO::FETCH_ASSOC);

// ============================================================
// MONTAGEM DOS DADOS
// ============================================================

// Estrutura: $dados[vendedor_id] = [nome, produtos[], registros[], totais...]
$dados = [];

// Inicializa todos os vendedores ativos
foreach ($vendedores as $v) {
    $dados[$v['id']] = [
        'nome'          => $v['nome'],
        'produtos'      => [],   // [codigo => [produto, saida, retorno, vendido, saldo]]
        'registros'     => [],   // extrato cronológico
        'qr_pendentes'  => 0,
        'tot_saida'     => 0,
        'tot_retorno'   => 0,
        'tot_vendido'   => 0,
        'saldo'         => 0,
        'tem_pendencia' => false,
        'tem_movimento' => false,
    ];
}

// Helper: garante produto inicializado
function initProd(array &$dados, $vid, $cod, $pnome): void {
    if (!isset($dados[$vid]['produtos'][$cod])) {
        $dados[$vid]['produtos'][$cod] = [
            'produto' => $pnome,
            'saida'   => 0,
            'retorno' => 0,
            'vendido' => 0,
            'saldo'   => 0,
        ];
    }
}

// Params separados por query para evitar conflito PDO
$p_s = [':di' => $data_ini, ':df' => $data_fim];
$p_r = [':di' => $data_ini, ':df' => $data_fim];
$p_v = [':di' => $data_ini, ':df' => $data_fim];
$es = $er = $ev = '';
if ($vid_filtro && is_numeric($vid_filtro)) {
    $es = ' AND s.vendedor_id = :vid';
    $er = ' AND r.vendedor_id = :vid';
    $ev = ' AND v.vendedor_id = :vid';
    $p_s[':vid'] = $p_r[':vid'] = $p_v[':vid'] = (int)$vid_filtro;
}

// ── Saídas ──
$st = $pdo->prepare("
    SELECT s.vendedor_id, s.vendedor, s.codigo, s.produto, SUM(s.quantidade) AS qtd
    FROM reg_saidas s
    WHERE s.data BETWEEN :di AND :df $es
    GROUP BY s.vendedor_id, s.vendedor, s.codigo, s.produto
");
$st->execute($p_s);
foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $vid = $r['vendedor_id'];
    if (!isset($dados[$vid])) continue; // vendedor inativo: ignorar
    initProd($dados, $vid, $r['codigo'], $r['produto']);
    $dados[$vid]['produtos'][$r['codigo']]['saida'] += (int)$r['qtd'];
    $dados[$vid]['tem_movimento'] = true;
}

// ── Retornos ──
$st = $pdo->prepare("
    SELECT r.vendedor_id, r.vendedor, r.codigo, r.produto, SUM(r.quantidade) AS qtd
    FROM reg_retornos r
    WHERE r.data BETWEEN :di AND :df $er
    GROUP BY r.vendedor_id, r.vendedor, r.codigo, r.produto
");
$st->execute($p_r);
foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $vid = $r['vendedor_id'];
    if (!isset($dados[$vid])) continue;
    initProd($dados, $vid, $r['codigo'], $r['produto']);
    $dados[$vid]['produtos'][$r['codigo']]['retorno'] += (int)$r['qtd'];
    $dados[$vid]['tem_movimento'] = true;
}

// ── Vendas ──
$st = $pdo->prepare("
    SELECT v.vendedor_id, v.vendedor, v.codigo, v.produto, SUM(v.quantidade) AS qtd
    FROM reg_vendas v
    WHERE v.data BETWEEN :di AND :df $ev
    GROUP BY v.vendedor_id, v.vendedor, v.codigo, v.produto
");
$st->execute($p_v);
foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $vid = $r['vendedor_id'];
    if (!isset($dados[$vid])) continue;
    initProd($dados, $vid, $r['codigo'], $r['produto']);
    $dados[$vid]['produtos'][$r['codigo']]['vendido'] += (int)$r['qtd'];
    $dados[$vid]['tem_movimento'] = true;
}

// ── Calcular saldos ──
foreach ($dados as $vid => &$vend) {
    foreach ($vend['produtos'] as $cod => &$prod) {
        $prod['saldo'] = $prod['saida'] - $prod['retorno'] - $prod['vendido'];
        $vend['tot_saida']   += $prod['saida'];
        $vend['tot_retorno'] += $prod['retorno'];
        $vend['tot_vendido'] += $prod['vendido'];
        $vend['saldo']       += $prod['saldo'];
        if ($prod['saldo'] != 0) $vend['tem_pendencia'] = true;
    }
    unset($prod);
}
unset($vend);

// ── Extrato cronológico (UNION ALL com params únicos) ──
// PDO não permite reusar o mesmo named param em queries UNION,
// então usamos :di1/:df1, :di2/:df2, :di3/:df3
$p_ext = [
    ':di1' => $data_ini, ':df1' => $data_fim,
    ':di2' => $data_ini, ':df2' => $data_fim,
    ':di3' => $data_ini, ':df3' => $data_fim,
];
$es1 = $er1 = $ev1 = '';
if ($vid_filtro && is_numeric($vid_filtro)) {
    $es1 = ' AND s.vendedor_id = :vid1';
    $er1 = ' AND r.vendedor_id = :vid2';
    $ev1 = ' AND v.vendedor_id = :vid3';
    $p_ext[':vid1'] = $p_ext[':vid2'] = $p_ext[':vid3'] = (int)$vid_filtro;
}

$st = $pdo->prepare("
    SELECT 'Saída'   AS tipo, s.data, s.hora, s.codigo, s.produto,
           s.quantidade, COALESCE(s.obs, '') AS obs, ''                   AS pedido, s.vendedor_id
    FROM reg_saidas s
    WHERE s.data BETWEEN :di1 AND :df1 $es1
    UNION ALL
    SELECT 'Retorno' AS tipo, r.data, r.hora, r.codigo, r.produto,
           r.quantidade, COALESCE(r.obs, '') AS obs, ''                   AS pedido, r.vendedor_id
    FROM reg_retornos r
    WHERE r.data BETWEEN :di2 AND :df2 $er1
    UNION ALL
    SELECT 'Venda'   AS tipo, v.data, v.hora, v.codigo, v.produto,
           v.quantidade, COALESCE(v.obs, '') AS obs, COALESCE(v.pedido, '') AS pedido, v.vendedor_id
    FROM reg_vendas v
    WHERE v.data BETWEEN :di3 AND :df3 $ev1
    ORDER BY data ASC, hora ASC, tipo ASC
");
$st->execute($p_ext);
foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $reg) {
    $vid = $reg['vendedor_id'];
    if (isset($dados[$vid])) {
        $dados[$vid]['registros'][] = $reg;
    }
}

// ── QR tokens pendentes (não confirmados pelo vendedor) ──
$p_qr  = [':di' => $data_ini, ':df' => $data_fim];
$ex_qr = '';
if ($vid_filtro && is_numeric($vid_filtro)) {
    $ex_qr = ' AND vendedor_id = :vid';
    $p_qr[':vid'] = (int)$vid_filtro;
}
$st = $pdo->prepare("
    SELECT vendedor_id, COUNT(*) AS qtd
    FROM qr_tokens
    WHERE DATE(data_ref) BETWEEN :di AND :df
      AND usado = 0
      AND expira_em > NOW()
    $ex_qr
    GROUP BY vendedor_id
");
$st->execute($p_qr);
foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
    if (isset($dados[$r['vendedor_id']])) {
        $dados[$r['vendedor_id']]['qr_pendentes'] = (int)$r['qtd'];
    }
}

// ── Filtro: apenas pendências ──
if ($so_pend) {
    $dados = array_filter($dados, fn($v) => $v['tem_pendencia'] || $v['qr_pendentes'] > 0);
}

// ── Ordenação: pendências primeiro, depois alfabético ──
uasort($dados, function ($a, $b) {
    // Primeiro: pendência crítica (saldo < 0)
    $crit_a = $a['tem_pendencia'] && $a['saldo'] < 0;
    $crit_b = $b['tem_pendencia'] && $b['saldo'] < 0;
    if ($crit_a !== $crit_b) return $crit_b <=> $crit_a;
    // Segundo: pendência (saldo > 0)
    if ($a['tem_pendencia'] !== $b['tem_pendencia']) return $b['tem_pendencia'] <=> $a['tem_pendencia'];
    // Terceiro: com movimento vs sem
    if ($a['tem_movimento'] !== $b['tem_movimento']) return $b['tem_movimento'] <=> $a['tem_movimento'];
    // Por último: alfabético
    return strcmp($a['nome'], $b['nome']);
});

// ── Totais gerais ──
$g_saida   = array_sum(array_column($dados, 'tot_saida'));
$g_retorno = array_sum(array_column($dados, 'tot_retorno'));
$g_vendido = array_sum(array_column($dados, 'tot_vendido'));
$g_saldo   = array_sum(array_column($dados, 'saldo'));
$g_pend    = count(array_filter($dados, fn($v) => $v['tem_pendencia']));
$g_qrpend  = array_sum(array_column($dados, 'qr_pendentes'));

$periodo_label = ($data_ini === $data_fim)
    ? formatarData($data_ini)
    : formatarData($data_ini) . ' a ' . formatarData($data_fim);

// ── Params para exportação CSV ──
$csv_qs = http_build_query([
    'data_ini'          => $data_ini,
    'data_fim'          => $data_fim,
    'vendedor_id'       => $vid_filtro,
    'apenas_pendencias' => $so_pend ? '1' : '',
]);

// ── Funções de badge (usadas no HTML) ──
function badgeStatus(int $saldo, bool $mov): string {
    if (!$mov) return '<span class="badge badge-sem-mov">— Sem movimento</span>';
    if ($saldo === 0) return '<span class="badge badge-verde">✓ Zerado</span>';
    if ($saldo > 0)  return '<span class="badge badge-amarelo">⚠ Em aberto</span>';
    return '<span class="badge badge-vermelho">⚠ Verificar</span>';
}
function badgeProd(int $saldo): string {
    if ($saldo === 0) return '<span class="badge badge-verde">✓ OK</span>';
    if ($saldo > 0)  return '<span class="badge badge-amarelo">' . $saldo . ' pendente</span>';
    return '<span class="badge badge-vermelho">' . abs($saldo) . ' negativo</span>';
}
function badgeTipo(string $tipo): string {
    return match($tipo) {
        'Saída'   => '<span class="badge badge-tipo-saida">↑ Saída</span>',
        'Retorno' => '<span class="badge badge-tipo-retorno">↓ Retorno</span>',
        'Venda'   => '<span class="badge badge-tipo-venda">✓ Venda</span>',
        default   => '<span class="badge">' . htmlspecialchars($tipo) . '</span>',
    };
}
function cor_saldo(int $saldo): string {
    if ($saldo === 0) return 'var(--verde)';
    if ($saldo > 0)  return '#856404';
    return 'var(--vermelho)';
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Relatórios — Controle de Carga</title>
<style>
/* ── Variáveis (consistente com o projeto) ─────────────────── */
:root {
  --primaria:  #2B2B88;
  --acento:    #0A7BC4;
  --verde:     #28A745;
  --vermelho:  #DC3545;
  --amarelo:   #FFC107;
  --cinza-bg:  #f0f2f5;
  --cinza-borda: #dee2e6;
  --sombra:    0 2px 8px rgba(0,0,0,.10);
  --sombra-lg: 0 4px 16px rgba(0,0,0,.13);
}
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
body {
  font-family: 'Segoe UI', Tahoma, Arial, sans-serif;
  background: var(--cinza-bg);
  color: #2d3748;
  min-height: 100vh;
}

/* ── NAVBAR ────────────────────────────────────────────────── */
.navbar {
  background: var(--primaria);
  display: flex;
  align-items: center;
  padding: 0 20px;
  height: 46px;
  gap: 8px;
  position: sticky;
  top: 0;
  z-index: 200;
  box-shadow: 0 2px 6px rgba(0,0,0,.25);
}
.navbar-brand {
  color: #fff;
  font-weight: 700;
  font-size: 1rem;
  text-decoration: none;
  margin-right: 16px;
  white-space: nowrap;
  display: flex;
  align-items: center;
  gap: 7px;
}
.navbar-nav {
  display: flex;
  gap: 2px;
  flex: 1;
  overflow: hidden;
}
.navbar-nav a {
  color: rgba(255,255,255,.82);
  text-decoration: none;
  padding: 6px 11px;
  border-radius: 4px;
  font-size: .84rem;
  white-space: nowrap;
  transition: background .15s, color .15s;
}
.navbar-nav a:hover { color: #fff; background: rgba(255,255,255,.12); }
.navbar-nav a.ativo  {
  color: #fff;
  background: rgba(255,255,255,.18);
  border-bottom: 2px solid rgba(255,255,255,.9);
}
.navbar-right {
  display: flex;
  align-items: center;
  gap: 8px;
  color: rgba(255,255,255,.8);
  font-size: .82rem;
  white-space: nowrap;
}
.badge-perfil {
  background: rgba(255,255,255,.2);
  color: #fff;
  padding: 2px 8px;
  border-radius: 10px;
  font-size: .72rem;
  font-weight: 800;
  letter-spacing: .06em;
  text-transform: uppercase;
}
.btn-sair {
  color: rgba(255,255,255,.8);
  text-decoration: none;
  padding: 4px 10px;
  border: 1px solid rgba(255,255,255,.3);
  border-radius: 4px;
  font-size: .8rem;
  transition: background .15s;
}
.btn-sair:hover { background: rgba(255,255,255,.15); color: #fff; }

/* ── CABEÇALHO DA PÁGINA ───────────────────────────────────── */
.page-top {
  background: linear-gradient(135deg, var(--primaria) 0%, #1a1a6e 100%);
  color: #fff;
  padding: 22px 28px 18px;
}
.page-top h1 {
  font-size: 1.45rem;
  font-weight: 800;
  display: flex;
  align-items: center;
  gap: 10px;
}
.page-top p {
  font-size: .84rem;
  opacity: .72;
  margin-top: 4px;
  padding-left: 2px;
}

/* ── LAYOUT ────────────────────────────────────────────────── */
.conteudo {
  padding: 22px 28px;
  max-width: 1320px;
  margin: 0 auto;
}

/* ── CARD ──────────────────────────────────────────────────── */
.card {
  background: #fff;
  border-radius: 10px;
  box-shadow: var(--sombra);
  padding: 20px;
  margin-bottom: 18px;
}
.card-titulo {
  font-size: .96rem;
  font-weight: 700;
  color: var(--primaria);
  margin-bottom: 14px;
  display: flex;
  align-items: center;
  gap: 8px;
}

/* ── STAT CARDS ────────────────────────────────────────────── */
.grid-stats {
  display: grid;
  grid-template-columns: repeat(4, 1fr);
  gap: 14px;
  margin-bottom: 18px;
}
.card-stat {
  background: #fff;
  border-radius: 10px;
  box-shadow: var(--sombra);
  padding: 18px 20px;
  border-left: 5px solid var(--primaria);
  transition: transform .15s, box-shadow .15s;
}
.card-stat:hover { transform: translateY(-2px); box-shadow: var(--sombra-lg); }
.card-stat.azul    { border-color: var(--acento);   }
.card-stat.verde   { border-color: var(--verde);    }
.card-stat.amarelo { border-color: var(--amarelo);  }
.card-stat.vermelho{ border-color: var(--vermelho); }
.stat-num {
  font-size: 2.1rem;
  font-weight: 900;
  color: var(--primaria);
  line-height: 1;
  letter-spacing: -.02em;
}
.stat-num.verde   { color: var(--verde);   }
.stat-num.amarelo { color: #856404;        }
.stat-num.vermelho{ color: var(--vermelho);}
.stat-num.azul    { color: var(--acento);  }
.stat-label {
  font-size: .73rem;
  color: #6c757d;
  margin-top: 5px;
  text-transform: uppercase;
  letter-spacing: .06em;
  font-weight: 600;
}

/* ── FILTROS ───────────────────────────────────────────────── */
.linha-filtros {
  display: flex;
  gap: 12px;
  flex-wrap: wrap;
  align-items: flex-end;
}
.campo-grupo { display: flex; flex-direction: column; }
.campo-grupo.largo  { flex: 2; min-width: 200px; }
.campo-grupo.medio  { flex: 1.5; min-width: 150px; }
.campo-grupo.curto  { flex: 1; min-width: 130px; }
.campo-grupo.acao   { flex: 0 0 auto; }
.campo-label {
  font-size: .78rem;
  color: #555;
  margin-bottom: 5px;
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: .04em;
}
.campo {
  padding: 8px 11px;
  border: 1.5px solid var(--cinza-borda);
  border-radius: 6px;
  font-size: .88rem;
  color: #333;
  transition: border-color .15s, box-shadow .15s;
  width: 100%;
  background: #fff;
}
.campo:focus { outline: none; border-color: var(--acento); box-shadow: 0 0 0 3px rgba(10,123,196,.12); }
.check-label {
  display: flex;
  align-items: center;
  gap: 7px;
  font-size: .86rem;
  padding: 9px 0;
  cursor: pointer;
  user-select: none;
  color: #444;
}
.check-label input[type=checkbox] { width: 16px; height: 16px; accent-color: var(--primaria); cursor: pointer; }

/* ── BOTÕES ────────────────────────────────────────────────── */
.btn {
  padding: 8px 16px;
  border: none;
  border-radius: 6px;
  cursor: pointer;
  font-size: .86rem;
  font-weight: 700;
  transition: background .15s, transform .1s, box-shadow .15s;
  text-decoration: none;
  display: inline-flex;
  align-items: center;
  gap: 6px;
  line-height: 1.2;
}
.btn:active { transform: scale(.97); }
.btn-primario   { background: var(--primaria); color: #fff; }
.btn-primario:hover { background: #1f1f6a; box-shadow: 0 3px 8px rgba(43,43,136,.35); }
.btn-secundario { background: #6c757d; color: #fff; }
.btn-secundario:hover { background: #565e64; }
.btn-acento     { background: var(--acento); color: #fff; }
.btn-acento:hover { background: #0864a3; }
.btn-grande  { padding: 9px 18px; font-size: .92rem; }
.btn-pequeno { padding: 5px 10px; font-size: .78rem; }

/* ── DROPDOWN EXPORTAR ─────────────────────────────────────── */
.dd-wrap { position: relative; display: inline-block; }
.dd-menu {
  display: none;
  position: absolute;
  right: 0;
  top: calc(100% + 5px);
  background: #fff;
  border: 1px solid var(--cinza-borda);
  border-radius: 8px;
  box-shadow: var(--sombra-lg);
  min-width: 190px;
  z-index: 300;
  overflow: hidden;
}
.dd-wrap.aberto .dd-menu { display: block; }
.dd-menu a {
  display: flex;
  align-items: center;
  gap: 9px;
  padding: 11px 15px;
  color: #333;
  text-decoration: none;
  font-size: .86rem;
  border-bottom: 1px solid #f0f0f0;
  transition: background .12s;
}
.dd-menu a:last-child { border-bottom: none; }
.dd-menu a:hover { background: #f7f8fc; color: var(--primaria); font-weight: 600; }

/* ── ALERTAS ───────────────────────────────────────────────── */
.alerta {
  padding: 12px 16px;
  border-radius: 8px;
  margin-bottom: 16px;
  font-size: .87rem;
  display: flex;
  align-items: flex-start;
  gap: 11px;
  line-height: 1.5;
}
.alerta-aviso  { background: #fff8e1; border-left: 4px solid var(--amarelo); color: #7a5c00; }
.alerta-erro   { background: #fdecea; border-left: 4px solid var(--vermelho); color: #7a1c1c; }
.alerta-sucesso{ background: #e8f5e9; border-left: 4px solid var(--verde); color: #1b5e20; }
.alerta-info   { background: #e3f2fd; border-left: 4px solid var(--acento); color: #0c5460; }
.alerta-icone  { font-size: 1.1rem; flex-shrink: 0; margin-top: 1px; }

/* ── TABELA ────────────────────────────────────────────────── */
.tabela {
  width: 100%;
  border-collapse: collapse;
  font-size: .87rem;
}
.tabela th {
  background: var(--primaria);
  color: #fff;
  padding: 10px 13px;
  text-align: left;
  font-size: .75rem;
  text-transform: uppercase;
  letter-spacing: .05em;
  white-space: nowrap;
  font-weight: 700;
}
.tabela th.c, .tabela td.c { text-align: center; }
.tabela th.r, .tabela td.r { text-align: right;  }
.tabela td {
  padding: 10px 13px;
  border-bottom: 1px solid #edf0f4;
  vertical-align: middle;
}
.tabela tbody tr:last-child td { border-bottom: none; }
.tabela tbody tr:hover:not(.linha-detalhe):not(.linha-total) {
  background: #f7f8fc;
}
.tabela tr.linha-total {
  background: #edf0fa;
  font-weight: 800;
}
.tabela tr.linha-total td { border-top: 2px solid var(--primaria); }
.tabela tr.linha-pendente td { background: #fffde7; }
.tabela tr.linha-critica  td { background: #fdecea; }

/* ── DETALHE EXPANSÍVEL ────────────────────────────────────── */
.linha-detalhe td { padding: 0; }
.detalhe-inner {
  padding: 18px 22px 20px;
  background: #f7f8fc;
  border-top: 3px solid var(--primaria);
  border-bottom: 1px solid #dee2e6;
}
.detalhe-secao {
  font-size: .78rem;
  font-weight: 800;
  color: var(--primaria);
  text-transform: uppercase;
  letter-spacing: .07em;
  margin-bottom: 10px;
  margin-top: 18px;
  display: flex;
  align-items: center;
  gap: 7px;
}
.detalhe-secao:first-child { margin-top: 0; }
.tabela-sub th { background: #3c3c9e; font-size: .73rem; }
.tabela-sub td { background: #fff; font-size: .84rem; }
.tabela-sub tr.linha-total td { background: #edf0fa; }
.tabela-extrato th { background: #4a4a6e; font-size: .73rem; }
.tabela-extrato td { background: #fff; font-size: .83rem; }

/* ── BADGES ────────────────────────────────────────────────── */
.badge {
  display: inline-block;
  padding: 3px 9px;
  border-radius: 20px;
  font-size: .73rem;
  font-weight: 700;
  white-space: nowrap;
  line-height: 1.4;
}
.badge-verde    { background: #d4edda; color: #155724; }
.badge-amarelo  { background: #fff3cd; color: #856404; }
.badge-vermelho { background: #f8d7da; color: #721c24; }
.badge-azul     { background: #d1ecf1; color: #0c5460; }
.badge-sem-mov  { background: #e9ecef; color: #6c757d; }
.badge-tipo-saida   { background: var(--primaria); color: #fff; }
.badge-tipo-retorno { background: var(--acento);   color: #fff; }
.badge-tipo-venda   { background: var(--verde);    color: #fff; }

/* ── CÓDIGO ────────────────────────────────────────────────── */
code.cod {
  background: #e9ecef;
  color: #495057;
  padding: 2px 6px;
  border-radius: 4px;
  font-size: .78rem;
  font-family: 'Consolas', 'Courier New', monospace;
}

/* ── LEGENDA ───────────────────────────────────────────────── */
.legenda {
  display: flex;
  gap: 16px;
  flex-wrap: wrap;
  padding: 12px 20px;
  border-top: 1px solid #eee;
  font-size: .78rem;
  color: #666;
}
.legenda-item { display: flex; align-items: center; gap: 6px; }
.leg-cor { width: 13px; height: 13px; border-radius: 3px; flex-shrink: 0; }

/* ── RODAPÉ ────────────────────────────────────────────────── */
.rodape {
  text-align: center;
  color: #aaa;
  font-size: .76rem;
  padding: 20px 0 30px;
}

/* ── PRINT ─────────────────────────────────────────────────── */
@media print {
  .navbar,
  .page-top,
  .card-filtros,
  .dd-wrap,
  .btn-detalhe,
  .rodape { display: none !important; }

  body { background: #fff; }
  .conteudo { padding: 10px; max-width: 100%; }

  /* Mostrar TODOS os detalhes no print */
  .linha-detalhe { display: table-row !important; }
  .detalhe-inner { border: 1px solid #ccc; }

  .card { box-shadow: none; border: 1px solid #ddd; page-break-inside: avoid; margin-bottom: 12px; }
  .card-stat { box-shadow: none; border: 1px solid #ddd; }
  .grid-stats { grid-template-columns: repeat(4, 1fr); }

  .tabela th { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
  .tabela tr.linha-pendente td { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
  .tabela tr.linha-critica  td { -webkit-print-color-adjust: exact; print-color-adjust: exact; }

  .print-header { display: block !important; }
}
.print-header { display: none; }
</style>
</head>
<body>

<!-- ════════════════════════════════════════════════════════
     NAVBAR
     ════════════════════════════════════════════════════════ -->
<div class="navbar">
  <a class="navbar-brand" href="dashboard.php">📦 Controle de Carga</a>
  <nav class="navbar-nav">
    <a href="dashboard.php">🏠 Início</a>
    <a href="saida.php">📤 Saída</a>
    <a href="retorno.php">📥 Retorno</a>
    <a href="confirmar_venda.php">✅ Confirmar Venda</a>
    <a href="relatorios.php" class="ativo">📊 Relatórios</a>
    <a href="cadastros.php">📋 Cadastros</a>
  </nav>
  <div class="navbar-right">
    <span><?= esc($usuario) ?></span>
    <span class="badge-perfil"><?= esc($perfil) ?></span>
    <a class="btn-sair" href="../logout.php">Sair</a>
  </div>
</div>

<!-- ════════════════════════════════════════════════════════
     CABEÇALHO
     ════════════════════════════════════════════════════════ -->
<div class="page-top">
  <h1>📊 Relatório de Movimentação</h1>
  <p>Saídas · Retornos · Vendas · Saldos · Pendências — por vendedor e produto</p>
</div>

<!-- Cabeçalho apenas para impressão -->
<div class="print-header" style="padding:12px;border-bottom:2px solid #333;margin-bottom:12px">
  <strong style="font-size:1.1rem">📊 Controle de Carga — Relatório de Movimentação</strong><br>
  <small>Período: <?= esc($periodo_label) ?> &nbsp;|&nbsp; Gerado em: <?= date('d/m/Y H:i') ?> &nbsp;|&nbsp; Por: <?= esc($usuario) ?></small>
</div>

<div class="conteudo">

<!-- ════════════════════════════════════════════════════════
     FILTROS
     ════════════════════════════════════════════════════════ -->
<div class="card card-filtros">
  <div class="card-titulo">🔍 Filtros</div>
  <form method="GET" action="relatorios.php">
    <div class="linha-filtros">

      <div class="campo-grupo curto">
        <label class="campo-label" for="data_ini">Data início</label>
        <input type="date" id="data_ini" name="data_ini" class="campo"
               value="<?= esc($data_ini) ?>">
      </div>

      <div class="campo-grupo curto">
        <label class="campo-label" for="data_fim">Data fim</label>
        <input type="date" id="data_fim" name="data_fim" class="campo"
               value="<?= esc($data_fim) ?>">
      </div>

      <div class="campo-grupo largo">
        <label class="campo-label" for="vendedor_id">Vendedor</label>
        <select id="vendedor_id" name="vendedor_id" class="campo">
          <option value="">— Todos os vendedores —</option>
          <?php foreach ($vendedores as $v): ?>
          <option value="<?= (int)$v['id'] ?>"
            <?= ($vid_filtro == $v['id']) ? 'selected' : '' ?>>
            <?= esc($v['nome']) ?>
          </option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="campo-grupo" style="justify-content:flex-end">
        <label class="campo-label">&nbsp;</label>
        <label class="check-label">
          <input type="checkbox" name="apenas_pendencias" value="1"
                 <?= $so_pend ? 'checked' : '' ?>>
          Mostrar apenas pendências
        </label>
      </div>

      <div class="campo-grupo acao" style="justify-content:flex-end">
        <label class="campo-label">&nbsp;</label>
        <div style="display:flex;gap:8px;align-items:center">
          <button type="submit" class="btn btn-primario btn-grande">🔍 Filtrar</button>
          <!-- Dropdown exportar -->
          <div class="dd-wrap" id="ddExport">
            <button type="button" class="btn btn-secundario btn-grande"
                    onclick="toggleDD(event)">📥 Exportar ▾</button>
            <div class="dd-menu">
              <a href="../api/exportar_csv.php?<?= $csv_qs ?>" target="_blank">
                📊 Baixar CSV / Excel
              </a>
              <a href="#" onclick="window.print();return false;">
                🖨️ Imprimir / Salvar PDF
              </a>
            </div>
          </div>
        </div>
      </div>

    </div><!-- /linha-filtros -->
  </form>
</div>

<!-- ════════════════════════════════════════════════════════
     ALERTAS
     ════════════════════════════════════════════════════════ -->
<?php if ($g_pend > 0): ?>
<div class="alerta alerta-aviso">
  <span class="alerta-icone">⚠️</span>
  <div>
    <strong><?= $g_pend ?> vendedor(es) com saldo em aberto</strong> no período
    <strong><?= esc($periodo_label) ?></strong>.
    <?php
      $nomes_pend = array_map(fn($v) => $v['nome'],
                   array_filter($dados, fn($v) => $v['tem_pendencia']));
      echo 'Pendentes: ' . implode(', ', array_map('esc', $nomes_pend)) . '.';
    ?>
    <?php if ($g_qrpend > 0): ?>
    <br>⏳ Há também <strong><?= $g_qrpend ?> QR Code(s) não confirmados</strong> —
    o saldo pode mudar após confirmação.
    <?php endif; ?>
  </div>
</div>
<?php elseif ($g_qrpend > 0): ?>
<div class="alerta alerta-aviso">
  <span class="alerta-icone">⏳</span>
  <div>
    <strong><?= $g_qrpend ?> QR Code(s) ainda aguardam confirmação</strong> pelo vendedor.
    Os saldos podem se alterar. Aguarde antes de exportar o relatório final.
  </div>
</div>
<?php elseif ($g_saida > 0): ?>
<div class="alerta alerta-sucesso">
  <span class="alerta-icone">✅</span>
  <div>
    <strong>Tudo certo!</strong> Todos os vendedores com movimento estão com saldo zerado
    no período <strong><?= esc($periodo_label) ?></strong>.
  </div>
</div>
<?php endif; ?>

<!-- ════════════════════════════════════════════════════════
     STAT CARDS
     ════════════════════════════════════════════════════════ -->
<div class="grid-stats">
  <div class="card-stat azul">
    <div class="stat-num azul"><?= $g_saida ?></div>
    <div class="stat-label">📤 Total Saídas</div>
  </div>
  <div class="card-stat azul">
    <div class="stat-num azul"><?= $g_retorno ?></div>
    <div class="stat-label">📥 Total Retornos</div>
  </div>
  <div class="card-stat verde">
    <div class="stat-num verde"><?= $g_vendido ?></div>
    <div class="stat-label">✅ Total Vendidos</div>
  </div>
  <div class="card-stat <?= $g_saldo === 0 ? 'verde' : ($g_saldo > 0 ? 'amarelo' : 'vermelho') ?>">
    <div class="stat-num <?= $g_saldo === 0 ? 'verde' : ($g_saldo > 0 ? 'amarelo' : 'vermelho') ?>">
      <?= $g_saldo ?>
    </div>
    <div class="stat-label">⚖️ Saldo Geral</div>
  </div>
</div>

<!-- ════════════════════════════════════════════════════════
     TABELA PRINCIPAL
     ════════════════════════════════════════════════════════ -->
<div class="card" style="padding:0;overflow:hidden">

  <!-- Cabeçalho do card -->
  <div style="padding:15px 20px;display:flex;align-items:center;
              justify-content:space-between;border-bottom:1px solid #eee">
    <div class="card-titulo" style="margin-bottom:0">
      📋 Movimentação por Vendedor — <?= esc($periodo_label) ?>
    </div>
    <div style="font-size:.8rem;color:#888">
      <?= count($dados) ?> vendedor(es)
      <?php if ($g_pend > 0): ?>
      &nbsp;·&nbsp; <span style="color:var(--vermelho);font-weight:700"><?= $g_pend ?> com pendência</span>
      <?php endif; ?>
    </div>
  </div>

  <table class="tabela" id="tabelaPrincipal">
    <thead>
      <tr>
        <th style="width:28%">Vendedor</th>
        <th class="c">Saídas</th>
        <th class="c">Retornos</th>
        <th class="c">Vendidos</th>
        <th class="c">Saldo</th>
        <th class="c">QR Pend.</th>
        <th class="c">Status</th>
        <th class="c" style="width:110px">Detalhes</th>
      </tr>
    </thead>
    <tbody>

    <?php if (empty($dados)): ?>
    <tr>
      <td colspan="8" style="text-align:center;color:#999;padding:36px;font-style:italic">
        Nenhum registro encontrado para o período selecionado.
      </td>
    </tr>
    <?php else: ?>

    <?php foreach ($dados as $vid => $vend):
      // Classe de linha
      $cls = '';
      if ($vend['tem_pendencia'] && $vend['saldo'] < 0) $cls = 'linha-critica';
      elseif ($vend['tem_pendencia']) $cls = 'linha-pendente';
    ?>

    <!-- ── Linha de resumo ── -->
    <tr class="<?= $cls ?>" id="row-<?= (int)$vid ?>">
      <td>
        <strong><?= esc($vend['nome']) ?></strong>
        <?php if ($vend['saldo'] < 0): ?>
          <span style="color:var(--vermelho);font-size:.72rem;display:block;margin-top:2px">
            ⚠ Saldo negativo — verificar
          </span>
        <?php endif; ?>
      </td>
      <td class="c">
        <?php if ($vend['tot_saida'] > 0): ?>
          <strong style="color:var(--primaria)"><?= $vend['tot_saida'] ?></strong>
        <?php else: ?><span style="color:#ccc">—</span><?php endif; ?>
      </td>
      <td class="c">
        <?php if ($vend['tot_retorno'] > 0): ?>
          <?= $vend['tot_retorno'] ?>
        <?php else: ?><span style="color:#ccc">—</span><?php endif; ?>
      </td>
      <td class="c">
        <?php if ($vend['tot_vendido'] > 0): ?>
          <strong style="color:var(--verde)"><?= $vend['tot_vendido'] ?></strong>
        <?php else: ?><span style="color:#ccc">—</span><?php endif; ?>
      </td>
      <td class="c">
        <?php if ($vend['tem_movimento']): ?>
          <strong style="font-size:1rem;color:<?= cor_saldo($vend['saldo']) ?>">
            <?= $vend['saldo'] ?>
          </strong>
        <?php else: ?>
          <span style="color:#ccc">—</span>
        <?php endif; ?>
      </td>
      <td class="c">
        <?php if ($vend['qr_pendentes'] > 0): ?>
          <span class="badge badge-amarelo">⏳ <?= $vend['qr_pendentes'] ?></span>
        <?php else: ?>
          <span style="color:#ccc">—</span>
        <?php endif; ?>
      </td>
      <td class="c"><?= badgeStatus($vend['saldo'], $vend['tem_movimento']) ?></td>
      <td class="c">
        <?php if ($vend['tem_movimento']): ?>
        <button class="btn btn-acento btn-pequeno btn-detalhe"
                id="btnD-<?= (int)$vid ?>"
                onclick="toggleDetalhe(<?= (int)$vid ?>)">▼ Detalhar</button>
        <?php else: ?>
        <span style="color:#ddd;font-size:.8rem">—</span>
        <?php endif; ?>
      </td>
    </tr>

    <!-- ── Linha de detalhe (colapsada) ── -->
    <?php if ($vend['tem_movimento']): ?>
    <tr class="linha-detalhe" id="detalhe-<?= (int)$vid ?>" style="display:none">
      <td colspan="8">
        <div class="detalhe-inner">

          <!-- Sub-tabela: saldo por produto -->
          <div class="detalhe-secao">📦 Saldo por produto — <?= esc($vend['nome']) ?></div>
          <table class="tabela tabela-sub">
            <thead>
              <tr>
                <th>Código</th>
                <th>Produto</th>
                <th class="c">Saída</th>
                <th class="c">Retorno</th>
                <th class="c">Vendido</th>
                <th class="c">Saldo</th>
                <th class="c">Status</th>
              </tr>
            </thead>
            <tbody>
            <?php
              $sub_s = $sub_r = $sub_v = $sub_sal = 0;
              foreach ($vend['produtos'] as $cod => $prod):
                $sub_s   += $prod['saida'];
                $sub_r   += $prod['retorno'];
                $sub_v   += $prod['vendido'];
                $sub_sal += $prod['saldo'];
                $cls_p    = '';
                if ($prod['saldo'] < 0) $cls_p = 'linha-critica';
                elseif ($prod['saldo'] > 0) $cls_p = 'linha-pendente';
            ?>
            <tr class="<?= $cls_p ?>">
              <td><code class="cod"><?= esc($cod) ?></code></td>
              <td><?= esc($prod['produto']) ?></td>
              <td class="c"><?= $prod['saida'] ?></td>
              <td class="c"><?= $prod['retorno'] ?></td>
              <td class="c"><?= $prod['vendido'] ?></td>
              <td class="c">
                <strong style="color:<?= cor_saldo($prod['saldo']) ?>">
                  <?= $prod['saldo'] ?>
                </strong>
              </td>
              <td class="c"><?= badgeProd($prod['saldo']) ?></td>
            </tr>
            <?php endforeach; ?>
            <!-- Subtotal -->
            <tr class="linha-total">
              <td colspan="2">SUBTOTAL</td>
              <td class="c"><?= $sub_s ?></td>
              <td class="c"><?= $sub_r ?></td>
              <td class="c"><?= $sub_v ?></td>
              <td class="c" style="color:<?= cor_saldo($sub_sal) ?>">
                <strong><?= $sub_sal ?></strong>
              </td>
              <td></td>
            </tr>
            </tbody>
          </table>

          <!-- Extrato cronológico -->
          <?php if (!empty($vend['registros'])): ?>
          <div class="detalhe-secao" style="margin-top:20px">
            🕐 Extrato cronológico — <?= count($vend['registros']) ?> registro(s)
          </div>
          <table class="tabela tabela-extrato">
            <thead>
              <tr>
                <th>Data</th>
                <th>Hora</th>
                <th>Tipo</th>
                <th>Código</th>
                <th>Produto</th>
                <th class="c">Qtd</th>
                <th>Pedido</th>
                <th>Observação</th>
              </tr>
            </thead>
            <tbody>
            <?php foreach ($vend['registros'] as $reg): ?>
            <tr>
              <td style="white-space:nowrap"><?= formatarData($reg['data']) ?></td>
              <td style="white-space:nowrap"><?= esc(substr($reg['hora'], 0, 5)) ?></td>
              <td><?= badgeTipo($reg['tipo']) ?></td>
              <td><code class="cod"><?= esc($reg['codigo']) ?></code></td>
              <td><?= esc($reg['produto']) ?></td>
              <td class="c"><strong><?= (int)$reg['quantidade'] ?></strong></td>
              <td>
                <?php if ($reg['pedido']): ?>
                  <span class="badge badge-azul"><?= esc($reg['pedido']) ?></span>
                <?php else: ?>
                  <span style="color:#ccc">—</span>
                <?php endif; ?>
              </td>
              <td style="color:#666;font-size:.82rem">
                <?= $reg['obs'] ? esc($reg['obs']) : '<span style="color:#ccc">—</span>' ?>
              </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
          <?php endif; ?>

        </div><!-- /detalhe-inner -->
      </td>
    </tr>
    <?php endif; // tem_movimento ?>

    <?php endforeach; ?>

    <!-- Linha de total geral -->
    <?php if (count($dados) > 1 && $g_saida > 0): ?>
    <tr class="linha-total">
      <td>TOTAL GERAL</td>
      <td class="c"><?= $g_saida ?></td>
      <td class="c"><?= $g_retorno ?></td>
      <td class="c"><?= $g_vendido ?></td>
      <td class="c" style="color:<?= cor_saldo($g_saldo) ?>">
        <strong style="font-size:1rem"><?= $g_saldo ?></strong>
      </td>
      <td class="c">
        <?= $g_qrpend > 0
            ? '<span class="badge badge-amarelo">'.$g_qrpend.'</span>'
            : '<span style="color:#ccc">—</span>' ?>
      </td>
      <td colspan="2"></td>
    </tr>
    <?php endif; ?>

    <?php endif; // empty($dados) ?>
    </tbody>
  </table>

  <!-- Legenda -->
  <div class="legenda">
    <strong style="color:#555;font-size:.78rem">Legenda:</strong>
    <span class="legenda-item">
      <span class="leg-cor" style="background:#d4edda"></span> Zerado
    </span>
    <span class="legenda-item">
      <span class="leg-cor" style="background:#fff3cd"></span> Em aberto (saldo &gt; 0)
    </span>
    <span class="legenda-item">
      <span class="leg-cor" style="background:#f8d7da"></span> Verificar (saldo negativo)
    </span>
    <span class="legenda-item">
      <span class="leg-cor" style="background:#e9ecef;border:1px solid #ccc"></span> Sem movimento
    </span>
  </div>

</div><!-- /card tabela principal -->

<!-- Rodapé -->
<div class="rodape">
  Controle de Carga v1.0 &nbsp;|&nbsp;
  Relatório gerado em <?= date('d/m/Y \à\s H:i') ?> &nbsp;|&nbsp;
  Período: <?= esc($periodo_label) ?> &nbsp;|&nbsp;
  Usuário: <?= esc($usuario) ?>
</div>

</div><!-- /conteudo -->

<!-- ════════════════════════════════════════════════════════
     JAVASCRIPT
     ════════════════════════════════════════════════════════ -->
<script>
// ── Expandir / recolher detalhe ────────────────────────────
function toggleDetalhe(vid) {
    var row = document.getElementById('detalhe-' + vid);
    var btn = document.getElementById('btnD-' + vid);
    if (!row || !btn) return;
    var aberto = row.style.display !== 'none' && row.style.display !== '';
    row.style.display = aberto ? 'none' : 'table-row';
    if (aberto) {
        btn.textContent = '▼ Detalhar';
        btn.className   = 'btn btn-acento btn-pequeno btn-detalhe';
    } else {
        btn.textContent = '▲ Fechar';
        btn.className   = 'btn btn-primario btn-pequeno btn-detalhe';
        // Scroll suave até o detalhe
        row.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    }
}

// ── Dropdown Exportar ──────────────────────────────────────
function toggleDD(e) {
    e.stopPropagation();
    document.getElementById('ddExport').classList.toggle('aberto');
}
document.addEventListener('click', function() {
    var dd = document.getElementById('ddExport');
    if (dd) dd.classList.remove('aberto');
});

// ── Auto-expand pendências graves (saldo negativo) ─────────
document.addEventListener('DOMContentLoaded', function() {
    <?php foreach ($dados as $vid => $vend): ?>
    <?php if ($vend['tem_pendencia'] && $vend['saldo'] < 0): ?>
    // Auto-expande vendedor com saldo negativo: <?= esc($vend['nome']) ?>
    toggleDetalhe(<?= (int)$vid ?>);
    <?php endif; ?>
    <?php endforeach; ?>
});

// ── Validação de datas no formulário ──────────────────────
document.querySelector('form').addEventListener('submit', function(e) {
    var ini = document.getElementById('data_ini').value;
    var fim = document.getElementById('data_fim').value;
    if (ini && fim && fim < ini) {
        alert('⚠ A data fim não pode ser anterior à data início.');
        e.preventDefault();
    }
});

// ── Atalho de teclado: P = imprimir ───────────────────────
document.addEventListener('keydown', function(e) {
    if (e.key === 'p' && e.ctrlKey) return; // deixa o Ctrl+P nativo funcionar
});
</script>

</body>
</html>