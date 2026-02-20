---
trigger: always_on
---

# Segurança

## Validação de Input

### ✅ SEMPRE
- Validar TODOS os inputs do usuário (formulários, URLs, APIs)
- Usar `basename()` em caminhos de arquivo
- Sanitizar dados antes de usar em queries SQL
- Validar tipos de arquivo por conteúdo, não apenas extensão
- Limitar tamanho de uploads

### ❌ NUNCA
- Confiar em dados do cliente
- Concatenar strings em queries SQL
- Expor mensagens de erro detalhadas ao usuário
- Armazenar senhas em texto plano
- Hardcodear credenciais no código

## PHP Security

### Queries SQL
```php
// ✅ CORRETO - Prepared Statements
$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$userId]);

// ❌ ERRADO - SQL Injection
$query = "SELECT * FROM users WHERE id = " . $_GET['id'];
```

### Upload de Arquivos
```php
// ✅ SEMPRE validar
$allowedTypes = ['application/pdf', 'image/jpeg', 'image/png'];
$finfo = finfo_open(FILEINFO_MIME_TYPE);
$mimeType = finfo_file($finfo, $filePath);

if (!in_array($mimeType, $allowedTypes)) {
    throw new Exception('Tipo de arquivo não permitido');
}

// Usar basename para evitar path traversal
$filename = basename($_FILES['file']['name']);
```

### Senhas
```php
// ✅ SEMPRE usar password_hash
$hash = password_hash($senha, PASSWORD_ARGON2ID);

// Verificar
if (password_verify($senhaInput, $hash)) {
    // Login válido
}
```

## Headers de Segurança

### Obrigatórios
```php
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('X-XSS-Protection: 1; mode=block');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Content-Security-Policy: default-src \'self\'');
```

## Sessões

### Configuração Segura
```php
// ✅ SEMPRE configurar sessões seguras
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'domain' => '',
    'secure' => true,      // HTTPS only
    'httponly' => true,    // Não acessível via JavaScript
    'samesite' => 'Strict' // CSRF protection
]);

session_start();
session_regenerate_id(true); // Regenerar ID após login
```

## Autenticação

### ✅ Boas Práticas
- Implementar rate limiting em login
- Usar tokens CSRF em formulários
- Implementar logout em todas as sessões
- Registrar tentativas de login falhas
- Implementar bloqueio temporário após múltiplas falhas

### Exemplo CSRF
```php
// Gerar token
$_SESSION['csrf_token'] = bin2hex(random_bytes(32));

// Validar
if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
    die('CSRF token inválido');
}
```

## Arquivos e Diretórios

### ✅ Permissões Corretas
- Arquivos PHP: `644` (rw-r--r--)
- Diretórios: `755` (rwxr-xr-x)
- Uploads: `644` e fora do webroot quando possível
- Config: `600` (rw-------)

### ❌ NUNCA
- Permitir execução em diretórios de upload
- Expor arquivos `.env`, `.git`, `composer.json`
- Usar `777` em produção

## Logs e Auditoria

### ✅ SEMPRE Registrar
- Tentativas de login (sucesso e falha)
- Alterações em dados sensíveis
- Uploads de arquivos
- Erros de segurança
- Acessos administrativos

### ❌ NUNCA Registrar
- Senhas
- Tokens de sessão
- Dados de cartão de crédito
- Informações pessoais sensíveis

## Variáveis de Ambiente

### ✅ SEMPRE
```php
// Usar variáveis de ambiente
$dbPassword = getenv('DB_PASSWORD');

// Arquivo .env fora do webroot
// NUNCA commitar .env no Git
```

## OWASP Top 10

### Checklist Obrigatório
- [ ] Proteção contra SQL Injection
- [ ] Proteção contra XSS
- [ ] Proteção contra CSRF
- [ ] Autenticação segura
- [ ] Controle de acesso adequado
- [ ] Configuração de segurança correta
- [ ] Proteção de dados sensíveis
- [ ] Logging e monitoramento
- [ ] Validação de uploads
- [ ] Headers de segurança configurados
