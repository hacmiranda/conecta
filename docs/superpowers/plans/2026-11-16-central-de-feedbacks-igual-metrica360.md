# Central de Feedbacks igual ao METRICA360 — Plano

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Satisfação → Avaliações vira a "Central de Feedbacks" do METRICA360.

**Architecture:** API ganha tendências de pendentes/resolvidos e `?acao=pendentes`; a tela sai de `satisfacao.js`
para `satisfacao-central.js` + `.css` (prefixo `cfb-`), com parte fixa (frase, Google) montada uma vez por visita
e parte dinâmica (números, gráfico, categorias, lista) refeita a cada filtro/página.

**Spec:** `docs/superpowers/specs/2026-11-16-central-de-feedbacks-igual-metrica360-design.md`

## Global Constraints
- Sem `style=`/`onclick=` no HTML montado; ações por `data-cfb`; cores por tokens; modo escuro; ícones em `icon_names`.
- Textos iguais aos do METRICA360, salvo as decisões do desenho.

## Review Focus
- Sem avaliações → "Nenhum feedback recebido ainda"; com filtro e sem resultado → "Nenhum feedback encontrado" + Limpar.
- Comentário com mais de 120 caracteres → cortado, "Ver tudo" alterna.
- Teclado: menu de status (ESC fecha, setas) e painel lateral (foco preso, ESC, volta o foco).
- Widget do Google sem internet → aviso, a tela segue.
- Perfil de consulta (supervisor/coordenador geral) → pode mudar status e anotar (regra do Conecta).

### Task 1: API
- [ ] Teste (integração): `indicadores()['tendencia']` tem `pendentes_atual/anterior` e `resolvidos_atual/anterior`
      (nota 2 hoje soma 1 em pendentes_atual; nota 5 soma 1 em resolvidos_atual).
- [ ] `PesquisaPainelService::indicadores`: as quatro chaves. `pesquisa-painel.php?acao=pendentes` → `{pendentes}`.

### Task 2: Tela
- [ ] `satisfacao-central.js`/`.css`; `satisfacao.js` sem a versão antiga; `index.html` (título, contador no menu,
      arquivos, `?v=`, ícones).
- [ ] Conferir no navegador com dados `zz_` (claro, escuro, 360 px, teclado, PDF, CSV, Google) e apagá-los.

### Task 3: Fechamento
- [ ] `composer test`, matriz; CLAUDE.md e PENDENCIAS.md (decisão do Google Reviews); commit sem push.
