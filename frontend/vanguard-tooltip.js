/**
 * Vanguard Tooltip Coordinator
 * Padrão: Singleton DOM Element (Injeta o modal apenas uma vez na página para não vazar RAM)
 */
class VanguardTooltip {
    constructor() {
        this.tooltipEl = null;
        this.activeTarget = null;
        this.init();
    }

    init() {
        // Criar o contêiner mestre do Tooltip vazio
        this.tooltipEl = document.createElement('div');
        this.tooltipEl.className = 'vanguard-tooltip';
        this.tooltipEl.setAttribute('role', 'tooltip');
        this.tooltipEl.setAttribute('aria-hidden', 'true');
        document.body.appendChild(this.tooltipEl);

        // Bind Events em elementos alvo
        this.bindEvents();
    }

    bindEvents() {
        // Observer para capturar tooltips inseridos dinamicamente (Ajax/Render React)
        const observer = new MutationObserver(() => {
            this.attachListeners();
        });
        observer.observe(document.body, { childList: true, subtree: true });

        // Bind inicial
        this.attachListeners();

        // Rolagem esconde o Tooltip para não quebrar coordenadas fixas na tela
        window.addEventListener('scroll', () => this.hide(), { passive: true });
    }

    attachListeners() {
        const triggers = document.querySelectorAll('[data-vg-tooltip]:not([data-vg-bound])');
        triggers.forEach(el => {
            // Marcar como bound
            el.setAttribute('data-vg-bound', 'true');

            // Garantir que é focável pelo teclado (WCAG AA) se nao tiver
            if (el.tabIndex === -1 && !el.hasAttribute('disabled')) {
                el.setAttribute('tabindex', '0');
            }

            // Mouse Events
            el.addEventListener('mouseenter', (e) => this.show(e.target));
            el.addEventListener('mouseleave', () => this.hide());

            // Focus Events (Acessibilidade Leitor de Tela / Teclado)
            el.addEventListener('focus', (e) => this.show(e.target));
            el.addEventListener('blur', () => this.hide());
        });
    }

    show(target) {
        this.activeTarget = target;
        const texto = target.getAttribute('data-vg-tooltip');
        const titulo = target.getAttribute('data-vg-title') || 'INFORMAÇÃO';
        const rawIcon = target.getAttribute('data-vg-icon');

        if (!texto) return;

        // Ícone Default Minimalista SVG se nenhum for passado
        const svgIcon = rawIcon || `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="square"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"></polygon></svg>`;

        // Render Brutalista
        this.tooltipEl.innerHTML = `
            <div class="vg-header">
                <div class="vg-icon">${svgIcon}</div>
                <span class="vg-title"></span>
            </div>
            <div class="vg-body"></div>
        `;
        // Título e texto vêm de atributos (podem conter dados de usuários): sempre como texto
        this.tooltipEl.querySelector('.vg-title').textContent = titulo;
        this.tooltipEl.querySelector('.vg-body').textContent = texto;

        this.calculatePosition(target);

        // Disparar Animação
        requestAnimationFrame(() => {
            this.tooltipEl.classList.add('vg-active');
            this.tooltipEl.setAttribute('aria-hidden', 'false');
        });
    }

    hide() {
        if (!this.activeTarget) return;
        this.tooltipEl.classList.remove('vg-active');
        this.tooltipEl.setAttribute('aria-hidden', 'true');
        this.activeTarget = null;
    }

    calculatePosition(target) {
        const rect = target.getBoundingClientRect();

        // Temporariamente mostrar (opacity 0) para pegar altura
        this.tooltipEl.style.opacity = '0';
        this.tooltipEl.classList.add('vg-active');
        const tooltipRect = this.tooltipEl.getBoundingClientRect();
        this.tooltipEl.classList.remove('vg-active');
        this.tooltipEl.style.opacity = '';

        // Posição base acima (Top).
        let top = rect.top - tooltipRect.height - 12; // 12px de respiro paramétrico

        // Tensão Assimétrica: amarramos o bico ao 1º terço esquerdo
        let left = rect.left - 10;

        // Limpeza de flip
        this.tooltipEl.classList.remove('vg-flipped');

        // Detecção de borda (Evita que o tooltip estoure a tela se tentar abrir perto do topo/bordas)
        if (top < 0) {
            top = rect.bottom + 12; // Joga pra baixo se faltar espaço em cima
            this.tooltipEl.style.transformOrigin = 'top left'; // Inverte física Bezier
            this.tooltipEl.classList.add('vg-flipped'); // Setinha para cima
        } else {
            this.tooltipEl.style.transformOrigin = 'bottom left';
        }

        if (left + tooltipRect.width > window.innerWidth) {
            left = window.innerWidth - tooltipRect.width - 20;
        }

        this.tooltipEl.style.top = `${top}px`;
        this.tooltipEl.style.left = `${left}px`;
    }
}

// Inicia a engenharia assim que a árvore carregar
document.addEventListener('DOMContentLoaded', () => {
    window.TooltipSystem = new VanguardTooltip();
});
