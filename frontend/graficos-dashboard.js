// GrÃ¡ficos adicionais do dashboard

function criarGraficoAgente(dados) {
    const canvas = document.getElementById('grafico-agente');
    if (!canvas) return;

    const observer = new IntersectionObserver((entries) => {
        entries.forEach(entry => {
            if (entry.isIntersecting) {
                const ctx = canvas.getContext('2d');
                const agentes = dados.por_agente || [];
                const cores = ['#f79226', '#17a2b8', '#28a745', '#ffc107', '#dc3545', '#6f42c1'];
                const total = agentes.reduce((sum, a) => sum + parseInt(a.total), 0);
                const centerX = canvas.width / 2;
                const centerY = canvas.height / 2;
                const raio = 80;

                ctx.clearRect(0, 0, canvas.width, canvas.height);

                // Animar cada fatia sequencialmente
                let fatiaIndex = 0;
                const fatias = [];
                let anguloAcumulado = 0;

                // Calcular todas as fatias
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

                            // Desenhar label
                            const labelAngulo = fatia.anguloInicio + (fatia.anguloFim - fatia.anguloInicio) / 2;
                            const labelX = centerX + Math.cos(labelAngulo) * (raio + 15);
                            const labelY = centerY + Math.sin(labelAngulo) * (raio + 15);

                            ctx.fillStyle = document.body.classList.contains('modo-escuro') ? '#cbd5e1' : '#475569';
                            ctx.font = 'bold 12px Arial';

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

                            ctx.fillText(`${fatia.agente.nome}: ${fatia.agente.total}`, labelX, labelY);

                            fatiaIndex++;
                            setTimeout(desenharProximaFatia, 100);
                        }

                        // Redesenhar todas as fatias anteriores + fatia atual
                        ctx.clearRect(0, 0, canvas.width, canvas.height);

                        // Desenhar fatias completas anteriores
                        for (let i = 0; i < fatiaIndex; i++) {
                            ctx.fillStyle = fatias[i].cor;
                            ctx.beginPath();
                            ctx.moveTo(centerX, centerY);
                            ctx.arc(centerX, centerY, raio, fatias[i].anguloInicio, fatias[i].anguloFim);
                            ctx.closePath();
                            ctx.fill();

                            // Redesenhar label
                            const labelAngulo = fatias[i].anguloInicio + (fatias[i].anguloFim - fatias[i].anguloInicio) / 2;
                            const labelX = centerX + Math.cos(labelAngulo) * (raio + 15);
                            const labelY = centerY + Math.sin(labelAngulo) * (raio + 15);

                            ctx.fillStyle = document.body.classList.contains('modo-escuro') ? '#cbd5e1' : '#475569';
                            ctx.font = 'bold 12px Arial';

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

                        // Desenhar fatia atual em animaÃ§Ã£o
                        ctx.fillStyle = fatia.cor;
                        ctx.beginPath();
                        ctx.moveTo(centerX, centerY);
                        ctx.arc(centerX, centerY, raio, fatia.anguloInicio, anguloAtual);
                        ctx.closePath();
                        ctx.fill();
                    }, 20);
                }

                desenharProximaFatia();
                observer.unobserve(canvas);
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
                const cores = ['#ffc107', '#28a745'];
                const maxValor = Math.max(...valores) || 1;
                const alturaBarra = 40;
                const espacamento = 30;
                const margemEsquerda = 100;

                ctx.clearRect(0, 0, canvas.width, canvas.height);
                valores.forEach((valor, i) => {
                    const larguraFinal = (valor / maxValor) * (canvas.width - margemEsquerda - 60);
                    const y = espacamento + i * (alturaBarra + espacamento);

                    ctx.fillStyle = document.body.classList.contains('modo-escuro') ? '#cbd5e1' : '#475569';
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
                        ctx.fillStyle = document.body.classList.contains('modo-escuro') ? '#cbd5e1' : '#475569';
                        ctx.font = 'bold 14px Arial';
                        ctx.textAlign = 'left';
                        ctx.fillText(valor, margemEsquerda + larguraAtual + 5, y + alturaBarra / 2 + 5);
                    }, 20);
                });
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
                    return item ? item.total : 0;
                });
                const maxValor = Math.max(...valores) || 1;
                const margemEsquerda = 30;
                const margemDireita = 30;
                const larguraUtil = canvas.width - margemEsquerda - margemDireita;
                const larguraBarra = (larguraUtil - (6 * 10)) / 7;
                const espacamento = 10;

                ctx.clearRect(0, 0, canvas.width, canvas.height);

                dias.forEach((dia, i) => {
                    ctx.fillStyle = document.body.classList.contains('modo-escuro') ? '#cbd5e1' : '#475569';
                    ctx.font = '11px Arial';
                    ctx.textAlign = 'center';
                    const x = margemEsquerda + i * (larguraBarra + espacamento);
                    ctx.fillText(dia, x + larguraBarra / 2, canvas.height - 5);
                });

                valores.forEach((valor, i) => {
                    const alturaFinal = (valor / maxValor) * (canvas.height - 50);
                    const x = margemEsquerda + i * (larguraBarra + espacamento);
                    const yBase = canvas.height - 20;

                    let alturaAtual = 0;
                    const animacao = setInterval(() => {
                        ctx.clearRect(x, 0, larguraBarra, canvas.height - 40);
                        alturaAtual += alturaFinal / 50;
                        if (alturaAtual >= alturaFinal) {
                            alturaAtual = alturaFinal;
                            clearInterval(animacao);
                        }
                        const y = yBase - alturaAtual;
                        ctx.fillStyle = '#17a2b8';
                        ctx.fillRect(x, y, larguraBarra, alturaAtual);
                        if (alturaAtual >= alturaFinal) {
                            ctx.fillStyle = document.body.classList.contains('modo-escuro') ? '#cbd5e1' : '#475569';
                            ctx.font = 'bold 11px Arial';
                            ctx.textAlign = 'center';
                            ctx.fillText(valor, x + larguraBarra / 2, y - 5);
                        }
                    }, 20);
                });
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
                const status = ['novo', 'em_atendimento', 'aguardando', 'aguardando_resposta', 'aguardando_terceiros', 'aguardando_data'];
                const cores = {
                    'novo': '#f79226',
                    'em_atendimento': '#17a2b8',
                    'aguardando': '#ffc107',
                    'aguardando_resposta': '#fd7e14',
                    'aguardando_terceiros': '#6f42c1',
                    'aguardando_data': '#9c27b0'
                };
                const margemDireita = 140;
                const larguraUtil = canvas.width - margemDireita;
                const larguraBarra = Math.min(60, (larguraUtil - ((agentes.length - 1) * 25)) / agentes.length);
                const espacamento = 25;
                const margemEsquerda = (larguraUtil - (agentes.length * larguraBarra + (agentes.length - 1) * espacamento)) / 2;

                ctx.clearRect(0, 0, canvas.width, canvas.height);

                // Desenhar legenda vertical na direita
                const legenda = [
                    { label: 'Novo', cor: '#f79226' },
                    { label: 'Em Atendimento', cor: '#17a2b8' },
                    { label: 'Aguardando', cor: '#ffc107' },
                    { label: 'Aguard. Resposta', cor: '#fd7e14' },
                    { label: 'Aguard. Terceiros', cor: '#6f42c1' },
                    { label: 'Aguard. Data', cor: '#9c27b0' }
                ];

                const legendaX = canvas.width - 130;
                let legendaY = 40;
                legenda.forEach(item => {
                    ctx.fillStyle = item.cor;
                    ctx.fillRect(legendaX, legendaY, 14, 14);
                    ctx.fillStyle = document.body.classList.contains('modo-escuro') ? '#cbd5e1' : '#475569';
                    ctx.font = '12px Arial';
                    ctx.textAlign = 'left';
                    ctx.fillText(item.label, legendaX + 20, legendaY + 11);
                    legendaY += 30;
                });

                // Desenhar labels dos agentes
                agentes.forEach((agente, i) => {
                    const x = margemEsquerda + i * (larguraBarra + espacamento);
                    ctx.fillStyle = document.body.classList.contains('modo-escuro') ? '#cbd5e1' : '#475569';
                    ctx.font = '9px Arial';
                    ctx.textAlign = 'center';
                    const nomeAgente = agente.length > 16 ? agente.substring(0, 16) + '...' : agente;
                    ctx.fillText(nomeAgente, x + larguraBarra / 2, canvas.height - 5);
                });

                // Animar barras em cascata
                let agenteIndex = 0;

                function animarProximaBarra() {
                    if (agenteIndex >= agentes.length) return;

                    const agente = agentes[agenteIndex];
                    const x = margemEsquerda + agenteIndex * (larguraBarra + espacamento);
                    const yBase = canvas.height - 50;

                    // Calcular alturas dos segmentos
                    const segmentos = [];
                    let alturaTotal = 0;
                    status.forEach(st => {
                        const item = statusAgente.find(s => s.agente === agente && s.status === st);
                        const valor = item ? item.total : 0;
                        if (valor > 0) {
                            const altura = valor * 15;
                            segmentos.push({ status: st, altura: altura, cor: cores[st] || '#6c757d' });
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
                            agenteIndex++;
                            setTimeout(animarProximaBarra, 150);
                        }

                        // Desenhar segmentos empilhados
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

                                // Mostrar nÃºmero dentro do segmento se altura >= 10px
                                if (alturaAtual >= alturaTotal && seg.altura >= 10) {
                                    const valor = seg.altura / 15;
                                    ctx.fillStyle = '#fff';
                                    ctx.font = 'bold 11px Arial';
                                    ctx.textAlign = 'center';
                                    ctx.fillText(valor, x + larguraBarra / 2, yOffset + seg.altura / 2 + 4);
                                }

                                alturaDesenhada += alturaSegmento;
                            }

                            if (alturaDesenhada >= alturaAtual) break;
                        }

                        // Mostrar total no topo da barra quando animaÃ§Ã£o completa
                        if (alturaAtual >= alturaTotal) {
                            const totalTickets = segmentos.reduce((sum, s) => sum + (s.altura / 15), 0);
                            ctx.fillStyle = document.body.classList.contains('modo-escuro') ? '#cbd5e1' : '#475569';
                            ctx.font = 'bold 12px Arial';
                            ctx.textAlign = 'center';
                            ctx.fillText(totalTickets, x + larguraBarra / 2, yBase - alturaTotal - 5);
                        }
                    }, 20);
                }

                animarProximaBarra();
                observer.unobserve(canvas);
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
                    { nome: 'Aberto', valor: funil.aberto || 0 },
                    { nome: 'Em Atendimento', valor: funil.em_atendimento || 0 },
                    { nome: 'Aguardando', valor: funil.aguardando || 0 },
                    { nome: 'Resolvido', valor: funil.resolvido || 0 },
                    { nome: 'Encerrado', valor: funil.encerrado || 0 }
                ];
                const maxValor = Math.max(...etapas.map(e => e.valor)) || 1;
                const cores = ['#f79226', '#17a2b8', '#ffc107', '#28a745', '#6c757d'];
                const alturaEtapa = 45;
                const espacamento = 15;

                ctx.clearRect(0, 0, canvas.width, canvas.height);

                // Animar etapas sequencialmente
                let etapaIndex = 0;

                function animarProximaEtapa() {
                    if (etapaIndex >= etapas.length) return;

                    const etapa = etapas[etapaIndex];
                    const larguraFinal = (etapa.valor / maxValor) * (canvas.width - 100);
                    const y = 10 + etapaIndex * (alturaEtapa + espacamento);
                    const centerX = canvas.width / 2;

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

                        // Desenhar barra expandindo do centro
                        const x = centerX - larguraAtual / 2;
                        ctx.clearRect(0, y, canvas.width, alturaEtapa);
                        ctx.fillStyle = cores[etapaIndex];
                        ctx.fillRect(x, y, larguraAtual, alturaEtapa);

                        // Mostrar texto quando completo
                        if (larguraAtual >= larguraFinal) {
                            ctx.fillStyle = '#fff';
                            ctx.font = 'bold 13px Arial';
                            ctx.textAlign = 'center';
                            ctx.fillText(`${etapa.nome}: ${etapa.valor}`, centerX, y + alturaEtapa / 2 + 5);
                        }
                    }, 20);
                }

                animarProximaEtapa();
                observer.unobserve(canvas);
            }
        });
    });
    observer.observe(canvas);
}

