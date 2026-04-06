<?php
/**
 * licitacoes.php — Monitor de Licitações com abas DIÁRIO / PNCP / PdCP
 * Estilo refinado baseado em licitacoes_old.php
 */

define('TITULO_PAGINA', 'Monitor de Licitações — Feira de Santana/BA');
define('ITEMS_POR_PAG', 30);
define('REFRESH_AUTO',  false);

$ARQUIVOS_FONTE = [
    'DIARIO' => __DIR__ . '/resultados_diario.json',
    'PNCP'   => __DIR__ . '/resultados_pncp.json',
    'PDCP'   => __DIR__ . '/resultados_pdcp.json',
];
$LABELS_FONTE = [
    'DIARIO' => '📰 Diário Oficial',
    'PNCP'   => '🏛️ PNCP',
    'PDCP'   => '🛒 Portal de Compras',
];

$aba_ativa   = strtoupper(trim($_GET['aba'] ?? 'DIARIO'));
if (!array_key_exists($aba_ativa, $ARQUIVOS_FONTE)) $aba_ativa = 'DIARIO';

$fbusca      = trim($_GET['busca']       ?? '');
$fmodalidade = trim($_GET['modalidade']  ?? '');
$fapenas_new = isset($_GET['novas'])  && $_GET['novas']  == '1';
$fseduc      = isset($_GET['seduc'])  && $_GET['seduc']  == '1';
$fordem      = trim($_GET['ordem']       ?? 'datadesc');
$pagina      = max(1, (int)($_GET['pagina'] ?? 1));

// Lê JSON da aba ativa
$erro_json = null;
$dados     = ['gerado_em' => null, 'total' => 0, 'novas' => 0, 'dispensas' => []];
$arquivo   = $ARQUIVOS_FONTE[$aba_ativa];

if (!file_exists($arquivo)) {
    $erro_json = "O arquivo <code>" . basename($arquivo) . "</code> ainda não existe. Execute o script Python.";
} else {
    $parsed = json_decode(file_get_contents($arquivo), true);
    if ($parsed === null) {
        $erro_json = "Erro ao ler o JSON. Verifique se o script gerou o arquivo corretamente.";
    } else {
        $dados = $parsed;
    }
}

$dispensas = $dados['dispensas'] ?? [];

// Contadores por aba (badges)
$contadores = [];
foreach ($ARQUIVOS_FONTE as $chave => $arq) {
    if (file_exists($arq)) {
        $p = json_decode(file_get_contents($arq), true);
        $contadores[$chave] = ['total' => $p['total'] ?? 0, 'novas' => $p['novas'] ?? 0];
    } else {
        $contadores[$chave] = ['total' => 0, 'novas' => 0];
    }
}

// Helpers
function esc(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}
function is_seduc(string $texto): bool {
    return (bool)preg_match('/SEDUC|SECRETARIA\s+MUNICIPAL\s+DE\s+EDUCA[CÇ][AÃ]O/i', $texto);
}
function badge_modalidade(string $mod): string {
    $map = [
        'Pregão'        => 'badge-pregao',
        'Dispensa'      => 'badge-dispensa',
        'Concorrência'  => 'badge-concorrencia',
        'Chamamento'    => 'badge-chamamento',
        'Inexigibilidade' => 'badge-inexig',
    ];
    foreach ($map as $k => $cls) {
        if (stripos($mod, $k) !== false) return $cls;
    }
    return 'badge-outro';
}
function formatar_valor(string $v): string {
    if (!$v || $v === 'NA') return '<span class="valor-nd">—</span>';
    return '<span class="valor-destaque">' . esc($v) . '</span>';
}
function icone_fonte(string $f): string {
    if (stripos($f, 'PNCP') !== false)   return '<span class="fonte-badge pncp">PNCP</span>';
    if (stripos($f, 'Portal') !== false) return '<span class="fonte-badge portal">Portal</span>';
    if (stripos($f, 'Diário') !== false || stripos($f, 'Diario') !== false) return '<span class="fonte-badge diario">Diário</span>';
    return '<span class="fonte-badge outro">' . esc($f) . '</span>';
}
function url_aba(string $aba, array $extra = []): string {
    $base = array_filter([
        'aba'        => $aba,
        'busca'      => $_GET['busca']      ?? '',
        'modalidade' => $_GET['modalidade'] ?? '',
        'novas'      => $_GET['novas']      ?? '',
        'seduc'      => $_GET['seduc']      ?? '',
        'ordem'      => $_GET['ordem']      ?? '',
        'pagina'     => '1',
    ]);
    foreach ($extra as $k => $v) {
        if ($v === null) unset($base[$k]); else $base[$k] = $v;
    }
    return '?' . http_build_query($base);
}
function url_filtro(array $params): string {
    global $aba_ativa;
    $base = array_filter([
        'aba'        => $aba_ativa,
        'busca'      => $_GET['busca']      ?? '',
        'modalidade' => $_GET['modalidade'] ?? '',
        'novas'      => $_GET['novas']      ?? '',
        'seduc'      => $_GET['seduc']      ?? '',
        'ordem'      => $_GET['ordem']      ?? '',
        'pagina'     => '1',
    ]);
    foreach ($params as $k => $v) {
        if ($v === null || $v === '') unset($base[$k]); else $base[$k] = $v;
    }
    return '?' . http_build_query($base);
}

function pag_link(int $p, array $base): string {
    $base['pagina'] = $p;
    return '?' . http_build_query(array_filter($base));
}

// Listas para selects
$todas_modalidades = array_unique(array_filter(
    array_column($dispensas, 'modalidade'),
    fn($v) => $v && $v !== 'NA'
));
sort($todas_modalidades);

// Filtros
$filtradas = array_values(array_filter($dispensas,
    function ($d) use ($fbusca, $fmodalidade, $fapenas_new, $fseduc) {
        if ($fapenas_new && empty($d['nova'])) return false;
        if ($fmodalidade && ($d['modalidade'] ?? '') !== $fmodalidade) return false;
        if ($fseduc) {
            if (!is_seduc($d['unidadecompradora'] ?? '')
             && !is_seduc($d['orgao'] ?? '')
             && !is_seduc($d['objeto'] ?? '')) return false;
        }
        if ($fbusca) {
            $b  = mb_strtolower($fbusca, 'UTF-8');
            $hs = mb_strtolower(
                ($d['objeto'] ?? '') . ' ' . ($d['orgao'] ?? '') . ' ' .
                ($d['numero'] ?? '') . ' ' . ($d['unidadecompradora'] ?? ''),
                'UTF-8'
            );
            if (mb_strpos($hs, $b) === false) return false;
        }
        return true;
    }
));

// Ordenação
usort($filtradas, function ($a, $b) use ($fordem) {
    $da = $a['dataiso'] ?? '';
    $db = $b['dataiso'] ?? '';
    if ($da === $db) return 0;
    if ($da === '') return 1;
    if ($db === '') return -1;
    return $fordem === 'dataasc' ? strcmp($da, $db) : strcmp($db, $da);
});

// Paginação
$total_filtrado = count($filtradas);
$total_paginas  = max(1, (int)ceil($total_filtrado / ITEMS_POR_PAG));
$pagina         = min($pagina, $total_paginas);
$offset         = ($pagina - 1) * ITEMS_POR_PAG;
$pagina_items   = array_slice($filtradas, $offset, ITEMS_POR_PAG);

$count_seduc = count(array_filter($dispensas, function ($d) {
    return is_seduc($d['unidadecompradora'] ?? '')
        || is_seduc($d['orgao'] ?? '')
        || is_seduc($d['objeto'] ?? '');
}));
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= esc(TITULO_PAGINA) ?></title>
<?php if (REFRESH_AUTO): ?>
<meta http-equiv="refresh" content="300">
<?php endif; ?>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans:wght@400;500;600&family=IBM+Plex+Mono:wght@400;500&family=IBM+Plex+Sans+Condensed:wght@600;700&display=swap" rel="stylesheet">
<style>
/* ─── Reset & Base ───────────────────────────────────────────── */
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
html { font-size: 15px; scroll-behavior: smooth; }
body {
    font-family: 'IBM Plex Sans', sans-serif;
    background: #f0ede8;
    color: #1a1916;
    min-height: 100vh;
    line-height: 1.55;
}
a { color: inherit; text-decoration: none; }

/* ─── Abas ───────────────────────────────────────────────────── */
.abas-wrap {
    background: #fff;
    border-bottom: 2px solid #ddd9d0;
    position: sticky;
    top: 0;
    z-index: 100;
}
.abas-inner {
    max-width: 1380px;
    margin: 0 auto;
    padding: 0 24px;
    display: flex;
    gap: 0;
    align-items: flex-end;
}
.aba-btn {
    display: flex;
    align-items: center;
    gap: 6px;
    padding: 14px 24px 12px;
    font-family: 'IBM Plex Sans', sans-serif;
    font-size: 0.9rem;
    font-weight: 500;
    border: none;
    border-bottom: 3px solid transparent;
    background: transparent;
    color: #8a837a;
    cursor: pointer;
    text-decoration: none;
    white-space: nowrap;
    transition: color .15s, border-color .15s;
    margin-bottom: -2px;
}
.aba-btn:hover { color: #1a1916; }
.aba-btn.ativa {
    color: #1a1916;
    border-bottom-color: #a88132;
    font-weight: 600;
}
.aba-badge {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 22px;
    height: 20px;
    padding: 0 6px;
    border-radius: 12px;
    font-size: 0.72rem;
    font-weight: 700;
    font-family: 'IBM Plex Mono', monospace;
    background: #f0ede8;
    color: #5a5650;
}
.aba-btn.ativa .aba-badge {
    background: #e8c46a;
    color: #6a4000;
}
.aba-badge.nova-badge {
    background: #e8a020;
    color: #fff;
    animation: pisca 1.4s ease-in-out infinite;
}
@keyframes pisca { 0%,100% { opacity: .9 } 50% { opacity: .3 } }

/* ─── Stats inline na barra de filtros ───────────────────────── */
.stat-pill {
    display: flex;
    align-items: center;
    gap: 5px;
    background: #f0ede8;
    border: 1px solid #ddd9d0;
    border-radius: 20px;
    padding: 4px 12px;
    font-size: 0.82rem;
    white-space: nowrap;
}
.stat-pill .n {
    font-family: 'IBM Plex Mono', monospace;
    font-weight: 600;
    font-size: 0.9rem;
    color: #1a1916;
}
.stat-pill .lbl { color: #8a837a; }
.stat-pill.pill-novas { border-color: #e8c46a; background: #fff8e8; }
.stat-pill.pill-novas .n { color: #9a6000; }
.stat-atualizado {
    font-family: 'IBM Plex Mono', monospace;
    font-size: 0.72rem;
    color: #aaa59a;
    white-space: nowrap;
}

/* ─── Alerta de erro ─────────────────────────────────────────── */
.alerta-erro {
    max-width: 1380px;
    margin: 28px auto;
    padding: 0 24px;
}
.alerta-erro .box {
    background: #fff3f3;
    border: 1px solid #f5b8b8;
    border-left: 4px solid #d94040;
    border-radius: 8px;
    padding: 18px 22px;
    font-size: 0.9rem;
    color: #6b1a1a;
}
.alerta-erro code {
    background: #ffe8e8;
    padding: 1px 5px;
    border-radius: 3px;
    font-family: 'IBM Plex Mono', monospace;
}

/* ─── Filtros ────────────────────────────────────────────────── */
.filtros-wrap {
    background: #fff;
    border-bottom: 1px solid #ddd9d0;
    position: sticky;
    top: 45px;
    z-index: 90;
}
.filtros-inner {
    max-width: 1380px;
    margin: 0 auto;
    padding: 12px 24px;
    display: flex;
    gap: 10px;
    align-items: center;
    flex-wrap: wrap;
}
.filtros-inner form { display: contents; }

.campo-busca {
    flex: 1;
    min-width: 200px;
    position: relative;
}
.campo-busca svg {
    position: absolute;
    left: 11px;
    top: 50%;
    transform: translateY(-50%);
    opacity: 0.4;
}
.campo-busca input {
    width: 100%;
    padding: 9px 12px 9px 36px;
    border: 1px solid #ccc9c0;
    border-radius: 7px;
    font-family: 'IBM Plex Sans', sans-serif;
    font-size: 0.875rem;
    background: #faf9f6;
    color: #1a1916;
    transition: border-color .15s, box-shadow .15s;
}
.campo-busca input:focus {
    outline: none;
    border-color: #a88132;
    box-shadow: 0 0 0 3px rgba(168,129,50,0.15);
}
.campo-busca input::placeholder { color: #aaa59a; }

select.filtro-sel {
    padding: 9px 32px 9px 12px;
    border: 1px solid #ccc9c0;
    border-radius: 7px;
    font-family: 'IBM Plex Sans', sans-serif;
    font-size: 0.875rem;
    background: #faf9f6;
    color: #1a1916;
    appearance: none;
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='8' viewBox='0 0 12 8'%3E%3Cpath d='M1 1l5 5 5-5' stroke='%23888' stroke-width='1.5' fill='none' stroke-linecap='round'/%3E%3C/svg%3E");
    background-repeat: no-repeat;
    background-position: right 10px center;
    cursor: pointer;
    transition: border-color .15s;
}
select.filtro-sel:focus { outline: none; border-color: #a88132; }

.btn-filtro-novas, .btn-filtro-seduc {
    display: flex;
    align-items: center;
    gap: 6px;
    padding: 9px 16px;
    border: 1px solid #ccc9c0;
    border-radius: 7px;
    font-family: 'IBM Plex Sans', sans-serif;
    font-size: 0.875rem;
    background: #faf9f6;
    color: #1a1916;
    cursor: pointer;
    white-space: nowrap;
    transition: all .15s;
}
.btn-filtro-novas:hover, .btn-filtro-seduc:hover { border-color: #a88132; }
.btn-filtro-novas.ativo {
    background: #fff8e8;
    border-color: #e8a020;
    color: #8a5a00;
}
.btn-filtro-seduc.ativo {
    background: #f5f0e8;
    border-color: #a88132;
    color: #6a4f00;
    font-weight: 500;
}
.btn-filtro-novas .dot, .btn-filtro-seduc .dot {
    width: 8px; height: 8px;
    border-radius: 50%;
    background: #ccc;
}
.btn-filtro-novas.ativo .dot { background: #e8a020; }
.btn-filtro-seduc.ativo .dot { background: #a88132; }

.btn-buscar {
    padding: 9px 20px;
    background: #a88132;
    color: #f5f2ec;
    border: none;
    border-radius: 7px;
    font-family: 'IBM Plex Sans', sans-serif;
    font-size: 0.875rem;
    font-weight: 500;
    cursor: pointer;
    transition: background .15s;
    white-space: nowrap;
}
.btn-buscar:hover { background: #8a6a28; }

.btn-limpar {
    padding: 9px 14px;
    background: transparent;
    color: #888;
    border: 1px solid #ddd9d0;
    border-radius: 7px;
    font-family: 'IBM Plex Sans', sans-serif;
    font-size: 0.875rem;
    cursor: pointer;
    text-decoration: none;
    transition: all .15s;
}
.btn-limpar:hover { border-color: #aaa; color: #555; }

/* ─── Main content ───────────────────────────────────────────── */
.main {
    max-width: 1380px;
    margin: 0 auto;
    padding: 20px 24px 60px;
}

.resultado-info {
    display: flex;
    align-items: baseline;
    gap: 10px;
    margin-bottom: 16px;
    font-size: 0.85rem;
    color: #776e62;
}
.resultado-info strong { color: #1a1916; font-size: 0.95rem; }

/* ─── Grid de cards ──────────────────────────────────────────── */
.cards-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(380px, 1fr));
    gap: 14px;
}

/* ─── Card ───────────────────────────────────────────────────── */
.card {
    background: #fff;
    border: 1px solid #ddd9d0;
    border-radius: 10px;
    overflow: hidden;
    display: flex;
    flex-direction: column;
    transition: box-shadow .2s, transform .15s;
    position: relative;
}
.card:hover {
    box-shadow: 0 4px 20px rgba(0,0,0,0.09);
    transform: translateY(-1px);
}
.card.nova {
    border-color: #e8c46a;
    border-left: 4px solid #e8a020;
}

.card-header {
    padding: 13px 16px 10px;
    display: flex;
    align-items: flex-start;
    gap: 10px;
    border-bottom: 1px solid #f0ede8;
}
.card-header-left { flex: 1; min-width: 0; }

.badge-nova {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    background: #e8a020;
    color: #fff;
    font-family: 'IBM Plex Sans Condensed', sans-serif;
    font-size: 0.68rem;
    font-weight: 700;
    letter-spacing: 0.08em;
    text-transform: uppercase;
    padding: 2px 8px;
    border-radius: 4px;
    margin-bottom: 5px;
}
.badge-nova::before {
    content: '';
    width: 6px; height: 6px;
    border-radius: 50%;
    background: #fff;
    opacity: 0.85;
    animation: pisca 1.4s ease-in-out infinite;
}

.card-numero {
    font-family: 'IBM Plex Mono', monospace;
    font-size: 0.92rem;
    font-weight: 500;
    color: #1a1916;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.card-tags {
    display: flex;
    gap: 5px;
    flex-wrap: wrap;
    margin-top: 6px;
}
.badge {
    display: inline-block;
    font-size: 0.72rem;
    font-weight: 500;
    padding: 2px 9px;
    border-radius: 20px;
    white-space: nowrap;
}
.badge-pregao    { background: #e8f0ff; color: #1a45a8; }
.badge-dispensa  { background: #eef7ee; color: #1a6a1a; }
.badge-concorrencia { background: #f5eeff; color: #5a1aa8; }
.badge-chamamento   { background: #fff0e8; color: #a84a00; }
.badge-inexig       { background: #fff8e0; color: #7a5800; }
.badge-outro        { background: #f0ede8; color: #5a5650; }
.badge-data { background: #f0ede8; color: #5a5650; font-family: 'IBM Plex Mono', monospace; }

.fonte-badge {
    font-size: 0.68rem;
    font-weight: 500;
    padding: 2px 7px;
    border-radius: 4px;
    letter-spacing: 0.04em;
}
.fonte-badge.diario   { background: #f5f0e8; color: #6a4f00; }
.fonte-badge.pncp     { background: #e8f0ff; color: #1a45a8; }
.fonte-badge.portal   { background: #eef7ee; color: #1a6a1a; }
.fonte-badge.outro    { background: #f0ede8; color: #5a5650; }

/* ─── Card body ──────────────────────────────────────────────── */
.card-body {
    padding: 12px 16px;
    flex: 1;
    display: flex;
    flex-direction: column;
    gap: 9px;
}

.campo {
    display: grid;
    grid-template-columns: 130px 1fr;
    gap: 4px;
    font-size: 0.85rem;
    align-items: baseline;
}
.campo .label {
    color: #8a837a;
    font-size: 0.78rem;
    text-transform: uppercase;
    letter-spacing: 0.04em;
    font-weight: 500;
    padding-top: 1px;
}
.campo .valor { color: #1a1916; word-break: break-word; }

.valor-destaque {
    font-family: 'IBM Plex Mono', monospace;
    font-weight: 500;
    color: #c03020;
    font-size: 1rem;
}
.valor-nd { color: #bbb; font-style: italic; }

/* Objeto com expand ────────────────────────────── */
.objeto-wrap { display: flex; flex-direction: column; gap: 4px; }
.objeto-texto {
    color: #1a1916;
    font-size: 0.85rem;
    line-height: 1.5;
}
.objeto-texto.truncado {
    display: -webkit-box;
    -webkit-line-clamp: 3;
    -webkit-box-orient: vertical;
    overflow: hidden;
}
.btn-expand {
    font-size: 0.75rem;
    color: #4477cc;
    cursor: pointer;
    background: none;
    border: none;
    padding: 0;
    text-align: left;
    font-family: 'IBM Plex Sans', sans-serif;
}
.btn-expand:hover { text-decoration: underline; }

/* ─── Card footer (links) ────────────────────────────────────── */
.card-footer {
    padding: 10px 16px;
    border-top: 1px solid #f0ede8;
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
}
.link-btn {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    font-size: 0.8rem;
    font-weight: 500;
    padding: 5px 12px;
    border-radius: 6px;
    transition: all .15s;
    white-space: nowrap;
}
.link-btn-primario {
    background: #a88132;
    color: #f5f2ec;
}
.link-btn-primario:hover { background: #8a6a28; }
.link-btn-secundario {
    background: #eef2ff;
    color: #1a45a8;
    border: 1px solid #c8d4f0;
}
.link-btn-secundario:hover { background: #dde6ff; }
.link-btn-diario {
    background: #f5f0e8;
    color: #6a4f00;
    border: 1px solid #e0d4b8;
}
.link-btn-diario:hover { background: #ede0c4; }

/* ─── Estado vazio ───────────────────────────────────────────── */
.estado-vazio {
    grid-column: 1/-1;
    text-align: center;
    padding: 60px 20px;
    color: #8a837a;
}
.estado-vazio svg { opacity: 0.25; margin-bottom: 16px; }
.estado-vazio h3 { font-size: 1.1rem; color: #5a5650; margin-bottom: 8px; }
.estado-vazio p { font-size: 0.875rem; }

/* ─── Paginação ──────────────────────────────────────────────── */
.paginacao {
    display: flex;
    justify-content: center;
    gap: 6px;
    margin-top: 28px;
    flex-wrap: wrap;
}
.pag-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 36px;
    height: 36px;
    padding: 0 10px;
    border: 1px solid #ddd9d0;
    border-radius: 7px;
    font-size: 0.85rem;
    background: #fff;
    color: #1a1916;
    cursor: pointer;
    text-decoration: none;
    transition: all .15s;
}
.pag-btn:hover { border-color: #a88132; background: #f5f3ef; }
.pag-btn.ativa { background: #a88132; color: #f5f2ec; border-color: #a88132; cursor: default; }
.pag-btn.desabilitada { opacity: .35; pointer-events: none; }

/* ─── Responsivo ─────────────────────────────────────────────── */
@media (max-width: 700px) {
    .abas-inner { padding: 0 12px; }
    .aba-btn { padding: 10px 12px 8px; font-size: 0.8rem; }
    .filtros-inner { gap: 8px; padding: 10px 12px; }
    .campo-busca { min-width: 100%; }
    .cards-grid { grid-template-columns: 1fr; }
    .campo { grid-template-columns: 110px 1fr; }
    .main { padding: 12px 12px 40px; }
}
</style>
</head>
<body>

<!-- ═══ ERRO JSON ════════════════════════════════════════════════════════════ -->
<?php if ($erro_json): ?>
<div class="alerta-erro">
  <div class="box"><?= $erro_json ?></div>
</div>
<?php endif; ?>

<!-- ═══ ABAS ═══════════════════════════════════════════════════════════════ -->
<div class="abas-wrap">
  <div class="abas-inner">
    <?php foreach ($ARQUIVOS_FONTE as $chave => $arq): ?>
    <?php $cnt = $contadores[$chave]; ?>
    <a href="<?= url_aba($chave) ?>" class="aba-btn <?= $aba_ativa === $chave ? 'ativa' : '' ?>">
      <?= $LABELS_FONTE[$chave] ?>
      <?php if ($cnt['novas'] > 0): ?>
        <span class="aba-badge nova-badge"><?= $cnt['novas'] ?></span>
      <?php elseif ($cnt['total'] > 0): ?>
        <span class="aba-badge"><?= $cnt['total'] ?></span>
      <?php endif; ?>
    </a>
    <?php endforeach; ?>
  </div>
</div>

<!-- ═══ BARRA DE FILTROS ════════════════════════════════════════════════════ -->
<div class="filtros-wrap">
  <div class="filtros-inner">
    <form method="GET" action="">
      <input type="hidden" name="aba" value="<?= esc($aba_ativa) ?>">
      <div class="campo-busca">
        <svg width="16" height="16" viewBox="0 0 16 16" fill="none" xmlns="http://www.w3.org/2000/svg">
          <circle cx="7" cy="7" r="4.5" stroke="#333" stroke-width="1.5"/>
          <path d="M10.5 10.5L13.5 13.5" stroke="#333" stroke-width="1.5" stroke-linecap="round"/>
        </svg>
        <input
          type="text"
          name="busca"
          placeholder="Buscar por objeto, órgão ou número..."
          value="<?= esc($fbusca) ?>"
        >
      </div>

      <?php if (!empty($todas_modalidades)): ?>
      <select name="modalidade" class="filtro-sel">
        <option value="">Todas as modalidades</option>
        <?php foreach ($todas_modalidades as $mod): ?>
        <option value="<?= esc($mod) ?>"<?= $fmodalidade === $mod ? ' selected' : '' ?>>
          <?= esc($mod) ?>
        </option>
        <?php endforeach; ?>
      </select>
      <?php endif; ?>

      <select name="ordem" class="filtro-sel">
        <option value="datadesc" <?= $fordem === 'datadesc' ? 'selected' : '' ?>>Mais recentes primeiro</option>
        <option value="dataasc"  <?= $fordem === 'dataasc'  ? 'selected' : '' ?>>Mais antigas primeiro</option>
      </select>

      <button
        type="submit"
        name="seduc"
        value="<?= $fseduc ? '0' : '1' ?>"
        class="btn-filtro-seduc<?= $fseduc ? ' ativo' : '' ?>"
        formnovalidate
      >
        <span class="dot"></span>
        SEDUC
        <?php if ($count_seduc > 0): ?>
          <span style="font-family:'IBM Plex Mono',monospace;opacity:.7"><?= $count_seduc ?></span>
        <?php endif; ?>
      </button>

      <button
        type="submit"
        name="novas"
        value="<?= $fapenas_new ? '0' : '1' ?>"
        class="btn-filtro-novas<?= $fapenas_new ? ' ativo' : '' ?>"
        formnovalidate
      >
        <span class="dot"></span>
        Somente novas
        <?php if ($dados['novas'] > 0): ?>
          <span style="font-family:'IBM Plex Mono',monospace;opacity:.7"><?= $dados['novas'] ?></span>
        <?php endif; ?>
      </button>

      <input type="hidden" name="pagina" value="1">
      <button type="submit" class="btn-buscar">Filtrar</button>

      <?php if ($fbusca || $fmodalidade || $fapenas_new || $fseduc): ?>
      <a href="<?= url_aba($aba_ativa) ?>" class="btn-limpar">✕ Limpar</a>
      <?php endif; ?>

    </form>

    <!-- Stats inline -->
    <div class="stat-pill">
      <span class="n"><?= $dados['total'] ?></span>
      <span class="lbl">total</span>
    </div>
    <?php if ($dados['novas'] > 0): ?>
    <div class="stat-pill pill-novas">
      <span class="n"><?= $dados['novas'] ?></span>
      <span class="lbl">novas</span>
    </div>
    <?php endif; ?>
    <?php if ($dados['gerado_em']): ?>
    <span class="stat-atualizado">⟳ <?= esc($dados['gerado_em']) ?></span>
    <?php endif; ?>
  </div>
</div>

<!-- ═══ CONTEÚDO PRINCIPAL ══════════════════════════════════════════════════ -->
<main class="main">

  <div class="resultado-info">
    <strong><?= $total_filtrado ?></strong>
    <?= $total_filtrado === 1 ? 'resultado' : 'resultados' ?>
    <?php if ($fbusca || $fmodalidade || $fapenas_new || $fseduc): ?>
      · <a href="<?= url_aba($aba_ativa) ?>" style="color:#4477cc;font-size:.82rem">ver todos (<?= count($dispensas) ?>)</a>
    <?php endif; ?>
    <?php if ($total_paginas > 1): ?>
      · página <?= $pagina ?> de <?= $total_paginas ?>
    <?php endif; ?>
  </div>

  <div class="cards-grid">

  <?php if (empty($pagina_items)): ?>
    <div class="estado-vazio">
      <svg width="64" height="64" viewBox="0 0 64 64" fill="none" xmlns="http://www.w3.org/2000/svg">
        <rect x="8" y="12" width="48" height="40" rx="6" stroke="#333" stroke-width="2"/>
        <line x1="18" y1="26" x2="46" y2="26" stroke="#333" stroke-width="2" stroke-linecap="round"/>
        <line x1="18" y1="34" x2="38" y2="34" stroke="#333" stroke-width="2" stroke-linecap="round"/>
        <line x1="18" y1="42" x2="30" y2="42" stroke="#333" stroke-width="2" stroke-linecap="round"/>
      </svg>
      <h3>Nenhum resultado</h3>
      <p><?= $erro_json ? 'Aguardando dados do script Python.' : 'Tente ajustar os filtros.' ?></p>
    </div>

  <?php else: ?>

  <?php foreach ($pagina_items as $idx => $d):
    $eh_nova    = !empty($d['nova']);
    $numero     = $d['numero']            ?? 'NA';
    $data       = $d['data']              ?? 'NA';
    $modalidade = $d['modalidade']        ?? 'NA';
    $orgao      = $d['orgao']             ?? 'NA';
    $unidade    = $d['unidadecompradora'] ?? 'NA';
    $objeto     = $d['objeto']            ?? 'NA';
    $valor      = $d['valorestimado']     ?? 'NA';
    $pncp       = $d['pncp']              ?? 'NA';
    $link       = $d['link']              ?? '';
    $linkpncp   = $d['linkpncp']          ?? '';
    $fonte      = $d['fonte']             ?? 'NA';
    $uid        = 'card_' . $offset . '_' . $idx;

    if ($aba_ativa === 'PNCP') $btn_label = '↗ PNCP';
    elseif ($aba_ativa === 'PDCP') $btn_label = '↗ Portal de Compras';
    else $btn_label = '↗ Diário Oficial';
  ?>

  <article class="card<?= $eh_nova ? ' nova' : '' ?>">

    <div class="card-header">
      <div class="card-header-left">
        <?php if ($eh_nova): ?>
          <div class="badge-nova">NOVA</div>
        <?php endif; ?>
        <div class="card-tags">
          <?php if ($data !== 'NA'): ?>
            <span class="badge badge-data"><?= esc($data) ?></span>
          <?php endif; ?>
          <?php if ($modalidade !== 'NA'): ?>
            <span class="badge <?= badge_modalidade($modalidade) ?>"><?= esc($modalidade) ?></span>
          <?php endif; ?>
          <?= icone_fonte($fonte) ?>
        </div>
        <div class="card-numero"><?= esc($numero) ?></div>
      </div>
    </div>

    <div class="card-body">

      <?php if ($orgao !== 'NA'): ?>
      <div class="campo">
        <span class="label">Órgão</span>
        <span class="valor"><?= esc($orgao) ?></span>
      </div>
      <?php endif; ?>

      <?php if ($unidade !== 'NA'): ?>
      <div class="campo">
        <span class="label">Unidade</span>
        <span class="valor"><?= esc($unidade) ?></span>
      </div>
      <?php endif; ?>

      <div class="campo">
        <span class="label">Valor Est.</span>
        <span class="valor"><?= formatar_valor($valor) ?></span>
      </div>

      <?php if ($pncp !== 'NA'): ?>
      <div class="campo">
        <span class="label">PNCP ID</span>
        <span class="valor" style="font-family:'IBM Plex Mono',monospace;font-size:.8rem"><?= esc($pncp) ?></span>
      </div>
      <?php endif; ?>

      <?php if ($objeto !== 'NA'): ?>
      <div class="campo">
        <span class="label">Objeto</span>
        <div class="objeto-wrap">
          <span class="objeto-texto truncado" id="obj_<?= $uid ?>"><?= esc($objeto) ?></span>
          <?php if (mb_strlen($objeto, 'UTF-8') > 160): ?>
          <button class="btn-expand" onclick="expandirObjeto('<?= $uid ?>', this)">
            ↓ Ver mais
          </button>
          <?php endif; ?>
        </div>
      </div>
      <?php endif; ?>

    </div>

    <?php if ($link || $linkpncp): ?>
    <div class="card-footer">
      <?php if ($link): ?>
        <a href="<?= esc($link) ?>" target="_blank" rel="noopener noreferrer" class="link-btn <?= $aba_ativa === 'DIARIO' ? 'link-btn-diario' : 'link-btn-primario' ?>">
          <?= $btn_label ?>
        </a>
      <?php endif; ?>
      <?php if ($linkpncp && $aba_ativa !== 'PNCP'): ?>
        <a href="<?= esc($linkpncp) ?>" target="_blank" rel="noopener noreferrer" class="link-btn link-btn-secundario">
          ↗ PNCP
        </a>
      <?php endif; ?>
    </div>
    <?php endif; ?>

  </article>

  <?php endforeach; ?>
  <?php endif; ?>

  </div><!-- .cards-grid -->

  <!-- ─── Paginação ──────────────────────────────────────────── -->
  <?php if ($total_paginas > 1):
    // Parâmetros base preservando filtros ativos
    $params_base = array_filter([
        'busca'      => $fbusca,
        'modalidade' => $fmodalidade,
        'novas'      => $fapenas_new ? '1' : '',
        'seduc'      => $fseduc ? '1' : '',
        'ordem'      => $fordem !== 'datadesc' ? $fordem : '',
        'aba'        => $aba_ativa,
    ]);

    $janela = 2;
    $p_ini  = max(1, $pagina - $janela);
    $p_fim  = min($total_paginas, $pagina + $janela);
  ?>
  <nav class="paginacao" aria-label="Paginação">
    <a href="<?= pag_link(1, $params_base) ?>"
       class="pag-btn<?= $pagina === 1 ? ' desabilitada' : '' ?>">&laquo;</a>
    <a href="<?= pag_link(max(1, $pagina - 1), $params_base) ?>"
       class="pag-btn<?= $pagina === 1 ? ' desabilitada' : '' ?>">‹</a>

    <?php if ($p_ini > 1): ?>
      <a href="<?= pag_link(1, $params_base) ?>" class="pag-btn">1</a>
      <?php if ($p_ini > 2): ?><span class="pag-btn" style="pointer-events:none;border:none;color:#aaa">…</span><?php endif; ?>
    <?php endif; ?>

    <?php for ($p = $p_ini; $p <= $p_fim; $p++): ?>
      <a href="<?= pag_link($p, $params_base) ?>"
         class="pag-btn<?= $p === $pagina ? ' ativa' : '' ?>"><?= $p ?></a>
    <?php endfor; ?>

    <?php if ($p_fim < $total_paginas): ?>
      <?php if ($p_fim < $total_paginas - 1): ?><span class="pag-btn" style="pointer-events:none;border:none;color:#aaa">…</span><?php endif; ?>
      <a href="<?= pag_link($total_paginas, $params_base) ?>" class="pag-btn"><?= $total_paginas ?></a>
    <?php endif; ?>

    <a href="<?= pag_link(min($total_paginas, $pagina + 1), $params_base) ?>"
       class="pag-btn<?= $pagina === $total_paginas ? ' desabilitada' : '' ?>">›</a>
    <a href="<?= pag_link($total_paginas, $params_base) ?>"
       class="pag-btn<?= $pagina === $total_paginas ? ' desabilitada' : '' ?>">&raquo;</a>
  </nav>
  <?php endif; ?>

</main>

<script>
function abrirLink(url) {
    window.open(url, '_blank', 'noopener,noreferrer');
    return false;
}
new MutationObserver(function(muts) {
    muts.forEach(function(m) {
        m.addedNodes.forEach(function(n) {
            if (n.nodeType !== 1) return;
            (n.tagName === 'A' ? [n] : Array.from(n.querySelectorAll('a[target=_blank]'))).forEach(function(a) {
                if (a.target === '_blank' && a.href && a.href.indexOf(location.hostname) !== -1) a.target = '_self';
            });
        });
    });
}).observe(document.body, { childList: true, subtree: true });

function expandirObjeto(uid, btn) {
    var el = document.getElementById('obj_' + uid);
    if (el.classList.contains('truncado')) {
        el.classList.remove('truncado');
        btn.textContent = '↑ Ver menos';
    } else {
        el.classList.add('truncado');
        btn.textContent = '↓ Ver mais';
    }
}
</script>

</body>
</html>