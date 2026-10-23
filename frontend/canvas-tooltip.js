/**
 * CanvasTooltip — Singleton para tooltips em elementos <canvas>
 * Uso: CanvasTooltip.show(mouseEvent, htmlConteudo)
 *       CanvasTooltip.hide()
 */
const CanvasTooltip = (() => {
    let el = null;

    function init() {
        el = document.createElement('div');
        el.id = 'canvas-tooltip';
        el.setAttribute('role', 'tooltip');
        el.setAttribute('aria-hidden', 'true');
        el.style.cssText = [
            'position:fixed',
            'z-index:9999',
            'pointer-events:none',
            'background:#1f1a18',
            'border:1px solid rgba(255,255,255,0.1)',
            'border-radius:8px',
            'padding:8px 12px',
            'font:13px/1.5 Inter,Arial,sans-serif',
            'color:#ddd6cd',
            'box-shadow:0 4px 20px rgba(0,0,0,0.5)',
            'opacity:0',
            'transform:translateY(4px)',
            'transition:opacity 0.15s ease,transform 0.15s ease',
            'max-width:220px',
            'white-space:nowrap',
        ].join(';');
        document.body.appendChild(el);
    }

    function show(e, html) {
        if (!el) init();
        el.innerHTML = html;
        el.setAttribute('aria-hidden', 'false');

        // Posicionar perto do cursor
        _posicionar(e.clientX, e.clientY);

        el.style.opacity = '1';
        el.style.transform = 'translateY(0)';
    }

    function move(e) {
        if (!el || el.style.opacity === '0') return;
        _posicionar(e.clientX, e.clientY);
    }

    function hide() {
        if (!el) return;
        el.style.opacity = '0';
        el.style.transform = 'translateY(4px)';
        el.setAttribute('aria-hidden', 'true');
    }

    function _posicionar(cx, cy) {
        const offset = 14;
        const W = window.innerWidth;
        const H = window.innerHeight;
        // Medir dimensões após conteúdo injetado
        el.style.left = '0px';
        el.style.top = '0px';
        const tw = el.offsetWidth;
        const th = el.offsetHeight;

        let left = cx + offset;
        let top  = cy - th / 2;

        // Evita sair pela direita
        if (left + tw > W - 8) left = cx - tw - offset;
        // Evita sair por baixo / cima
        if (top + th > H - 8) top = H - th - 8;
        if (top < 8) top = 8;

        el.style.left = `${left}px`;
        el.style.top  = `${top}px`;
    }

    return { show, move, hide };
})();

// Garantir acessibilidade global em qualquer escopo
window.CanvasTooltip = CanvasTooltip;
