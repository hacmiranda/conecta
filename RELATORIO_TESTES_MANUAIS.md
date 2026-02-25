# Relatório de Testes Manuais — CRPDESK Helpdesk
**Sistema:** Helpdesk CRPRS
**Data:** 24/02/2026
**Ambiente:** Localhost (XAMPP)
**URL base:** `http://localhost/crpdesk/frontend/`

---

> **Como usar este relatório:**
> Marque `[x]` em cada caixa ao concluir o teste. Registre observações após o resultado esperado quando necessário (ex: `✅ OK`, `❌ FALHOU — motivo`).

---

## Índice

1. [Página de Login](#1-página-de-login)
2. [Autenticação e Sessão](#2-autenticação-e-sessão)
3. [Cabeçalho e Navegação](#3-cabeçalho-e-navegação)
4. [Lista de Tickets (Abertos)](#4-lista-de-tickets-abertos)
5. [Arquivo — Tickets Encerrados](#5-arquivo--tickets-encerrados)
6. [Criar Novo Ticket](#6-criar-novo-ticket)
7. [Detalhes do Ticket](#7-detalhes-do-ticket)
8. [Comentários e Editor Rico](#8-comentários-e-editor-rico)
9. [SLA — Indicadores e Cálculo](#9-sla--indicadores-e-cálculo)
10. [Mesclar Tickets](#10-mesclar-tickets)
11. [Dashboard — Gráficos e Métricas](#11-dashboard--gráficos-e-métricas)
12. [Relatórios e Exportação](#12-relatórios-e-exportação)
13. [Notificações em Tempo Real](#13-notificações-em-tempo-real)
14. [Macros — Respostas Rápidas](#14-macros--respostas-rápidas)
15. [Lembretes](#15-lembretes)
16. [Avatar do Usuário](#16-avatar-do-usuário)
17. [Alterar Senha](#17-alterar-senha)
18. [Modo Escuro](#18-modo-escuro)
19. [Configurações — Gerenciar Usuários (Admin)](#19-configurações--gerenciar-usuários-admin)
20. [Configurações — Categorias (Admin)](#20-configurações--categorias-admin)
21. [Configurações — Horários e Feriados SLA (Admin)](#21-configurações--horários-e-feriados-sla-admin)
22. [Configurações — Email SMTP (Admin)](#22-configurações--email-smtp-admin)
23. [Configurações — Personalização (Admin)](#23-configurações--personalização-admin)
24. [Configurações — Auditoria (Admin)](#24-configurações--auditoria-admin)
25. [Seção de Ajuda](#25-seção-de-ajuda)
26. [Segurança e Controle de Acesso](#26-segurança-e-controle-de-acesso)
27. [Acessibilidade e Usabilidade](#27-acessibilidade-e-usabilidade)

---

## 1. Página de Login

**URL:** `http://localhost/crpdesk/frontend/login.html`

### 1.1 Carregamento da Página

| # | Teste | Resultado Esperado | Status |
|---|-------|--------------------|--------|
| 1.1.1 | Acessar a URL de login diretamente | Página de login exibida com logo, campos de e-mail, senha e CAPTCHA Turnstile | `[ ]` |
| 1.1.2 | Acessar `index.html` sem estar autenticado | Redirecionado para `login.html` automaticamente | `[ ]` |
| 1.1.3 | Verificar fonte tipográfica carregada | Página usa fonte **Inter** (Google Fonts) | `[ ]` |
| 1.1.4 | Verificar modo responsivo | Layout se adapta corretamente em telas menores | `[ ]` |

### 1.2 Login com Credenciais Válidas

| # | Teste | Resultado Esperado | Status |
|---|-------|--------------------|--------|
| 1.2.1 | Inserir e-mail e senha válidos de um **cliente** e clicar em entrar | Login efetuado; redirecionado para `index.html`; token armazenado no `localStorage` | `[ ]` |
| 1.2.2 | Inserir e-mail e senha válidos de um **agente** | Login efetuado; menu de agente exibido (pode criar tickets, ver todos os tickets) | `[ ]` |
| 1.2.3 | Inserir e-mail e senha válidos de um **admin** | Login efetuado; menu de admin exibido (Configurações visível) | `[ ]` |
| 1.2.4 | Verificar `localStorage` após login | Chaves `token`, `usuario` e `token_exp` presentes com valores corretos | `[ ]` |

### 1.3 Login com Credenciais Inválidas

| # | Teste | Resultado Esperado | Status |
|---|-------|--------------------|--------|
| 1.3.1 | Inserir e-mail inexistente no sistema | Mensagem de erro: "Credenciais inválidas" ou similar; login negado | `[ ]` |
| 1.3.2 | Inserir e-mail correto mas senha errada | Mensagem de erro clara; login negado; senha não exposta | `[ ]` |
| 1.3.3 | Deixar o campo e-mail em branco | Validação HTML5 impede envio; campo destacado | `[ ]` |
| 1.3.4 | Deixar o campo senha em branco | Validação HTML5 impede envio; campo destacado | `[ ]` |
| 1.3.5 | Tentar login com conta **desativada** | Mensagem de erro informando conta inativa; acesso negado | `[ ]` |

### 1.4 CAPTCHA Turnstile

| # | Teste | Resultado Esperado | Status |
|---|-------|--------------------|--------|
| 1.4.1 | Verificar se o widget Turnstile é carregado | Widget Cloudflare Turnstile visível no formulário | `[ ]` |
| 1.4.2 | Tentar submeter o formulário sem resolver o CAPTCHA | Login bloqueado; mensagem de erro sobre CAPTCHA | `[ ]` |
| 1.4.3 | Resolver o CAPTCHA e fazer login | CAPTCHA aceito; login prosseguido normalmente | `[ ]` |

---

## 2. Autenticação e Sessão

### 2.1 Gerenciamento de Token JWT

| # | Teste | Resultado Esperado | Status |
|---|-------|--------------------|--------|
| 2.1.1 | Após login, verificar `localStorage` | Token JWT em `token`, dados do usuário em `usuario`, expiração em `token_exp` | `[ ]` |
| 2.1.2 | Simular token expirado (alterar `token_exp` para valor passado) | Sistema detecta expiração e exibe alerta de sessão encerrada; redireciona para login | `[ ]` |
| 2.1.3 | Aguardar 30 minutos sem interação | Sistema exibe aviso de inatividade e efetua logout automático | `[ ]` |
| 2.1.4 | Clicar em "Sair" no menu do avatar | `localStorage` é limpo (`token`, `usuario`, `token_exp` removidos); redirecionado para login | `[ ]` |

### 2.2 Proteção de Rotas

| # | Teste | Resultado Esperado | Status |
|---|-------|--------------------|--------|
| 2.2.1 | Tentar acessar `index.html` sem token | Redirecionado para `login.html` | `[ ]` |
| 2.2.2 | Fazer chamada à API sem header `Authorization` | API retorna HTTP 401 | `[ ]` |
| 2.2.3 | Fazer chamada à API com token inválido/adulterado | API retorna HTTP 401; mensagem de token inválido | `[ ]` |
| 2.2.4 | Usuário **cliente** tentar acessar endpoint de admin (ex: criar usuário) | API retorna HTTP 403 Forbidden | `[ ]` |

---

## 3. Cabeçalho e Navegação

### 3.1 Barra de Navegação Principal

| # | Teste | Resultado Esperado | Status |
|---|-------|--------------------|--------|
| 3.1.1 | Verificar logo e título exibidos | Logo CRPRS e texto "Helpdesk CRPRS" visíveis no cabeçalho | `[ ]` |
| 3.1.2 | Clicar no botão **Tickets** | Seção de tickets abertos é exibida | `[ ]` |
| 3.1.3 | Clicar no botão **Arquivo** | Seção de tickets encerrados é exibida | `[ ]` |
| 3.1.4 | Clicar no botão **Dashboard** | Seção de dashboard com gráficos é exibida | `[ ]` |
| 3.1.5 | Clicar no botão **Relatórios** | Seção de relatórios com filtros é exibida | `[ ]` |

### 3.2 Menu Ajuda (dropdown)

| # | Teste | Resultado Esperado | Status |
|---|-------|--------------------|--------|
| 3.2.1 | Clicar em "Ajuda ▾" | Dropdown abre com todas as opções de ajuda | `[ ]` |
| 3.2.2 | Clicar em "⏰ SLA" | Conteúdo sobre SLA carregado na seção de ajuda | `[ ]` |
| 3.2.3 | Clicar em "📊 Status dos Tickets" | Guia de status carregado | `[ ]` |
| 3.2.4 | Clicar em "🔥 Prioridades" | Guia de prioridades carregado | `[ ]` |
| 3.2.5 | Clicar em "📧 Fluxos de Email" | Documentação de fluxos de e-mail carregada | `[ ]` |
| 3.2.6 | Clicar em "⌨️ Atalhos de Teclado" | Lista de atalhos exibida | `[ ]` |
| 3.2.7 | Pressionar **ESC** com dropdown aberto | Dropdown fecha | `[ ]` |
| 3.2.8 | Clicar fora do dropdown aberto | Dropdown fecha | `[ ]` |

### 3.3 Menu Configurações (dropdown — Admin)

| # | Teste | Resultado Esperado | Status |
|---|-------|--------------------|--------|
| 3.3.1 | Usuário **admin** ver menu "Configurações ▾" | Dropdown visível com todas as opções de configuração | `[ ]` |
| 3.3.2 | Usuário **agente** ou **cliente** verificar menu | Menu "Configurações" não exibido ou opções restritas | `[ ]` |
| 3.3.3 | Clicar em "👥 Gerenciar Usuários" | Painel de usuários carregado na seção de configurações | `[ ]` |
| 3.3.4 | Clicar em "📁 Categorias" | Painel de categorias carregado | `[ ]` |
| 3.3.5 | Clicar em "⏰ Horários e Feriados" | Painel de configuração de SLA carregado | `[ ]` |
| 3.3.6 | Clicar em "📧 Configurar Email" | Painel de configuração de e-mail carregado | `[ ]` |
| 3.3.7 | Clicar em "📋 Auditoria" | Painel de logs de auditoria carregado | `[ ]` |

### 3.4 Sino de Notificações

| # | Teste | Resultado Esperado | Status |
|---|-------|--------------------|--------|
| 3.4.1 | Verificar ícone do sino no cabeçalho | Sino visível; badge com contagem de não lidas (se houver) | `[ ]` |
| 3.4.2 | Clicar no sino | Dropdown com lista das últimas 50 notificações abre | `[ ]` |
| 3.4.3 | Verificar notificações não lidas | Borda esquerda vermelha/destaque nas notificações não lidas | `[ ]` |
| 3.4.4 | Clicar em "Marcar lidas" | Todas as notificações marcadas como lidas; badge some | `[ ]` |
| 3.4.5 | Clicar em "Excluir todos" | Confirmação exibida; todas notificações removidas da lista | `[ ]` |
| 3.4.6 | Clicar em uma notificação de ticket | Modal/seção de detalhes do ticket abre; notificação marcada como lida | `[ ]` |

### 3.5 Menu Avatar (dropdown do usuário)

| # | Teste | Resultado Esperado | Status |
|---|-------|--------------------|--------|
| 3.5.1 | Verificar exibição do avatar no cabeçalho | Iniciais do usuário (2 letras) ou imagem de avatar em círculo | `[ ]` |
| 3.5.2 | Clicar no avatar | Dropdown com nome, papel e opções do usuário abre | `[ ]` |
| 3.5.3 | Verificar nome e papel exibidos no dropdown | Nome do usuário e tipo (Cliente/Agente/Admin) corretos | `[ ]` |
| 3.5.4 | Clicar em "⚡ Respostas Rápidas" | Modal de macros abre | `[ ]` |
| 3.5.5 | Clicar em "📷 Alterar Avatar" | Modal de upload e recorte de avatar abre | `[ ]` |
| 3.5.6 | Clicar em "🌙 Modo Escuro" / "☀️ Modo Claro" | Tema alterna; ícone muda conforme estado | `[ ]` |
| 3.5.7 | Clicar em "🔑 Alterar Senha" | Seção de alteração de senha exibida | `[ ]` |
| 3.5.8 | Clicar em "ℹ️ Sobre" | Modal ou seção "Sobre o sistema" aberta | `[ ]` |
| 3.5.9 | Clicar em "🚪 Sair" | Sessão encerrada; redirecionado para login | `[ ]` |

### 3.6 Alerta de Falhas de E-mail

| # | Teste | Resultado Esperado | Status |
|---|-------|--------------------|--------|
| 3.6.1 | Quando há e-mails com falha nas últimas 24h | Banner de alerta amarelo/laranja aparece no topo com contagem | `[ ]` |
| 3.6.2 | Clicar em "Ver detalhes" no banner | Log de e-mails com falhas abre | `[ ]` |
| 3.6.3 | Fechar/dispensar o banner | Banner some por 4 horas; reaparece no próximo acesso após esse período | `[ ]` |

---

## 4. Lista de Tickets (Abertos)

**Seção:** Tickets — **Título esperado:** "🎫 Tickets Abertos"

### 4.1 Carregamento e Exibição

| # | Teste | Resultado Esperado | Status |
|---|-------|--------------------|--------|
| 4.1.1 | Navegar para a seção Tickets | Tabela de tickets abertos carregada com colunas: ID, Título, Solicitante, Categoria, Prioridade, Status, Agente, Criado em, SLA | `[ ]` |
| 4.1.2 | Verificar contador de tickets no título | Badge com total de tickets abertos visível e correto | `[ ]` |
| 4.1.3 | Verificar badges de prioridade | Cores corretas: Baixa (cinza), Normal (azul), Alta (laranja), Urgente (vermelho) | `[ ]` |
| 4.1.4 | Verificar badges de status | Ícone e cor condizentes com cada status | `[ ]` |
| 4.1.5 | Verificar coluna SLA | Indicadores de tempo corretos (verde/amarelo/vermelho/vencido) | `[ ]` |

### 4.2 Busca

| # | Teste | Resultado Esperado | Status |
|---|-------|--------------------|--------|
| 4.2.1 | Digitar texto no campo de busca | Tabela filtrada em tempo real conforme digitação | `[ ]` |
| 4.2.2 | Buscar por **título** de ticket existente | Ticket encontrado na lista | `[ ]` |
| 4.2.3 | Buscar por **nome do solicitante** | Tickets do solicitante encontrados | `[ ]` |
| 4.2.4 | Buscar texto que não existe | Mensagem "Nenhum ticket encontrado" ou lista vazia | `[ ]` |
| 4.2.5 | Clicar no botão "✕" do campo de busca | Campo limpo; lista volta ao estado original | `[ ]` |

### 4.3 Filtros Rápidos (Pills)

| # | Teste | Resultado Esperado | Status |
|---|-------|--------------------|--------|
| 4.3.1 | Clicar em **Todos** | Lista sem filtro de status; todos os tickets abertos visíveis | `[ ]` |
| 4.3.2 | Clicar em **🆕 Novo** | Apenas tickets com status "Novo/Aberto" exibidos | `[ ]` |
| 4.3.3 | Clicar em **🔧 Em atendimento** | Apenas tickets em atendimento exibidos | `[ ]` |
| 4.3.4 | Clicar em **⏳ Aguardando** | Apenas tickets em estados de espera exibidos | `[ ]` |
| 4.3.5 | Clicar em **💬 Nova resposta** | Apenas tickets com nova resposta do cliente exibidos | `[ ]` |
| 4.3.6 | Clicar em **🔥 Urgente** | Apenas tickets de prioridade urgente exibidos | `[ ]` |
| 4.3.7 | Verificar contadores nos pills | Badge de contagem correto em cada pill | `[ ]` |

### 4.4 Filtro "Meus Tickets"

| # | Teste | Resultado Esperado | Status |
|---|-------|--------------------|--------|
| 4.4.1 | Marcar checkbox "Meus Tickets" | Lista filtrada para tickets atribuídos ao usuário logado | `[ ]` |
| 4.4.2 | Verificar contagem exibida | Badge ao lado do checkbox mostra número correto | `[ ]` |
| 4.4.3 | Desmarcar o checkbox | Lista volta a exibir todos os tickets | `[ ]` |

### 4.5 Ordenação

| # | Teste | Resultado Esperado | Status |
|---|-------|--------------------|--------|
| 4.5.1 | Clicar no cabeçalho "ID" | Lista ordenada por ID (crescente); novo clique inverte para decrescente | `[ ]` |
| 4.5.2 | Clicar no cabeçalho "Título" | Lista ordenada alfabeticamente pelo título | `[ ]` |
| 4.5.3 | Clicar no cabeçalho "Criado em" | Lista ordenada por data de criação | `[ ]` |

### 4.6 Paginação

| # | Teste | Resultado Esperado | Status |
|---|-------|--------------------|--------|
| 4.6.1 | Verificar controles de paginação | "Itens por página" dropdown e botões Anterior/Próxima visíveis | `[ ]` |
| 4.6.2 | Alterar "Itens por página" para 5 | Lista exibe no máximo 5 tickets; navegação por páginas disponível | `[ ]` |
| 4.6.3 | Alterar "Itens por página" para 25 | Lista exibe até 25 tickets por página | `[ ]` |
| 4.6.4 | Clicar em "Próxima" | Página seguinte carregada | `[ ]` |
| 4.6.5 | Clicar em "Anterior" na primeira página | Botão desabilitado; sem regressão | `[ ]` |
| 4.6.6 | Verificar indicador de página | Texto "Página X de Y" correto | `[ ]` |

### 4.7 Visualização Kanban

| # | Teste | Resultado Esperado | Status |
|---|-------|--------------------|--------|
| 4.7.1 | Alternar para visualização **Kanban** | Colunas por status exibidas lado a lado | `[ ]` |
| 4.7.2 | Arrastar ticket para outra coluna | Status do ticket atualizado; ticket move para a nova coluna | `[ ]` |
| 4.7.3 | Alternar de volta para visualização **Lista** | Tabela normal restaurada | `[ ]` |

### 4.8 Ações no Ticket (linha da tabela)

| # | Teste | Resultado Esperado | Status |
|---|-------|--------------------|--------|
| 4.8.1 | Clicar em um ticket na lista | Seção de detalhes do ticket abre | `[ ]` |
| 4.8.2 | Verificar opção "Atribuir" na linha | Modal de seleção de agente abre; atribuição salva | `[ ]` |
| 4.8.3 | Verificar opção "Alterar Status" na linha | Modal/dropdown de status abre; mudança salva | `[ ]` |
| 4.8.4 | Verificar opção "Alterar Prioridade" na linha | Dropdown de prioridade abre; mudança salva | `[ ]` |
| 4.8.5 | Verificar opção "Alterar Categoria" na linha | Modal/dropdown de categoria abre; mudança salva | `[ ]` |
| 4.8.6 | Verificar indicador de anexos | Ícone visível na linha de tickets que possuem anexos | `[ ]` |
| 4.8.7 | Verificar indicador de comentários não lidos | Destaque visual em tickets com comentários não lidos | `[ ]` |

---

## 5. Arquivo — Tickets Encerrados

**Seção:** Arquivo — **Título esperado:** "🏁 Tickets Encerrados"

| # | Teste | Resultado Esperado | Status |
|---|-------|--------------------|--------|
| 5.1 | Navegar para a seção Arquivo | Lista de tickets com status "encerrado" ou "cancelado" exibida | `[ ]` |
| 5.2 | Verificar que tickets abertos NÃO aparecem aqui | Nenhum ticket com status "aberto", "em atendimento" etc. na lista | `[ ]` |
| 5.3 | Usar campo de busca no Arquivo | Busca funciona igual à da seção Tickets | `[ ]` |
| 5.4 | Marcar "Meus Tickets Fechados" | Lista filtrada para tickets encerrados do usuário | `[ ]` |
| 5.5 | Filtrar por status "Cancelado" | Apenas tickets cancelados exibidos | `[ ]` |
| 5.6 | Abrir detalhes de um ticket encerrado | Detalhes carregam normalmente; histórico e comentários visíveis | `[ ]` |
| 5.7 | Reabrir um ticket encerrado (se permitido) | Status alterado para "Reaberto"; ticket move para a seção Tickets | `[ ]` |

---

## 6. Criar Novo Ticket

**Seção:** Novo Ticket

### 6.1 Exibição do Formulário

| # | Teste | Resultado Esperado | Status |
|---|-------|--------------------|--------|
| 6.1.1 | Acessar o formulário de criação | Campos: Título, Descrição (editor Quill), E-mail do solicitante, Categoria, Prioridade e Anexos | `[ ]` |
| 6.1.2 | Verificar editor de texto rico (Quill) | Barra de ferramentas com: negrito, itálico, sublinhado, listas, tamanho de fonte, cores, links | `[ ]` |

### 6.2 Validação de Campos Obrigatórios

| # | Teste | Resultado Esperado | Status |
|---|-------|--------------------|--------|
| 6.2.1 | Tentar submeter sem preencher **Título** | Erro de validação; campo destacado | `[ ]` |
| 6.2.2 | Tentar submeter sem preencher **Descrição** | Erro de validação; campo destacado | `[ ]` |
| 6.2.3 | Tentar submeter sem selecionar **Categoria** | Erro de validação; campo destacado | `[ ]` |
| 6.2.4 | Preencher e-mail inválido no campo Solicitante | Erro de validação de formato de e-mail | `[ ]` |

### 6.3 Campo de Categoria (Autocomplete)

| # | Teste | Resultado Esperado | Status |
|---|-------|--------------------|--------|
| 6.3.1 | Digitar texto no campo Categoria | Lista de categorias filtrada conforme digitação | `[ ]` |
| 6.3.2 | Selecionar uma categoria da lista | Categoria selecionada; campo preenchido; ID oculto armazenado | `[ ]` |
| 6.3.3 | Limpar seleção de categoria | Campo volta ao estado vazio | `[ ]` |

### 6.4 Prioridade

| # | Teste | Resultado Esperado | Status |
|---|-------|--------------------|--------|
| 6.4.1 | Verificar opções do dropdown de prioridade | Opções: Baixa, Normal, Alta, Urgente | `[ ]` |
| 6.4.2 | Selecionar prioridade **Urgente** e criar ticket | Ticket criado com prioridade Urgente; badge vermelho na lista | `[ ]` |

### 6.5 Anexos

| # | Teste | Resultado Esperado | Status |
|---|-------|--------------------|--------|
| 6.5.1 | Selecionar 1 arquivo válido (PDF, JPG etc.) | Arquivo listado com nome e tamanho; indicador "1/5 — XMB/20MB" | `[ ]` |
| 6.5.2 | Selecionar 5 arquivos válidos | Todos listados; limite atingido | `[ ]` |
| 6.5.3 | Tentar adicionar 6º arquivo | Sistema rejeita ou ignora o 6º arquivo; mensagem de limite | `[ ]` |
| 6.5.4 | Tentar adicionar arquivo de tipo inválido (ex: `.exe`) | Arquivo rejeitado; mensagem de tipo não permitido | `[ ]` |
| 6.5.5 | Tentar adicionar arquivo maior que 20MB | Arquivo rejeitado; mensagem de tamanho excedido | `[ ]` |
| 6.5.6 | Remover arquivo da lista com "×" | Arquivo removido; contador atualizado | `[ ]` |

### 6.6 Submissão e Resultado

| # | Teste | Resultado Esperado | Status |
|---|-------|--------------------|--------|
| 6.6.1 | Preencher todos os campos e clicar em "Abrir Ticket" | Ticket criado; toast de sucesso exibido; formulário limpo; ticket aparece na lista | `[ ]` |
| 6.6.2 | Verificar SLA calculado do novo ticket | Prazo SLA calculado e exibido conforme horários de negócio configurados | `[ ]` |
| 6.6.3 | Verificar e-mail enviado ao solicitante (se configurado) | E-mail de criação de ticket enviado ao endereço informado | `[ ]` |
| 6.6.4 | Clicar em "Cancelar" no formulário | Formulário limpo; volta para a lista de tickets | `[ ]` |

---

## 7. Detalhes do Ticket

**Seção:** Detalhes (ao clicar em um ticket)

### 7.1 Informações do Cabeçalho

| # | Teste | Resultado Esperado | Status |
|---|-------|--------------------|--------|
| 7.1.1 | Abrir detalhes de um ticket | ID, Título, Status, Prioridade, Categoria, Data de criação visíveis | `[ ]` |
| 7.1.2 | Verificar informações do solicitante | Nome e e-mail do solicitante exibidos | `[ ]` |
| 7.1.3 | Verificar agente atribuído | Nome do agente ou "Não atribuído" exibido | `[ ]` |
| 7.1.4 | Verificar data de resolução | Exibida se ticket encerrado/resolvido; em branco se aberto | `[ ]` |

### 7.2 Linha do Tempo (Comentários + Histórico)

| # | Teste | Resultado Esperado | Status |
|---|-------|--------------------|--------|
| 7.2.1 | Verificar exibição da linha do tempo | Comentários e histórico mesclados em ordem cronológica | `[ ]` |
| 7.2.2 | Verificar entradas de histórico | Cada mudança registrada: campo, valor anterior, valor novo, usuário e data | `[ ]` |
| 7.2.3 | Verificar entradas de comentários | Autor, data/hora, conteúdo HTML formatado do Quill | `[ ]` |
| 7.2.4 | Verificar anexos em comentários | Link de download para cada anexo de comentário | `[ ]` |

### 7.3 Ações Disponíveis

| # | Teste | Resultado Esperado | Status |
|---|-------|--------------------|--------|
| 7.3.1 | Clicar em "Atribuir" (alterar agente) | Modal de seleção de agente; após confirmação, agente atualizado; histórico registrado | `[ ]` |
| 7.3.2 | Alterar **status** do ticket | Dropdown com todos os status; após seleção, status atualizado; histórico registrado | `[ ]` |
| 7.3.3 | Alterar **prioridade** do ticket | Modal/dropdown de prioridade; após confirmação, prioridade atualizada | `[ ]` |
| 7.3.4 | Alterar **categoria** do ticket | Modal/dropdown de categoria; após confirmação, categoria atualizada | `[ ]` |
| 7.3.5 | Status "Aguardando" — verificar SLA pausado | Ao marcar como "Aguardando", timer de SLA congela; campo `pausado_em` preenchido | `[ ]` |
| 7.3.6 | Retornar de "Aguardando" para "Em atendimento" | SLA retoma contagem; tempo em pausa descontado corretamente | `[ ]` |

### 7.4 Anexos do Ticket

| # | Teste | Resultado Esperado | Status |
|---|-------|--------------------|--------|
| 7.4.1 | Verificar lista de anexos do ticket | Todos os arquivos anexados na criação listados com nome, tipo e tamanho | `[ ]` |
| 7.4.2 | Clicar em link de download de um anexo | Download iniciado; arquivo correto recebido | `[ ]` |
| 7.4.3 | Tentar acessar URL de anexo sem autenticação | Acesso negado (401/403); download bloqueado | `[ ]` |

### 7.5 Exclusão de Ticket

| # | Teste | Resultado Esperado | Status |
|---|-------|--------------------|--------|
| 7.5.1 | Admin clicar em "Excluir Ticket" | Modal de confirmação exibida com aviso de ação irreversível | `[ ]` |
| 7.5.2 | Confirmar exclusão | Ticket removido; redirecionado para lista; toast de confirmação | `[ ]` |
| 7.5.3 | Usuário **cliente** tentar excluir ticket de outro usuário | Ação não disponível ou HTTP 403 | `[ ]` |

---

## 8. Comentários e Editor Rico

### 8.1 Editor Quill (WYSIWYG)

| # | Teste | Resultado Esperado | Status |
|---|-------|--------------------|--------|
| 8.1.1 | Verificar barra de ferramentas do editor | Botões: negrito, itálico, sublinhado, tachado, blocos de código, listas, tamanho de fonte, cor de texto, cor de fundo, links, imagens, limpar formatação | `[ ]` |
| 8.1.2 | Digitar texto e aplicar **negrito** | Texto selecionado fica em negrito | `[ ]` |
| 8.1.3 | Digitar texto e aplicar **itálico** | Texto em itálico | `[ ]` |
| 8.1.4 | Criar **lista com marcadores** | Lista com bullets gerada corretamente | `[ ]` |
| 8.1.5 | Criar **lista numerada** | Lista numerada gerada corretamente | `[ ]` |
| 8.1.6 | Inserir **bloco de código** | Texto em bloco de código formatado | `[ ]` |
| 8.1.7 | Colar imagem com **Ctrl+V** | Imagem inserida no editor inline | `[ ]` |
| 8.1.8 | Inserir **link** no texto | Link funcional criado no editor | `[ ]` |

### 8.2 Adicionar Comentário

| # | Teste | Resultado Esperado | Status |
|---|-------|--------------------|--------|
| 8.2.1 | Escrever comentário simples e salvar | Comentário salvo; aparece na linha do tempo com autor e timestamp | `[ ]` |
| 8.2.2 | Salvar comentário com **formatação rica** (negrito, listas, etc.) | Formatação preservada na exibição | `[ ]` |
| 8.2.3 | Tentar salvar comentário **vazio** | Erro de validação; comentário não salvo | `[ ]` |
| 8.2.4 | Marcar checkbox "Enviar e-mail ao solicitante" e salvar | E-mail enviado ao solicitante com conteúdo do comentário | `[ ]` |
| 8.2.5 | Desmarcar "Enviar e-mail" e salvar | Comentário salvo; nenhum e-mail enviado | `[ ]` |
| 8.2.6 | Usar campo "Com Cópia" para CC | E-mail enviado com agente em cópia | `[ ]` |

### 8.3 Anexos no Comentário

| # | Teste | Resultado Esperado | Status |
|---|-------|--------------------|--------|
| 8.3.1 | Adicionar 1 arquivo ao comentário e salvar | Arquivo salvo; link de download visível no comentário | `[ ]` |
| 8.3.2 | Tentar adicionar 6 arquivos ao comentário | 6º arquivo rejeitado; mensagem de limite | `[ ]` |
| 8.3.3 | Arquivo inválido no comentário | Tipo não permitido rejeitado | `[ ]` |

### 8.4 Macros no Comentário

| # | Teste | Resultado Esperado | Status |
|---|-------|--------------------|--------|
| 8.4.1 | Abrir modal de macros estando na aba de comentário | Modal de macros abre | `[ ]` |
| 8.4.2 | Clicar em uma macro | Conteúdo da macro inserido no editor de comentário | `[ ]` |
| 8.4.3 | Editar o texto da macro antes de salvar | Texto personalizado salvo, não a macro original | `[ ]` |

---

## 9. SLA — Indicadores e Cálculo

| # | Teste | Resultado Esperado | Status |
|---|-------|--------------------|--------|
| 9.1 | Criar ticket e verificar prazo SLA calculado | Prazo calculado com base nos horários de negócio (ex: criado às 17h, prazo passa para o dia seguinte) | `[ ]` |
| 9.2 | Ticket com SLA dentro do prazo | Indicador verde "SLA ok" + tempo restante em horas/dias | `[ ]` |
| 9.3 | Ticket com menos de 2h restantes no SLA | Indicador amarelo "SLA alerta" | `[ ]` |
| 9.4 | Ticket com menos de 30min restantes | Indicador vermelho "SLA crítico" | `[ ]` |
| 9.5 | Ticket com SLA vencido | Indicador vermelho "SLA vencido" + tempo decorrido | `[ ]` |
| 9.6 | Alterar status para "Aguardando" | SLA pausa; timer congela; `pausado_em` registrado no banco | `[ ]` |
| 9.7 | Alterar de "Aguardando" para "Em atendimento" | SLA retoma; tempo em pausa descontado | `[ ]` |
| 9.8 | Criar ticket fora do horário de expediente | SLA só começa a contar no próximo horário útil | `[ ]` |
| 9.9 | Criar ticket em dia de feriado configurado | SLA não conta o dia do feriado | `[ ]` |
| 9.10 | Ticket com status "encerrado" | SLA não exibido ou marcado como concluído | `[ ]` |

---

## 10. Mesclar Tickets

| # | Teste | Resultado Esperado | Status |
|---|-------|--------------------|--------|
| 10.1 | Clicar em "🔗 Mesclar Tickets" | Modo de mesclagem ativado; checkbox aparece em cada ticket; banner de instrução exibido | `[ ]` |
| 10.2 | Selecionar 2 tickets para mesclar | 2 tickets selecionados; botão "🔗 Mesclar Selecionados" habilitado | `[ ]` |
| 10.3 | Verificar qual é o ticket principal | Primeiro selecionado é o ticket mestre (principal) | `[ ]` |
| 10.4 | Confirmar mesclagem | Modal de confirmação exibida; após confirmar, ticket secundário mesclado | `[ ]` |
| 10.5 | Verificar resultado após mesclagem | Comentários do ticket secundário importados para o principal; ticket secundário marcado como mesclado | `[ ]` |
| 10.6 | Clicar em "Cancelar" no modo mesclar | Modo desativado; checkboxes somem | `[ ]` |
| 10.7 | Tentar mesclar 1 único ticket | Botão "Mesclar" desabilitado; necessário selecionar ao menos 2 | `[ ]` |

---

## 11. Dashboard — Gráficos e Métricas

**Seção:** Dashboard

### 11.1 Cards de Métricas

| # | Teste | Resultado Esperado | Status |
|---|-------|--------------------|--------|
| 11.1.1 | Verificar card **Total de Tickets** | Número total correto (excluindo cancelados) | `[ ]` |
| 11.1.2 | Verificar card **Total Encerrados** | Contagem correta de tickets com status "encerrado" | `[ ]` |
| 11.1.3 | Verificar card **Total Abertos** | Contagem correta de tickets em aberto | `[ ]` |

### 11.2 Gráficos

| # | Teste | Resultado Esperado | Status |
|---|-------|--------------------|--------|
| 11.2.1 | Verificar gráfico **Por Status** | Gráfico pizza/donut com distribuição de status; cores corretas | `[ ]` |
| 11.2.2 | Verificar gráfico **Por Prioridade** | Gráfico com Baixa, Normal, Alta, Urgente; cores condizentes | `[ ]` |
| 11.2.3 | Verificar gráfico **Por Categoria** | Top 5 categorias com maior número de tickets | `[ ]` |
| 11.2.4 | Verificar gráfico **Por Solicitante** | Top 5 solicitantes com mais tickets | `[ ]` |
| 11.2.5 | Verificar gráfico **Por Agente** | Distribuição de tickets por agente; nomes legíveis | `[ ]` |
| 11.2.6 | Verificar gráfico **Por Dia da Semana** | Distribuição por dia (seg–dom) indicando dias de pico | `[ ]` |
| 11.2.7 | Rolar até cada gráfico | Gráficos animam ao entrar na viewport (IntersectionObserver) | `[ ]` |
| 11.2.8 | Verificar tema dos gráficos no modo escuro | Fundo escuro; textos legíveis; cores contrastantes | `[ ]` |
| 11.2.9 | Criar um ticket e verificar Dashboard após 60s | Dados do dashboard atualizados refletindo o novo ticket | `[ ]` |

---

## 12. Relatórios e Exportação

**Seção:** Relatórios

### 12.1 Filtros

| # | Teste | Resultado Esperado | Status |
|---|-------|--------------------|--------|
| 12.1.1 | Selecionar período **Mês Atual** e gerar | Relatório exibe tickets do mês corrente | `[ ]` |
| 12.1.2 | Selecionar período **Personalizado** | Campos "De" e "Até" aparecem; selecionar intervalo | `[ ]` |
| 12.1.3 | Filtrar por **Status** específico | Apenas tickets com o status selecionado exibidos | `[ ]` |
| 12.1.4 | Filtrar por **Prioridade** | Apenas tickets da prioridade selecionada | `[ ]` |
| 12.1.5 | Filtrar por **Categoria** | Apenas tickets da categoria selecionada | `[ ]` |
| 12.1.6 | Filtrar por **Agente** | Apenas tickets atribuídos ao agente selecionado | `[ ]` |
| 12.1.7 | Combinar múltiplos filtros | Resultado reflete interseção de todos os filtros aplicados | `[ ]` |

### 12.2 Exportação

| # | Teste | Resultado Esperado | Status |
|---|-------|--------------------|--------|
| 12.2.1 | Gerar relatório e clicar em **Exportar Excel** | Arquivo `.xlsx` baixado com dados corretos e formatados | `[ ]` |
| 12.2.2 | Gerar relatório e clicar em **Exportar PDF** | Arquivo `.pdf` baixado com dados do relatório | `[ ]` |
| 12.2.3 | Exportar sem nenhum dado (filtros sem resultado) | Arquivo vazio ou com cabeçalho indicando ausência de dados | `[ ]` |

---

## 13. Notificações em Tempo Real

### 13.1 Server-Sent Events (SSE)

| # | Teste | Resultado Esperado | Status |
|---|-------|--------------------|--------|
| 13.1.1 | Verificar conexão SSE ativa | No console do browser, `EventSource` conectado ao endpoint `notificacoes-stream.php` | `[ ]` |
| 13.1.2 | Outro usuário atribuir um ticket ao usuário logado | Notificação aparece em tempo real no sino sem recarregar página; badge incrementado | `[ ]` |
| 13.1.3 | Outro usuário comentar em ticket do usuário | Notificação de "Novo comentário" recebida em tempo real | `[ ]` |
| 13.1.4 | Encerrar e reabrir a conexão | Browser reconecta automaticamente ao SSE após perda de conexão | `[ ]` |

### 13.2 Gestão de Notificações

| # | Teste | Resultado Esperado | Status |
|---|-------|--------------------|--------|
| 13.2.1 | Verificar notificações não lidas | Badge vermelho no sino com contagem correta | `[ ]` |
| 13.2.2 | Abrir dropdown e clicar em notificação de ticket | Ticket correspondente aberto; notificação marcada como lida | `[ ]` |
| 13.2.3 | Clicar em "Marcar lidas" | Todas as notificações marcadas como lidas; badge some | `[ ]` |
| 13.2.4 | Clicar em "Excluir todos" | Todas as notificações removidas; lista vazia exibida | `[ ]` |
| 13.2.5 | Verificar formato de data nas notificações | "X minuto(s)/hora(s)/dia(s) atrás" exibido corretamente | `[ ]` |

---

## 14. Macros — Respostas Rápidas

### 14.1 Gestão de Macros

| # | Teste | Resultado Esperado | Status |
|---|-------|--------------------|--------|
| 14.1.1 | Abrir modal de macros pelo avatar | Modal "⚡ Respostas Rápidas" abre com lista de macros do usuário | `[ ]` |
| 14.1.2 | Criar nova macro com título e texto formatado | Macro salva; aparece na lista imediatamente | `[ ]` |
| 14.1.3 | Criar macro sem título | Erro de validação; macro não salva | `[ ]` |
| 14.1.4 | Criar macro sem texto | Erro de validação; macro não salva | `[ ]` |
| 14.1.5 | Editar macro própria | Formulário pré-preenchido; após salvar, alterações refletidas | `[ ]` |
| 14.1.6 | Excluir macro própria | Confirmação exibida; após confirmar, macro removida da lista | `[ ]` |
| 14.1.7 | Usuário **não-admin** tentar editar macro de outro usuário | Botões de editar/excluir não exibidos ou ação bloqueada (403) | `[ ]` |
| 14.1.8 | Admin editar/excluir macro de qualquer usuário | Admin tem acesso para editar e excluir qualquer macro | `[ ]` |

### 14.2 Uso de Macros no Comentário

| # | Teste | Resultado Esperado | Status |
|---|-------|--------------------|--------|
| 14.2.1 | Abrir detalhes de ticket e abrir macros | Modal de macros lista macros disponíveis | `[ ]` |
| 14.2.2 | Clicar em uma macro da lista | Conteúdo da macro inserido no editor de comentário (Quill) | `[ ]` |
| 14.2.3 | Verificar que formatação da macro é preservada | HTML/formatação da macro mantida ao inserir no editor | `[ ]` |

---

## 15. Lembretes

### 15.1 Criar Lembrete

| # | Teste | Resultado Esperado | Status |
|---|-------|--------------------|--------|
| 15.1.1 | Acessar detalhes de ticket e clicar em "Adicionar Lembrete" | Modal com campos: Data, Hora, Descrição, Agente a notificar | `[ ]` |
| 15.1.2 | Preencher todos os campos e criar lembrete | Lembrete salvo; aparece na seção de lembretes do ticket | `[ ]` |
| 15.1.3 | Tentar criar lembrete com data no **passado** | Erro de validação; lembrete não salvo | `[ ]` |
| 15.1.4 | Criar lembrete sem preencher todos os campos | Erros de validação por campo obrigatório | `[ ]` |
| 15.1.5 | Selecionar "Todos os agentes" no campo Notificar | Todos os agentes notificados quando lembrete disparar | `[ ]` |

### 15.2 Exibição de Lembretes

| # | Teste | Resultado Esperado | Status |
|---|-------|--------------------|--------|
| 15.2.1 | Verificar lista de lembretes no ticket | Data, hora, descrição e agente responsável exibidos | `[ ]` |
| 15.2.2 | Verificar lembrete já disparado | Badge "✓ Notificado" visível; botão de excluir ausente | `[ ]` |
| 15.2.3 | Excluir lembrete pendente | Confirmação exibida; após confirmar, lembrete removido | `[ ]` |

### 15.3 Disparo de Lembrete

| # | Teste | Resultado Esperado | Status |
|---|-------|--------------------|--------|
| 15.3.1 | Criar lembrete para daqui a 2 minutos e aguardar cron | Após disparo, notificação aparece para o agente designado | `[ ]` |
| 15.3.2 | Verificar e-mail de lembrete | E-mail com detalhes do lembrete enviado ao agente | `[ ]` |
| 15.3.3 | Verificar marcação após disparo | Lembrete marcado como "Notificado" na lista | `[ ]` |

---

## 16. Avatar do Usuário

| # | Teste | Resultado Esperado | Status |
|---|-------|--------------------|--------|
| 16.1 | Abrir modal de avatar pelo dropdown | Modal "Alterar Avatar" abre com área de upload | `[ ]` |
| 16.2 | Selecionar imagem JPG/PNG | Preview da imagem exibido com ferramenta de recorte (crop) | `[ ]` |
| 16.3 | Usar alça de recorte para ajustar área | Área de seleção responde ao arrastar; proporção 1:1 mantida | `[ ]` |
| 16.4 | Usar **slider de zoom** | Imagem ampliada/reduzida conforme slider; recorte ajustado | `[ ]` |
| 16.5 | Clicar em "Crop and Save" | Avatar salvo no servidor; exibido no cabeçalho imediatamente | `[ ]` |
| 16.6 | Recarregar página após salvar avatar | Avatar persistido; imagem exibida corretamente | `[ ]` |
| 16.7 | Tentar enviar arquivo maior que 5MB | Erro de tamanho exibido; upload rejeitado | `[ ]` |
| 16.8 | Tentar enviar arquivo de tipo inválido (ex: `.pdf`) | Tipo não permitido; upload rejeitado | `[ ]` |
| 16.9 | Usuário sem avatar definido | Iniciais do nome em círculo colorido exibidas no cabeçalho | `[ ]` |
| 16.10 | Clicar em "Cancelar" no modal | Modal fecha sem alterações | `[ ]` |

---

## 17. Alterar Senha

**Acesso:** Avatar → "🔑 Alterar Senha"

| # | Teste | Resultado Esperado | Status |
|---|-------|--------------------|--------|
| 17.1 | Verificar campos do formulário | Campos: Senha Atual, Nova Senha, Confirmar Nova Senha | `[ ]` |
| 17.2 | Verificar indicadores de complexidade em tempo real | Requisitos exibidos: ✅/❌ para mínimo 10 chars, maiúscula, minúscula, número, caractere especial | `[ ]` |
| 17.3 | Inserir senha atual **errada** | Erro: "Senha atual incorreta"; senha não alterada | `[ ]` |
| 17.4 | Inserir nova senha que não atende complexidade | Botão "Alterar" desabilitado ou erro de validação | `[ ]` |
| 17.5 | Nova senha e confirmação **divergentes** | Erro: "Senhas não coincidem"; botão desabilitado | `[ ]` |
| 17.6 | Preencher todos os campos corretamente e salvar | Senha alterada; toast de sucesso; formulário limpo; usuário permanece logado | `[ ]` |
| 17.7 | Fazer logout e login com a **nova senha** | Login efetuado com sucesso | `[ ]` |
| 17.8 | Tentar login com a **senha antiga** após alteração | Login negado; credenciais inválidas | `[ ]` |

---

## 18. Modo Escuro

| # | Teste | Resultado Esperado | Status |
|---|-------|--------------------|--------|
| 18.1 | Ativar modo escuro pelo dropdown do avatar | Interface muda para tema escuro; ícone no menu muda para ☀️ | `[ ]` |
| 18.2 | Verificar que **todas** as seções estão escuras | Tickets, detalhes, modais, cabeçalho — todos no tema escuro | `[ ]` |
| 18.3 | Recarregar a página com modo escuro ativo | Preferência persistida no `localStorage`; modo escuro mantido | `[ ]` |
| 18.4 | Desativar modo escuro | Interface volta ao tema claro; ícone muda para 🌙 | `[ ]` |
| 18.5 | Verificar Dashboard em modo escuro | Gráficos sempre em tema escuro independente da preferência | `[ ]` |
| 18.6 | Verificar contraste de texto | Todos os textos legíveis com contraste adequado (WCAG 4.5:1 mínimo) | `[ ]` |

---

## 19. Configurações — Gerenciar Usuários (Admin)

**Acesso:** Menu Configurações → "👥 Gerenciar Usuários"

### 19.1 Listar Usuários

| # | Teste | Resultado Esperado | Status |
|---|-------|--------------------|--------|
| 19.1.1 | Acessar painel de usuários | Lista/cards de todos os usuários exibidos: ID, Nome, E-mail, Tipo, Ativo, Data de criação | `[ ]` |
| 19.1.2 | Verificar colunas da tabela | Todas as colunas com dados corretos | `[ ]` |

### 19.2 Criar Usuário

| # | Teste | Resultado Esperado | Status |
|---|-------|--------------------|--------|
| 19.2.1 | Clicar em "Criar Usuário" e preencher formulário válido | Usuário criado com senha padrão; aparece na lista | `[ ]` |
| 19.2.2 | Criar usuário com e-mail já existente | Erro de e-mail duplicado; usuário não criado | `[ ]` |
| 19.2.3 | Criar usuário sem nome | Erro de validação; campo obrigatório | `[ ]` |
| 19.2.4 | Criar usuário sem e-mail | Erro de validação | `[ ]` |
| 19.2.5 | Criar usuário tipo **agente** | Usuário com papel "agente" criado; pode ser atribuído a tickets | `[ ]` |
| 19.2.6 | Criar usuário tipo **admin** | Usuário com papel "admin" criado; acesso completo | `[ ]` |

### 19.3 Editar Usuário

| # | Teste | Resultado Esperado | Status |
|---|-------|--------------------|--------|
| 19.3.1 | Editar nome de usuário existente | Nome atualizado; reflete nas listas e detalhes de tickets | `[ ]` |
| 19.3.2 | Alterar tipo de **cliente** para **agente** | Papel atualizado; usuário aparece no dropdown de agentes | `[ ]` |
| 19.3.3 | Alterar tipo de **agente** para **cliente** | Papel atualizado; usuário removido do dropdown de agentes | `[ ]` |

### 19.4 Ativar/Desativar Usuário

| # | Teste | Resultado Esperado | Status |
|---|-------|--------------------|--------|
| 19.4.1 | Desativar usuário ativo | Status "Inativo"; usuário não consegue fazer login | `[ ]` |
| 19.4.2 | Tentar login com usuário desativado | Login negado; mensagem de conta inativa | `[ ]` |
| 19.4.3 | Reativar usuário | Status "Ativo"; usuário consegue fazer login novamente | `[ ]` |

### 19.5 Excluir Usuário

| # | Teste | Resultado Esperado | Status |
|---|-------|--------------------|--------|
| 19.5.1 | Excluir usuário sem tickets atribuídos | Confirmação exibida; após confirmar, usuário removido | `[ ]` |
| 19.5.2 | Tentar excluir o próprio usuário admin logado | Ação bloqueada ou aviso de auto-exclusão negada | `[ ]` |

---

## 20. Configurações — Categorias (Admin)

**Acesso:** Menu Configurações → "📁 Categorias"

| # | Teste | Resultado Esperado | Status |
|---|-------|--------------------|--------|
| 20.1 | Listar categorias existentes | Tabela com Nome, Macro Categoria, opções de editar/excluir | `[ ]` |
| 20.2 | Criar nova categoria simples | Categoria criada; aparece no autocomplete do formulário de novo ticket | `[ ]` |
| 20.3 | Criar categoria com macro categoria (categoria pai) | Hierarquia refletida no autocomplete | `[ ]` |
| 20.4 | Criar categoria com nome duplicado | Erro de nome duplicado ou aviso | `[ ]` |
| 20.5 | Editar nome de categoria existente | Nome atualizado | `[ ]` |
| 20.6 | Excluir categoria sem tickets vinculados | Confirmação; categoria removida | `[ ]` |
| 20.7 | Tentar excluir categoria com tickets vinculados | Erro ou aviso de que há tickets usando essa categoria | `[ ]` |
| 20.8 | Criar macro categoria (categoria raiz/pai) | Macro categoria criada; disponível no dropdown de categorias | `[ ]` |

---

## 21. Configurações — Horários e Feriados SLA (Admin)

**Acesso:** Menu Configurações → "⏰ Horários e Feriados"

### 21.1 Horários de Negócio

| # | Teste | Resultado Esperado | Status |
|---|-------|--------------------|--------|
| 21.1.1 | Verificar configuração de horários por dia da semana | Cada dia (Seg–Dom) com campos de início e fim; toggle de ativar/desativar | `[ ]` |
| 21.1.2 | Alterar horário de início da segunda-feira | Novo horário salvo; SLA calculado com base no novo horário | `[ ]` |
| 21.1.3 | Desativar sábado e domingo | Final de semana excluído do cálculo de SLA | `[ ]` |
| 21.1.4 | Ativar sábado com horário 08:00–12:00 | SLA passa a contar horas do sábado | `[ ]` |
| 21.1.5 | Tentar definir horário de fim anterior ao início | Erro de validação; configuração rejeitada | `[ ]` |

### 21.2 Feriados

| # | Teste | Resultado Esperado | Status |
|---|-------|--------------------|--------|
| 21.2.1 | Adicionar feriado com data e nome | Feriado salvo; aparece na lista | `[ ]` |
| 21.2.2 | Criar ticket em dia de feriado configurado | SLA não conta o dia do feriado | `[ ]` |
| 21.2.3 | Excluir feriado da lista | Feriado removido; SLA volta a contar o dia | `[ ]` |

---

## 22. Configurações — Email SMTP (Admin)

**Acesso:** Menu Configurações → "📧 Configurar Email"

| # | Teste | Resultado Esperado | Status |
|---|-------|--------------------|--------|
| 22.1 | Visualizar formulário de configuração SMTP | Campos: Servidor, Porta, Usuário, Senha, TLS/SSL, E-mail remetente, Nome remetente | `[ ]` |
| 22.2 | Salvar configuração SMTP válida | Configuração salva; confirmação exibida | `[ ]` |
| 22.3 | Clicar em "Enviar E-mail de Teste" | E-mail de teste enviado; confirmação ou erro exibido | `[ ]` |
| 22.4 | Configurar SMTP inválido e enviar e-mail de teste | Erro de conexão SMTP detalhado exibido | `[ ]` |
| 22.5 | Ver **Log de E-mails** | Tabela com e-mails enviados: Para, Assunto, Data, Status (enviado/falhou), Erro | `[ ]` |
| 22.6 | Verificar alerta de falhas no cabeçalho após falha | Banner de alerta aparece no topo da página | `[ ]` |
| 22.7 | Verificar que senha SMTP não é exibida em texto claro | Campo de senha mascarado; não exibido em logs ou respostas da API | `[ ]` |

---

## 23. Configurações — Personalização (Admin)

**Acesso:** Menu Configurações → "🎨 Personalização"

| # | Teste | Resultado Esperado | Status |
|---|-------|--------------------|--------|
| 23.1 | Verificar opções de personalização disponíveis | Opções de tema, cores e notificações listadas | `[ ]` |
| 23.2 | Alterar preferências e salvar | Configurações salvas e aplicadas à interface | `[ ]` |
| 23.3 | Verificar persistência após recarregar página | Configurações salvas mantidas | `[ ]` |

---

## 24. Configurações — Auditoria (Admin)

**Acesso:** Menu Configurações → "📋 Auditoria"

### 24.1 Visualização de Logs

| # | Teste | Resultado Esperado | Status |
|---|-------|--------------------|--------|
| 24.1.1 | Acessar painel de auditoria | Tabela com: Data/Hora, Usuário, Ação, Recurso, ID, Detalhes, IP, User Agent | `[ ]` |
| 24.1.2 | Criar um ticket e verificar registro na auditoria | Entrada de "create" para recurso "ticket" registrada | `[ ]` |
| 24.1.3 | Alterar status de ticket e verificar auditoria | Entrada de "update" com campo alterado registrada | `[ ]` |
| 24.1.4 | Login de usuário registrado | Entrada de login com IP e user agent registrada | `[ ]` |
| 24.1.5 | Tentativa de login com falha | Entrada de login falho registrada | `[ ]` |

### 24.2 Filtros de Auditoria

| # | Teste | Resultado Esperado | Status |
|---|-------|--------------------|--------|
| 24.2.1 | Filtrar por **usuário** | Apenas ações do usuário selecionado exibidas | `[ ]` |
| 24.2.2 | Filtrar por **ação** (ex: delete) | Apenas ações de exclusão exibidas | `[ ]` |
| 24.2.3 | Filtrar por **intervalo de datas** | Apenas registros do período exibidos | `[ ]` |
| 24.2.4 | Filtrar por **recurso** (ex: tickets) | Apenas operações em tickets exibidas | `[ ]` |
| 24.2.5 | Exportar log de auditoria | Arquivo CSV/Excel baixado com registros filtrados | `[ ]` |

---

## 25. Seção de Ajuda

**Acesso:** Menu "Ajuda ▾"

| # | Teste | Resultado Esperado | Status |
|---|-------|--------------------|--------|
| 25.1 | Clicar em "⏰ SLA" | Explicação do SLA, cálculo por horas úteis, feriados e pausas exibida | `[ ]` |
| 25.2 | Clicar em "📊 Status dos Tickets" | Tabela ou lista com todos os status, ícones e descrição | `[ ]` |
| 25.3 | Clicar em "🔥 Prioridades" | Guia de prioridades com tempos de resposta de referência | `[ ]` |
| 25.4 | Clicar em "📧 Fluxos de Email" | Documentação sobre quando e-mails são enviados e para quem | `[ ]` |
| 25.5 | Clicar em "⌨️ Atalhos de Teclado" | Tabela de atalhos: ESC, Ctrl+V, Enter, Tab, etc. | `[ ]` |
| 25.6 | Conteúdo renderiza corretamente no modo escuro | Texto legível e formatação mantida em ambos os temas | `[ ]` |

---

## 26. Segurança e Controle de Acesso

### 26.1 Controle por Papel (RBAC)

| # | Teste | Resultado Esperado | Status |
|---|-------|--------------------|--------|
| 26.1.1 | Usuário **cliente** não vê menu Configurações | Menu de administração oculto ou inacessível | `[ ]` |
| 26.1.2 | **Cliente** tenta via API criar usuário | HTTP 403 Forbidden retornado | `[ ]` |
| 26.1.3 | **Agente** tenta excluir ticket de outro agente | Ação bloqueada ou erro | `[ ]` |
| 26.1.4 | **Admin** tem acesso a todas as seções | Todas as configurações e ações disponíveis | `[ ]` |

### 26.2 Proteção de Formulários

| # | Teste | Resultado Esperado | Status |
|---|-------|--------------------|--------|
| 26.2.1 | Verificar header CSRF em chamadas POST/PUT/DELETE | `X-CSRF-Token` presente em todas as requisições que modificam dados | `[ ]` |
| 26.2.2 | Tentar POST na API sem header CSRF | Requisição rejeitada | `[ ]` |

### 26.3 Upload de Arquivos

| # | Teste | Resultado Esperado | Status |
|---|-------|--------------------|--------|
| 26.3.1 | Enviar arquivo com extensão renomeada (ex: `malware.php` → `malware.jpg`) | Upload rejeitado por validação de MIME type via `finfo` | `[ ]` |
| 26.3.2 | Tentar acesso direto ao arquivo no servidor sem autenticação | HTTP 401/403; arquivo não acessível | `[ ]` |

### 26.4 Headers de Segurança HTTP

| # | Teste | Resultado Esperado | Status |
|---|-------|--------------------|--------|
| 26.4.1 | Verificar headers HTTP nas respostas da API (DevTools → Network) | `X-Content-Type-Options: nosniff`, `X-Frame-Options: DENY`, `X-XSS-Protection: 1; mode=block` presentes | `[ ]` |

---

## 27. Acessibilidade e Usabilidade

### 27.1 Navegação por Teclado

| # | Teste | Resultado Esperado | Status |
|---|-------|--------------------|--------|
| 27.1.1 | Navegar pelo formulário com **Tab** | Foco move entre campos na ordem correta; sem armadilhas de foco | `[ ]` |
| 27.1.2 | Fechar modal com **ESC** | Modal fecha ao pressionar ESC | `[ ]` |
| 27.1.3 | Verificar outline de foco visível | Todos os elementos interativos têm `outline` visível ao receber foco | `[ ]` |

### 27.2 Semântica HTML

| # | Teste | Resultado Esperado | Status |
|---|-------|--------------------|--------|
| 27.2.1 | Verificar atributo `lang` no HTML | `<html lang="pt-BR">` presente no documento | `[ ]` |
| 27.2.2 | Modais com ARIA correto | `role="dialog"`, `aria-modal="true"`, `aria-labelledby` nos modais | `[ ]` |
| 27.2.3 | Formulários com labels associados | Todos os `<input>` com `<label for>` correspondente | `[ ]` |
| 27.2.4 | Imagens com alt text | Imagens funcionais com `alt` descritivo; ícones decorativos com `aria-hidden="true"` | `[ ]` |

### 27.3 Notificações Visuais

| # | Teste | Resultado Esperado | Status |
|---|-------|--------------------|--------|
| 27.3.1 | Verificar Toast de sucesso | Aparece com texto claro, cor verde/positiva, some após alguns segundos | `[ ]` |
| 27.3.2 | Verificar Toast de erro | Aparece com texto claro, cor vermelha, persiste ou some após mais tempo | `[ ]` |
| 27.3.3 | Ação de confirmação em operações destrutivas | Modal de confirmação exibida antes de qualquer exclusão | `[ ]` |

### 27.4 Responsividade

| # | Teste | Resultado Esperado | Status |
|---|-------|--------------------|--------|
| 27.4.1 | Testar em viewport de 1280px (desktop) | Layout completo sem sobreposição de elementos | `[ ]` |
| 27.4.2 | Testar em viewport de 768px (tablet) | Layout adaptado; navegação acessível | `[ ]` |
| 27.4.3 | Testar em viewport de 375px (mobile) | Layout mínimo funcional; elementos principais acessíveis | `[ ]` |

---

## Resumo de Execução

| Seção | Total de Testes | Aprovados | Reprovados | Pendentes |
|-------|:--------------:|:---------:|:----------:|:---------:|
| 1. Página de Login | 15 | | | |
| 2. Autenticação e Sessão | 8 | | | |
| 3. Cabeçalho e Navegação | 27 | | | |
| 4. Lista de Tickets | 28 | | | |
| 5. Arquivo | 7 | | | |
| 6. Criar Novo Ticket | 16 | | | |
| 7. Detalhes do Ticket | 17 | | | |
| 8. Comentários e Editor | 14 | | | |
| 9. SLA | 10 | | | |
| 10. Mesclar Tickets | 7 | | | |
| 11. Dashboard | 9 | | | |
| 12. Relatórios | 10 | | | |
| 13. Notificações | 9 | | | |
| 14. Macros | 11 | | | |
| 15. Lembretes | 11 | | | |
| 16. Avatar | 10 | | | |
| 17. Alterar Senha | 8 | | | |
| 18. Modo Escuro | 6 | | | |
| 19. Gerenciar Usuários | 15 | | | |
| 20. Categorias | 8 | | | |
| 21. Horários e Feriados | 8 | | | |
| 22. Email SMTP | 7 | | | |
| 23. Personalização | 3 | | | |
| 24. Auditoria | 10 | | | |
| 25. Seção de Ajuda | 6 | | | |
| 26. Segurança | 8 | | | |
| 27. Acessibilidade | 12 | | | |
| **TOTAL** | **310** | | | |

---

*Relatório gerado em 24/02/2026 — CRPDESK Helpdesk CRPRS.*
