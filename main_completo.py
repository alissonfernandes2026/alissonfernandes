#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
main_completo.py — Sistema Completo de Monitoramento de Licitações
Captura múltiplas páginas de todas as fontes e gera JSONs separados
"""

import os
import sys
import json
import time
from datetime import datetime
from pathlib import Path

# Imports internos
from scraping.scraper_completo import executar_scraping_completo, salvar_resultados_por_fonte
from web_sync import upload_fontes_ftp
from storage.logger import log_event

# ──────────────────────────────────────────────────────────────────────────
# CONFIGURAÇÕES GERAIS
# ──────────────────────────────────────────────────────────────────────────

PASTA_RAIZ = Path(__file__).resolve().parent
CONFIG_ARQ = PASTA_RAIZ / "config.json"
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
        sys.exit(1)
else:
    print(f"[ERRO] Arquivo config.json não encontrado: {CONFIG_ARQ}")
    sys.exit(1)

# ──────────────────────────────────────────────────────────────────────────
# FUNÇÕES AUXILIARES
# ──────────────────────────────────────────────────────────────────────────

def inicializar_diretorios():
    """Garante que os diretórios necessários existem"""
    dirs = ["pdfs_diario", "logs", "backups"]
    for dir_name in dirs:
        dir_path = PASTA_RAIZ / dir_name
        dir_path.mkdir(exist_ok=True)

def carregar_historico(caminho):
    """Carrega histórico JSON de forma segura"""
    if not Path(caminho).exists():
        return []
    try:
        with open(caminho, "r", encoding="utf-8") as f:
            data = json.load(f)
            return data if isinstance(data, list) else []
    except Exception as e:
        log_event(f"Erro ao ler {caminho}: {e}", tipo="WARNING")
        return []

def salvar_historico(caminho, dados, max_itens=300):
    """Salva histórico JSON mantendo apenas os últimos itens"""
    try:
        # Garantir que não exceda o limite
        if len(dados) > max_itens:
            dados = dados[:max_itens]
        
        with open(caminho, "w", encoding="utf-8") as f:
            json.dump(dados, f, ensure_ascii=False, indent=2)
    except Exception as e:
        log_event(f"Erro ao salvar {caminho}: {e}", tipo="ERROR")

def marcar_novas_licitacoes(novas, historico):
    """Marca quais licitações são realmente novas"""
    if not novas:
        return []
    
    # Criar conjunto de identificadores únicos do histórico
    ids_historico = set()
    for item in historico:
        if isinstance(item, dict):
            # Criar ID único baseado em vários campos
            id_parts = [
                item.get("numero", ""),
                item.get("pncp", ""),
                item.get("objeto", "")[:50],
                item.get("data", "")
            ]
            item_id = "|".join(str(p) for p in id_parts if p)
            ids_historico.add(item_id)
    
    # Marcar novas
    for item in novas:
        if isinstance(item, dict):
            id_parts = [
                item.get("numero", ""),
                item.get("pncp", ""),
                item.get("objeto", "")[:50],
                item.get("data", "")
            ]
            item_id = "|".join(str(p) for p in id_parts if p)
            item["nova"] = item_id not in ids_historico
        else:
            item["nova"] = True
    
    return novas

def relatorio_execucao(resultados):
    """Gera relatório resumido da execução"""
    log_event("=" * 70)
    log_event("RELATÓRIO DE EXECUÇÃO - SISTEMA COMPLETO")
    log_event("=" * 70)
    log_event(f"Data/Hora: {datetime.now().strftime('%d/%m/%Y %H:%M:%S')}")
    
    log_event("\nCAPTURA POR FONTE:")
    for fonte, dados in resultados.items():
        if fonte == "diario":
            nome = "Diário Oficial"
        elif fonte == "pncp":
            nome = "PNCP"
        elif fonte == "portal":
            nome = "Portal de Compras"
        else:
            nome = fonte
        
        total = len(dados)
        novas = sum(1 for d in dados if d.get("nova", False))
        log_event(f"  {nome}: {novas} nova(s) de {total} capturadas")
    
    # Verificar arquivos gerados
    log_event("\nARQUIVOS GERADOS:")
    arquivos = [
        ("resultados_diario.json", "Diário Oficial"),
        ("resultados_pncp.json", "PNCP"),
        ("resultados_pdcp.json", "Portal de Compras"),
        ("resultados_web.json", "Unificado")
    ]
    
    for arquivo, nome in arquivos:
        caminho = PASTA_RAIZ / arquivo
        if caminho.exists():
            try:
                with open(caminho, "r", encoding="utf-8") as f:
                    data = json.load(f)
                total = data.get("total", 0)
                novas = data.get("novas", 0)
                log_event(f"  {nome}: {novas} nova(s) | total: {total} | {arquivo}")
            except:
                log_event(f"  {nome}: Erro ao ler {arquivo}")
        else:
            log_event(f"  {nome}: {arquivo} não encontrado")
    
    log_event("\n" + "=" * 70)

# ──────────────────────────────────────────────────────────────────────────
# MAIN — EXECUÇÃO DO SISTEMA COMPLETO
# ──────────────────────────────────────────────────────────────────────────

def executar_sistema_completo():
    """Executa o sistema completo: scraping + armazenamento + sync"""
    
    log_event(f"\n{'='*70}")
    log_event(f"INICIANDO SISTEMA COMPLETO DE MONITORAMENTO")
    log_event(f"{'='*70}")
    log_event(f"Timestamp: {datetime.now().isoformat()}")
    
    # 1. Inicializar
    inicializar_diretorios()
    
    # 2. Carregar históricos
    historico_diario = carregar_historico(PASTA_RAIZ / "historico_diario.json")
    historico_pncp = carregar_historico(PASTA_RAIZ / "historico_pncp.json")
    historico_portal = carregar_historico(PASTA_RAIZ / "historico_portal.json")
    
    # 3. EXECUTAR SCRAPING COMPLETO
    log_event("\n[1/4] Executando scraping completo de todas as fontes...")
    try:
        resultados = executar_scraping_completo(CONFIG)
        
        # Separar resultados por fonte
        diario_dados = resultados.get("diario", [])
        pncp_dados = resultados.get("pncp", [])
        portal_dados = resultados.get("portal", [])
        
        log_event(f"     Diário Oficial: {len(diario_dados)} licitações")
        log_event(f"     PNCP: {len(pncp_dados)} licitações")
        log_event(f"     Portal de Compras: {len(portal_dados)} licitações")
        
    except Exception as e:
        log_event(f"     ERRO no scraping completo: {e}", tipo="ERROR")
        return False
    
    # 4. MARCAR NOVAS LICITAÇÕES
    log_event("\n[2/4] Marcando licitações novas...")
    try:
        diario_dados = marcar_novas_licitacoes(diario_dados, historico_diario)
        pncp_dados = marcar_novas_licitacoes(pncp_dados, historico_pncp)
        portal_dados = marcar_novas_licitacoes(portal_dados, historico_portal)
        
        novas_diario = sum(1 for d in diario_dados if d.get("nova", False))
        novas_pncp = sum(1 for d in pncp_dados if d.get("nova", False))
        novas_portal = sum(1 for d in portal_dados if d.get("nova", False))
        
        log_event(f"     Novas licitações: Diário={novas_diario}, PNCP={novas_pncp}, Portal={novas_portal}")
        
    except Exception as e:
        log_event(f"     ERRO ao marcar novas licitações: {e}", tipo="ERROR")
    
    # 5. SALVAR RESULTADOS POR FONTE
    log_event("\n[3/4] Salvando resultados por fonte...")
    try:
        salvar_resultados_por_fonte(resultados, CONFIG)
        log_event(f"     OK Resultados salvos em arquivos JSON")
    except Exception as e:
        log_event(f"     ERRO ao salvar resultados: {e}", tipo="ERROR")
    
    # 6. ATUALIZAR HISTÓRICOS
    log_event("\n[4/4] Atualizando históricos locais...")
    try:
        # Atualizar históricos (adicionar novas no início)
        todas_novas = []
        if diario_dados:
            historico_diario = diario_dados + historico_diario
            todas_novas.extend([d for d in diario_dados if d.get("nova", False)])
        
        if pncp_dados:
            historico_pncp = pncp_dados + historico_pncp
            todas_novas.extend([d for d in pncp_dados if d.get("nova", False)])
        
        if portal_dados:
            historico_portal = portal_dados + historico_portal
            todas_novas.extend([d for d in portal_dados if d.get("nova", False)])
        
        # Salvar históricos
        salvar_historico(PASTA_RAIZ / "historico_diario.json", historico_diario)
        salvar_historico(PASTA_RAIZ / "historico_pncp.json", historico_pncp)
        salvar_historico(PASTA_RAIZ / "historico_portal.json", historico_portal)
        
        log_event(f"     OK Históricos atualizados ({len(todas_novas)} novas no total)")
        
    except Exception as e:
        log_event(f"     ERRO ao atualizar históricos: {e}", tipo="ERROR")
    
    # 7. UPLOAD FTP (se configurado)
    log_event("\n[5/5] Sincronizando com servidor FTP...")
    try:
        if CONFIG.get("webdisplay", {}).get("ftp", {}).get("habilitado", False):
            upload_fontes_ftp(CONFIG, log_fn=log_event)
            log_event(f"     OK Sincronização FTP concluída")
        else:
            log_event(f"     AVISO: FTP desabilitado na configuração")
    except Exception as e:
        log_event(f"     ERRO no upload FTP: {e}", tipo="ERROR")
    
    # 8. RELATÓRIO FINAL
    log_event("\n[6/6] Gerando relatório final...")
    relatorio_execucao(resultados)
    
    log_event(f"\n{'='*70}")
    log_event(f"SISTEMA COMPLETO CONCLUÍDO COM SUCESSO")
    log_event(f"{'='*70}\n")
    
    return True

# ──────────────────────────────────────────────────────────────────────────
# ENTRY POINT
# ──────────────────────────────────────────────────────────────────────────

if __name__ == "__main__":
    try:
        executar_sistema_completo()
    except KeyboardInterrupt:
        log_event("\n[!] Execução interrompida pelo usuário", tipo="WARNING")
        sys.exit(0)
    except Exception as e:
        log_event(f"\n[!] Erro fatal: {e}", tipo="ERROR")
        sys.exit(1)