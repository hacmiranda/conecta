/**
 * Tela de login: constelação sobre o fundo (17/11/2026). Pontos que flutuam devagar e se ligam por linhas quando
 * estão perto; perto do mouse as ligações ficam mais fortes. Cores da marca (branco, laranja e verde). Para quando a aba
 * está escondida; com "menos movimento" o desenho fica parado. Canvas #constelacao (estilo em login-animacao.css).
 */
(function () {
    'use strict';
    const canvas = document.getElementById('constelacao');
    if (!canvas || !canvas.getContext) return;
    const ctx = canvas.getContext('2d');
    const parado = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    const DISTANCIA = 140;          // até onde dois pontos se ligam (px)
    const RAIO_MOUSE = 180;         // alcance do mouse (px)
    const CORES = {
        branco: 'rgba(255, 255, 255, 0.8)',
        laranja: 'rgba(248, 147, 39, 0.9)',   // #F89327
        verde: 'rgba(112, 171, 42, 0.95)',    // #70AB2A
    };
    let pontos = [];
    let largura = 0;
    let altura = 0;
    let mouse = null;
    let quadro = null;

    function criarPontos() {
        const dpr = window.devicePixelRatio || 1;
        largura = window.innerWidth;
        altura = window.innerHeight;
        canvas.width = largura * dpr;    // tamanho do desenho; o tamanho na tela vem do CSS
        canvas.height = altura * dpr;
        ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
        const quantidade = Math.min(120, Math.round((largura * altura) / 11000));
        pontos = Array.from({ length: quantidade }, () => ({
            x: Math.random() * largura,
            y: Math.random() * altura,
            vx: (Math.random() - 0.5) * 0.35,
            vy: (Math.random() - 0.5) * 0.35,
            r: Math.random() * 1.6 + 0.8,
            // Cores oficiais do CRPRS: laranja e verde entre os pontos brancos
            cor: (() => { const sorteio = Math.random(); return sorteio < 0.18 ? 'laranja' : sorteio < 0.36 ? 'verde' : 'branco'; })(),
        }));
    }

    function desenhar() {
        ctx.clearRect(0, 0, largura, altura);
        for (let i = 0; i < pontos.length; i++) {
            const a = pontos[i];
            for (let j = i + 1; j < pontos.length; j++) {
                const b = pontos[j];
                const dx = a.x - b.x;
                const dy = a.y - b.y;
                const d = Math.sqrt(dx * dx + dy * dy);
                if (d < DISTANCIA) {
                    let alfa = (1 - d / DISTANCIA) * 0.35;
                    if (mouse) {
                        const dm = Math.hypot((a.x + b.x) / 2 - mouse.x, (a.y + b.y) / 2 - mouse.y);
                        if (dm < RAIO_MOUSE) alfa += (1 - dm / RAIO_MOUSE) * 0.4;
                    }
                    ctx.strokeStyle = `rgba(255, 255, 255, ${alfa.toFixed(3)})`;
                    ctx.lineWidth = 0.8;
                    ctx.beginPath();
                    ctx.moveTo(a.x, a.y);
                    ctx.lineTo(b.x, b.y);
                    ctx.stroke();
                }
            }
            // Ligação ao mouse
            if (mouse) {
                const dm = Math.hypot(a.x - mouse.x, a.y - mouse.y);
                if (dm < RAIO_MOUSE) {
                    ctx.strokeStyle = `rgba(248, 147, 39, ${((1 - dm / RAIO_MOUSE) * 0.55).toFixed(3)})`;
                    ctx.lineWidth = 1;
                    ctx.beginPath();
                    ctx.moveTo(a.x, a.y);
                    ctx.lineTo(mouse.x, mouse.y);
                    ctx.stroke();
                }
            }
        }
        pontos.forEach(p => {
            ctx.beginPath();
            ctx.arc(p.x, p.y, p.r, 0, Math.PI * 2);
            ctx.fillStyle = CORES[p.cor];
            ctx.fill();
        });
    }

    function mover() {
        pontos.forEach(p => {
            p.x += p.vx;
            p.y += p.vy;
            if (p.x < 0 || p.x > largura) p.vx *= -1;
            if (p.y < 0 || p.y > altura) p.vy *= -1;
        });
    }

    function passo() {
        mover();
        desenhar();
        quadro = requestAnimationFrame(passo);
    }

    function iniciar() {
        cancelAnimationFrame(quadro);
        criarPontos();
        if (parado) desenhar(); else passo();
    }

    window.addEventListener('resize', () => {
        clearTimeout(iniciar.t);
        iniciar.t = setTimeout(iniciar, 200);
    });
    window.addEventListener('mousemove', (e) => { mouse = { x: e.clientX, y: e.clientY }; if (parado) desenhar(); });
    window.addEventListener('mouseout', (e) => { if (!e.relatedTarget) { mouse = null; if (parado) desenhar(); } });
    document.addEventListener('visibilitychange', () => {
        if (parado) return;
        if (document.hidden) cancelAnimationFrame(quadro); else passo();
    });
    iniciar();
})();
