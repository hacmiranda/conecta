---
trigger: always_on
---
# Acessibilidade WCAG 2.2 

## Princípios Fundamentais

### 1. Perceptível
Informações e componentes da interface devem ser apresentados de forma que os usuários possam percebê-los.

### 2. Operável
Componentes de interface e navegação devem ser operáveis.

### 3. Compreensível
Informações e operação da interface devem ser compreensíveis.

### 4. Robusto
Conteúdo deve ser robusto o suficiente para ser interpretado por tecnologias assistivas.

## HTML Semântico

### ✅ SEMPRE Usar
```html
<!-- Estrutura semântica -->
<header>
<nav>
<main>
<article>
<section>
<aside>
<footer>

<!-- Headings hierárquicos -->
<h1>Título Principal</h1>
<h2>Subtítulo</h2>
<h3>Seção</h3>
```

### ❌ NUNCA
```html
<!-- Não usar divs genéricas para tudo -->
<div class="header">
<div class="navigation">
```

## Contraste de Cores

### Requisitos Mínimos (WCAG AA)
- **Texto normal:** Contraste mínimo de 4.5:1
- **Texto grande (18pt+):** Contraste mínimo de 3:1
- **Componentes UI:** Contraste mínimo de 3:1

### Verificação
```css
/* ✅ BOM - Texto branco (#ffffff) em fundo escuro (#0f172a) */
/* Contraste: 15.52:1 */

/* ✅ BOM - Cyan (#00d4ff) em fundo escuro (#020617) */
/* Contraste: 8.2:1 */
```

## Formulários

### ✅ Labels Obrigatórios
```html
<!-- SEMPRE associar label com input -->
<label for="nome">Nome:</label>
<input type="text" id="nome" name="nome" required>

<!-- Mensagens de erro claras -->
<span class="error" role="alert" aria-live="polite">
    Campo obrigatório
</span>
```

### Atributos ARIA
```html
<input 
    type="email" 
    id="email"
    aria-required="true"
    aria-invalid="false"
    aria-describedby="email-error"
>
<span id="email-error" class="error"></span>
```

## Navegação por Teclado

### ✅ SEMPRE Garantir
- Todos os elementos interativos acessíveis via Tab
- Ordem de foco lógica
- Indicador de foco visível
- Atalhos de teclado quando apropriado

### CSS para Foco
```css
/* ✅ SEMPRE ter indicador de foco visível */
button:focus,
a:focus,
input:focus {
    outline: 2px solid #00d4ff;
    outline-offset: 2px;
}

/* ❌ NUNCA remover outline sem substituir */
/* outline: none; */ /* PROIBIDO sem alternativa */
```

## Imagens

### ✅ Alt Text Obrigatório
```html
<!-- Imagem informativa -->
<img src="logo.png" alt="Logo CRPRS - Conselho Regional de Psicologia">

<!-- Imagem decorativa -->
<img src="decoration.png" alt="" role="presentation">

<!-- Ícones com significado -->
<i class="fas fa-download" aria-hidden="true"></i>
<span class="sr-only">Baixar arquivo</span>
```

## Botões e Links

### ✅ Texto Descritivo
```html
<!-- ✅ BOM -->
<button>Baixar PDF Anonimizado</button>
<a href="index.php">Voltar para página inicial</a>

<!-- ❌ RUIM -->
<button>Clique aqui</button>
<a href="index.php">Voltar</a>
```

### ARIA para Ícones
```html
<button aria-label="Fechar modal">
    <i class="fas fa-times" aria-hidden="true"></i>
</button>
```

## Modais e Diálogos

### ✅ Implementação Acessível
```html
<div 
    role="dialog" 
    aria-modal="true"
    aria-labelledby="modal-title"
    aria-describedby="modal-description"
>
    <h2 id="modal-title">PDF Anonimizado com Sucesso</h2>
    <p id="modal-description">Seu PDF foi processado...</p>
    
    <button aria-label="Fechar modal">
        <i class="fas fa-times" aria-hidden="true"></i>
    </button>
</div>
```

### JavaScript
```javascript
// Gerenciar foco ao abrir modal
modal.addEventListener('open', () => {
    previousFocus = document.activeElement;
    modal.querySelector('button').focus();
});

// Restaurar foco ao fechar
modal.addEventListener('close', () => {
    previousFocus.focus();
});

// Fechar com ESC
document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape' && modal.open) {
        modal.close();
    }
});
```

## Tabelas

### ✅ Estrutura Semântica
```html
<table>
    <caption>Lista de usuários do sistema</caption>
    <thead>
        <tr>
            <th scope="col">Nome</th>
            <th scope="col">Email</th>
            <th scope="col">Ações</th>
        </tr>
    </thead>
    <tbody>
        <tr>
            <td>João Silva</td>
            <td>joao@email.com</td>
            <td>
                <button aria-label="Editar João Silva">Editar</button>
            </td>
        </tr>
    </tbody>
</table>
```

## Mensagens de Status

### ✅ ARIA Live Regions
```html
<!-- Mensagens de sucesso/erro -->
<div role="status" aria-live="polite" aria-atomic="true">
    Arquivo enviado com sucesso
</div>

<!-- Alertas urgentes -->
<div role="alert" aria-live="assertive">
    Erro ao processar arquivo
</div>
```

## Animações

### ✅ Respeitar Preferências
```css
/* Reduzir animações para usuários sensíveis */
@media (prefers-reduced-motion: reduce) {
    * {
        animation-duration: 0.01ms !important;
        animation-iteration-count: 1 !important;
        transition-duration: 0.01ms !important;
    }
}
```

## Idioma

### ✅ SEMPRE Declarar
```html
<html lang="pt-BR">

<!-- Trechos em outro idioma -->
<p>O termo <span lang="en">design tokens</span> refere-se a...</p>
```

## Leitores de Tela

### Classes Utilitárias
```css
/* Texto visível apenas para leitores de tela */
.sr-only {
    position: absolute;
    width: 1px;
    height: 1px;
    padding: 0;
    margin: -1px;
    overflow: hidden;
    clip: rect(0, 0, 0, 0);
    white-space: nowrap;
    border: 0;
}
```

## Checklist de Verificação

### Antes de Cada Deploy
- [ ] Todas as imagens têm alt text
- [ ] Formulários têm labels associados
- [ ] Contraste de cores adequado (4.5:1 mínimo)
- [ ] Navegação por teclado funciona
- [ ] Indicadores de foco visíveis
- [ ] Headings em ordem hierárquica
- [ ] ARIA labels em ícones e botões
- [ ] Modais gerenciam foco corretamente
- [ ] Mensagens de erro são anunciadas
- [ ] Idioma declarado no HTML
- [ ] Animações respeitam prefers-reduced-motion
- [ ] Testado com leitor de tela (NVDA/JAWS)

## Ferramentas de Teste

### Recomendadas
- **axe DevTools** - Extensão do navegador
- **WAVE** - Avaliação de acessibilidade web
- **Lighthouse** - Auditoria do Chrome DevTools
- **NVDA** - Leitor de tela gratuito (Windows)
- **Color Contrast Analyzer** - Verificação de contraste
