// Gráficos adicionais do dashboard

function criarGraficoAgente(dados) {
    const canvas = document.getElementById('grafico-agente');
    if (!canvas) return;

    const observer = new IntersectionObserver((entries) => {
        entries.forEach(entry => {
            if (entry.isIntersecting) {
                const ctx = canvas.getContext('2d');
                const agentes = dados.por_agente || [];
                const cores = ['#2797e1', '#c52ee7', '#39dd52', '#d7e744', '#e56b46', '#e62c45'];
                const total = agentes.reduce((sum, a) => sum + parseInt(a.total), 0);
                const centerX = canvas.width / 2;
                const centerY = canvas.height / 2;
                const raio = 80;
                const raioInterno = 44;

                ctx.clearRect(0, 0, canvas.width, canvas.height);

                function desenharBuracoRosca() {
                    ctx.fillStyle = '#0d1117';
                    ctx.beginPath();
                    ctx.arc(centerX, centerY, raioInterno, 0, Math.PI * 2);
                    ctx.fill();
                }

                let fatiaIndex = 0;
                const fatias = [];
                let anguloAcumulado = 0;

                agentes.forEach((agente, i) => {
                    const angulo = (agente.total / total) * 2 * Math.PI;
                    fatias.push({
                        agente: agente,
                        anguloInicio: anguloAcumulado,
                        anguloFim: anguloAcumulado + angulo,
                        cor: cores[i % cores.length]
                    });
                    anguloAcumulado += angulo;
                });

                function desenharProximaFatia() {
                    if (fatiaIndex >= fatias.length) return;

                    const fatia = fatias[fatiaIndex];
                    let anguloAtual = fatia.anguloInicio;
                    const incremento = (fatia.anguloFim - fatia.anguloInicio) / 30;

                    const animacao = setInterval(() => {
                        anguloAtual += incremento;
                        if (anguloAtual >= fatia.anguloFim) {
                            anguloAtual = fatia.anguloFim;
                            clearInterval(animacao);
                            fatiaIndex++;
                            setTimeout(desenharProximaFatia, 100);
                        }

                        ctx.clearRect(0, 0, canvas.width, canvas.height);

                        for (let i = 0; i < fatiaIndex; i++) {
                            ctx.fillStyle = fatias[i].cor;
                            ctx.beginPath();
                            ctx.moveTo(centerX, centerY);
                            ctx.arc(centerX, centerY, raio, fatias[i].anguloInicio, fatias[i].anguloFim);
                            ctx.closePath();
                            ctx.fill();
                        }

                        ctx.fillStyle = fatia.cor;
                        ctx.beginPath();
                        ctx.moveTo(centerX, centerY);
                        ctx.arc(centerX, centerY, raio, fatia.anguloInicio, anguloAtual);
                        ctx.closePath();
                        ctx.fill();

                        desenharBuracoRosca();

                        for (let i = 0; i < fatiaIndex; i++) {
                            const labelAngulo = fatias[i].anguloInicio + (fatias[i].anguloFim - fatias[i].anguloInicio) / 2;
                            const labelX = centerX + Math.cos(labelAngulo) * (raio + 15);
                            const labelY = centerY + Math.sin(labelAngulo) * (raio + 15);

                            ctx.fillStyle = '#94a3b8';
                            ctx.font = 'bold 14px Arial';

                            const anguloGraus = (labelAngulo * 180 / Math.PI) % 360;
                            if (anguloGraus > 80 && anguloGraus < 100) {
                                ctx.textAlign = 'center';
                            } else if (anguloGraus > 260 && anguloGraus < 280) {
                                ctx.textAlign = 'center';
                            } else if (anguloGraus >= 90 && anguloGraus <= 270) {
                                ctx.textAlign = 'right';
                            } else {
                                ctx.textAlign = 'left';
                            }

                            ctx.fillText(`${fatias[i].agente.nome}: ${fatias[i].agente.total}`, labelX, labelY);
                        }
                    }, 20);
                }

                desenharProximaFatia();
                observer.unobserve(canvas);

                // Tooltip: hit-detection por ângulo e raio
                canvas.addEventListener('mousemove', function(e) {
                    const rect = canvas.getBoundingClientRect();
                    const scaleX = canvas.width  / rect.width;
                    const scaleY = canvas.height / rect.height;
                    const mx = (e.clientX - rect.left) * scaleX;
                    const my = (e.clientY - rect.top)  * scaleY;
                    const dx = mx - centerX;
                    const dy = my - centerY;
                    const dist = Math.sqrt(dx * dx + dy * dy);

                    if (dist < raioInterno || dist > raio) { CanvasTooltip.hide(); return; }

                    let angulo = Math.atan2(dy, dx);
                    if (angulo < 0) angulo += 2 * Math.PI;

                    const fatia = fatias.find(f => {
                        let ini = f.anguloInicio < 0 ? f.anguloInicio + 2 * Math.PI : f.anguloInicio;
                        let fim = f.anguloFim   < 0 ? f.anguloFim   + 2 * Math.PI : f.anguloFim;
                        return angulo >= ini && angulo <= fim;
                    });
                    if (!fatia) { CanvasTooltip.hide(); return; }

                    const pct = Math.round((parseInt(fatia.agente.total) / total) * 100);
                    CanvasTooltip.show(e,
                        `<strong style="color:${fatia.cor}">${fatia.agente.nome}</strong><br>` +
                        `Tickets: <strong>${fatia.agente.total}</strong><br>` +
                        `Percentual: <strong>${pct}%</strong>`
                    );
                });
                canvas.addEventListener('mouseleave', () => CanvasTooltip.hide());
            }
        });
    });
    observer.observe(canvas);
}

function criarGraficoResolucao(dados) {
    const canvas = document.getElementById('grafico-resolucao');
    if (!canvas) return;

    const observer = new IntersectionObserver((entries) => {
        entries.forEach(entry => {
            if (entry.isIntersecting) {
                const ctx = canvas.getContext('2d');
                const abertos = dados.taxa_resolucao?.abertos || 0;
                const fechados = dados.taxa_resolucao?.fechados || 0;
                const valores = [abertos, fechados];
                const labels = ['Abertos', 'Fechados'];
                const cores = ['#e56b46', '#39dd52'];
                const totalResolucao = (abertos + fechados) || 1;
                const maxValor = Math.max(...valores) || 1;
                const alturaBarra = 40;
                const espacamento = 30;
                const margemEsquerda = 100;

                // Mapa de barras para hit-detection
                const barrasResolucao = valores.map((valor, i) => ({
                    label: labels[i],
                    cor: cores[i],
                    valor,
                    x: margemEsquerda,
                    y: espacamento + i * (alturaBarra + espacamento),
                    largura: (valor / maxValor) * (canvas.width - margemEsquerda - 60),
                    altura: alturaBarra
                }));

                ctx.clearRect(0, 0, canvas.width, canvas.height);
                valores.forEach((valor, i) => {
                    const larguraFinal = barrasResolucao[i].largura;
                    const y = barrasResolucao[i].y;

                    ctx.fillStyle = '#94a3b8';
                    ctx.font = '14px Arial';
                    ctx.textAlign = 'right';
                    ctx.fillText(labels[i], margemEsquerda - 10, y + alturaBarra / 2 + 5);

                    let larguraAtual = 0;
                    const animacao = setInterval(() => {
                        ctx.clearRect(margemEsquerda, y, canvas.width - margemEsquerda, alturaBarra);
                        larguraAtual += larguraFinal / 50;
                        if (larguraAtual >= larguraFinal) {
                            larguraAtual = larguraFinal;
                            clearInterval(animacao);
                        }
                        ctx.fillStyle = cores[i];
                        ctx.fillRect(margemEsquerda, y, larguraAtual, alturaBarra);
                        ctx.fillStyle = '#94a3b8';
                        ctx.font = 'bold 14px Arial';
                        ctx.textAlign = 'left';
                        ctx.fillText(valor, margemEsquerda + larguraAtual + 5, y + alturaBarra / 2 + 5);
                    }, 20);
                });

                // Tooltip
                canvas.addEventListener('mousemove', function(e) {
                    const rect = canvas.getBoundingClientRect();
                    const scaleX = canvas.width  / rect.width;
                    const scaleY = canvas.height / rect.height;
                    const mx = (e.clientX - rect.left) * scaleX;
                    const my = (e.clientY - rect.top)  * scaleY;

                    const barra = barrasResolucao.find(b =>
                        mx >= b.x && mx <= b.x + b.largura &&
                        my >= b.y && my <= b.y + b.altura
                    );
                    if (!barra) { CanvasTooltip.hide(); return; }

                    const pct = Math.round((barra.valor / totalResolucao) * 100);
                    CanvasTooltip.show(e,
                        `<strong style="color:${barra.cor}">${barra.label}</strong><br>` +
                        `Tickets: <strong>${barra.valor}</strong><br>` +
                        `Percentual: <strong>${pct}%</strong>`
                    );
                });
                canvas.addEventListener('mouseleave', () => CanvasTooltip.hide());

                observer.unobserve(canvas);
            }
        });
    });
    observer.observe(canvas);
}

function criarGraficoDiaSemana(dados) {
    const canvas = document.getElementById('grafico-dia-semana');
    if (!canvas) return;

    const observer = new IntersectionObserver((entries) => {
        entries.forEach(entry => {
            if (entry.isIntersecting) {
                const ctx = canvas.getContext('2d');
                const dias = ['Dom', 'Seg', 'Ter', 'Qua', 'Qui', 'Sex', 'Sáb'];
                const valores = dias.map((_, i) => {
                    const item = (dados.por_dia_semana || []).find(d => d.dia === i + 1);
                    return item ? parseInt(item.total) : 0;
                });
                const totalDias = valores.reduce((s, v) => s + v, 0) || 1;
                const maxValor = Math.max(...valores) || 1;
                const margemEsquerda = 30;
                const margemDireita = 30;
                const larguraUtil = canvas.width - margemEsquerda - margemDireita;
                const larguraBarra = (larguraUtil - (6 * 10)) / 7;
                const espacamento = 10;

                // Mapa de barras para hit-detection
                const barrasDia = valores.map((valor, i) => ({
                    dia: dias[i],
                    valor,
                    x: margemEsquerda + i * (larguraBarra + espacamento),
                    largura: larguraBarra,
                    alturaFinal: (valor / maxValor) * (canvas.height - 50),
                    yBase: canvas.height - 20
                }));

                ctx.clearRect(0, 0, canvas.width, canvas.height);

                dias.forEach((dia, i) => {
                    ctx.fillStyle = '#94a3b8';
                    ctx.font = '14px Arial';
                    ctx.textAlign = 'center';
                    const x = margemEsquerda + i * (larguraBarra + espacamento);
                    ctx.fillText(dia, x + larguraBarra / 2, canvas.height - 5);
                });

                valores.forEach((valor, i) => {
                    const alturaFinal = barrasDia[i].alturaFinal;
                    const x = barrasDia[i].x;
                    const yBase = barrasDia[i].yBase;

                    let alturaAtual = 0;
                    const animacao = setInterval(() => {
                        ctx.clearRect(x, 0, larguraBarra, canvas.height - 40);
                        alturaAtual += alturaFinal / 50;
                        if (alturaAtual >= alturaFinal) {
                            alturaAtual = alturaFinal;
                            clearInterval(animacao);
                        }
                        const y = yBase - alturaAtual;
                        ctx.fillStyle = '#39dd52';
                        ctx.fillRect(x, y, larguraBarra, alturaAtual);
                        if (alturaAtual >= alturaFinal) {
                            ctx.fillStyle = '#94a3b8';
                            ctx.font = 'bold 14px Arial';
                            ctx.textAlign = 'center';
                            ctx.fillText(valor, x + larguraBarra / 2, y - 5);
                        }
                    }, 20);
                });

                // Tooltip
                canvas.addEventListener('mousemove', function(e) {
                    const rect = canvas.getBoundingClientRect();
                    const scaleX = canvas.width  / rect.width;
                    const scaleY = canvas.height / rect.height;
                    const mx = (e.clientX - rect.left) * scaleX;
                    const my = (e.clientY - rect.top)  * scaleY;

                    const barra = barrasDia.find(b => {
                        const yTopo = b.yBase - b.alturaFinal;
                        return mx >= b.x && mx <= b.x + b.largura &&
                               my >= yTopo && my <= b.yBase;
                    });
                    if (!barra || barra.valor === 0) { CanvasTooltip.hide(); return; }

                    const pct = Math.round((barra.valor / totalDias) * 100);
                    CanvasTooltip.show(e,
                        `<strong style="color:#39dd52">${barra.dia}</strong><br>` +
                        `Tickets: <strong>${barra.valor}</strong><br>` +
                        `Percentual: <strong>${pct}%</strong>`
                    );
                });
                canvas.addEventListener('mouseleave', () => CanvasTooltip.hide());

                observer.unobserve(canvas);
            }
        });
    });
    observer.observe(canvas);
}

function criarGraficoStatusAgente(dados) {
    const canvas = document.getElementById('grafico-status-agente');
    if (!canvas) return;

    const observer = new IntersectionObserver((entries) => {
        entries.forEach(entry => {
            if (entry.isIntersecting) {
                const ctx = canvas.getContext('2d');
                const statusAgente = dados.status_por_agente || [];
                const agentes = [...new Set(statusAgente.map(s => s.agente))];
                const statusList = ['novo', 'em_atendimento', 'aguardando', 'aguardando_resposta', 'aguardando_terceiros', 'aguardando_data'];
                const statusLabels = {
                    'novo': 'Novo',
                    'em_atendimento': 'Em Atendimento',
                    'aguardando': 'Aguardando',
                    'aguardando_resposta': 'Aguard. Resposta',
                    'aguardando_terceiros': 'Aguard. Terceiros',
                    'aguardando_data': 'Aguard. Data'
                };
                const cores = {
                    'novo': '#e56b46',
                    'em_atendimento': '#2797e1',
                    'aguardando': '#d7e744',
                    'aguardando_resposta': '#e56b46',
                    'aguardando_terceiros': '#c52ee7',
                    'aguardando_data': '#39dd52'
                };
                const margemDireita = 140;
                const larguraUtil = canvas.width - margemDireita;
                const larguraBarra = Math.min(60, (larguraUtil - ((agentes.length - 1) * 25)) / agentes.length);
                const espacamento = 25;
                const margemEsquerda = (larguraUtil - (agentes.length * larguraBarra + (agentes.length - 1) * espacamento)) / 2;

                // Mapa de segmentos para hit-detection: [{agente, status, label, valor, cor, x, yTopo, altura}]
                const segmentosHit = [];

                ctx.clearRect(0, 0, canvas.width, canvas.height);

                const legenda = [
                    { label: 'Novo',             cor: '#e56b46' },
                    { label: 'Em Atendimento',   cor: '#2797e1' },
                    { label: 'Aguardando',        cor: '#d7e744' },
                    { label: 'Aguard. Resposta',  cor: '#e56b46' },
                    { label: 'Aguard. Terceiros', cor: '#c52ee7' },
                    { label: 'Aguard. Data',      cor: '#39dd52' }
                ];

                const legendaX = canvas.width - 130;
                let legendaY = 40;
                legenda.forEach(item => {
                    ctx.fillStyle = item.cor;
                    ctx.fillRect(legendaX, legendaY, 14, 14);
                    ctx.fillStyle = '#94a3b8';
                    ctx.font = '14px Arial';
                    ctx.textAlign = 'left';
                    ctx.fillText(item.label, legendaX + 20, legendaY + 11);
                    legendaY += 30;
                });

                agentes.forEach((agente, i) => {
                    const x = margemEsquerda + i * (larguraBarra + espacamento);
                    ctx.fillStyle = '#94a3b8';
                    ctx.font = '14px Arial';
                    ctx.textAlign = 'center';
                    const nomeAgente = agente.length > 16 ? agente.substring(0, 16) + '...' : agente;
                    ctx.fillText(nomeAgente, x + larguraBarra / 2, canvas.height - 5);
                });

                let agenteIndex = 0;

                function animarProximaBarra() {
                    if (agenteIndex >= agentes.length) return;

                    const agente = agentes[agenteIndex];
                    const x = margemEsquerda + agenteIndex * (larguraBarra + espacamento);
                    const yBase = canvas.height - 50;

                    const segmentos = [];
                    let alturaTotal = 0;
                    let totalAgente = 0;
                    statusList.forEach(st => {
                        const item = statusAgente.find(s => s.agente === agente && s.status === st);
                        const valor = item ? parseInt(item.total) : 0;
                        totalAgente += valor;
                        if (valor > 0) {
                            const altura = valor * 15;
                            segmentos.push({ status: st, label: statusLabels[st] || st, altura, cor: cores[st] || '#6c757d', valor });
                            alturaTotal += altura;
                        }
                    });

                    let alturaAtual = 0;
                    const incremento = alturaTotal / 30;

                    const animacao = setInterval(() => {
                        alturaAtual += incremento;
                        if (alturaAtual >= alturaTotal) {
                            alturaAtual = alturaTotal;
                            clearInterval(animacao);

                            // Registrar segmentos para hit-detection após animação
                            let yOffset = yBase;
                            for (let i = segmentos.length - 1; i >= 0; i--) {
                                const seg = segmentos[i];
                                yOffset -= seg.altura;
                                segmentosHit.push({
                                    agente,
                                    label: seg.label,
                                    valor: seg.valor,
                                    cor: seg.cor,
                                    totalAgente,
                                    x,
                                    largura: larguraBarra,
                                    yTopo: yOffset,
                                    altura: seg.altura
                                });
                            }

                            agenteIndex++;
                            setTimeout(animarProximaBarra, 150);
                        }

                        ctx.clearRect(x, 0, larguraBarra, canvas.height - 50);
                        let yOffset = yBase;
                        let alturaDesenhada = 0;

                        for (let i = segmentos.length - 1; i >= 0; i--) {
                            const seg = segmentos[i];
                            const alturaSegmento = Math.min(seg.altura, alturaAtual - alturaDesenhada);

                            if (alturaSegmento > 0) {
                                yOffset -= alturaSegmento;
                                ctx.fillStyle = seg.cor;
                                ctx.fillRect(x, yOffset, larguraBarra, alturaSegmento);

                                if (alturaAtual >= alturaTotal && seg.altura >= 10) {
                                    ctx.fillStyle = '#fff';
                                    ctx.font = 'bold 14px Arial';
                                    ctx.textAlign = 'center';
                                    ctx.fillText(seg.valor, x + larguraBarra / 2, yOffset + seg.altura / 2 + 4);
                                }

                                alturaDesenhada += alturaSegmento;
                            }

                            if (alturaDesenhada >= alturaAtual) break;
                        }

                        if (alturaAtual >= alturaTotal) {
                            ctx.fillStyle = '#94a3b8';
                            ctx.font = 'bold 14px Arial';
                            ctx.textAlign = 'center';
                            ctx.fillText(totalAgente, x + larguraBarra / 2, yBase - alturaTotal - 5);
                        }
                    }, 20);
                }

                animarProximaBarra();
                observer.unobserve(canvas);

                // Tooltip
                canvas.addEventListener('mousemove', function(e) {
                    const rect = canvas.getBoundingClientRect();
                    const scaleX = canvas.width  / rect.width;
                    const scaleY = canvas.height / rect.height;
                    const mx = (e.clientX - rect.left) * scaleX;
                    const my = (e.clientY - rect.top)  * scaleY;

                    const seg = segmentosHit.find(s =>
                        mx >= s.x && mx <= s.x + s.largura &&
                        my >= s.yTopo && my <= s.yTopo + s.altura
                    );
                    if (!seg) { CanvasTooltip.hide(); return; }

                    const pct = Math.round((seg.valor / seg.totalAgente) * 100);
                    CanvasTooltip.show(e,
                        `<strong style="color:#94a3b8">${seg.agente}</strong><br>` +
                        `<span style="color:${seg.cor}">● ${seg.label}</span><br>` +
                        `Tickets: <strong>${seg.valor}</strong><br>` +
                        `Percentual: <strong>${pct}%</strong>`
                    );
                });
                canvas.addEventListener('mouseleave', () => CanvasTooltip.hide());
            }
        });
    });
    observer.observe(canvas);
}

function criarGraficoFunil(dados) {
    const canvas = document.getElementById('grafico-funil');
    if (!canvas) return;

    const observer = new IntersectionObserver((entries) => {
        entries.forEach(entry => {
            if (entry.isIntersecting) {
                const ctx = canvas.getContext('2d');
                const funil = dados.funil_conversao || {};
                const etapas = [
                    { nome: 'Aberto',          valor: funil.aberto          || 0 },
                    { nome: 'Em Atendimento',   valor: funil.em_atendimento  || 0 },
                    { nome: 'Aguardando',       valor: funil.aguardando      || 0 },
                    { nome: 'Resolvido',        valor: funil.resolvido       || 0 },
                    { nome: 'Encerrado',        valor: funil.encerrado       || 0 }
                ];
                const totalFunil = etapas.reduce((s, e) => s + e.valor, 0) || 1;
                const maxValor = Math.max(...etapas.map(e => e.valor)) || 1;
                const cores = ['#2797e1', '#c52ee7', '#d7e744', '#39dd52', '#475569'];
                const alturaEtapa = 45;
                const espacamento = 15;
                const centerX = canvas.width / 2;

                // Mapa de etapas para hit-detection
                const etapasHit = etapas.map((etapa, i) => ({
                    ...etapa,
                    cor: cores[i],
                    largura: (etapa.valor / maxValor) * (canvas.width - 100),
                    y: 10 + i * (alturaEtapa + espacamento)
                }));

                ctx.clearRect(0, 0, canvas.width, canvas.height);

                let etapaIndex = 0;

                function animarProximaEtapa() {
                    if (etapaIndex >= etapas.length) return;

                    const etapa = etapas[etapaIndex];
                    const larguraFinal = etapasHit[etapaIndex].largura;
                    const y = etapasHit[etapaIndex].y;
                    const cor = cores[etapaIndex];

                    let larguraAtual = 0;
                    const incremento = larguraFinal / 30;

                    const animacao = setInterval(() => {
                        larguraAtual += incremento;
                        if (larguraAtual >= larguraFinal) {
                            larguraAtual = larguraFinal;
                            clearInterval(animacao);
                            etapaIndex++;
                            setTimeout(animarProximaEtapa, 150);
                        }

                        const x = centerX - larguraAtual / 2;
                        ctx.clearRect(0, y, canvas.width, alturaEtapa);
                        ctx.fillStyle = cor;
                        ctx.fillRect(x, y, larguraAtual, alturaEtapa);

                        if (larguraAtual >= larguraFinal) {
                            ctx.fillStyle = '#fff';
                            ctx.font = 'bold 14px Arial';
                            ctx.textAlign = 'center';
                            ctx.fillText(`${etapa.nome}: ${etapa.valor}`, centerX, y + alturaEtapa / 2 + 5);
                        }
                    }, 20);
                }

                animarProximaEtapa();
                observer.unobserve(canvas);

                // Tooltip
                canvas.addEventListener('mousemove', function(e) {
                    const rect = canvas.getBoundingClientRect();
                    const scaleX = canvas.width  / rect.width;
                    const scaleY = canvas.height / rect.height;
                    const mx = (e.clientX - rect.left) * scaleX;
                    const my = (e.clientY - rect.top)  * scaleY;

                    const etapa = etapasHit.find(et => {
                        const x = centerX - et.largura / 2;
                        return mx >= x && mx <= x + et.largura &&
                               my >= et.y && my <= et.y + alturaEtapa;
                    });
                    if (!etapa || etapa.valor === 0) { CanvasTooltip.hide(); return; }

                    const pct = Math.round((etapa.valor / totalFunil) * 100);
                    CanvasTooltip.show(e,
                        `<strong style="color:${etapa.cor}">${etapa.nome}</strong><br>` +
                        `Tickets: <strong>${etapa.valor}</strong><br>` +
                        `Percentual: <strong>${pct}%</strong>`
                    );
                });
                canvas.addEventListener('mouseleave', () => CanvasTooltip.hide());
            }
        });
    });
    observer.observe(canvas);
}

// ============================================================
// Evolução Diária — Últimos 30 Dias (gráfico de linha/área)
// ============================================================
function criarGraficoDiarios30d(dados) {
    const canvas = document.getElementById('grafico-diarios-30d');
    if (!canvas) return;

    const observer = new IntersectionObserver((entries) => {
        entries.forEach(entry => {
            if (!entry.isIntersecting) return;

            const ctx = canvas.getContext('2d');
            const registros = dados.diarios_30d || [];

            // Montar array completo dos últimos 30 dias (preenche dias sem ticket com 0)
            const hoje = new Date();
            const diasLabels = [];
            const valoresMap = {};
            registros.forEach(r => { valoresMap[r.dia] = parseInt(r.total); });

            for (let i = 29; i >= 0; i--) {
                const d = new Date(hoje);
                d.setDate(hoje.getDate() - i);
                const chave = d.toISOString().slice(0, 10);
                const label = `${String(d.getDate()).padStart(2, '0')}/${String(d.getMonth() + 1).padStart(2, '0')}`;
                diasLabels.push({ chave, label });
            }

            const valores = diasLabels.map(d => valoresMap[d.chave] || 0);
            const totalDiarios = valores.reduce((s, v) => s + v, 0) || 1;
            const maxValor = Math.max(...valores, 1);

            const margemEsq = 36;
            const margemDir = 20;
            const margemSup = 20;
            const margemInf = 32;
            const larguraUtil = canvas.width - margemEsq - margemDir;
            const alturaUtil = canvas.height - margemSup - margemInf;
            const passoX = larguraUtil / (diasLabels.length - 1);

            // Pontos para hit-detection
            const pontos30d = valores.map((v, i) => ({
                label: diasLabels[i].label,
                valor: v,
                x: margemEsq + i * passoX,
                y: margemSup + alturaUtil - (v / maxValor) * alturaUtil
            }));

            ctx.clearRect(0, 0, canvas.width, canvas.height);

            const linhasGrade = 4;
            for (let g = 0; g <= linhasGrade; g++) {
                const y = margemSup + alturaUtil - (g / linhasGrade) * alturaUtil;
                ctx.strokeStyle = 'rgba(255,255,255,0.045)';
                ctx.lineWidth = 1;
                ctx.beginPath();
                ctx.moveTo(margemEsq, y);
                ctx.lineTo(canvas.width - margemDir, y);
                ctx.stroke();
                const labelVal = Math.round((g / linhasGrade) * maxValor);
                ctx.fillStyle = '#475569';
                ctx.font = '14px Arial';
                ctx.textAlign = 'right';
                ctx.fillText(labelVal, margemEsq - 4, y + 4);
            }

            diasLabels.forEach((d, i) => {
                if (i % 5 === 0 || i === diasLabels.length - 1) {
                    const x = margemEsq + i * passoX;
                    ctx.fillStyle = '#475569';
                    ctx.font = '14px Arial';
                    ctx.textAlign = 'center';
                    ctx.fillText(d.label, x, canvas.height - 4);
                }
            });

            let pontoAtual = 0;
            const animacao = setInterval(() => {
                ctx.clearRect(0, 0, canvas.width, canvas.height);

                for (let g = 0; g <= linhasGrade; g++) {
                    const y = margemSup + alturaUtil - (g / linhasGrade) * alturaUtil;
                    ctx.strokeStyle = 'rgba(255,255,255,0.045)';
                    ctx.lineWidth = 1;
                    ctx.beginPath();
                    ctx.moveTo(margemEsq, y);
                    ctx.lineTo(canvas.width - margemDir, y);
                    ctx.stroke();
                    const labelVal = Math.round((g / linhasGrade) * maxValor);
                    ctx.fillStyle = '#475569';
                    ctx.font = '14px Arial';
                    ctx.textAlign = 'right';
                    ctx.fillText(labelVal, margemEsq - 4, y + 4);
                }
                diasLabels.forEach((d, i) => {
                    if (i % 5 === 0 || i === diasLabels.length - 1) {
                        const x = margemEsq + i * passoX;
                        ctx.fillStyle = '#475569';
                        ctx.font = '14px Arial';
                        ctx.textAlign = 'center';
                        ctx.fillText(d.label, x, canvas.height - 4);
                    }
                });

                const limite = Math.min(pontoAtual + 2, pontos30d.length - 1);

                ctx.beginPath();
                ctx.moveTo(pontos30d[0].x, margemSup + alturaUtil);
                pontos30d.slice(0, limite + 1).forEach(p => ctx.lineTo(p.x, p.y));
                ctx.lineTo(pontos30d[limite].x, margemSup + alturaUtil);
                ctx.closePath();
                const gradArea = ctx.createLinearGradient(0, margemSup, 0, margemSup + alturaUtil);
                gradArea.addColorStop(0, 'rgba(57,221,82,0.18)');
                gradArea.addColorStop(1, 'rgba(57,221,82,0)');
                ctx.fillStyle = gradArea;
                ctx.fill();

                ctx.beginPath();
                ctx.strokeStyle = '#39dd52';
                ctx.lineWidth = 2;
                ctx.lineJoin = 'round';
                pontos30d.slice(0, limite + 1).forEach((p, idx) => idx === 0 ? ctx.moveTo(p.x, p.y) : ctx.lineTo(p.x, p.y));
                ctx.stroke();

                ctx.beginPath();
                ctx.arc(pontos30d[limite].x, pontos30d[limite].y, 3, 0, Math.PI * 2);
                ctx.fillStyle = '#39dd52';
                ctx.fill();

                pontoAtual += 2;
                if (pontoAtual >= pontos30d.length - 1) {
                    clearInterval(animacao);
                    // Mostrar valor em laranja em todos os dias com ticket > 0
                    pontos30d.forEach(p => {
                        if (p.valor > 0) {
                            ctx.fillStyle = '#e56b46';
                            ctx.font = 'bold 10px Inter, Arial';
                            ctx.textAlign = 'center';
                            ctx.fillText(p.valor, p.x, p.y - 6);
                        }
                    });
                    // Destacar pico
                    const maxIdx = valores.indexOf(Math.max(...valores));
                    ctx.beginPath();
                    ctx.arc(pontos30d[maxIdx].x, pontos30d[maxIdx].y, 4, 0, Math.PI * 2);
                    ctx.fillStyle = '#39dd52';
                    ctx.fill();
                }
            }, 20);

            // Tooltip: ponto mais próximo no eixo X
            canvas.addEventListener('mousemove', function(e) {
                const rect = canvas.getBoundingClientRect();
                const scaleX = canvas.width  / rect.width;
                const mx = (e.clientX - rect.left) * scaleX;

                let closest = null;
                let menorDist = Infinity;
                pontos30d.forEach(p => {
                    const d = Math.abs(mx - p.x);
                    if (d < menorDist) { menorDist = d; closest = p; }
                });

                if (!closest || menorDist > passoX / 2 + 4) { CanvasTooltip.hide(); return; }

                const pct = Math.round((closest.valor / totalDiarios) * 100);
                const isPico = closest.valor === Math.max(...valores);
                CanvasTooltip.show(e,
                    `<strong style="color:#39dd52">${closest.label}</strong>` +
                    (isPico ? ' <span style="color:#39dd52">▲ Pico</span>' : '') + `<br>` +
                    `Tickets: <strong>${closest.valor}</strong><br>` +
                    `Percentual: <strong>${pct}%</strong>`
                );
            });
            canvas.addEventListener('mouseleave', () => CanvasTooltip.hide());

            observer.unobserve(canvas);
        });
    });
    observer.observe(canvas);
}

// ============================================================
// Status Empilhado por Mês (barras empilhadas, ano atual)
// ============================================================
function criarGraficoStatusMensal(dados) {
    const canvas = document.getElementById('grafico-status-mensal');
    if (!canvas) return;

    const observer = new IntersectionObserver((entries) => {
        entries.forEach(entry => {
            if (!entry.isIntersecting) return;

            const ctx = canvas.getContext('2d');
            const registros = dados.status_por_mes || [];

            const statusConfig = [
                { key: 'novo',                  cor: '#e56b46', label: 'Novo' },
                { key: 'em_atendimento',        cor: '#2797e1', label: 'Em Atend.' },
                { key: 'aguardando_resposta',   cor: '#d7e744', label: 'Ag. Resposta' },
                { key: 'aguardando_terceiros',  cor: '#c52ee7', label: 'Ag. Terceiros' },
                { key: 'aguardando_data',       cor: '#39dd52', label: 'Ag. Data' },
                { key: 'resolvido',             cor: '#39dd52', label: 'Resolvido' },
                { key: 'encerrado',             cor: '#475569', label: 'Encerrado' },
            ];

            const mesesNomes = ['Jan','Fev','Mar','Abr','Mai','Jun','Jul','Ago','Set','Out','Nov','Dez'];

            const porMes = {};
            registros.forEach(r => {
                const m = parseInt(r.mes);
                if (!porMes[m]) porMes[m] = {};
                porMes[m][r.status] = parseInt(r.total);
            });

            const meses = Object.keys(porMes).map(Number).sort((a, b) => a - b);
            if (meses.length === 0) {
                ctx.fillStyle = '#475569';
                ctx.font = '14px Arial';
                ctx.textAlign = 'center';
                ctx.fillText('Sem dados para o período', canvas.width / 2, canvas.height / 2);
                observer.unobserve(canvas);
                return;
            }

            const maxTotal = Math.max(...meses.map(m => Object.values(porMes[m]).reduce((s, v) => s + v, 0)), 1);
            const margemEsq = 36;
            const margemDir = 10;
            const margemSup = 20;
            const margemInf = 44;
            const larguraUtil = canvas.width - margemEsq - margemDir;
            const alturaUtil = canvas.height - margemSup - margemInf;
            const larguraBarra = Math.min(40, (larguraUtil - (meses.length - 1) * 12) / meses.length);
            const espacamento = (larguraUtil - meses.length * larguraBarra) / (meses.length + 1);

            // Mapa de segmentos para hit-detection
            const segmentosMensalHit = [];

            ctx.clearRect(0, 0, canvas.width, canvas.height);

            for (let g = 0; g <= 4; g++) {
                const y = margemSup + alturaUtil - (g / 4) * alturaUtil;
                ctx.strokeStyle = 'rgba(255,255,255,0.045)';
                ctx.lineWidth = 1;
                ctx.beginPath();
                ctx.moveTo(margemEsq, y);
                ctx.lineTo(canvas.width - margemDir, y);
                ctx.stroke();
                ctx.fillStyle = '#475569';
                ctx.font = '14px Arial';
                ctx.textAlign = 'right';
                ctx.fillText(Math.round((g / 4) * maxTotal), margemEsq - 4, y + 4);
            }

            const legendaY = canvas.height - 16;
            let legendaX = margemEsq;
            statusConfig.forEach(sc => {
                ctx.fillStyle = sc.cor;
                ctx.fillRect(legendaX, legendaY - 8, 8, 8);
                ctx.fillStyle = '#475569';
                ctx.font = '14px Arial';
                ctx.textAlign = 'left';
                ctx.fillText(sc.label, legendaX + 11, legendaY);
                legendaX += ctx.measureText(sc.label).width + 22;
            });

            let mesIndex = 0;
            function animarProximoMes() {
                if (mesIndex >= meses.length) return;
                const m = meses[mesIndex];
                const x = margemEsq + espacamento + mesIndex * (larguraBarra + espacamento);
                const totalMes = Object.values(porMes[m]).reduce((s, v) => s + v, 0);
                const alturaTotal = (totalMes / maxTotal) * alturaUtil;

                let alturaAtual = 0;
                const segmentos = statusConfig.map(sc => ({
                    key: sc.key,
                    label: sc.label,
                    cor: sc.cor,
                    valor: porMes[m][sc.key] || 0,
                    altura: ((porMes[m][sc.key] || 0) / maxTotal) * alturaUtil
                })).filter(s => s.altura > 0);

                const animMes = setInterval(() => {
                    alturaAtual += alturaTotal / 40;
                    if (alturaAtual >= alturaTotal) {
                        alturaAtual = alturaTotal;
                        clearInterval(animMes);

                        // Registrar segmentos para hit-detection após animação
                        let yBase = margemSup + alturaUtil;
                        for (const seg of segmentos) {
                            yBase -= seg.altura;
                            segmentosMensalHit.push({
                                mes: mesesNomes[m - 1],
                                label: seg.label,
                                valor: seg.valor,
                                cor: seg.cor,
                                totalMes,
                                x,
                                largura: larguraBarra,
                                yTopo: yBase,
                                altura: seg.altura
                            });
                        }

                        mesIndex++;
                        setTimeout(animarProximoMes, 60);
                    }

                    ctx.clearRect(x, margemSup, larguraBarra, alturaUtil + 1);

                    for (let g = 0; g <= 4; g++) {
                        const gy = margemSup + alturaUtil - (g / 4) * alturaUtil;
                        ctx.strokeStyle = 'rgba(255,255,255,0.045)';
                        ctx.lineWidth = 1;
                        ctx.beginPath();
                        ctx.moveTo(x, gy);
                        ctx.lineTo(x + larguraBarra, gy);
                        ctx.stroke();
                    }

                    let yBase = margemSup + alturaUtil;
                    let alturaRestante = alturaAtual;
                    for (const seg of segmentos) {
                        const h = Math.min(seg.altura, alturaRestante);
                        if (h <= 0) break;
                        ctx.fillStyle = seg.cor;
                        ctx.fillRect(x, yBase - h, larguraBarra, h);
                        yBase -= h;
                        alturaRestante -= h;
                    }

                    ctx.fillStyle = '#94a3b8';
                    ctx.font = '14px Arial';
                    ctx.textAlign = 'center';
                    ctx.fillText(mesesNomes[m - 1], x + larguraBarra / 2, margemSup + alturaUtil + 14);

                    if (alturaAtual >= alturaTotal) {
                        ctx.fillStyle = '#94a3b8';
                        ctx.font = 'bold 14px Arial';
                        ctx.textAlign = 'center';
                        ctx.fillText(totalMes, x + larguraBarra / 2, margemSup + alturaUtil - alturaTotal - 4);
                    }
                }, 20);
            }
            animarProximoMes();

            // Tooltip
            canvas.addEventListener('mousemove', function(e) {
                const rect = canvas.getBoundingClientRect();
                const scaleX = canvas.width  / rect.width;
                const scaleY = canvas.height / rect.height;
                const mx = (e.clientX - rect.left) * scaleX;
                const my = (e.clientY - rect.top)  * scaleY;

                const seg = segmentosMensalHit.find(s =>
                    mx >= s.x && mx <= s.x + s.largura &&
                    my >= s.yTopo && my <= s.yTopo + s.altura
                );
                if (!seg || seg.valor === 0) { CanvasTooltip.hide(); return; }

                const pct = Math.round((seg.valor / seg.totalMes) * 100);
                CanvasTooltip.show(e,
                    `<strong style="color:#94a3b8">${seg.mes}</strong><br>` +
                    `<span style="color:${seg.cor}">● ${seg.label}</span><br>` +
                    `Tickets: <strong>${seg.valor}</strong><br>` +
                    `Percentual: <strong>${pct}%</strong>`
                );
            });
            canvas.addEventListener('mouseleave', () => CanvasTooltip.hide());

            observer.unobserve(canvas);
        });
    });
    observer.observe(canvas);
}

// ============================================================
// Heatmap: Dia da Semana × Hora do Dia
// ============================================================
function criarGraficoHeatmap(dados) {
    const canvas = document.getElementById('grafico-heatmap');
    if (!canvas) return;

    const observer = new IntersectionObserver((entries) => {
        entries.forEach(entry => {
            if (!entry.isIntersecting) return;

            const ctx = canvas.getContext('2d');
            const registros = dados.heatmap_dia_hora || [];

            const diasNomes = ['Dom', 'Seg', 'Ter', 'Qua', 'Qui', 'Sex', 'Sáb'];
            const horas = Array.from({ length: 24 }, (_, i) => i);

            const mapa = {};
            registros.forEach(r => {
                const chave = `${r.dia}_${r.hora}`;
                mapa[chave] = parseInt(r.total);
            });

            const maxValor = Math.max(...Object.values(mapa), 1);
            const totalHeatmap = Object.values(mapa).reduce((s, v) => s + v, 0) || 1;

            const margemEsq = 32;
            const margemSup = 16;
            const margemDir = 8;
            const margemInf = 20;
            const larguraUtil = canvas.width - margemEsq - margemDir;
            const alturaUtil = canvas.height - margemSup - margemInf;
            const larguraCelula = larguraUtil / 24;
            const alturaCelula = alturaUtil / 7;

            ctx.clearRect(0, 0, canvas.width, canvas.height);

            for (let h = 0; h < 24; h += 3) {
                const x = margemEsq + h * larguraCelula + larguraCelula / 2;
                ctx.fillStyle = '#475569';
                ctx.font = '14px Arial';
                ctx.textAlign = 'center';
                ctx.fillText(`${String(h).padStart(2,'0')}h`, x, canvas.height - 4);
            }

            for (let dIdx = 0; dIdx < 7; dIdx++) {
                const diaDOW = dIdx + 1;
                const y = margemSup + dIdx * alturaCelula;

                ctx.fillStyle = '#475569';
                ctx.font = '14px Arial';
                ctx.textAlign = 'right';
                ctx.fillText(diasNomes[dIdx], margemEsq - 4, y + alturaCelula / 2 + 4);

                for (let h = 0; h < 24; h++) {
                    const valor = mapa[`${diaDOW}_${h}`] || 0;
                    const intensidade = valor / maxValor;
                    const x = margemEsq + h * larguraCelula;

                    if (intensidade === 0) {
                        ctx.fillStyle = 'rgba(255,255,255,0.025)';
                    } else {
                        const r = Math.round(197 + (39 - 197) * intensidade);
                        const g = Math.round(46 + (151 - 46) * intensidade);
                        const b = Math.round(231 + (225 - 231) * intensidade);
                        ctx.fillStyle = `rgba(${r},${g},${b},${0.25 + intensidade * 0.7})`;
                    }

                    const pad = 1;
                    ctx.fillRect(x + pad, y + pad, larguraCelula - pad * 2, alturaCelula - pad * 2);

                    if (valor > 0 && larguraCelula >= 14) {
                        ctx.fillStyle = intensidade > 0.5 ? '#0d1117' : '#94a3b8';
                        ctx.font = `${Math.min(14, larguraCelula - 2)}px Arial`;
                        ctx.textAlign = 'center';
                        ctx.fillText(valor, x + larguraCelula / 2, y + alturaCelula / 2 + 3);
                    }
                }
            }

            // Tooltip: detecção direta pela célula (dia × hora)
            canvas.addEventListener('mousemove', function(e) {
                const rect = canvas.getBoundingClientRect();
                const scaleX = canvas.width  / rect.width;
                const scaleY = canvas.height / rect.height;
                const mx = (e.clientX - rect.left) * scaleX;
                const my = (e.clientY - rect.top)  * scaleY;

                // Verificar se está na área do grid
                if (mx < margemEsq || mx > canvas.width - margemDir ||
                    my < margemSup || my > canvas.height - margemInf) {
                    CanvasTooltip.hide(); return;
                }

                const hIdx  = Math.floor((mx - margemEsq) / larguraCelula);
                const dIdx  = Math.floor((my - margemSup)  / alturaCelula);

                if (hIdx < 0 || hIdx >= 24 || dIdx < 0 || dIdx >= 7) { CanvasTooltip.hide(); return; }

                const diaDOW = dIdx + 1;
                const hora   = hIdx;
                const valor  = mapa[`${diaDOW}_${hora}`] || 0;

                if (valor === 0) { CanvasTooltip.hide(); return; }

                const pct = Math.round((valor / totalHeatmap) * 100);
                const horaStr = `${String(hora).padStart(2,'0')}:00`;
                CanvasTooltip.show(e,
                    `<strong style="color:#94a3b8">${diasNomes[dIdx]}, ${horaStr}</strong><br>` +
                    `Tickets: <strong>${valor}</strong><br>` +
                    `Percentual: <strong>${pct}%</strong>`
                );
            });
            canvas.addEventListener('mouseleave', () => CanvasTooltip.hide());

            observer.unobserve(canvas);
        });
    });
    observer.observe(canvas);
}
