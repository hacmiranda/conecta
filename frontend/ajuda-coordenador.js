// Ajuda → Guia do Coordenador (24/11/2026): tudo o que o coordenador configura no setor, com os prints das telas
// e o que é obrigatório para o setor funcionar. Conteúdo fixo (sem dados da API).
// Itens do menu: data-acao-ajuda="coordenador" (sidebar) e "coordenador-guia" (botões da tela Ajuda), tratados
// pelo ouvinte delegado de sidebar-navegacao.js. Visível para coordenador e admin (aplicarVisibilidadePorPapel).

// Monta uma figura com o print da tela; o clique abre a imagem inteira em outra aba
function figuraGuiaCoordenador(arquivo, legenda) {
    const caminho = 'images/ajuda/' + arquivo;
    return `<figure class="gc-figura">
        <a href="${caminho}" target="_blank" rel="noopener" title="Abrir a imagem em tamanho real (nova aba)">
            <img src="${caminho}" alt="Print da tela: ${legenda}" loading="lazy" width="1106" height="860">
        </a>
        <figcaption>${legenda} <span class="gc-figura-dica">(clique para ampliar)</span></figcaption>
    </figure>`;
}

// Selo de importância da configuração
function seloGuiaCoordenador(tipo) {
    const rotulos = { obrigatorio: 'Obrigatório', recomendado: 'Recomendado', opcional: 'Opcional', admin: 'Pedir ao administrador' };
    return `<span class="gc-selo gc-selo-${tipo}">${rotulos[tipo]}</span>`;
}

function mostrarGuiaCoordenador() {
    // Só coordenador e admin; para os demais (gestor, operador...) abre o guia padrão da Ajuda
    if (!Papeis.veGuiaCoordenador()) {
        mostrarSLA();
        return;
    }
    document.querySelectorAll('.btn-ajuda').forEach(btn => btn.classList.remove('active'));
    const botao = document.getElementById('btn-guia-coordenador');
    if (botao) botao.classList.add('active');
    localStorage.setItem('cd_subSecaoAtiva', 'guia-coordenador');

    const content = document.getElementById('ajuda-content');
    content.style.animation = 'none';
    setTimeout(() => {
        content.style.animation = '';
        content.innerHTML = `
        <div class="ajuda-card gc-guia">
            <h3>🧭 Guia do Coordenador: configurações do setor</h3>
            <p>Como coordenador(a), você configura os setores que coordena. Todas as telas ficam no menu
            <strong>Configurações</strong> da barra lateral e valem para o setor escolhido no <strong>seletor de setor</strong>,
            logo abaixo do nome do sistema. Se você coordena mais de um setor, escolha o setor antes de mexer:
            com “Todos os meus setores” as telas pedem que você escolha um.</p>

            <h4 id="gc-checklist">✅ Lista de conferência: o mínimo para o setor funcionar</h4>
            <p>Confira estes itens em cada setor que você coordena. Sem eles, o setor não recebe demandas, a equipe não
            consegue encerrar o atendimento ou as cobranças de relatório ficam erradas.</p>
            <table class="gc-tabela">
                <caption class="sr-only">Configurações essenciais do setor</caption>
                <thead>
                    <tr><th scope="col">Configuração</th><th scope="col">Onde</th><th scope="col">Importância</th><th scope="col">Por que</th></tr>
                </thead>
                <tbody>
                    <tr><td><a href="#gc-email">Caixa de e-mail do setor</a></td><td>Administrador</td><td>${seloGuiaCoordenador('admin')}</td>
                        <td>É por ela que as demandas chegam (cada e-mail recebido abre uma demanda) e saem as respostas.</td></tr>
                    <tr><td><a href="#gc-usuarios">Equipe (gestores e operadores)</a></td><td>Configurações → Usuários</td><td>${seloGuiaCoordenador('obrigatorio')}</td>
                        <td>Sem equipe ninguém atende as demandas nem envia o Registro de Atividades.</td></tr>
                    <tr><td><a href="#gc-categorias">Categorias e subcategorias</a></td><td>Configurações → Categorias</td><td>${seloGuiaCoordenador('obrigatorio')}</td>
                        <td>A demanda precisa de subcategoria para ser respondida e encerrada; também organizam os relatórios.</td></tr>
                    <tr><td><a href="#gc-horarios">Horários e feriados</a></td><td>Configurações → Horários e Feriados</td><td>${seloGuiaCoordenador('obrigatorio')}</td>
                        <td>Definem os dias úteis do setor: usados no SLA e nas cobranças de relatório de atividades.</td></tr>
                    <tr><td><a href="#gc-atividades">Atividades e regras de prazo</a></td><td>Configurações → Atividades</td><td>${seloGuiaCoordenador('obrigatorio')}</td>
                        <td>Sem atividades cadastradas a equipe não tem o que lançar; sem as regras, o prazo não é cobrado.</td></tr>
                    <tr><td><a href="#gc-envio-emails">Envio de e-mails</a></td><td>Ativação por Setor → Envio de E-mails</td><td>${seloGuiaCoordenador('recomendado')}</td>
                        <td>Todos começam ligados; confira se o setor deve avisar o solicitante em cada etapa.</td></tr>
                    <tr><td><a href="#gc-sla">SLA (prazos de atendimento)</a></td><td>Ativação por Setor → SLA</td><td>${seloGuiaCoordenador('recomendado')}</td>
                        <td>Começa desligado. Ligue quando o setor tiver prazos combinados para responder e resolver.</td></tr>
                    <tr><td><a href="#gc-status">Status</a>, <a href="#gc-filtros">filtros de e-mail</a>,
                        <a href="#gc-relatorio-setor">relatório do setor</a>, <a href="#gc-respostas">respostas rápidas</a></td>
                        <td>Várias telas</td><td>${seloGuiaCoordenador('opcional')}</td>
                        <td>Ajustes finos: use conforme a rotina do setor.</td></tr>
                </tbody>
            </table>

            <h4 id="gc-email">📧 Caixa de e-mail do setor ${seloGuiaCoordenador('admin')}</h4>
            <p>O endereço de e-mail do setor (por onde chegam as demandas e saem as respostas) é cadastrado pelo
            <strong>administrador</strong> em Configurações → Configurar E-mail. Se o seu setor ainda não recebe demandas por
            e-mail, peça ao administrador que cadastre a caixa. Sem caixa própria, as respostas saem pelo remetente geral do
            sistema.</p>

            <h4 id="gc-usuarios">👥 Usuários (equipe do setor) ${seloGuiaCoordenador('obrigatorio')}</h4>
            ${figuraGuiaCoordenador('usuarios.png', 'Configurações → Usuários')}
            <ul>
                <li><strong>Adicionar Novo Usuário</strong>: cadastre os <strong>gestores</strong> e <strong>operadores</strong> do
                setor. Informe nome, e-mail, nome de usuário, perfil, setor e uma senha inicial: no primeiro acesso a pessoa é
                obrigada a trocá-la.</li>
                <li><strong>Gestor</strong> vê todas as demandas do setor e distribui o trabalho; <strong>operador</strong> atende
                as demandas que são dele e pega as da fila do setor (o quadro no topo da tela explica a diferença).</li>
                <li>Você também <strong>edita</strong>, <strong>desativa</strong> e <strong>redefine a senha</strong> deles e pode
                movê-los entre os seus setores. Ao desativar ou mover alguém, as demandas abertas dessa pessoa voltam para o setor.</li>
                <li>Você não cadastra nem edita coordenadores (nem a si mesmo): isso é com o administrador.</li>
            </ul>

            <h4 id="gc-categorias">📁 Categorias e subcategorias ${seloGuiaCoordenador('obrigatorio')}</h4>
            ${figuraGuiaCoordenador('categorias.png', 'Configurações → Categorias')}
            <ul>
                <li>Crie primeiro as <strong>categorias</strong> (grandes assuntos, ex.: “Atendimento”) e depois as
                <strong>subcategorias</strong> dentro delas (ex.: “Informação”, “Reclamação”).</li>
                <li>A equipe escolhe a subcategoria na demanda; sem ela, o sistema não deixa responder nem encerrar.</li>
                <li>Subcategoria já usada em demandas não pode ser excluída: prefira renomear.</li>
            </ul>

            <h4 id="gc-horarios">⏰ Horários e feriados ${seloGuiaCoordenador('obrigatorio')}</h4>
            ${figuraGuiaCoordenador('horarios.png', 'Configurações → Horários e Feriados')}
            <ul>
                <li>Em <strong>Horários Úteis de Atendimento</strong>, marque os dias em que o setor trabalha, ajuste o início e o
                fim do expediente e clique em <strong>Salvar</strong> em cada linha alterada.</li>
                <li>Em <strong>Calendário de Feriados</strong>, cadastre os feriados e os pontos facultativos do setor (há a opção
                de repetir todo ano).</li>
                <li>Esses dias e horários valem para o <strong>SLA</strong> (só contam horas úteis) e para a cobrança do
                <strong>Registro de Atividades</strong> (fins de semana e feriados nunca são cobrados).</li>
                <li>O setor novo já vem com o expediente e os feriados do modelo do sistema: confira se batem com a realidade.</li>
            </ul>

            <h4 id="gc-atividades">📝 Atividades e regras de prazo ${seloGuiaCoordenador('obrigatorio')}</h4>
            ${figuraGuiaCoordenador('atividades.png', 'Configurações → Atividades: atividades do setor')}
            <ul>
                <li><strong>Atividades do setor</strong>: escreva as tarefas que a equipe lança no Registro de Atividades, uma por
                linha, e clique em <strong>Adicionar</strong>. Dá para renomear, mudar a ordem e desativar. Atividade já registrada
                não pode ser excluída, só desativada.</li>
            </ul>
            ${figuraGuiaCoordenador('atividades-regras.png', 'Configurações → Atividades: regras de prazo do setor')}
            <ul>
                <li><strong>1. Vale para as duas cobranças</strong>: o prazo (em dias úteis) para enviar o relatório depois do dia
                cobrado e a data em que a cobrança começa. Em branco, vale o dia em que você salvar.</li>
                <li><strong>2. Trabalho remoto</strong>: semanal (uma quantidade de relatórios remotos por semana) ou diário (dias
                fixos de trabalho remoto).</li>
                <li><strong>3. Entrega de relatório</strong>: marque <strong>Cobrar entrega nos dias</strong> se todos do setor
                devem enviar o relatório em dias certos (trabalhem remoto ou não).</li>
                <li>Clique em <strong>Salvar regras do setor</strong>. Sem regras salvas, ninguém do setor é cobrado.</li>
            </ul>
            ${figuraGuiaCoordenador('atividades-servidores.png', 'Configurações → Atividades: regras por servidor')}
            <ul>
                <li><strong>Regras por servidor</strong> ${seloGuiaCoordenador('recomendado')}: para cada gestor e operador, ligue
                <strong>Trabalha remoto</strong> e informe a cota (ou os dias) de quem trabalha remoto; na entrega de relatório,
                a pessoa pode seguir o setor, ser dispensada ou ter dias próprios. A coluna <strong>O que é cobrado</strong> mostra
                o resultado; clique em <strong>Salvar</strong> em cada linha.</li>
                <li>Quem não tem “Trabalha remoto” ligado só é cobrado pela entrega de relatório.</li>
            </ul>

            <h4 id="gc-relatorio-setor">📑 Relatório do setor pelo coordenador ${seloGuiaCoordenador('opcional')}</h4>
            ${figuraGuiaCoordenador('atividades-relatorio-setor.png', 'Configurações → Atividades: bloco 4, relatório do setor')}
            <ul>
                <li>No bloco <strong>4. Relatório do setor pelo coordenador</strong>, você pode trocar os relatórios individuais por
                <strong>um relatório só, enviado por você</strong>, semanal ou mensal. Enquanto estiver ligado, gestores e
                operadores do setor ficam desobrigados.</li>
                <li>Depois de ligar, envie o relatório em <strong>Registro de Atividades</strong> a cada semana ou mês. O período
                não enviado vira pendência; você mesmo pode abonar, com justificativa, no Painel de Atividades.</li>
                <li>Detalhes em Ajuda → <strong>Relatório do Setor</strong>.</li>
            </ul>

            <h4 id="gc-status">🚦 Ativação por Setor → Status ${seloGuiaCoordenador('opcional')}</h4>
            ${figuraGuiaCoordenador('ativacao-status.png', 'Configurações → Ativação por Setor: Status')}
            <ul>
                <li>Liga ou desliga os status que aparecem para o setor (ex.: “Aguardando documento”) e muda a ordem deles.</li>
                <li>Os status essenciais (Novo, Nova resposta, Reaberto e Encerrado) ficam sempre ligados: o sistema depende deles.</li>
            </ul>

            <h4 id="gc-envio-emails">🔔 Ativação por Setor → Envio de E-mails ${seloGuiaCoordenador('recomendado')}</h4>
            ${figuraGuiaCoordenador('ativacao-emails.png', 'Configurações → Ativação por Setor: Envio de E-mails')}
            <ul>
                <li>Cada e-mail automático do setor tem um interruptor, em dois grupos: <strong>Para solicitantes</strong> (demanda
                criada, resposta da equipe, encerrada, pesquisa de satisfação…) e <strong>Para agentes internos</strong>.</li>
                <li>Todos começam <strong>ligados</strong>. Desligado, o e-mail simplesmente não sai; a demanda continua normal.</li>
                <li>Atenção: com <strong>Resposta da equipe</strong> desligado, as respostas digitadas na demanda não chegam ao
                solicitante.</li>
            </ul>

            <h4 id="gc-sla">⏱️ Ativação por Setor → SLA ${seloGuiaCoordenador('recomendado')}</h4>
            ${figuraGuiaCoordenador('ativacao-sla.png', 'Configurações → Ativação por Setor: SLA')}
            <ul>
                <li>O SLA é o prazo para a <strong>1ª resposta</strong> e para a <strong>resolução</strong> da demanda, contado só em
                horário útil do setor. Ele começa <strong>desligado</strong>: as demandas ficam sem selo de prazo.</li>
                <li>Para ligar: preencha, para cada prioridade, os prazos em dias, horas e minutos (1 dia = a jornada do setor) ou
                marque <strong>Sem SLA</strong>; depois ligue <strong>Contar SLA neste setor</strong> e clique em
                <strong>Salvar</strong>. Só é possível ligar com todas as prioridades preenchidas.</li>
                <li>A resolução precisa ser igual ou maior que a 1ª resposta. A prioridade Baixíssima nunca tem SLA.</li>
                <li>Quer os prazos padrão do CRPRS (Urgente 2h/4h, Alta 4h/8h, Normal 12h/24h, Baixa 24h/40h)? Peça ao
                administrador para aplicar o modelo <strong>Padrão CRPRS</strong> no setor.</li>
                <li>Desligar pausa na hora a contagem das demandas abertas; ao religar, o tempo em que ficou desligado não conta.</li>
            </ul>

            <h4 id="gc-filtros">🚫 Filtros de E-mail ${seloGuiaCoordenador('opcional')}</h4>
            ${figuraGuiaCoordenador('filtros-email.png', 'Configurações → Filtros de E-mail')}
            <ul>
                <li>Evitam que propaganda, newsletter e avisos automáticos virem demanda: escolha o campo (remetente, destinatário,
                assunto ou corpo), o texto que ele deve conter e clique em <strong>Adicionar filtro</strong>.</li>
                <li>Use <strong>Testar um e-mail</strong> antes de cadastrar: um texto muito comum (ex.: “obrigado”) barraria
                e-mails legítimos.</li>
                <li>Respostas de quem já participa de uma demanda nunca são barradas.</li>
            </ul>

            <h4 id="gc-respostas">⚡ Respostas rápidas ${seloGuiaCoordenador('opcional')}</h4>
            <p>No menu do seu avatar, <strong>Respostas Rápidas</strong> guarda textos prontos que a equipe insere na resposta de
            uma demanda. Cadastre as respostas mais comuns do setor para padronizar o atendimento.</p>

            <h4 id="gc-so-admin">🔒 O que só o administrador faz</h4>
            <ul>
                <li>Criar, renomear e excluir setores; cadastrar coordenadores e dar setores adicionais a eles.</li>
                <li>Cadastrar a caixa de e-mail do setor (Configurar E-mail).</li>
                <li>Ativar prioridades por setor e aplicar o modelo de SLA “Padrão CRPRS”.</li>
                <li>Configurar a pesquisa de satisfação, a personalização do sistema e consultar a auditoria.</li>
            </ul>
        </div>
    `;
    }, 10);
}
