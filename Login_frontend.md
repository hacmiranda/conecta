# Login_frontend.md — Especificação de Design da Tela de Login (CRPDESK)

> Documento de referência para replicação ou recriação da tela de login do sistema Helpdesk CRPRS.
> Extraído de `frontend/login.html`.

---

## 1. Estrutura Geral (Layout)

A tela é um **card de dois painéis lado a lado** (split layout), centralizado vertical e horizontalmente na viewport.

```
┌──────────────────────────────────────────────────┐
│          FUNDO (imagem + overlay gradiente)       │
│                                                  │
│   ┌──────────────────┬──────────────────────┐    │
│   │  PAINEL ESQUERDO │   PAINEL DIREITO     │    │
│   │  (Identidade     │   (Formulário        │    │
│   │   Visual)        │    de Login)         │    │
│   │   42% largura    │   58% largura (flex) │    │
│   └──────────────────┴──────────────────────┘    │
│                                                  │
└──────────────────────────────────────────────────┘
```

### Card principal (`.login-wrapper`)
| Propriedade | Valor |
|---|---|
| Largura | `900px` (máx: `calc(100vw - 2rem)`) |
| Altura mínima | `560px` |
| Border-radius | `20px` |
| Box-shadow | `0 20px 60px rgba(30,58,138,0.18), 0 8px 24px rgba(0,0,0,0.12)` |
| Overflow | `hidden` |
| Display | `flex` (row) |
| Animação de entrada | `cardEnter` — translateY(32px)→0 + scale(0.96)→1, `0.6s cubic-bezier(0.34,1.56,0.64,1)` |
| Animação de saída | `cardExit` — scale(0.92) + opacity 0, `0.4s ease-in` |

---

## 2. Fundo da Página (`.body`)

| Propriedade | Valor |
|---|---|
| Imagem de fundo | `images/background_login.png` — `cover`, `center center`, `fixed` |
| Overlay (pseudo `::before`) | `linear-gradient(135deg, rgba(10,20,60,0.82) 0%, rgba(30,58,138,0.75) 50%, rgba(15,25,70,0.85) 100%)` |
| Alinhamento | `flexbox` — `align-items: center`, `justify-content: center`, `min-height: 100vh` |

### Orbs decorativos (`.bg-orb`) — efeitos de luz difusa no fundo
| Orb | Tamanho | Cor | Posição | Blur |
|---|---|---|---|---|
| `.bg-orb-1` | 420×420px | `#f79226` (laranja) | top:-100px, left:-100px | 80px |
| `.bg-orb-2` | 350×350px | `#3b82f6` (azul claro) | bottom:-80px, right:-80px | 80px |
| `.bg-orb-3` | 200×200px | `#71aa29` (verde) | center (50%/50%) | 80px |
- Opacidade geral dos orbs: `0.18`
- `pointer-events: none`, `z-index: 0`

---

## 3. Painel Esquerdo — Identidade Visual (`.login-brand`)

| Propriedade | Valor |
|---|---|
| Largura | `flex: 0 0 42%` |
| Background | `linear-gradient(160deg, #1e3a8a 0%, #1a327a 45%, #0f1f5a 100%)` |
| Padding | `3rem 2.5rem` |
| Display | `flex column`, `align-items: center`, `justify-content: center` |
| `overflow` | `hidden` |

**Decorações internas:**
- `::before` — grade sutil: `linear-gradient` com linhas brancas `rgba(255,255,255,0.04)`, `background-size: 36px 36px`
- `::after` — arco circular: `260×260px`, `border-radius: 50%`, `border: 2px solid rgba(247,146,38,0.25)`, posicionado `bottom:-80px, right:-80px`

### Logo (`.brand-logo-wrap img`)
| Propriedade | Valor |
|---|---|
| Arquivo | `images/logo_vazado.png` |
| Largura | `160px` |
| Altura | `auto` |
| Filter | `drop-shadow(0 4px 20px rgba(0,0,0,0.3))` |
| Container min-height | `180px` |
| Margin-bottom | `1.75rem` |

### Título da marca (`.brand-name`)
| Propriedade | Valor |
|---|---|
| Font-size | `1.65rem` |
| Font-weight | `800` |
| Cor | `#ffffff` |
| Cor do `<span>` | `#f79226` (laranja) |
| Letter-spacing | `-0.5px` |
| Line-height | `1.2` |
| Margin-bottom | `0.5rem` |

### Subtítulo (`.brand-subtitle`)
| Propriedade | Valor |
|---|---|
| Font-size | `0.88rem` |
| Font-weight | `400` |
| Cor | `rgba(255,255,255,0.52)` |
| Letter-spacing | `0.3px` |

### Divisor (`.brand-divider`)
| Propriedade | Valor |
|---|---|
| Largura | `48px` |
| Altura | `3px` |
| Background | `linear-gradient(90deg, #f79226, #d97c15)` |
| Border-radius | `2px` |
| Margin | `1.25rem auto` |

### Lista de features (`.brand-features li`)
| Propriedade | Valor |
|---|---|
| Font-size | `0.82rem` |
| Font-weight | `500` |
| Cor | `rgba(255,255,255,0.65)` |
| Gap entre ícone e texto | `0.6rem` |
| Margin-bottom por item | `0.6rem` |
| Ícone | Material Symbols Outlined, `1rem`, cor `#f79226`, `FILL 1, wght 500` |

**Itens da lista:**
1. `check_circle` — Gestão de Tickets em tempo real
2. `notifications_active` — Notificações automáticas por e-mail
3. `lock` — Acesso seguro e autenticado
4. `bar_chart` — Dashboard e relatórios gerenciais

---

## 4. Painel Direito — Formulário (`.login-form-panel`)

| Propriedade | Valor |
|---|---|
| Flex | `1` (ocupa o restante após os 42% do painel esquerdo) |
| Background | `#ffffff` |
| Padding | `3rem 2.75rem` |
| Display | `flex column`, `justify-content: center` |
| `overflow-y` | `auto` |

### Cabeçalho do formulário (`.form-header`)
| Elemento | Font-size | Font-weight | Cor | Outras |
|---|---|---|---|---|
| `h1` "Bem-vindo de volta" | `1.6rem` | `800` | `#111827` | letter-spacing: -0.5px, margin-bottom: 0.3rem |
| `p` "Faça login para acessar o sistema" | `0.88rem` | `400` | `#6b7280` | — |
| Margin-bottom do `.form-header` | — | — | — | `2rem` |

---

## 5. Campos de Input

### Estrutura HTML de cada campo
```
.field-group (margin-bottom: 1.1rem)
  └── label.field-label
  └── .field-input-wrap (position: relative, flex)
       ├── span.material-symbols-outlined.field-icon  ← ícone à esquerda
       ├── input.field-input
       └── button.btn-toggle-pass  ← apenas no campo senha
```

### Label (`.field-label`)
| Propriedade | Valor |
|---|---|
| Font-size | `0.83rem` |
| Font-weight | `600` |
| Cor | `#374151` |
| Margin-bottom | `0.45rem` |
| Letter-spacing | `0.2px` |

### Ícone do campo (`.field-icon`)
| Propriedade | Valor |
|---|---|
| Posição | `absolute left: 12px` |
| Font-size | `1.15rem` |
| Cor padrão | `#6b7280` |
| Cor ao focar | `#3b82f6` |
| `font-variation-settings` | `FILL 0, wght 400` |

### Input (`.field-input`)
| Propriedade | Valor |
|---|---|
| Padding | `0.78rem 2.75rem 0.78rem 2.6rem` |
| Border | `1.5px solid #e5e7eb` |
| Border-radius | `10px` |
| Font-size | `0.92rem` |
| Font-family | `Inter, Arial, sans-serif` |
| Cor do texto | `#111827` |
| Background padrão | `#f9fafb` |
| Box-shadow | `0 1px 3px rgba(0,0,0,0.08)` |
| Placeholder color | `#9ca3af`, font-weight 400 |
| Transition | `border-color, box-shadow, background — 0.22s ease` |
| **Focus** — border | `#3b82f6` |
| **Focus** — background | `#ffffff` |
| **Focus** — box-shadow | `0 0 0 3px rgba(59,130,246,0.15), 0 1px 3px rgba(0,0,0,0.08)` |
| **Erro** — border | `#a8231e` |
| **Erro** focus shadow | `0 0 0 3px rgba(168,35,30,0.12)` |

### Botão toggle de senha (`.btn-toggle-pass`)
| Propriedade | Valor |
|---|---|
| Posição | `absolute right: 10px` |
| Background | `none` |
| Border | `none` |
| Padding | `4px` |
| Border-radius | `6px` |
| Cor padrão | `#6b7280` |
| Cor hover | `#1e3a8a` |
| Background hover | `rgba(30,58,138,0.07)` |
| Focus outline | `2px solid #3b82f6`, offset `2px` |
| Ícone | Material Symbols `visibility` / `visibility_off`, `1.1rem`, `FILL 0, wght 400` |

---

## 6. Link "Esqueci minha senha" (`.link-recuperar`)
| Propriedade | Valor |
|---|---|
| Display | `block` |
| Text-align | `right` |
| Font-size | `0.8rem` |
| Font-weight | `500` |
| Cor | `#f79226` (laranja) |
| Cor hover | `#d97c15` |
| Margin-top | `-0.4rem` |
| Margin-bottom | `1rem` |
| Focus outline | `2px solid #f79226`, offset `2px`, `border-radius: 4px` |

---

## 7. Botão Principal "Entrar" (`.btn-entrar`)
| Propriedade | Valor |
|---|---|
| Width | `100%` |
| Padding | `0.88rem 1.5rem` |
| Background | `linear-gradient(135deg, #1e428a 0%, #1e3a8a 100%)` |
| Cor texto | `#ffffff` |
| Font-size | `0.95rem` |
| Font-weight | `700` |
| Letter-spacing | `0.3px` |
| Border-radius | `10px` |
| Box-shadow | `0 4px 16px rgba(30,58,138,0.35)` |
| Margin-top | `0.5rem` |
| Display | `flex`, `align-items: center`, `justify-content: center`, `gap: 0.5rem` |
| **Hover** — transform | `translateY(-2px)` |
| **Hover** — box-shadow | `0 8px 24px rgba(30,58,138,0.42)` |
| **Hover** — filter | `brightness(1.08)` |
| **Active** — transform | `translateY(0)` |
| **Disabled** — opacity | `0.55`, `cursor: not-allowed` |
| Focus outline | `2px solid #3b82f6`, offset `3px` |
| Ícone | Material Symbols `login`, `1.15rem`, `FILL 1, wght 600` |

---

## 8. Botão Secundário "Voltar ao Login" (`.btn-secundario`)
| Propriedade | Valor |
|---|---|
| Width | `100%` |
| Padding | `0.78rem` |
| Background | `transparent` |
| Border | `1.5px solid #e5e7eb` |
| Border-radius | `10px` |
| Font-size | `0.88rem` |
| Font-weight | `600` |
| Cor | `#374151` |
| Cor hover | `#1e3a8a` |
| Border hover | `#3b82f6` |
| Background hover | `rgba(59,130,246,0.04)` |
| Margin-top | `0.6rem` |

---

## 9. Área Cloudflare Turnstile (`.turnstile-wrap`)
| Propriedade | Valor |
|---|---|
| Margin | `1rem 0` |
| Display | `flex`, `justify-content: center` |
| Conteúdo | Widget Cloudflare Turnstile (tema `light`) |

---

## 10. Alerta de Bloqueio (`#aviso-bloqueio`)
| Propriedade | Valor |
|---|---|
| Background | `linear-gradient(135deg, #a8231e, #c0302a)` |
| Cor texto | `white` |
| Padding | `0.9rem 1rem` |
| Border-radius | `10px` |
| Margin-bottom | `1.25rem` |
| Font-weight | `600` |
| Font-size | `0.85rem` |
| Line-height | `1.5` |
| Display | `flex`, `align-items: center`, `justify-content: center`, `gap: 0.5rem` |

---

## 11. Seção "Recuperar Senha" (`#recuperar-senha`)

Substitui o formulário de login no mesmo painel direito (display none/block toggle).

### Cabeçalho (`.recuperar-header`)
| Propriedade | Valor |
|---|---|
| Display | `flex`, `align-items: center`, `gap: 0.6rem` |
| Margin-bottom | `1.25rem` |
| `h2` font-size | `1.1rem` |
| `h2` font-weight | `700` |
| `h2` cor | `#111827` |
| Ícone | Material Symbols `lock_reset`, `1.3rem`, cor `#f79226`, `FILL 1, wght 600` |

---

## 12. Rodapé do Formulário (`.form-footer`)
| Propriedade | Valor |
|---|---|
| Margin-top | `1.5rem` |
| Padding-top | `1.2rem` |
| Border-top | `1px solid #e5e7eb` |
| Text-align | `center` |
| Font-size | `0.78rem` |
| Cor | `#6b7280` |
| Line-height | `1.6` |
| Links hover | cor `#f79226`, `text-decoration: underline` |
| Links focus | `outline: 2px solid #f79226`, offset `2px`, `border-radius: 3px` |
| `.footer-links` | `display: flex`, `justify-content: center`, `gap: 1.2rem`, `margin-top: 0.4rem` |

---

## 13. Modal de Alerta (`.modal`)
| Propriedade | Valor |
|---|---|
| Background | `white` |
| Border-radius | `14px` |
| Box-shadow | `0 20px 60px rgba(0,0,0,0.3)` |
| Max-width | `400px` |
| Width | `90%` |
| Animação de entrada | `modalEnter` — scale(0.92)+translateY(-10px)→1+0, `0.25s ease` |
| Overlay background | `rgba(0,0,0,0.55)` com `backdrop-filter: blur(3px)` |

#### Modal Header (`.modal-header`)
| Estado | Background |
|---|---|
| Padrão | `#f79226` (laranja) |
| Sucesso | `#71aa29` (verde) |
| Erro | `#a8231e` (vermelho) |
| Padding | `1rem 1.25rem` |
| `h3` font-size | `1rem`, font-weight `700`, cor `white` |

#### Modal Body
| Propriedade | Valor |
|---|---|
| Padding | `1.25rem 1.5rem` |
| `p` font-size | `0.9rem` |
| `p` cor | `#374151` |
| Line-height | `1.55` |

#### Modal Footer
| Propriedade | Valor |
|---|---|
| Padding | `0.85rem 1.5rem` |
| Border-top | `1px solid #f0f0f0` |
| Display | `flex`, `justify-content: flex-end` |

#### Botão OK (`.btn-primary`)
| Propriedade | Valor |
|---|---|
| Background | `linear-gradient(135deg, #71aa29 0%, #5a8a20 100%)` |
| Cor | `white` |
| Padding | `0.6rem 1.4rem` |
| Border-radius | `8px` |
| Font-size | `0.88rem` |
| Font-weight | `600` |

---

## 14. Paleta de Cores

| Variável | Valor | Uso |
|---|---|---|
| `--laranja` | `#f79226` | CTA secundário, ícones, destaques |
| `--laranja-dark` | `#d97c15` | Hover do laranja |
| `--vermelho` | `#a8231e` | Erros, bloqueio |
| `--azul` | `#1e3a8a` | Cor primária, header, botão |
| `--azul-mid` | `#1e428a` | Gradiente do botão |
| `--azul-light` | `#3b82f6` | Focus rings, hover |
| `--azul-pale` | `#dbeafe` | Backgrounds suaves |
| `--verde` | `#71aa29` | Sucesso, modal sucesso |
| `--branco` | `#ffffff` | Painel direito, textos em fundo escuro |
| `--cinza-bg` | `#f0f4ff` | Background claro |
| `--texto-escuro` | `#111827` | Títulos, valores |
| `--texto-medio` | `#374151` | Labels, corpo |
| `--texto-claro` | `#6b7280` | Placeholders, subtítulos, rodapé |
| `--borda` | `#e5e7eb` | Bordas de inputs |

---

## 15. Tipografia

| Propriedade | Valor |
|---|---|
| Fonte principal | **Inter** (Google Fonts) — pesos: 400, 500, 600, 700, 800 |
| Fallback | `Arial, sans-serif` |
| Renderização | `-webkit-font-smoothing: antialiased` |
| Ícones | **Material Symbols Outlined** (Google Fonts), variáveis: `opsz 20..48`, `wght 100..700`, `FILL 0..1`, `GRAD -50..200` |

---

## 16. Dimensões e Espaçamentos Resumidos

| Elemento | Valor |
|---|---|
| Border-radius padrão (card) | `20px` |
| Border-radius inputs/botões | `10px` |
| Border-radius modal | `14px` |
| Transição padrão | `0.22s ease` |
| Padding painel esquerdo | `3rem 2.5rem` |
| Padding painel direito | `3rem 2.75rem` |
| Largura do card | `900px` |
| Altura mínima do card | `560px` |
| Logo | `160px` de largura |

---

## 17. Responsividade (Breakpoint `≤ 680px`)

| Alteração | Valor mobile |
|---|---|
| Layout | `flex-direction: column` (painéis empilhados) |
| Border-radius do card | `16px` |
| Max-width | `calc(100vw - 1.5rem)` |
| Padding painel esquerdo | `2rem 1.5rem 1.5rem` |
| Padding painel direito | `2rem 1.5rem 1.75rem` |
| `.brand-features` | `display: none` (oculta a lista de recursos) |
| `.login-brand` | `flex: 0 0 auto` |

---

## 18. Acessibilidade

| Requisito | Implementação |
|---|---|
| `lang` | `<html lang="pt-BR">` |
| Semântica | `<aside>` (painel esquerdo), `<main>` (painel direito), `<footer>`, `<nav>` |
| Labels | Todos os `<input>` têm `<label for>` associado |
| Focus visible | `outline: 2px solid` em todos botões, inputs e links — nunca removido |
| Aria | `aria-required`, `aria-label`, `aria-hidden` nos ícones decorativos |
| Modal | `role="dialog"`, `aria-modal="true"`, `aria-labelledby` |
| Alertas | `role="alert"` implícito pela modal |
| Movimento reduzido | `@media (prefers-reduced-motion: reduce)` — desativa todas animações |
| Fechar ESC | Modal fecha com tecla ESC |

---

## 19. Animações

| Animação | Duração | Efeito |
|---|---|---|
| `cardEnter` (card aparece) | `0.6s cubic-bezier(0.34,1.56,0.64,1)` | translateY(32px)→0 + scale(0.96→1) + opacity |
| `cardExit` (redirect após login) | `0.4s ease-in` | scale(0.92) + opacity→0 |
| `modalEnter` (modal abre) | `0.25s ease` | scale(0.92)+translateY(-10px)→normal |
| Hover botão entrar | `0.22s ease` | translateY(-2px) + sombra maior |
| Focus inputs | `0.22s ease` | border-color + box-shadow |

---

## 20. Comportamentos Funcionais (UX)

- **Bloqueio por tentativas:** após 5 erros → formulário desabilitado por 5 minutos, contador regressivo visível
- **Toggle de senha:** botão dentro do campo alterna `type=password/text`
- **Turnstile CAPTCHA:** visível apenas em produção; localhost faz bypass automático
- **Transição de seções:** "Esqueci minha senha" troca o `<form>` de login pela `<section>` de recuperação via display none/block
- **Animação de bloqueio:** logo cai e é substituído por imagem `blocked.png` durante o período de bloqueio
- **Saindo:** ao fazer login com sucesso, o card recebe classe `.saindo` (fade out) antes de redirecionar
