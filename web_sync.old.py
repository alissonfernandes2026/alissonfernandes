# web_sync.py
import json, os, ftplib, re
from datetime import datetime

MAX_POR_FONTE = 100

MAPA_ARQUIVOS = {
    "DIARIO": "resultados_diario.json",
    "PNCP":   "resultados_pncp.json",
    "PDCP":   "resultados_pdcp.json",
}

def _classificar_fonte(item: dict) -> str:
    f = (item.get("fonte") or "").upper()
    if "DIÁRIO" in f or "DIARIO" in f:
        return "DIARIO"
    if "PNCP" in f:
        return "PNCP"
    if "PORTAL" in f or "PDCP" in f or "COMPRAS" in f:
        return "PDCP"
    return "DIARIO"

def normalizar_valor(v):
    if not v or v == "NA":
        return 0.0
    try:
        return float(re.sub(r"[^0-9,]", "", str(v)).replace(",", "."))
    except Exception:
        return 0.0

def formatar_data_iso(d):
    if not d or d == "NA":
        return ""
    try:
        return datetime.strptime(d, "%d/%m/%Y").strftime("%Y-%m-%d")
    except Exception:
        return d

def _carregar_json(caminho):
    if os.path.exists(caminho):
        try:
            with open(caminho, "r", encoding="utf-8") as f:
                return json.load(f)
        except Exception:
            pass
    return {"gerado_em": "", "total": 0, "novas": 0, "dispensas": []}

def _salvar_json(caminho, payload):
    with open(caminho, "w", encoding="utf-8") as f:
        json.dump(payload, f, ensure_ascii=False, indent=2)

def _id_item(item):
    return item.get("pncp") or item.get("numero") or item.get("objeto", "")[:60]

def salvar_por_fonte(novos: list, historico_path: str, config: dict):
    """Separa novos itens por fonte, mescla com histórico de cada JSON
    e mantém as últimas MAX_POR_FONTE entradas."""
    agrupados = {"DIARIO": [], "PNCP": [], "PDCP": []}
    for item in novos:
        chave = _classificar_fonte(item)
        agrupados[chave].append(item)

    arquivos_cfg = config.get("webdisplay", {}).get("arquivos_fonte", {})
    resultado = {}
    total_novas = 0

    for fonte, arquivo_padrao in MAPA_ARQUIVOS.items():
        arquivo = arquivos_cfg.get(fonte, arquivo_padrao)
        existente = _carregar_json(arquivo)
        hist_dispensas = existente.get("dispensas", [])
        ids_existentes = {_id_item(d) for d in hist_dispensas}

        novos_fonte = agrupados[fonte]
        for item in novos_fonte:
            if "dataiso" not in item:
                item["dataiso"] = formatar_data_iso(item.get("data", ""))
            if "valornumerico" not in item:
                item["valornumerico"] = normalizar_valor(item.get("valorestimado", ""))
            item["nova"] = _id_item(item) not in ids_existentes

        qtd_novas = sum(1 for i in novos_fonte if i.get("nova"))
        total_novas += qtd_novas

        realmente_novos = [i for i in novos_fonte if i.get("nova")]
        combinado = realmente_novos + hist_dispensas
        combinado = combinado[:MAX_POR_FONTE]

        payload = {
            "gerado_em": datetime.now().strftime("%d/%m/%Y %H:%M:%S"),
            "gerado_em_iso": datetime.now().isoformat(),
            "fonte": fonte,
            "total": len(combinado),
            "novas": qtd_novas,
            "dispensas": combinado,
        }
        _salvar_json(arquivo, payload)
        resultado[fonte] = {
            "arquivo": arquivo,
            "total": len(combinado),
            "novas": qtd_novas,
        }
        print(f"[{fonte}] {qtd_novas} nova(s) | total: {len(combinado)} | {arquivo}")

    return resultado, total_novas

def gerar_json_web_unificado(config: dict, caminho_saida="resultados_web.json"):
    """Gera resultados_web.json unificado lendo os 3 JSONs por fonte."""
    arquivos_cfg = config.get("webdisplay", {}).get("arquivos_fonte", {})
    todas = []
    for fonte, arquivo_padrao in MAPA_ARQUIVOS.items():
        arquivo = arquivos_cfg.get(fonte, arquivo_padrao)
        dados = _carregar_json(arquivo)
        todas.extend(dados.get("dispensas", []))
    todas.sort(key=lambda d: d.get("dataiso", ""), reverse=True)
    payload = {
        "gerado_em": datetime.now().strftime("%d/%m/%Y %H:%M:%S"),
        "gerado_em_iso": datetime.now().isoformat(),
        "total": len(todas),
        "novas": sum(1 for d in todas if d.get("nova")),
        "dispensas": todas,
    }
    _salvar_json(caminho_saida, payload)
    return caminho_saida

def upload_ftp(arquivo_local: str, ftp_cfg: dict):
    host      = ftp_cfg.get("host", "").strip()
    usuario   = ftp_cfg.get("usuario", "").strip()
    senha     = ftp_cfg.get("senha", "").strip()
    diretorio = ftp_cfg.get("diretorio", "public_html/licitacoes").strip()
    porta     = int(ftp_cfg.get("porta", 21))
    if not all([host, usuario, senha]):
        return False, "Configuração FTP incompleta"
    if not os.path.exists(arquivo_local):
        return False, f"Arquivo não encontrado: {arquivo_local}"
    try:
        with ftplib.FTP() as ftp:
            ftp.connect(host, porta, timeout=30)
            ftp.login(usuario, senha)
            _garantir_diretorio_ftp(ftp, diretorio)
            nome_arq = os.path.basename(arquivo_local)
            with open(arquivo_local, "rb") as f:
                ftp.storbinary(f"STOR {nome_arq}", f)
        return True, f"Upload OK → {host}/{diretorio}/{nome_arq}"
    except ftplib.all_errors as e:
        return False, f"Erro FTP: {e}"
    except Exception as e:
        return False, f"Erro inesperado: {e}"

def _garantir_diretorio_ftp(ftp, caminho):
    partes = [p for p in caminho.strip("/").split("/") if p]
    atual = ""
    for parte in partes:
        atual = atual.rstrip("/") + "/" + parte
        try:
            ftp.cwd(atual)
        except ftplib.error_perm:
            ftp.mkd(atual)
            ftp.cwd(atual)

def upload_fontes_ftp(config: dict, log_fn=None):
    """Faz upload dos 3 JSONs de fonte + resultados_web.json unificado."""
    webcfg = config.get("webdisplay", {})
    if not webcfg.get("habilitado", False):
        return
    ftp_cfg = webcfg.get("ftp", {})
    if not ftp_cfg.get("habilitado", False):
        return
    arquivos_cfg = webcfg.get("arquivos_fonte", {})
    arquivos_enviar = []
    for fonte, arquivo_padrao in MAPA_ARQUIVOS.items():
        arquivo = arquivos_cfg.get(fonte, arquivo_padrao)
        if os.path.exists(arquivo):
            arquivos_enviar.append(arquivo)
    unificado = webcfg.get("arquivo_json_web", "resultados_web.json")
    if os.path.exists(unificado):
        arquivos_enviar.append(unificado)
    for arq in arquivos_enviar:
        ok, msg = upload_ftp(arq, ftp_cfg)
        if log_fn:
            log_fn(f"FTP {msg}", tipo="INFO" if ok else "ERROR")
        else:
            print(f"FTP {msg}")

# ── Compatibilidade com main.py antigo ──────────────────────────────────────
def gerar_json_web(dispensas, caminho_historico, caminho_saida="resultados_web.json",
                   max_dispensas=200):
    def _num(v):
        if not v or v == "NA": return 0.0
        try: return float(re.sub(r"[^0-9,]", "", str(v)).replace(",", "."))
        except: return 0.0

    def _iso(d):
        if not d or d == "NA": return ""
        try: return datetime.strptime(d, "%d/%m/%Y").strftime("%Y-%m-%d")
        except: return d

    numeros_ant = set()
    if os.path.exists(caminho_historico):
        try:
            with open(caminho_historico, "r", encoding="utf-8") as f:
                hist = json.load(f)
            if isinstance(hist, list):
                numeros_ant = {d.get("numero") for d in hist}
        except Exception:
            pass

    registros = []
    novas_count = 0
    for d in dispensas:
        numero = d.get("numero", "")
        nova = bool(numero and numero != "NA" and numero not in numeros_ant)
        if nova:
            novas_count += 1
        registros.append({
            "numero":           numero or "NA",
            "nova":             nova,
            "data":             d.get("data", "NA"),
            "dataiso":          _iso(d.get("data", "")),
            "modalidade":       d.get("modalidade", "NA"),
            "orgao":            d.get("orgao", "NA"),
            "local":            d.get("local", "NA"),
            "unidadecompradora":d.get("unidadecompradora", "NA"),
            "objeto":           d.get("objeto", "NA"),
            "valorestimado":    d.get("valorestimado", "NA"),
            "valornumerico":    _num(d.get("valorestimado", "")),
            "pncp":             d.get("pncp", "NA"),
            "link":             d.get("link", ""),
            "linkpncp":         d.get("linkpncp", ""),
            "fonte":            d.get("fonte", "NA"),
        })
    registros.sort(key=lambda x: (not x["nova"], x.get("dataiso", "")), reverse=True)
    registros = registros[:max_dispensas]
    payload = {
        "gerado_em":     datetime.now().strftime("%d/%m/%Y %H:%M:%S"),
        "gerado_em_iso": datetime.now().isoformat(),
        "total":         len(registros),
        "novas":         novas_count,
        "dispensas":     registros,
    }
    with open(caminho_saida, "w", encoding="utf-8") as f:
        json.dump(payload, f, ensure_ascii=False, indent=2)
    return caminho_saida, novas_count

def upload_ftp_se_habilitado(arquivo_local, config, log_fn=None):
    webcfg = config.get("webdisplay", {})
    if not webcfg.get("habilitado", False):
        return
    ftp_cfg = webcfg.get("ftp", {})
    if not ftp_cfg.get("habilitado", False):
        return
    ok, msg = upload_ftp(arquivo_local, ftp_cfg)
    if log_fn:
        log_fn(f"FTP {msg}", tipo="INFO" if ok else "ERROR")
    else:
        print(f"FTP {msg}")