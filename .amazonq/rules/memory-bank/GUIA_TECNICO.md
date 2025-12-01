# 🛠️ CRPDESK - Guia Técnico Completo

## 🔧 Stack Tecnológico

### Backend
- **PHP 8.2+** com PDO e IMAP extension
- **MariaDB 10.4+** (via XAMPP) - `crpdesk` database
- **Composer:** PHPMailer 6.8+, Firebase JWT 6.8+
- **Apache 2.4+** com mod_rewrite

### Frontend
- **HTML5, CSS3, JavaScript Vanilla ES6+**
- **Sem frameworks** - XMLHttpRequest para API
- **Local Storage** para sessão

### Configuração XAMPP
```ini
# php.ini - Extensões necessárias
extension=imap
extension=pdo_mysql
extension=openssl
extension=fileinfo
extension=mbstring
```

### Banco de Dados
**IMPORTANTE:** Sempre consultar o banco MariaDB local antes de modificações
- **Caminho:** `C:\xampp\mysql\bin\mysql.exe`
- **Database:** `crpdesk`
- **Charset:** utf8mb4
- **Engine:** InnoDB com foreign keys

**REGRA CRÍTICA - FUNÇÕES NO BANCO:**
- ❌ **NUNCA criar funções (FUNCTION) no banco de dados**
- ❌ **Remover todas as funções existentes no banco**
- ✅ **Toda lógica deve estar no código PHP da aplicação**
- ✅ **Triggers e procedures são permitidos se necessário**

---

## 📁 Estrutura de Arquivos

### Frontend (`frontend/`)
- `index.html` - Interface principal
- `login.html` - Autenticação
- `script.js` - Lógica principal (~3000+ linhas)
- `style.css` - Estilos CRPRS
- `modal-modern.css` - Modais
- `funcionalidades.css` - Features
- `historico.css` - Timeline

### API (`api/`)

**Controllers:**
- `tickets.php` - CRUD de tickets
- `usuarios.php` - Gerenciamento de usuários
- `categorias.php` - Categorias
- `comentarios.php` - Sistema de comentários
- `anexos.php` - Upload/download
- `dashboard.php` - Métricas
- `merge.php` - Mesclagem de tickets
- `auth.php` - Autenticação

**Services:**
- `email-service.php` - Processamento IMAP
- `email-sender.php` - Envio SMTP

**Cron:**
- `verificar-emails.php` - Automação (executa a cada 1 min)

### Backend (`backend/`)
- `anexos/` - Arquivos anexados
- `logs/` - Logs do sistema
- `database.sql` - Schema completo
- Email templates (HTML)

---

## 💻 Padrões de Código

### PHP - Padrão PSR-4

```php
// Conexão com banco
require_once '../config/database.php';
$database = new Database();
$db = $database->getConnection();

// Prepared statements SEMPRE
$query = "SELECT * FROM tickets WHERE id = :id";
$stmt = $db->prepare($query);
$stmt->bindParam(':id', $ticket_id);
$stmt->execute();
$result = $stmt->fetch();

// Resposta JSON consistente
echo json_encode($result);

// Tratamento de erros
try {
    // código
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Mensagem de erro']);
}
```

### JavaScript - ES6+

```javascript
// Carregar dados
function carregarTickets() {
    const xhr = new XMLHttpRequest();
    xhr.open('GET', '/crpdesk/api/controllers/tickets.php', true);
    setSecurityHeaders(xhr);
    
    xhr.onload = function() {
        if (xhr.status === 200) {
            const tickets = JSON.parse(xhr.responseText);
            mostrarTicketsNaTela(tickets);
        } else {
            mostrarAlert('Erro', 'Erro ao carregar tickets');
        }
    };
    
    xhr.send();
}

// Formatação de ticket number
const ticketNumber = String(ticketId).padStart(5, '0');
const displayNumber = `#${ticketNumber}`;

// Data em pt-BR
const dataFormatada = new Date(timestamp).toLocaleString('pt-BR');
```

### CSS - Custom Properties

```css
:root {
    --laranja: #f79226;
    --vermelho: #a8231e;
    --verde: #71aa29;
}

.ticket-card {
    background: white;
    border-radius: 8px;
    padding: 0.75rem;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
}
```

---

## 🔄 Padrões Semânticos Frequentes

### 1. Processamento de Email (3+ arquivos)

```php
// Conectar IMAP
$inbox = imap_open($mailbox, $username, $password);

// Buscar emails não lidos
$emails = imap_search($inbox, 'UNSEEN');

// Processar cada email
foreach ($emails as $email_number) {
    $this->processarEmail($inbox, $email_number);
}

// Marcar como lido e fechar
imap_setflag_full($inbox, $email_number, "\\Seen");
imap_close($inbox);
```

### 2. Gestão de Status de Ticket (5+ arquivos)

```php
// Transição de status com histórico
if ($status === 'fechado' && $fechado_por) {
    $query = "UPDATE tickets SET 
                status = ?, 
                fechado_por = ?, 
                fechado_em = NOW()
              WHERE id = ?";
    $stmt = $db->prepare($query);
    $stmt->execute([$status, $fechado_por, $id]);
    
    // Enviar notificação
    $emailSender->enviarEmailFechamento($ticketInfo);
}
```

### 3. Validação de Upload (4+ arquivos)

```php
$tipos_permitidos = ['pdf', 'doc', 'docx', 'txt', 'csv', 'xls', 'xlsx', 'png', 'jpg', 'jpeg'];
$extensao = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

if (!in_array($extensao, $tipos_permitidos)) {
    throw new Exception('Tipo de arquivo não permitido');
}

if ($tamanho > 20 * 1024 * 1024) { // 20MB
    throw new Exception('Arquivo muito grande');
}
```

### 4. Sistema de Modais (10+ funções)

```javascript
function mostrarModal(tipo, titulo, mensagem, callback) {
    document.getElementById(`modal-${tipo}-title`).textContent = titulo;
    document.getElementById(`modal-${tipo}-message`).textContent = mensagem;
    document.getElementById('modal-overlay').classList.add('show');
    document.getElementById(`modal-${tipo}`).classList.add('show');
    modalCallback = callback;
}
```

### 5. Middleware de Autenticação

```php
// Verificação padrão em todos os controllers
require_once '../middleware/auth.php';

$user = AuthMiddleware::verificarAuth();
AuthMiddleware::verificarAdmin($user); // Se necessário
AuthMiddleware::verificarCSRF();
```

---

## 🔐 Segurança

### Validações Obrigatórias
- **Input:** Sanitização com `htmlspecialchars()`
- **SQL:** Prepared statements SEMPRE
- **Upload:** Validação de tipo MIME e extensão
- **Auth:** JWT token em todas requisições protegidas
- **CSRF:** Token em formulários de alteração

### Headers de Segurança
```php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE');
```

---

## 📧 Configuração de Email

### Locaweb IMAP/SMTP
```php
// api/config/email-config.php
define('IMAP_HOST', 'email-ssl.com.br');
define('IMAP_PORT', 993);
define('IMAP_USERNAME', 'suporte2@crprs.org.br');
define('SMTP_HOST', 'email-ssl.com.br');
define('SMTP_PORT', 465);
```

### Task Scheduler (Windows)
```
Nome: CRPRS Helpdesk Email
Programa: C:\xampp\php\php.exe
Argumentos: C:\xampp\htdocs\crpdesk\api\cron\verificar-emails.php
Disparador: Repetir a cada 1 minuto
```

---

## 🧪 Setup e Testes

### Instalação
```bash
# 1. Extrair para XAMPP
C:\xampp\htdocs\crpdesk\

# 2. Instalar dependências
cd C:\xampp\htdocs\crpdesk\api
composer install

# 3. Importar banco
C:\xampp\mysql\bin\mysql.exe -u root crpdesk < backend/database.sql

# 4. Configurar email
# Editar api/config/email-config.php

# 5. Habilitar IMAP no php.ini
extension=imap

# 6. Reiniciar Apache
```

### Testes
- `api/test/test-email.php` - Testar configuração de email
- `api/test/test-cli.php` - Utilitários CLI
- `api/test_connection.php` - Testar conexão com banco
- `api/test_login.php` - Testar autenticação

### Logs
- `backend/logs/email-cron.log` - Processamento de emails
- `C:\xampp\apache\logs\error.log` - Erros do Apache
- MySQL query logs

---

## 🔧 Manutenção

### Backup
- **Banco de dados:** Diário
- **Arquivos anexados:** Semanal
- **Configurações:** Mensal

### Limpeza
- Logs antigos (>30 dias)
- Tickets encerrados (>1 ano) - opcional
- Anexos órfãos

### Monitoramento
- Espaço em disco (`backend/anexos/`)
- Performance do banco (queries lentas)
- Taxa de sucesso do cron de email
- SLA compliance (>90%)

---

## 🐛 Troubleshooting

### Email não processa
1. Verificar extensão IMAP habilitada
2. Testar credenciais em `test-email.php`
3. Verificar Task Scheduler ativo
4. Checar logs em `backend/logs/email-cron.log`

### Anexos não fazem upload
1. Verificar permissões da pasta `backend/anexos/`
2. Checar limites no `php.ini`:
   - `upload_max_filesize = 20M`
   - `post_max_size = 25M`
3. Verificar espaço em disco

### SLA não calcula corretamente
1. Verificar triggers no banco de dados
2. Verificar lógica de cálculo no código PHP
3. Limpar cache do navegador
4. Verificar tabela `ticket_sla` e campos de tempo

### Banco de dados com erro
1. Fazer backup: `mysqldump -u root crpdesk > backup.sql`
2. Verificar estrutura: `SHOW TABLES;`
3. Reimportar se necessário: `mysql -u root crpdesk < database.sql`

---

## 📝 Convenções de Código

### Nomenclatura
- **PHP:** camelCase para funções, PascalCase para classes
- **JavaScript:** camelCase para funções e variáveis
- **CSS:** kebab-case para classes
- **Banco:** snake_case para tabelas e colunas

### Comentários
```php
// === SISTEMA DE ANEXOS ===
// Função para validar e processar múltiplos arquivos
function adicionarArquivos($novosArquivos) {
    // Validar tipos de arquivo
    // Verificar limite de quantidade
    // Verificar limite de tamanho
}
```

### Idioma
- **Código:** Português do Brasil
- **Comentários:** Português do Brasil
- **Documentação:** Português do Brasil
- **Commits:** Português do Brasil

### ⚠️ Backup Obrigatório
**ANTES de qualquer alteração em `frontend/script.js`:**
```cmd
copy frontend\script.js frontend\script.js.backup
```
**Motivo:** Arquivo grande e crítico. Encoding UTF-8 sensível.

---

## 🚀 Deploy em Produção

### Requisitos do Servidor
- PHP 8.2+ com extensão IMAP
- MariaDB 10.4+
- Apache 2.4+ com mod_rewrite
- SSL/HTTPS
- Cron job (ou Task Scheduler)

### Checklist de Deploy
- [ ] Configurar `.env` com credenciais de produção
- [ ] Desabilitar `display_errors` no PHP
- [ ] Configurar HTTPS
- [ ] Ajustar permissões de arquivos (755/644)
- [ ] Configurar backup automático
- [ ] Testar envio/recebimento de emails
- [ ] Configurar cron job
- [ ] Monitorar logs por 24h

---

## 📞 Suporte

**URL Produção:** https://crp07.org.br/crpdesk/  
**Email Suporte:** suporte2@crprs.org.br  
**Documentação:** `.amazonq/rules/memory-bank/`

---

**Sistema CRPDESK - Guia Técnico**  
*Versão 1.0 - Janeiro 2025*
