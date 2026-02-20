# Changelog - Sistema de Helpdesk CRPRS

Todas as alterações notáveis neste projeto serão documentadas neste arquivo.

---

## [3.1.0] - 2026-02-20 (Em desenvolvimento)

### 🎫 Tabelas de Tickets — Melhorias de UX e Unificação *(novo)*

- **Unificação da Tabela de Tickets Encerrados (Arquivo)**: A tabela de "Tickets Fechados" foi totalmente reescrita para possuir **paridade de design e performance** com a de "Tickets Abertos", solucionando gargalos de renderização e usabilidade:
  - **Paginação Dedicada**: Renderização estrita de 25/50/100 itens por página com paginação assíncrona (não mais milhares de dom/nós injetados de vez). *Configuração independente salva `ticketsFechadosPorPagina`.*
  - **Busca por Debounce**: Retardo de pesquisa (300ms) implementado para a busca de inativos ser performática.
  - **Identidade Visual Sincronizada**: Skeleton loading, títulos truncados para não quebrar a largura e substituição de `.png` por *Material Symbols*.
  - **Ordenação Persistente Independente**: Sorteamento salvo na chave `ticketsFechadosOrdem`.

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

## [3.0.0] - anterior

> Versão inicial com autenticação por sessão PHP, tickets básicos e painel de atendimento.
