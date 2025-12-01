# 🎫 CRPDESK - Sistema de Helpdesk

Sistema completo de gerenciamento de tickets de suporte desenvolvido para o Conselho Regional de Psicologia do Rio Grande do Sul (CRPRS).

![Status](https://img.shields.io/badge/status-production-green)
![PHP](https://img.shields.io/badge/PHP-7.4+-blue)
![MySQL](https://img.shields.io/badge/MySQL-5.7+-orange)
![License](https://img.shields.io/badge/license-proprietary-red)

---

## 📋 Sobre o Sistema

O CRPDESK é um sistema de helpdesk desenvolvido especificamente para atender às necessidades do CRPRS, permitindo o gerenciamento eficiente de solicitações de suporte técnico, acompanhamento de SLA, e comunicação automatizada com usuários.

---

## ✨ Funcionalidades Principais

### 🎫 Gestão de Tickets
- ✅ Criação de tickets por formulário web ou email
- ✅ Atribuição automática e manual de tickets para agentes
- ✅ Sistema de prioridades (Baixa, Normal, Alta, Urgente)
- ✅ Múltiplos status de acompanhamento
- ✅ Categorização por macrocategorias e categorias
- ✅ Mesclagem de tickets duplicados
- ✅ Reabertura de tickets encerrados

### 👥 Gestão de Usuários
- ✅ Três níveis de acesso: Cliente, Agente, Admin
- ✅ Autenticação com JWT
- ✅ Recuperação de senha por email
- ✅ Auditoria completa de ações

### 📧 Sistema de Emails
- ✅ Notificações automáticas para:
  - Criação de ticket
  - Atribuição de agente
  - Primeira resposta ao solicitante
  - Novos comentários
  - Mudança de status
  - Fechamento de ticket
- ✅ Templates HTML personalizados
- ✅ Processamento de emails via IMAP (criação automática de tickets)

### ⏱️ SLA (Service Level Agreement)
- ✅ Cálculo automático de SLA por prioridade
- ✅ Contagem de tempo útil (horário comercial)
- ✅ Pausar/retomar contagem de SLA
- ✅ Alertas de SLA vencido
- ✅ Configuração de horários úteis e feriados

### 📊 Dashboard e Relatórios
- ✅ Visão geral de tickets (abertos, em atendimento, encerrados)
- ✅ Gráficos de desempenho
- ✅ Métricas de tempo de resposta
- ✅ Relatórios de auditoria
- ✅ Histórico completo de alterações

### 💬 Comentários e Anexos
- ✅ Sistema de comentários em tickets
- ✅ Upload de múltiplos anexos
- ✅ Notificação de comentários não lidos
- ✅ Suporte a diversos formatos de arquivo

### 🔔 Lembretes
- ✅ Criação de lembretes por ticket
- ✅ Notificações automáticas por email
- ✅ Agendamento de data e hora

### 🎨 Personalização
- ✅ Configuração de cores do sistema
- ✅ Logo personalizado
- ✅ Templates de email customizáveis

---

## 🛠️ Tecnologias Utilizadas

### Backend
- **PHP 7.4+** - Linguagem principal
- **MySQL 5.7+** - Banco de dados
- **PHPMailer** - Envio de emails
- **Firebase JWT** - Autenticação
- **PDO** - Acesso ao banco de dados

### Frontend
- **HTML5/CSS3** - Interface
- **JavaScript Vanilla** - Lógica do cliente
- **Chart.js** - Gráficos
- **XMLHttpRequest** - Comunicação com API

### Infraestrutura
- **Apache/Nginx** - Servidor web
- **Cron Jobs** - Tarefas agendadas
- **IMAP** - Processamento de emails

---

## 📁 Estrutura do Projeto

```
crpdesk/
├── api/
│   ├── config/          # Configurações (DB, Email)
│   ├── controllers/     # Controladores da API
│   ├── middleware/      # Autenticação e validação
│   ├── services/        # Serviços (Email, SLA, Auditoria)
│   ├── cron/           # Scripts agendados
│   └── vendor/         # Dependências PHP
├── frontend/
│   ├── images/         # Imagens e ícones
│   ├── *.html          # Páginas HTML
│   ├── *.css           # Estilos
│   └── *.js            # Scripts JavaScript
├── backend/
│   ├── anexos/         # Arquivos anexados
│   ├── logs/           # Logs do sistema
│   └── *.sql           # Scripts SQL
└── README.md
```

---

## 🚀 Instalação

### Requisitos
- PHP 7.4 ou superior
- MySQL 5.7 ou superior
- Composer
- Servidor web (Apache/Nginx)
- Extensões PHP: PDO, mysqli, imap, mbstring

### Passo a Passo

1. **Clone o repositório**
```bash
git clone https://github.com/seu-usuario/crpdesk.git
cd crpdesk
```

2. **Instale as dependências**
```bash
cd api
composer install
```

3. **Configure o banco de dados**
```bash
# Importe o schema
mysql -u root -p crpdesk < crpdesk01122025.sql

# Execute os scripts de correção
mysql -u root -p crpdesk < backend/deploy_producao.sql
```

4. **Configure as credenciais**
```bash
# Edite api/config/database.php
# Edite api/config/email-config.php
```

5. **Configure o servidor web**
```apache
# Apache .htaccess já incluído
# Para Nginx, configure o root para a pasta raiz
```

6. **Configure os cron jobs**
```bash
# Verificar emails a cada 5 minutos
*/5 * * * * php /caminho/api/cron/verificar-emails.php

# Verificar lembretes a cada hora
0 * * * * php /caminho/api/cron/verificar-lembretes.php
```

---

## 🔧 Configuração

### Banco de Dados
Edite `api/config/database.php`:
```php
$host = 'localhost';
$dbname = 'crpdesk';
$username = 'seu_usuario';
$password = 'sua_senha';
```

### Email
Edite `api/config/email-config.php`:
```php
$config = [
    'smtp_host' => 'smtp.exemplo.com',
    'smtp_port' => 587,
    'smtp_user' => 'seu@email.com',
    'smtp_pass' => 'sua_senha',
    // ...
];
```

---

## 📖 Uso

### Acesso ao Sistema
1. Acesse: `http://seu-dominio/frontend/login.html`
2. Faça login com suas credenciais
3. Dashboard será exibido após autenticação

### Criar Ticket
1. Clique em "Novo Ticket"
2. Preencha título, descrição e prioridade
3. Adicione anexos se necessário
4. Clique em "Criar Ticket"

### Atribuir Ticket
1. Abra o ticket desejado
2. Clique em "Atribuir"
3. Selecione o agente
4. Confirme a atribuição

---

## 🔒 Segurança

- ✅ Autenticação JWT
- ✅ Proteção CSRF
- ✅ Sanitização de inputs
- ✅ Prepared statements (PDO)
- ✅ Auditoria completa de ações
- ✅ Controle de acesso por níveis

---

## 📝 Changelog

### Versão 1.0.0 (Dezembro 2025)
- ✅ Sistema completo de tickets
- ✅ Gestão de SLA
- ✅ Notificações por email
- ✅ Dashboard com métricas
- ✅ Sistema de auditoria
- ✅ Correções de produção aplicadas

---

## 🤝 Contribuindo

Este é um projeto proprietário do CRPRS. Para contribuições internas, entre em contato com a equipe de TI.

---

## 📄 Licença

Proprietary - Conselho Regional de Psicologia do Rio Grande do Sul (CRPRS)

---

## 👥 Equipe

**Desenvolvimento:**
- Hugo Miranda - Coordenador de TI
- Rafael Rueda - Desenvolvedor
- Mateus Pereira - Estagiário de TI

**Conselho Regional de Psicologia do Rio Grande do Sul**  
Av. Protásio Alves, 2854/301 - Porto Alegre/RS  
(51) 3334.6799  
www.crprs.org.br

---

## 📞 Suporte

Para suporte técnico, abra um ticket no sistema ou entre em contato:
- Email: suporte@crprs.org.br
- Telefone: (51) 3334.6799
