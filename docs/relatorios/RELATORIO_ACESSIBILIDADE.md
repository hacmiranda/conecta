# Relatório de Auditoria de Acessibilidade Digital

**Data:** 19/02/2026
**Responsável:** Agente de IA (Antigravity)
**Status:** ✅ Concluído

## Introdução
Este documento registra as inconsistências de acessibilidade encontradas e as correções aplicadas no frontend do Sistema de Helpdesk CRPRS, visando conformidade com WCAG 2.1 nível AA.

---

## ♿ Correções Implementadas

### 1. Formulários e Labels (WCAG 1.3.1, 3.3.2)
- **Problema:** Diversos campos de filtro em `index.html` (Período, Status, Agente) e inputs de busca não possuíam associação programática com seus labels.
- **Correção:** 
  - Adicionado atributo `for` nos labels apontando para o `id` correto dos inputs.
  - Adicionado `aria-label` descritivo em inputs de busca que não possuem label visual.
  - Adicionado `aria-label` e `role="switch"` nos checkboxes personalizados de filtro.

### 2. Estrutura HTML e Semântica (WCAG 1.3.1, 4.1.1)
- **Problema:** A página de Login (`login.html`) possuía elementos HTML (banner de cookies) fora da tag `<body>`, tornando a estrutura inválida.
- **Correção:** 
  - Movido todo o código HTML do sistema de cookies e scripts para dentro do fechamento do `</body>`.

### 3. Modais e Diálogos (WCAG 2.1.2, 2.4.3)
- **Problema:** Modais de alerta, confirmação e cookies eram apenas `divs` estilizadas, sem semântica para leitores de tela.
- **Correção:** 
  - Adicionados atributos `role="dialog"`, `aria-modal="true"` e `aria-labelledby` apontando para o título do modal.
  - Adicionado `aria-label="Fechar modal"` nos botões de fechar (×).

### 4. Elementos Interativos (WCAG 2.1.1, 4.1.2)
- **Problema:** O ícone de "olho" para mostrar senha era um `span` com `onclick`, inalcançável via teclado.
- **Correção:** 
  - Adicionados `role="button"`, `tabindex="0"` e manipulador de teclado (`onkeydown`) para permitir ativação via Enter/Espaço.
  - Adicionado `aria-label="Mostrar senha"` para descrever a ação.

### 5. Contraste e Identificação (WCAG 1.4.3)
- **Problema:** Alguns ícones e textos informativos dependiam apenas de cor ou contexto visual.
- **Correção:** 
  - Adicionados textos alternativos em elementos puramente visuais (loaders, toggles).

---

## 📝 Recomendações Futuras

1. **Gestão de Foco em Modais:** Implementar "focus trapping" via JavaScript para garantir que, ao abrir um modal, o foco do teclado não saia dele até que seja fechado.
2. **Navegação de Teclado:** Testar extensivamente a ordem de tabulação (Tab order) após as mudanças de layout no CSS moderno.
3. **Mecânismo "Pular para Conteúdo":** Adicionar um link oculto (visible on focus) no topo da página para permitir que usuários de teclado pulem o menu de navegação.

---
**Setor de Tecnologia da Informação - CRPRS**
