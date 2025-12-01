# 🎫 CRPDESK - Contexto Completo do Sistema

## 📊 Visão Geral

**Organização:** Conselho Regional de Psicologia do RS (CRPRS)  
**Tipo:** Sistema de Helpdesk Interno  
**Equipe:** 3 agentes de suporte  
**Volume:** ~5 tickets por dia  
**Filosofia:** Solução simples e minimalista  
**Idioma:** Português do Brasil (pt-BR) em todo o projeto

---

## 🏗️ Arquitetura

### Stack Tecnológico
- **Frontend:** HTML5, CSS3, JavaScript Vanilla
- **Backend:** PHP 8.2+ com arquitetura MVC
- **Banco de Dados:** MariaDB 10.4+ (via XAMPP)
- **Servidor Web:** Apache 2.4+
- **Email:** IMAP/SMTP Locaweb (email-ssl.com.br)
- **Autenticação:** JWT token-based

### Estrutura de Diretórios
```
crpdesk/
├── frontend/          # Interface web (HTML/CSS/JS)
├── api/
│   ├── config/        # Database e email config
│   ├── controllers/   # Lógica de negócio
│   ├── services/      # Email e integrações
│   ├── middleware/    # Auth e segurança
│   └── cron/          # Automação de emails
├── backend/
│   ├── anexos/        # Arquivos anexados
│   ├── logs/          # Logs do sistema
│   └── database.sql   # Schema completo
└── .amazonq/rules/memory-bank/  # Documentação
```

---

## 👥 Sistema de Usuários

### Tipos de Perfil
- **Admin:** Acesso total + configurações
- **Agente:** Atendimento e resolução de tickets
- **Usuário:** Abertura de tickets (futuro)

---

## 🎫 Sistema de Tickets

### Ciclo de Vida
```
Novo → Em Atendimento → [Aguardando] → Resolvido → Encerrado
                    ↓
                Reaberto (volta para Em Atendimento)
```

### Status Disponíveis
- 🆕 **Novo:** Ticket recém criado, aguardando atribuição ou início de atendimento
- 🔧 **Em Atendimento:** Agente trabalhando no ticket
- ⏳ **Aguardando:** Ticket na fila ou aguardando ações gerais (SLA pausado)
- ⏳ **Aguardando Resposta:** Aguardando resposta do solicitante (SLA pausado)
- 🔄 **Aguardando Terceiros:** Aguardando setor externo ou fornecedor (SLA pausado)
- 📋 **Aguardando Aprovação:** Aguardando autorização (SLA pausado)
- 📅 **Aguardando Data:** Aguardando data agendada (SLA pausado)
- ✅ **Resolvido:** Solução aplicada, aguardando confirmação
- 🏁 **Encerrado:** Ticket finalizado oficialmente
- ❌ **Cancelado:** Ticket aberto por engano ou solicitação retirada
- 🔄 **Reaberto:** Ticket encerrado que voltou a apresentar problemas

### Prioridades e SLA
| Prioridade | 1ª Resposta | Resolução | Exemplos |
|------------|-------------|-----------|----------|
| 🔥 Urgente | 2h úteis | 4h úteis | Sistema fora do ar |
| ⬆️ Alta | 4h úteis | 8h úteis | Funcionalidade importante indisponível |
| ➡️ Normal | 12h úteis | 24h úteis | Dúvidas rotineiras |
| ⬇️ Baixa | 24h úteis | 40h úteis | Melhorias, sugestões |

**Horário de Atendimento:** Segunda a Sexta, 8h às 18h  
**SLA Pausado:** Status "Aguardando" pausa o SLA automaticamente

### Indicadores Visuais de SLA
- 🟢 **0-50% do prazo:** Sem ícone
- 🟡 **50-80% do prazo:** ⚠️ Atenção
- 🔴 **80-100%+ do prazo:** 🔥 Urgente/Vencido

---

## 📧 Integração com Email

### Configuração
- **Servidor:** email-ssl.com.br
- **IMAP:** Porta 993 (SSL)
- **SMTP:** Porta 465 (SSL)
- **Conta:** suporte2@crprs.org.br
- **Verificação:** A cada 1 minuto (Task Scheduler)

### Fluxos Automatizados

#### 1. Novo Ticket por Email
1. Email recebido em suporte2@crprs.org.br
2. Sistema verifica assunto (sem número de ticket)
3. Cria novo ticket automaticamente
4. Processa anexos (até 5 arquivos, 20MB total)
5. Envia confirmação para remetente
6. Notifica todos agentes/admins

#### 2. Comentário em Ticket Existente
1. Email com número de ticket no assunto: `[#12345]`, `#00123`, `ticket 123`
2. Sistema identifica ticket existente
3. Se fechado → Reabre automaticamente
4. Adiciona email como comentário
5. Processa anexos
6. Marca como comentário não lido
7. Notifica agente responsável (ou todos se não atribuído)

#### 3. Fechamento de Ticket
1. Agente fecha ticket via interface
2. Sistema envia email de fechamento ao solicitante
3. Instruções para reabertura incluídas

#### 4. Reabertura Automática
1. Email recebido com número de ticket fechado
2. Sistema reabre automaticamente (status → "atendimento")
3. Adiciona email como comentário
4. Notifica agentes

---

## 💬 Sistema de Comentários Não Lidos

### Regras de Negócio

**Comentário via Email (Solicitante):**
- **Ticket COM responsável:** Indicador aparece APENAS para o agente responsável
- **Ticket SEM responsável:** Indicador aparece para TODOS os agentes/admins
- **Remoção:** Individual ao visualizar o ticket

**Comentário via Interface (Agente):**
- **Ticket COM responsável:** Indicador para o responsável (se diferente do autor)
- **Ticket SEM responsável:** Indicador para TODOS, exceto quem adicionou
- **Remoção:** Individual ao visualizar o ticket

### Tabela: comentarios_nao_lidos
- Chave única: `(ticket_id, usuario_id)`
- Um registro por agente por ticket
- Deletado ao visualizar detalhes do ticket

---

## 📎 Sistema de Anexos

### Validações
- **Tipos permitidos:** PDF, DOC, DOCX, TXT, CSV, XLS, XLSX, PNG, JPG, JPEG, ZIP, RAR
- **Quantidade:** Máximo 5 arquivos por ticket/comentário
- **Tamanho total:** Máximo 20MB por ticket
- **Visualização inline:** PDFs e imagens abrem no navegador

### Armazenamento
- **Pasta:** `backend/anexos/`
- **Nomenclatura:** `timestamp_email_nome_original`
- **Banco:** Tabela `anexos` com metadados

---

## 🚀 Funcionalidades Principais

### Core
- ✅ Autenticação JWT com sessões seguras
- ✅ CRUD completo de tickets
- ✅ Sistema de comentários com histórico
- ✅ Upload de anexos com validação
- ✅ Visualização inline de PDFs e imagens
- ✅ Gerenciamento de usuários (Admin)
- ✅ Gerenciamento de categorias hierárquicas

### Avançadas
- ✅ Busca avançada por título, descrição, solicitante
- ✅ Modo escuro com persistência
- ✅ Atalhos de teclado (Ctrl+N, Ctrl+T, Ctrl+D, Ctrl+F)
- ✅ Gráficos interativos no dashboard
- ✅ Merge de tickets com transferência de dados
- ✅ SLA automático em horas úteis
- ✅ Menu de ajuda com documentação

### Interface
- ✅ Design responsivo (desktop/mobile)
- ✅ Modais modernos com glassmorphism
- ✅ Toasts de notificação
- ✅ Loader animado
- ✅ Tabelas ordenáveis
- ✅ Paginação e filtros

---

## 📊 Dashboard e Relatórios

### Métricas Disponíveis
- 📈 Total de tickets por período
- ⏱️ Tempo médio de resolução
- ⚡ Tempo médio de primeira resposta
- 📊 Tickets por status (gráfico de barras)
- 📅 Tickets por mês (gráfico de linha)
- 🏆 Top 5 categorias mais utilizadas
- 👥 Performance por agente
- 📁 Distribuição por macrocategoria

### Filtros
- Período (data início/fim)
- Status, prioridade, categoria
- Agente responsável
- Exportação (planejado)

---

## 🔧 Sistema de Categorias

### Estrutura Hierárquica
```
Macrocategoria
└── Categoria
    └── Ticket
```

### Funcionalidades
- ✅ Pesquisa inteligente com autocomplete
- ✅ Formatação automática de nomes
- ✅ Preservação de siglas com asteriscos (*RFID*)
- ✅ Navegação por teclado (setas, Enter, ESC)
- ✅ Agrupamento visual por macrocategoria

---

## 🔗 Sistema de Merge

### Processo
1. Ativar modo merge
2. Selecionar tickets (primeiro = principal)
3. Confirmar operação
4. Transferência automática de comentários e anexos
5. Fechamento dos tickets secundários com histórico
6. Animação visual de remoção

---

## ⌨️ Atalhos de Teclado

| Atalho | Função |
|--------|--------|
| Ctrl+N | Criar novo ticket |
| Ctrl+T | Ir para lista de tickets |
| Ctrl+D | Abrir dashboard |
| Ctrl+F | Ver tickets fechados |
| ESC | Fechar modals/janelas |

---

## 🔒 Segurança

### Medidas Implementadas
- ✅ Autenticação JWT
- ✅ Tokens CSRF em formulários
- ✅ Sanitização de inputs
- ✅ Validação de tipos de arquivo
- ✅ Controle de acesso por perfil
- ✅ Prepared statements no banco
- ✅ Headers de segurança

---

## 🗄️ Banco de Dados

### Tabelas Principais
- `usuarios` - Contas de usuário
- `tickets` - Dados dos tickets
- `comentarios` - Comentários e histórico
- `anexos` - Metadados de arquivos
- `categorias` - Categorias de tickets
- `macrocategorias` - Agrupamento de categorias
- `comentarios_nao_lidos` - Rastreamento de leitura
- `ticket_historico` - Auditoria de alterações
- `ticket_tempo` - Métricas de SLA

### Relacionamentos
- tickets.usuario_id → usuarios.id
- tickets.agente_id → usuarios.id
- tickets.categoria_id → categorias.id
- comentarios.ticket_id → tickets.id
- anexos.ticket_id → tickets.id

---

## 🎯 Regras de Negócio

### Atribuição Automática
- Ticket "Novo" + Atribuir agente = Status vira "Em Atendimento"

### Merge de Tickets
- Mínimo 2 tickets para mesclar
- Primeiro selecionado = ticket principal
- Comentários e anexos transferidos automaticamente
- Tickets secundários fechados com histórico

### Histórico
- Todas alterações registradas automaticamente
- Ações rastreadas: status, prioridade, categoria, atribuição, merge
- Formato legível para auditoria

---

## 📈 Métricas de Performance

### Objetivos de SLA
- **90%** dos tickets no prazo
- **Tempo médio de 1ª resposta:** < 4 horas
- **Tempo médio de resolução:** < 2 dias
- **Satisfação do cliente:** > 85%

### KPIs Monitorados
- Volume de tickets por dia/semana/mês
- Taxa de resolução no primeiro contato
- Tickets reabertos (< 5%)
- Distribuição por categoria
- Performance individual dos agentes

---

## 🚀 Roadmap Futuro

### Curto Prazo
- [ ] Templates de tickets para problemas recorrentes
- [ ] Respostas rápidas pré-definidas
- [ ] Notificações push no navegador
- [ ] Comentários internos (não visíveis ao solicitante)

### Médio Prazo
- [ ] Auto-categorização baseada em palavras-chave
- [ ] Atribuição automática por carga de trabalho
- [ ] Escalação automática para tickets não respondidos
- [ ] Chat em tempo real com solicitantes

### Longo Prazo
- [ ] App mobile para agentes
- [ ] Integração com APIs externas
- [ ] PWA (Progressive Web App)
- [ ] Base de conhecimento integrada

---

**Versão:** 1.0.0  
**Data:** Janeiro 2025  
**Status:** Produção  
**Licença:** Uso Interno CRPRS
