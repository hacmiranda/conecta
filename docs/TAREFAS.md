# 📋 Tarefas e Recomendações de Melhoria - CRPDESK

Abaixo estão listadas as recomendações estratégicas e técnicas para a evolução da arquitetura e interface do sistema, divididas por áreas de atuação.

## 1. 🏗️ Arquitetura e Backend (Prioridade Alta)

- [x] **Ambiente Padrão: PHP 8.2 (Produção)**
  - **Contexto:** O projeto já roda em PHP 8.2 na produção.
  - **Ação:** Todas as próximas implementações de backend (como WebSockets) devem explorar os recursos nativos de tipagem forte, JIT e classes modernas do PHP 8.2.
- [x] **Evolução de Polling para Server-Sent Events (SSE)**
  - **Contexto:** Produção possui limitação severa, e não permite serviços TCP paralelos em bash (Node.js/Ratchet WebSocket). Lembretes e notificações atualizam via Polling (30s/5min).
  - **Ação:** Implementar o protocolo SSE (Server-Sent Events). Ele opera no padrão HTTP(S) atual usando um script PHP 8 long-lived que transmite dados unidirecionalmente (EventSource API no JavaScript), sem necessidade de instalação de novos daemons na Hospedagem.
- [x] **Otimização de Banco de Dados**
  - **Ação:** Como o Redis/Memcached também requerem instalação no servidor (restrito), trabalharemos em indexação SQL mais agressiva, e cache do próprio painel usando `localStorage` e Cache Control Headers na API para aliviar a carga do MySQL atual.

## 2. 🎨 Frontend e Interface de Usuário

- [x] **Transição Gradual para Componentização**
  - **Contexto:** Manter Kanban rico e Quill.js em Vanilla JS traz alto custo de manutenção a longo prazo.
  - **Ação:** Introduzir soluções leves como Alpine.js ou Petite Vue para isolar o estado de componentes complexos.
- [x] **Refinamento das Micro-animações**
  - **Ação:** Beneficiar a jornada interna com "Spring Physics" do CSS nas Dropdowns (Avatar e Filtros), respeitando `prefers-reduced-motion`.

## 3. 🔒 Segurança e Normativas (Compliance)

- [x] **Regras Explícitas de CSRF nas Modais**
  - **Ação:** Garantir que requisições oriundas dos painéis de Respostas Rápidas (Macros) exijam headers CSRF idênticos aos do login.
- [x] **Ofuscação de Dados Pessoais (LGPD)**
  - **Ação:** Implementar filtro de ofuscação/mascaramento de e-mails em Tabelas de Tickets Encerrados e exportação de logs.

## 4. 📈 Business Intelligence

- [x] **Dashboards Avançados de Resolução**
  - **Ação:** Criar relatórios de FCR (First Contact Resolution - taxa de resolução no primeiro contato) complementando as métricas de SLA.

---
*Gerado automaticamente a partir da análise das atualizações e Changelog da versão 3.1.0.*
