# Painel de Setores igual ao METRICA360 — Plano

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** a tela Satisfação → Painel de Setores fica igual à `admin-painel-setores` do METRICA360.

**Architecture:** API (`pesquisa-setores.php` / `PesquisaPainelService::painelSetores`) ganha o papel do
servidor e a foto do coordenador; a tela sai de `satisfacao.js` para `satisfacao-setores.js` +
`satisfacao-setores.css` (prefixo `sps-`), com ações por `data-sps` e ouvinte delegado.

**Spec:** `docs/superpowers/specs/2026-11-16-painel-de-setores-igual-metrica360-design.md`

## Global Constraints
- Sem `style=`/`onclick=` no HTML montado; larguras/alturas por `data-*` aplicadas no JS; cores só por tokens.
- Todo valor da API por `sanitizeHTML()`; modo escuro (`body.modo-escuro`); ícones novos em `icon_names`.
- Textos iguais aos do METRICA360.

## Review Focus
- Setor sem atendentes → "Nenhum atendente vinculado".
- Atendente sem avaliação → selo "—", faixa neutra, sem "Distribuição"/"Sentimento".
- Um só atendente com nota → só o selo ↑ (sem ↓ igual).
- Foto inexistente/sem foto → iniciais.
- Impressão (PDF) → sem sidebar/cabeçalho/barra de ferramentas, vista em tabela.

### Task 1: API
- [ ] `AcessoService::ROTULOS_PAPEIS` (espelho de `papeis.js`) e `rotuloPapel(string $tipo): string`.
- [ ] `painelSetores()`: `tipo` por servidor e `coordenador_avatar` por setor.
- [ ] CSV: Setor; Coordenador(a); Atendente; Perfil; Semana Ant. (ini – fim); Semana Atual; Mês Ant.; Mês Atual;
      Período; Total Convites; Avaliações Respondidas; Taxa de Resposta (%); Média de Nota; Último Envio.
- [ ] Teste `AcessoServiceTest::test_rotulo_do_papel`; integração: `PesquisaModuloIntegracaoTest` confere `tipo`.

### Task 2: Tela
- [ ] `satisfacao-setores.js`: `carregarSatSetores()` e o montador (resumo, ferramentas, setor, cartão, tabela),
      ordenação, vista, detalhes, PDF, "Atualizado às", recarga de 5 min (aba visível, sem modal, sem digitação).
- [ ] `satisfacao.js`: tira a versão simplificada (`cartaoSetorSat`, `tabelaSetoresSat`, `taxaSat`,
      `satVisaoSetores` e o ramo `visao`); valida "de ≤ até" no filtro.
- [ ] `satisfacao-setores.css` (+ `@media print`), `index.html` (CSS/JS, `?v=`, ícones).
- [ ] Conferir no navegador (claro, escuro, 360 px, teclado, impressão) com dados de teste `zz_` e apagá-los.

### Task 3: Fechamento
- [ ] `composer test`, matriz de acesso; CLAUDE.md (tabela do frontend); commit.
