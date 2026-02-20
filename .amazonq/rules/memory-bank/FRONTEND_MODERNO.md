# REGRAS DE FRONTEND (DESIGN MODERNO)

> **STATUS: ATIVO E OBRIGATÓRIO (Baseado em RELATORIO_DESIGN_MODERNO.md)**
> **ESCOPO: CSS, JS, HTML, Design System**

## 1. Princípios de Identidade Visual
O design deve refletir a Modernização (2025) mantendo a seriedade institucional do CRPRS.

### ✅ Aprovado (Design)
- **Tipografia**: OBRIGATÓRIO usar **Inter** (Google Fonts).
- **Cores**: Use as variáveis CSS definidas no projeto (`modern-enhancements.css`).
  - `--brand-500` (Laranja/Primário)
  - `--text-secondary` (Cinza neutro)
  - `--bg-card` (Fundo de cards)
- **Modo Escuro**: Compatibilidade obrigatória.
- **Interatividade**: Ripple effect, hover states e transições suaves (`--transition-fast`).

### 🚫 Proibido (Anti-Padrões)
- **Fontes**: Arial, Times, Comic Sans.
- **Cores**: Roxo/Violeta (clichê de IA), cores muito gritantes.
- **Layout**: Elementos desalinhados ou sem espaçamento consistente.
- **Animações**: Pesadas ou demoradas. Use transições curtas.

## 2. Componentes Críticos
- **Cards**: Use `--shadow-sm` ou `--shadow-md`. Borda arredondada `--radius-lg`.
- **Botões**:
  - Primário: Sólido, `--brand-500`, border radius `--radius-md`.
  - Hover: Efeito de elevação ou feedback visual.
- **Modais**: Devem ter *backdrop blur* e animação de *scale/fade-in*.
- **Listas**: Espaçamento vertical consistente e hover em items.

## 3. Estrutura CSS (Tokens)
Use sempre que possível as variáveis globais de CSS para garantir consistência:

```css
:root {
    --shadow-sm: 0 ...;
    --shadow-md: 0 ...;
    --radius-sm: 6px;
    --radius-md: 10px;
    --transition-fast: 0.15s;
    /* ... outras do modern-enhancements.css ... */
}
```

## 4. Rodapé e Cookies (Footer & GDPR)
- Seguir especificações de `FOOTER_SPEC.md`.
- Incluir `cookie-manager.js` ou lógica similar para GDPR.
- Link para "Gerenciar Preferências" obrigatório.

## 5. JavaScript (Frontend)
- Prefira **Vanilla JS** limpo e modular.
- Evite adicionar frameworks pesados (React/Angular) se o core for HTML/JS puro.
- Trate sempre erros de fetch/ajax com notificações visuais (Toasts/Alerts).
