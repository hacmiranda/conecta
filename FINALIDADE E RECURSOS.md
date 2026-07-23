# Análise do Sistema: CRPDESK (Helpdesk e Gestão de Chamados)

## 1 - Qual a finalidade do sistema?
A finalidade principal do **CRPDESK** é atuar como um sistema centralizado de *Helpdesk* para o Conselho Regional de Psicologia do Rio Grande do Sul (CRPRS). Ele foi desenvolvido para gerenciar e organizar eficientemente todas as solicitações de suporte, chamados e atendimentos internos e externos. O sistema otimiza o fluxo de trabalho das equipes, garante o cumprimento do Acordo de Nível de Serviço (SLA) e promove a comunicação automatizada e transparente entre os atendentes e os usuários solicitantes.

## 2 - Quais recursos o sistema entrega para os utilizadores?
O sistema disponibiliza um amplo conjunto de ferramentas para diferentes perfis de usuários (Clientes, Agentes e Administradores), destacando-se:

*   **Gestão Completa de Tickets (Chamados):**
    *   Abertura de tickets via formulário web ou de forma automatizada através do envio de e-mails.
    *   Categorização estruturada (macrocategorias e categorias) e classificação de prioridades (Baixa, Normal, Alta, Urgente).
    *   Atribuição de tickets para os agentes (automática ou manual).
    *   Possibilidade de mesclar tickets duplicados e reabrir chamados previamente encerrados.

*   **Controle de SLA (Service Level Agreement):**
    *   Cálculo automático do prazo limite de atendimento com base na prioridade do chamado, considerando apenas o horário comercial útil (excluindo feriados e fins de semana).
    *   Ferramenta para pausar e retomar a contagem do tempo de SLA.
    *   Alertas automáticos para chamados com SLA em risco ou vencido.

*   **Sistema de E-mails Inteligente e Comunicação:**
    *   Integração IMAP para leitura de caixas de e-mail institucionais, convertendo as mensagens diretamente em tickets.
    *   Envio automático de notificações a cada evento do ciclo de vida do ticket (criação, nova resposta, mudança de status, atribuição e encerramento).
    *   Interface interna para troca de comentários entre agentes e usuários, suportando múltiplos arquivos anexos e alerta de leitura.

*   **Gestão de Usuários, Segurança e Auditoria:**
    *   Três níveis de permissão bem definidos: Cliente (solicitante), Agente (atendente) e Admin (gestor).
    *   Recuperação de senha segura e autenticação moderna via tokens (JWT).
    *   Trilha de auditoria minuciosa, que registra um log completo das ações e alterações realizadas na plataforma.

*   **Ferramentas de Organização e Produtividade:**
    *   Módulo de Lembretes vinculados aos tickets, com disparos automáticos por e-mail na data e hora agendadas pelo agente.
    *   Dashboard analítico e gerencial com visão panorâmica, gráficos de desempenho e relatórios de tempo de resposta.

*   **Personalização Institucional:**
    *   Configurações flexíveis para adequar as cores do sistema, logomarca oficial e customizar os templates HTML de e-mail de acordo com a identidade visual da instituição.
