#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
main.py — Orquestrador Principal do Monitor de Licitações
Executa scraping de 3 fontes, gera JSONs separados por fonte e sincroniza com FTP
"""

import os
import sys
import json
import time
from datetime import datetime, timedelta
from pathlib import Path

# Imports internos
from scraping.scraper import scraper_portal_compras
from scraping.scraper_diario import processar_diario_oficial
from web_sync import salvar_por_fonte, gerar_json_web_unificado, upload_fontes_ftp
from storage.logger import log_event, escrever_log_json

# ──────────────────────────────────────────────────────────────────────────
# CONFIGURAÇÕES GERAIS
# ──────────────────────────────────────────────────────────────────────────

PASTA_RAIZ = Path(__file__).resolve().parent
CONFIG_ARQ = PASTA_RAIZ / "config.json"  # Usar config.json original
HISTORICO_ARQ = PASTA_RAIZ / "historico_dispensas.json"
HISTORICO_DIARIO_ARQ = PASTA_RAIZ / "historico_diario.json"
JSONWEB_ARQ = PASTA_RAIZ / "resultados_web.json"
LOG_ARQ = PASTA_RAIZ / "execution.log"

# Carrega config
CONFIG = {}
if CONFIG_ARQ.exists():
    try:
        with open(CONFIG_ARQ, "r", encoding="utf-8") as f:
            CONFIG = json.load(f)
        print(f"[OK] Config carregado: {CONFIG_ARQ}")
    except Exception as e:
        print(f"[ERRO] Erro ao carregar config: {e}")
        print(f"[INFO] Tentando usar config_test.json como fallback...")
        # Tentar usar config_test.json como fallback
        CONFIG_ARQ = PASTA_RAIZ / "config_test.json"
        if CONFIG_ARQ.exists():
            try:
                with open(CONFIG_ARQ, "r", encoding="utf-8") as f:
                    CONFIG = json.load(f)
                print(f"[OK] Config fallback carregado: {CONFIG_ARQ}")
            except Exception as e2:
                print(f"[ERRO] Erro ao carregar config fallback: {e2}")
                sys.exit(1)
        else:
            print(f"[ERRO] Arquivo config_test.json também não encontrado")
            sys.exit(1)
else:
    print(f"[ERRO] Arquivo config.json não encontrado: {CONFIG_ARQ}")
    sys.exit(1)

# ──────────────────────────────────────────────────────────────────────────
# FUNÇÕES AUXILIARES
# ──────────────────────────────────────────────────────────────────────────

def inicializar_historicos():
    """Garante que os arquivos de histórico existem"""
    for arq in [HISTORICO_ARQ, HISTORICO_DIARIO_ARQ]:
        if not arq.exists():
            with open(arq, "w", encoding="utf-8") as f:
                json.dump([], f, ensure_ascii=False, indent=2)
            log_event(f"Histórico criado: {arq.name}")

def carregar_historico(caminho):
    """Carrega histórico JSON de forma segura"""
    if not caminho.exists():
        return []
    try:
        with open(caminho, "r", encoding="utf-8") as f:
            data = json.load(f)
            # Garantir que retorna uma lista
            return data if isinstance(data, list) else []
    except Exception as e:
        log_event(f"Erro ao ler {caminho.name}: {e}", tipo="WARNING")
        return []

def salvar_historico(caminho, dados):
    """Salva histórico JSON de forma segura"""
    try:
        with open(caminho, "w", encoding="utf-8") as f:
            json.dump(dados, f, ensure_ascii=False, indent=2)
    except Exception as e:
        log_event(f"Erro ao salvar {caminho.name}: {e}", tipo="ERROR")

def limpar_duplicatas(novos, historico):
    """Remove IDs já presentes no histórico para evitar duplicatas"""
    if not novos:
        return []
    
    # Verificar se os itens são dicionários
    if novos and isinstance(novos[0], dict):
        ids_historico = {
            (item.get("numero") or item.get("pncp") or item.get("objeto", "")[:60])
            for item in historico if isinstance(item, dict)
        }
        return [
            item for item in novos
            if (item.get("numero") or item.get("pncp") or item.get("objeto", "")[:60])
            not in ids_historico
        ]
    else:
        # Se não são dicionários, retornar lista vazia
        return []

def relatorio_execucao(dados_scraping, resultado_sync):
    """Gera relatório resumido da execução"""
    log_event("=" * 70)
    log_event("RELATÓRIO DE EXECUÇÃO")
    log_event("=" * 70)
    log_event(f"Data/Hora: {datetime.now().strftime('%d/%m/%Y %H:%M:%S')}")
    
    log_event("\nSCRAPING:")
    for nome, dados in dados_scraping.items():
        if isinstance(dados, dict):
            total = dados.get("total", 0)
            novas = dados.get("novas", 0)
            log_event(f"  {nome}: {novas} nova(s) de {total} coletadas")
        else:
            log_event(f"  {nome}: Erro na coleta")
    
    log_event("\nARMAZENAMENTO (Últimas 100 por fonte):")
    if isinstance(resultado_sync, dict):
        for fonte, info in resultado_sync.items():
            if isinstance(info, dict):
                log_event(f"  {fonte}: {info.get('novas', 0)} nova(s) | "
                         f"total: {info.get('total', 0)} | "
                         f"{info.get('arquivo', 'JSON')}")
    
    log_event("\n" + "=" * 70)

# ──────────────────────────────────────────────────────────────────────────
# MAIN — EXECUÇÃO DO CICLO COMPLETO
# ──────────────────────────────────────────────────────────────────────────

def executar_ciclo():
    """Executa o ciclo completo: scraping + armazenamento + sync"""
    
    log_event(f"\n{'='*70}")
    log_event(f"INICIANDO CICLO DE MONITORAMENTO")
    log_event(f"{'='*70}")
    log_event(f"Timestamp: {datetime.now().isoformat()}")
    
    # 1. Inicializar
    inicializar_historicos()
    
    # 2. Carregar históricos
    historico_dispensas = carregar_historico(HISTORICO_ARQ)
    historico_diario    = carregar_historico(HISTORICO_DIARIO_ARQ)
    
    # 3. SCRAPING — Portal de Compras (PNCP + PdCP)
    log_event("\n[1/3] Iniciando scraping do Portal de Compras...")
    novos_portal = []
    novos_portal_unicos = []
    try:
        resultado_portal = scraper_portal_compras(config=CONFIG)
        if isinstance(resultado_portal, dict):
            novos_portal = resultado_portal.get("dispensas", [])
        else:
            novos_portal = resultado_portal if isinstance(resultado_portal, list) else []
        
        novos_portal_unicos = limpar_duplicatas(novos_portal, historico_dispensas)
        log_event(f"     OK {len(novos_portal_unicos)} nova(s) do Portal de Compras")
    except Exception as e:
        log_event(f"     ERRO no Portal de Compras: {e}", tipo="ERROR")
    
    # 4. SCRAPING — Diário Oficial (pular se não tiver Selenium)
    log_event("\n[2/3] Iniciando scraping do Diário Oficial...")
    novos_diario = []
    novos_diario_unicos = []
    try:
        # Tentar importar Selenium
        from selenium import webdriver
        from selenium.webdriver.chrome.options import Options
        
        chrome_options = Options()
        chrome_options.add_argument("--headless")
        chrome_options.add_argument("--no-sandbox")
        chrome_options.add_argument("--disable-dev-shm-usage")
        
        driver = webdriver.Chrome(options=chrome_options)
        
        resultado_diario = processar_diario_oficial(driver, config=CONFIG, log_fn=log_event)
        driver.quit()
        
        # processar_diario_oficial retorna lista direta
        novos_diario = resultado_diario if isinstance(resultado_diario, list) else []
        novos_diario_unicos = limpar_duplicatas(novos_diario, historico_diario)
        log_event(f"     OK {len(novos_diario_unicos)} nova(s) do Diário Oficial")
    except ImportError:
        log_event("     AVISO: Selenium não instalado, pulando Diário Oficial", tipo="WARNING")
    except Exception as e:
        log_event(f"     ERRO no Diário Oficial: {e}", tipo="ERROR")
    
    # 5. MESCLAR TODOS OS NOVOS
    todas_dispensas = novos_portal_unicos + novos_diario_unicos
    log_event(f"\n[3/3] Total de novas licitações: {len(todas_dispensas)}")
    
    # 6. SALVAR HISTÓRICOS LOCAIS
    log_event("\n[4/4] Salvando históricos locais...")
    try:
        # Garantir que os históricos são listas
        if not isinstance(historico_dispensas, list):
            historico_dispensas = []
        if not isinstance(historico_diario, list):
            historico_diario = []
            
        historico_dispensas = (novos_portal_unicos + historico_dispensas)[:300]
        historico_diario    = (novos_diario_unicos + historico_diario)[:300]
        salvar_historico(HISTORICO_ARQ, historico_dispensas)
        salvar_historico(HISTORICO_DIARIO_ARQ, historico_diario)
        log_event(f"     OK Históricos atualizados")
    except Exception as e:
        log_event(f"     ERRO ao salvar históricos: {e}", tipo="ERROR")
    
    # 7. SALVAR JSONs POR FONTE (últimas 100 cada)
    log_event("\n[5/6] Gerando JSONs separados por fonte (últimas 100 cada)...")
    resultado_fontes = {}
    try:
        resultado_fontes, qtd_novas_total = salvar_por_fonte(
            todas_dispensas,
            historico_path=str(HISTORICO_ARQ),
            config=CONFIG,
        )
        log_event(f"     OK JSONs por fonte gerados")
    except Exception as e:
        log_event(f"     ERRO ao salvar JSONs por fonte: {e}", tipo="ERROR")
    
    # 8. GERAR JSON UNIFICADO (compatibilidade)
    log_event("\n[6/7] Gerando JSON unificado (compatibilidade)...")
    try:
        json_web = gerar_json_web_unificado(CONFIG, caminho_saida=str(JSONWEB_ARQ))
        log_event(f"     OK JSON unificado gerado: {json_web}")
    except Exception as e:
        log_event(f"     ERRO ao gerar JSON unificado: {e}", tipo="ERROR")
    
    # 9. UPLOAD FTP (pular se não configurado)
    log_event("\n[7/8] Sincronizando com servidor FTP...")
    try:
        if CONFIG.get("webdisplay", {}).get("ftp", {}).get("habilitado", False):
            upload_fontes_ftp(CONFIG, log_fn=log_event)
            log_event(f"     OK Sincronização FTP concluída")
        else:
            log_event(f"     AVISO: FTP desabilitado na configuração")
    except Exception as e:
        log_event(f"     ERRO no upload FTP: {e}", tipo="ERROR")
    
    # 10. RELATÓRIO FINAL
    log_event("\n[8/8] Gerando relatório final...")
    relatorio_execucao(
        {
            "Portal de Compras": {"total": len(novos_portal), "novas": len(novos_portal_unicos)},
            "Diário Oficial": {"total": len(novos_diario), "novas": len(novos_diario_unicos)},
        },
        resultado_fontes
    )
    
    log_event(f"\n{'='*70}")
    log_event(f"CICLO CONCLUÍDO COM SUCESSO")
    log_event(f"{'='*70}\n")
    
    return True

# ──────────────────────────────────────────────────────────────────────────
# ENTRY POINT
# ──────────────────────────────────────────────────────────────────────────

if __name__ == "__main__":
    try:
        executar_ciclo()
    except KeyboardInterrupt:
        log_event("\n[!] Execução interrompida pelo usuário", tipo="WARNING")
        sys.exit(0)
    except Exception as e:
        log_event(f"\n[!] Erro fatal: {e}", tipo="ERROR")
        sys.exit(1)