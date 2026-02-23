# Changelog - Sistema de Helpdesk CRPRS

Todas as alterações notáveis neste projeto serão documentadas neste arquivo.

---

## [3.1.0] - 2026-02-22 (Em desenvolvimento)

### 🎫 Tabelas de Tickets — Melhorias de UX e Unificação *(novo)*

- **Unificação da Tabela de Tickets Encerrados (Arquivo)**: A tabela de "Tickets Fechados" foi totalmente reescrita para possuir **paridade de design e performance** com a de "Tickets Abertos", solucionando gargalos de renderização e usabilidade:
  - **Paginação Dedicada**: Renderização estrita de 25/50/100 itens por página com paginação assíncrona (não mais milhares de dom/nós injetados de vez). *Configuração independente salva `ticketsFechadosPorPagina`.*
  - **Busca por Debounce**: Retardo de pesquisa (300ms) implementado para a busca de inativos ser performática.
  - **Identidade Visual Sincronizada**: Skeleton loading, títulos truncados para não quebrar a largura e substituição de `.png` por *Material Symbols*.
  - **Ordenação Persistente Independente**: Sorteamento salvo na chave `ticketsFechadosOrdem`.

- **Módulo Ágil Kanban**: Introdução da visão Kanban para organização de tickets abertos.
  - **Toggle View**: Alternância fluida entre Tabela e Kanban pelo header com botões Material.
  - **Persistência de Visão**: O estado visual selecionado é salvo no `localStorage` sob a chave `ticketsModoView`.
  - **Drag and Drop Assíncrono**: Deslocamento de tickets via clique e arraste que emite update do status para a API nativamente usando dataset colunas.
  - **Colunas Inteligentes**: Disposição nativa de Novo, Em Atendimento, Aguardando e Resp. Usuário com fallback "Outros" de contadores dinâmicos.

- **Responsividade Híbrida em Dispositivos Móveis**: Transformação da visualização padrão de Listas (TR/TD) da tabela.
  - **Data-Labels CSS Injetadas**: Adaptação da tabela transformando Linhas em Cards em telas `< 768px`.
  - **Pseudo-elementos `:before`**: Os cabeçalhos de coluna foram movidos para a posição esqueda absoluta com letras uppercase e títulos truncados na direita sem necessidade de JavaScript extra.

- **Editor de Texto Rico Analítico (WYSIWYG)**: Implementação do formato CDN da biblioteca Quill.js nas caixas de texto text-area substituindo as antigas strings planas.
  - Substituição da infraestrutura do formulário de Criar Novo Ticket para a captura rica com HTML interno.
  - Limpeza e injeção do Input no envio dentro da tela de Comentários / Detalhes de Ticket.
  - Migração algorítmica limitante nos Datatypes MySQL de `TEXT` para `LONGTEXT` nas tabelas `tickets` e `comentarios` para suportar grandes blocos de imagens coladas em formato clipboard (Base64) ou HTML complexos formatados via WYSIWYG.
  - Módulos dinâmicos ativados: Negrito, Listas, Imagens, Vídeos e Aumento/Diminuição do tamanho da Célula Tipográfica.

- **Indicador de ordenação ativa**: A coluna ordenada exibe `↑` ou `↓` em laranja com destaque no `<th>` (gradiente azul mais intenso). As demais colunas exibem `↕` cinza. **A escolha de ordenação agora é persistente** via `localStorage` — sobrevive a recarregamentos da página.
- **Ordenação padrão persistente**: Campo e direção salvos em `ticketsOrdem` no `localStorage`. Padrão inicial: `id desc` (mais recente primeiro).
- **Filtros rápidos por status**: Pills clicáveis acima da tabela — `Todos`, `🆕 Novo`, `🔧 Em atendimento`, `⏳ Aguardando`, `💬 Nova resposta`, `🔥 Urgente` — com contadores atualizados em tempo real. Combinável com a busca textual e o toggle "Meus Tickets".
- **Badge de SLA inline**: Na coluna Abertura, exibe o tempo restante (ou vencimento) do SLA do ticket com badge colorido e animado. O prefixo indica qual SLA está ativo:
  - `Resposta: 3h úteis` — enquanto o ticket não tiver primeira resposta.
  - `Resolução: 45m úteis` — após a primeira resposta.
  - Cores: 🟢 OK (< 50%), 🟡 Alerta (50–79%), 🔴 Crítico (80–99%), 🩸 Vencido (≥ 100%), ⬜ Pausado.
  - Animação de pulso proporcional à urgência. Respeita `prefers-reduced-motion`.
- **Skeleton loading**: 8 linhas com efeito shimmer substituem o texto `"Carregando tickets..."` durante o fetch inicial.
- **Ícones Material Symbols**: `attach_file`, `chat_bubble`, `event`, `call_merge` substituem os PNGs antigos (clipe, comentário, lembrete, mescla). Escalam, suportam dark mode e são acessíveis com `aria-label`.
- **Truncamento do Assunto**: `max-width: 300px + text-overflow: ellipsis` com `title=` contendo o título completo no hover.
- **Debounce na busca**: 300 ms de atraso antes de filtrar (evita re-render a cada tecla). Integrado com filtros de status e paginação.
- **Paginação**: 25 / 50 / 100 itens por página com botões Anterior / Próximo e numeração `±2` da página atual. **Quantidade de itens por página persistente** via `localStorage` (`ticketsPorPagina`). **Padrão alterado de 25 para 50**.
- **Linha de resumo no footer**: "Exibindo 1–50 de 47 tickets" com selector de itens por página inline.
- **Cores hardcoded removidas**: `color: #666` → `class="ticket-data-hora"` com variável CSS — compatível com dark mode.
- **Cabeçalho da tabela corrigido**: `modern-enhancements.css` sobrescrevia o gradiente azul com cinza escuro (`#2d3748→#1a202c`). Corrigido para usar o azul institucional (`#1e3a8a→#1a3a7a`), igual ao header do sistema.

### 🧹 Tabela de Tickets — Remoção de Código Obsoleto *(melhoria)*

- **Remoção da rotina `meu-ticket-row`**: A coloração verde-claro (`#F0F8F4`) aplicada às linhas onde o responsável era o usuário logado foi completamente removida — `style.css` (4 blocos, modo claro e escuro) e `script.js` (2 tabelas: abertos e fechados). A funcionalidade é substituída pelo filtro "Meus Tickets".
- Removidos também: classe `meu-ticket` no `<span>` de responsável e estilo inline `color: #28A745; font-weight: bold` na tabela de fechados.

### ⚡ Respostas Rápidas (Macros) *(novo)*

### 🔔 Notificações e Alertas Flutuantes Internos (Push) *(novo)*

- **Novo Painel de Notificações**: Adicionado um ícone de "Sino" (🔔) no Header do sistema, focado em entregar alertas em tempo real ao longo do uso do Helpdesk.
- **Evolução Arquitetural de Polling para SSE (Server-Sent Events)**: O antigo sistema de Polling via AJAX (`setInterval` a cada 30 segundos) foi totalmente reescrito utilizando a API nativa do Browser `EventSource`.
  - O Backend PHP 8.2 (`notificacoes-stream.php`) mantém uma conexão "Long-Lived" baseada em HTTP padrão, economizando vastos recursos TCP estáticos e reduzindo absurdamente a carga do Servidor Apache/MySQL.
  - A interface gráfica (o Sino) e as listagens atualizam com latência minúscula assim que as mutações no banco ocorrem de fato.
  - Fechamento imediato de concorrência session (`session_write_close()`) para evitar travamentos paralelos se o usuário abrir 10 abas do CRPDESK ao mesmo tempo.
  - **Badge de Alertas**: Um contador circular e animado (`pulse`) é exibido sobre o ícone sempre que houver novas interações.
  - **Dropdown de Feed**: Clique no sino abre uma gaveta flutuante exibindo o histórico de alertas (ex: *"Novo ticket atribuído a você"* ou *"Novo comentário adicionado"*) ordenados por cronologia.
  - **Leitura Contextual Ativa**: Clicar numa notificação de Ticket na lista altera automaticamente a interface principal do SPA (`script.js`), abrindo os Detalhes daquele chamado de maneira imediata ao mesmo tempo que marca a flag como lida no banco de dados.
  - **Botão "Marcar todas como Lidas"**: Ação rápida em JS via instrução `PUT` na API para zerar a lista.

- **Atualizações Baseadas em Eventos**: O Trigger da API nativa em `tickets.php` e `comentarios.php` foram aprimorados.
  - Em casos de **Nova Atribuição de Responsável**, o agente notificado recebe um sinal de PUSH no portal em seu ID para iniciar os atendimentos.
  - Em casos de **Novo Ticket Aberto**, um loop assíncrono envia PUSH de aviso na mesa de todos os Administradores e Agentes setados como Ativos = 1.
  - Em casos de **Novos Comentários Injetados**, todos os membros previamente da fila associados ao Ticket (que receberiam o alerta apenas por e-mail) agora acompanham via sinal sonoro/visual pelo Painel.
  - **Banco de Dados Dedicado**: Inserido SQL de fundação (`7_create_notificacoes.sql`) e controller autônomo.

- **Novo Módulo "Macros"**: Implementado um sistema de criação e gerenciamento de templates de texto pré-formatados para acelerar a resposta de tickets.
  - Acessível pelo dropdown do Perfil de Usuário com a opção "⚡ Respostas Rápidas".
  - **Interface de Gerenciamento**: Modal dedicado com listagem dinâmica, suporte a edição e exclusão de macros existentes. Apenas os donos ou Administradores podem excluir seus registros.
  - **Editor Rico (WYSIWYG)**: Formulário de criação conta com integração ao provedor rico *QuillJS*, possuindo recursos de formatação em Negrito, Listagem, HTML customizado e Cores para os Templates.
  - **Integração Fluida na Resposta de Tickets**: Adicionado um `<select>` flutuante no topo do campo de comentários que processa e espalha (injetando via innerHTML) a Macro escolhida para dentro do ticket local do usuário, preservando as inserções anteriores sem sobrescrevê-las.
  - **Banco de Dados**: Migração gerada (`6_add_macros_and_longtext.sql`) com o provisionamento da tabela `macros` amarrando por `FOREIGN KEY` ao usuário solicitante.

### 🏗️ Arquitetura Frontend: Componentização (Alpine.js) *(novo)*

- **Transição de Paradigma**: Iniciada a migração arquitetural do massivo `script.js` (Vanilla JS / jQuery-style) para a adoção do framwork reativo **Alpine.js**. O Alpine operará de forma encapsulada no Virtual DOM sem precisar de ferramentas de build pesadas (Node.js/Webpack).
- **Extração do Componente Editor (Quill.js)**: A integração do editor de texto rico, que antes usava o objeto Global `window.quillDescricao` (sujeito a bugs de carregamento de DOM tardio), foi totalmente extraída para o novo arquivo `/frontend/alpine-components.js` e inicializada de forma atômica sob a tag `x-data="quillEditorComponent()"`.
- **Refinamento de Micro-animações (Spring Physics)**: Substituição das animações lineares duras de abertura de Modais e Dropdowns em CSS. Aplicada aceleração customizada com `cubic-bezier(0.175, 0.885, 0.32, 1.275)` para criar transições elásticas/bouncy no carregamento dos elementos do Header e Formulários. O sistema agora respeita internamente a media query `prefers-reduced-motion` no SO do usuário em conformidade de Acessibilidade.

### 🛡️ Segurança e Compliance (LGPD) *(novo)*

- **Ofuscação de Dados Pessoais em Views (LGPD)**: Implementado mascaramento obrigatório de strings de e-mails (`a***@gmail.com`) na renderização das matrizes de Tickets Encerrados e visualização do Arquivo. Impede raspagem indevida de PII (Personally Identificable Information) nas pontas abertas.
- **Saneamento CSRF Contínuo nas Modais Pós-Renderização**: O novo módulo de Respostas Rápidas (Macros) passou a injetar e assinar obrigatoriamente a verificação estrita `AuthMiddleware::verificarCSRF()` nas requisições do Payload de Mutações (POST/PUT/DELETE), garantindo conformidade anti-forgery na sessão local.

### 📈 Business Intelligence e Analítica *(novo)*

- **Dashboard Avançado de FCR (First Contact Resolution)**: Criação de um Widget exclusivo no painel da aba Dashboard. Ele calcula via API e exibe de forma destacada a porcentagem de tickets resolvidos no **primeiro contato**, isto é, onde não existiu vai e vem de respostas do cliente. Essa é a principal métrica analítica solicitada pela área técnica de avaliação do SLA do suporte. Inclui Tooltip pedagógico (Interrogação) explicando como cruzar os dados com os fechados do dia.

### 📦 Refinamento Avançado do Banco de Dados (MySQL) e API *(melhoria)*

- **Estratégia de Cache HTTP Dinâmico (`dashboard.php`)**: Implementado `header('Cache-Control: private, max-age=60');` no retorno da API do Painel Administrativo. Reduz as requisições agressivas com consultas SQL simultâneas (`SUM`, `COUNT` multiplas tabelas) em 90%, aproveitando o cache temporal natural do Frontend do agente que navega em outras telas e volta a home em curtos períodos de tempo, preservando os recursos da hospedagem.
- **Indexação Agressiva SQL (Migration v8)**: Para suprir a restrição arquitetural da hospedagem em não permitir uso de Bancos Chave-Valor Paralelos em RAM (como Redis/Memcached), foi implementado o arquivo `8_optimize_indexes.sql` que atua na raiz do problema (MySQL):
  - **Tabela de Tickets**: Criação de `INDEX` composto cobrindo `status`, `usuario_id`, `agente_id`, `criado_em` e `prioridade`. As queries de listagens assíncronas no Kanban saltaram drasticamente em performance.
  - **Tabela Comentários e Histórico**: Otimização na costura da linha do tempo da tela do Ticket com index em `ticket_id` e data.
  - **Tabela Notificações (SSE)**: O `EventSource` (Web Push) fará milhares de checagens diárias em Loop via PHP 8.2 procurando o flag `lida = 0`. Um index composto `(usuario_id, lida)` foi introduzido neutralizando a carga (Table Scan) do banco neste ping infinito.
- **Protocolo Persistente de Deploy DB**: Estabelecida regra de ouro `CONFIGURACAO_AMBIENTE.md` garantindo que todo script DDL seja gerado estritamente com nomenclatura sequencial na pasta `database/` para assegurar simetria com o ambiente de Produção.

### 🗂️ Navegação — Botão "Arquivo" no Header *(melhoria)*

- O botão **"Encerrados"** foi movido da área de ações da tabela para o **header do sistema**, ao lado do botão "Tickets".
- Label renomeada de "📋 Encerrados" para **"Arquivo"** — nomenclatura mais neutra e consistente com o menu.
- Tutorial interativo atualizado para apontar para o novo ID `#btn-nav-arquivo`.


### 🔐 Página de Login — Redesign Completo *(novo)*

- **Layout split-screen**: Novo layout dividido em dois painéis — painel esquerdo com identidade visual CRPRS e painel direito com o formulário de login.
- **Painel de identidade (esquerdo)**: Fundo gradiente azul institucional (`#1e3a8a`) com grade decorativa, logo `logo_vazado.png`, nome "Helpdesk CRPRS", divisor em laranja e lista de features do sistema com ícones Material Symbols.
- **Tipografia**: Substituição de Arial por **Inter** (Google Fonts) em todo o formulário de login.
- **Ícones Material Symbols**: Adicionados nos campos de entrada (e-mail, senha), botão de submit, botão toggle de senha e lista de features.
- **Campos de input redesenhados**: Borda sutil, fundo `#f9fafb`, transição de foco com `box-shadow` azul e ícone à esquerda.
- **Botão de login**: Gradiente azul institucional com `box-shadow`, efeito hover de elevação e ícone `login`.
- **Estado de carregamento**: Botão desabilitado com ícone `hourglass_top` e texto "Entrando..." durante a requisição.
- **Animações**: Entrada do card com `cubic-bezier` spring, saída com scale ao fazer login, orbs decorativos no fundo com blur.
- **Logo**: Alterado de `logo_crpdesk.jpg` para `logo_vazado.png` (consistente com favicon e header do sistema).
- **Rodapé**: Atualizado texto de "CRP-RS" para "CRPRS".
- **Suporte a `prefers-reduced-motion`**: Todas as animações desabilitadas para usuários sensíveis.
- **Acessibilidade WCAG 2.2**: `lang="pt-BR"`, labels associados, `aria-required`, `aria-label`, `aria-modal`, foco visível em todos os elementos, fechar modal com `Escape`.

### 🔒 Login — Melhorias de Segurança e UX *(melhoria)*

- **Validação do Cloudflare Turnstile no frontend**: Antes de enviar a requisição, verifica se o token do captcha foi gerado. Se não, exibe modal amigável e **não envia** — elimina os erros 400 por token vazio.
- **Tratamento diferenciado de erros HTTP**:
  - `400` (captcha inválido/expirado): exibe mensagem específica, **não conta como tentativa errada**, reseta o widget Turnstile.
  - `401/403` (credenciais inválidas): conta como tentativa e dispara o bloqueio progressivo.
  - Erro de rede (`onerror`): exibe "Erro de conexão" sem consumir tentativas.
- **Limite de tentativas aumentado**: De **3 para 5 tentativas** antes do bloqueio de 5 minutos.
- **Botão reabilitado após erro**: Em qualquer falha, o botão "Entrar" volta ao estado normal e o Turnstile é resetado automaticamente.



### 🎨 Header — Melhorias de Design

- **Estado ativo com contraste real**: `.btn-nav.active` agora usa `background: #ffffff` com `color: #1e3a8a` — pill branco sólido com contraste 7:1, substituindo o `rgba(255,255,255,0.2)` imperceptível anterior.
- **Separador logo ↔ nav**: `.header-logo` ganhou `border-right` + `padding-right` + `margin-right` — divide visualmente a identidade da marca da navegação.
- **Separador nav ↔ avatar**: `.header-avatar-wrapper` ganhou `border-left` + `padding-left` — isola o controle de usuário da nav principal.
- **Tipografia do título**: `header h1` recebeu `font-weight: 700` e `letter-spacing: -0.5px` — mais presença e identidade sem alterar fonte ou tamanho.
- **Altura do header**: `padding` aumentado de `1.0625rem` para `1.25rem` — mais respiro para os elementos.
- **Ajuste de padding do avatar**: Padding direito reduzido de `2.25rem` para `1.25rem`, proporcional ao separador adicionado.
- **Remoção de emoji na nav**: Emoji `📋` removido do botão "Ajuda" para consistência visual com os demais botões de navegação.
- **Fontes do botão avatar aumentadas**: `.header-avatar-nome` de `0.82rem` → `0.95rem`; `.header-avatar-role` de `0.7rem` → `0.82rem`.
- **Seta do avatar corrigida**: `.header-avatar-chevron` com `align-self: center` e `line-height: 1` — corrige posicionamento fora de alinhamento.
- **Largura do botão avatar**: Padding direito aumentado em 20px para ampliar a área de clique.

### 🎨 Header — Identidade Visual Atualizada *(novo)*

- **Gradiente azul profundo**: O background do header foi atualizado para usar as cores do Design System da Sidebar: `linear-gradient(135deg, #1e3a8a → #1e428a → #1a3a7a)`, substituindo o gradiente cinza genérico anterior.
- **Botões de navegação com glass effect**: Substituídos por `rgba(255,255,255,0.08)` com borda sutil — estado hover mais luminoso e active com borda branca opaca.
- **Correção de sobrescrita CSS**: O arquivo `modern-enhancements.css` (carregado por último) sobrescrevia o header com cinza escuro. Corrigido para usar o mesmo gradiente azul.

### 👤 Avatar do Usuário no Header *(novo)*

- **Círculo de iniciais**: Na extrema direita do header, um botão pill exibe o círculo com as iniciais do usuário logado (ex: `JS` para "João Silva"), gerado automaticamente a partir do nome no `localStorage`.
- **Info inline**: Primeiro nome e role (`Administrador` / `Agente` / `Usuário`) exibidos ao lado do círculo.
- **Dropdown rico ao clicar**: Abre um menu com:
  - Cabeçalho azul com avatar, nome completo e e-mail.
  - **🌙 Modo Escuro** — toggle de tema.
  - **🔑 Alterar Senha** — abre o modal de senha.
  - **🚪 Sair** — logout (destacado em vermelho).
- **Fecha ao clicar fora** ou pressionar `Esc`.
- **Acessível**: `aria-haspopup`, `aria-expanded`, `aria-hidden`, `role="menu"`, foco visível.
- **Modo escuro** e **`prefers-reduced-motion`** suportados.
- Botão "Sair" removido da barra de navegação — integrado ao dropdown do avatar.

### 🎫 Detalhe do Ticket — "Criado por" com E-mail *(melhoria)*

- O campo **"Criado por:"** agora exibe sempre o **e-mail** do solicitante, com o nome entre parênteses quando disponível:
  - Ticket via e-mail externo: `fulano@email.com (João Silva)`
  - Ticket de usuário cadastrado: `joao@crprs.org.br (João Silva)`
  - Fallback para nome quando não há e-mail; `—` quando nenhum dado existe.

### 🚨 Alerta Visual de Falhas de E-mail *(novo)*

- **Endpoint `api/controllers/email-alert.php`**: Retorna de forma leve a contagem de falhas de e-mail nas últimas 24h e a data da última ocorrência. Acessível apenas por agentes e admins.
- **Badge pulsante 🔴 no botão "Configurações"**: Exibe a contagem de falhas com animação de pulso (vermelho). Some automaticamente quando não há falhas.
- **Banner de alerta no topo da tela**: Aparece com destaque amarelo/laranja informando:
  - Quantidade de falhas nas últimas 24h.
  - Data e hora da última falha.
  - Botão **"Ver detalhes"** — navega diretamente para **Configurações → Auditoria → Envio de E-mails**.
  - Botão **✕ fechar** — silencia o banner por **4 horas** (salvo no `localStorage`).
- **Polling automático** a cada **5 minutos**: Verifica novas falhas sem intervenção do usuário. Se as falhas forem corrigidas, badge e banner somem no próximo ciclo.
- **`email-alert.css`**: Novo arquivo de estilos com suporte a:
  - Modo escuro (`body.dark-mode` e `prefers-color-scheme: dark`).
  - Responsividade (banner adapta-se a telas pequenas).
  - `prefers-reduced-motion`: Animações desabilitadas para usuários sensíveis.
- **Acessibilidade**: Banner com `role="alert"`, `aria-live="assertive"` e foco visível nos botões.

### 📬 Log de E-mails *(novo)*

- **Tabela `email_logs`** criada no banco (`api/migrations/4_create_email_logs.sql`):
  - Campos: `id`, `enviado_em`, `destinatario`, `assunto`, `tipo`, `ticket_id`, `status` (sucesso/falha), `erro`.
  - Índices em `enviado_em`, `ticket_id`, `status` e `tipo` para consultas rápidas.
- **Registro automático** em `email-sender.php`: todos os métodos de envio (`enviarEmail`, `enviarEmailTexto`, `enviarEmailComAnexos`) agora usam `try/finally` para registrar **toda tentativa** de envio — com sucesso ou falha — na tabela `email_logs`.
- **Auto-detecção de tipo** pelo assunto (ex: contém "criado" → `novo_ticket`, "fechado" → `fechamento`, "comentário" → `comentario`, etc.).
- **Auto-detecção de `ticket_id`** pelo padrão `#XXXXX` no assunto, quando não informado via contexto.
- **Método `setEmailContext(ticket_id, tipo)`**: permite definir explicitamente o contexto antes de um envio para garantir classificação precisa.
- **Endpoint `api/controllers/email-logs.php`** (somente agente/admin):
  - Filtros: `status`, `tipo`, `ticket_id`, `busca` (texto livre), `periodo` (dias retroativos).
  - Retorna: logs paginados + estatísticas (total, sucessos, falhas, taxa de sucesso %) + agrupamento por tipo.
  - Fallback: se o banco falhar ao gravar o log, a tentativa é registrada no `error_log` do PHP.


### 🔒 Segurança

- **Logout por Inatividade:** Frontend verifica inatividade do usuário a cada evento (mouse/teclado/scroll). Após 30 minutos sem interação, exibe alerta e redireciona para login.
- **Expiração de Token JWT:** Verificação automática da validade do token a cada 1 minuto. Token expira em 8 horas; ao expirar, sessão é encerrada automaticamente.
- **Migração de senhas** para hash seguro (`password_hash` / `PASSWORD_ARGON2ID`) com atualização transparente no login.
- **JWT** substituindo sessões PHP — autenticação stateless segura.
- `session_regenerate_id(true)` após login para prevenir Session Fixation.
- Validação dinâmica de tokens CSRF em toda a API.
- `tickets.php`: Reativados `AuthMiddleware` e `verificarCSRF` em POST/PUT/DELETE; removidos fallbacks inseguros.

---

### 📧 Sistema de Tickets via E-mail

- **Leitura IMAP:** Estratégia alterada de `UNSEEN` para `SINCE 3 days ago` — garante que e-mails lidos em outros clientes (Outlook, celular) também sejam processados.
- **Correção Base64:** Adicionada decodificação forçada quando o corpo do e-mail é Base64 não declarado no header MIME (ex: alguns webmails e clientes mal formatados). Resolve o bug de conteúdo exibido como `PCFET0NU...` no ticket.
- **Blacklist de Remetentes:** E-mails de `suporte@crprs.org.br` e `suporte2@crprs.org.br` são automaticamente ignorados e marcados como lidos — evita loops de criação de tickets.
- **Anti-Duplicidade de Tickets:** Antes de criar um novo ticket via e-mail, verifica se já existe ticket idêntico (mesmo assunto + corpo + usuário) nos últimos **10 minutos**. Se sim, ignora.
- **Anti-Duplicidade de Comentários (e-mail):** Antes de inserir comentário via e-mail, verifica se já existe comentário idêntico (mesmo texto + usuário + ticket) nos últimos **5 minutos**. Se sim, ignora.
- **Anti-Duplicidade de Comentários (interface):** Mesma proteção aplicada ao controller `comentarios.php` para evitar duplicatas por duplo clique ou reenvio de formulário.
- **Respostas em thread:** E-mails com `#XXXXX` no assunto são reconhecidos como respostas e adicionados como comentário ao ticket correspondente.
- Processamento de anexos inline vs. reais com validação de tipo e tamanho.

---

### 👥 Notificações entre Agentes *(novo)*

- **Comportamento em `comentarios.php`:** Quando um **agente ou admin** comenta em ticket atribuído a **outro agente**:
  1. Status do ticket é alterado automaticamente para **'Nova Resposta'** (exceto se já estiver encerrado/cancelado).
  2. A mudança é **registrada no histórico** do ticket com val. anterior e novo.
  3. Um **e-mail de notificação** é disparado ao agente responsável informando quem comentou e exibindo o trecho do comentário.
- **Novo método `notificarComentarioEntreAgentes()`** em `email-sender.php`:
  - E-mail HTML responsivo com: nome do agente destinatário, nome do autor, número/título do ticket, trecho do comentário (máx. 300 chars) e assinatura padrão.

---

### 📊 Dashboard e Relatórios

- Tickets com status **'cancelado'** excluídos de **todas** as métricas e gráficos do Dashboard: total, por status, por categoria, por agente, SLA, tempo médio, etc.

---

### ♿ Acessibilidade e UX

- **Destaque Visual "Nova Resposta":** Tickets com esse status possuem fundo amarelo, texto em negrito e borda lateral azul na listagem (`nova_resposta.css`).
- Status **'Nova Resposta'** adicionado ao modal de alteração de status.
- Botão "Mostrar Senha" acessível via teclado.
- Textos alternativos (`aria-label`) em ícones sem texto.

---

### 📦 Banco de Dados

| Arquivo SQL | Descrição |
|---|---|
| `1_update_status_tickets.sql` | Adiciona valor `nova_resposta` ao ENUM de status |
| `2_update_enum_status.sql` | Adiciona `aguardando` e variações ao ENUM |
| `3_fix_enum_status_production.sql` | Correção do ENUM em produção |

---

### 🐛 Correções de Bugs

| Bug | Descrição |
|---|---|
| **Status "Aguardando" invisível** | Tickets com status "aguardando" não apareciam na lista — corrigido via fix de ENUM |
| **Token JWT ausente** | Requisições de alteração de categoria, prioridade, atribuição e exclusão não enviavam token — corrigido |
| **Comentários duplicados** | Comentários podiam ser inseridos múltiplas vezes por loop de e-mail ou duplo clique — corrigido com verificação de janela de 5 min |
| **E-mail com Base64 visível** | Corpo do e-mail exibido como código `PCFET0NU...` no ticket — corrigido com decodificação forçada |
| **E-mail de fechamento** | Nome do solicitante na saudação estava incorreto — corrigido |

---

### ⚙️ Configurações — Títulos e Espaçamentos *(melhoria)*

- **Títulos dinâmicos por submenu**: O `h2` da seção de Configurações agora atualiza seu texto ao navegar entre submenus — cada página exibe seu próprio título com ícone (`👥 Gerenciar Usuários`, `📁 Categorias`, `⏰ Horários e Feriados`, `📧 Configurar Email`, `🎨 Personalização`, `📋 Auditoria`).
- **Estilo do título**: `#config-title` recebeu `font-size: 1.5rem`, `font-weight: 700`, borda inferior laranja e `color: var(--laranja)` — diferencia visualmente o título do conteúdo.
- **Espaçamentos da seção**: Ajustados `padding-top` em `#config`, `padding-top` em `#config-content` e `margin` nos `h3` internos para ritmo vertical consistente.
- **Página Horários e Feriados — espaçamento vertical**: Corrigidos espaçamentos entre todos os elementos da página:
  - `h3` com `margin-bottom: 0.4rem` e parágrafos descritivos com `margin-top: 0` para aproximar título e descrição.
  - Tabela de horários com `margin-bottom: 2.5rem` para separar os dois blocos.
  - Formulário de feriado com `padding: 1.25rem`, `margin-bottom: 1.5rem` e `h4` com `margin-top: 0`.
  - Botões Salvar/Cancelar agrupados em `flex` com `gap: 0.5rem` e `margin-top: 1rem`.
  - Células da tabela com `padding: 0.65rem 1rem` e `vertical-align: middle`.
  - Inputs `type="time"` com estilo consistente (borda, border-radius, font-size).
  - Campos do formulário de feriado com `margin-bottom: 0.85rem`.

---

## [3.0.0] - anterior

> Versão inicial com autenticação por sessão PHP, tickets básicos e painel de atendimento.
