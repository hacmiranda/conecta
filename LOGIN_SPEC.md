# Sistema de Login - Documentação Completa

## Visão Geral
Sistema completo de autenticação com login, logout, sessões seguras, validações e proteção contra ataques.

---

## Estrutura de Arquivos

```
projeto/
├── config/
│   └── database.php              # Conexão com banco
├── controllers/
│   └── AuthController.php        # Lógica de autenticação
├── models/
│   └── User.php                  # Model de usuário
├── includes/
│   ├── functions.php             # Funções auxiliares
│   └── validation.php            # Validações
└── views/
    └── login.php                 # Interface de login
```

---

## Banco de Dados

### Tabela: users
```sql
CREATE TABLE users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) UNIQUE NOT NULL,
    email VARCHAR(100) UNIQUE NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    full_name VARCHAR(100) NOT NULL,
    role ENUM('admin', 'operador', 'user') DEFAULT 'user',
    is_active TINYINT(1) DEFAULT 1,
    avatar VARCHAR(255) DEFAULT NULL,
    last_login DATETIME,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_username (username),
    INDEX idx_email (email),
    INDEX idx_role (role)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### Inserir Usuário Admin Padrão
```sql
INSERT INTO users (username, email, password_hash, full_name, role, is_active) 
VALUES (
    'admin', 
    'admin@sistema.com', 
    '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', -- senha: password
    'Administrador', 
    'admin', 
    1
);
```

---

## Model: User.php

```php
<?php
/**
 * Model: User
 * Gerenciamento de Usuários e Autenticação
 */

class User {
    private $db;

    public function __construct() {
        require_once __DIR__ . '/../config/database.php';
        $this->db = getDB();
    }

    /**
     * Autenticar usuário
     * @param string $username - Username ou email
     * @param string $password - Senha em texto plano
     * @return array|false - Dados do usuário ou false
     */
    public function authenticate($username, $password) {
        $sql = "SELECT * FROM users 
                WHERE (username = :username OR email = :email) 
                AND is_active = 1";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            'username' => $username, 
            'email' => $username
        ]);
        
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password_hash'])) {
            $this->updateLastLogin($user['id']);
            return $user;
        }
        
        return false;
    }

    /**
     * Buscar usuário por ID
     */
    public function getById($id) {
        $sql = "SELECT * FROM users WHERE id = :id";
        $stmt = $this->db->prepare($sql);
        $stmt->execute(['id' => $id]);
        return $stmt->fetch();
    }

    /**
     * Criar novo usuário
     */
    public function create($data) {
        $sql = "INSERT INTO users (username, email, password_hash, full_name, role) 
                VALUES (:username, :email, :password_hash, :full_name, :role)";
        
        $data['password_hash'] = password_hash($data['password'], PASSWORD_BCRYPT);
        unset($data['password']);
        
        $stmt = $this->db->prepare($sql);
        return $stmt->execute($data);
    }

    /**
     * Atualizar senha
     */
    public function updatePassword($id, $newPassword) {
        $sql = "UPDATE users SET password_hash = :password_hash WHERE id = :id";
        $stmt = $this->db->prepare($sql);
        return $stmt->execute([
            'id' => $id,
            'password_hash' => password_hash($newPassword, PASSWORD_BCRYPT)
        ]);
    }

    /**
     * Atualizar último login
     */
    private function updateLastLogin($id) {
        $sql = "UPDATE users SET last_login = NOW() WHERE id = :id";
        $stmt = $this->db->prepare($sql);
        $stmt->execute(['id' => $id]);
    }
}
```

---

## Controller: AuthController.php

```php
<?php
/**
 * Controller: Auth
 * Gerenciamento de Autenticação
 */

session_start();
require_once __DIR__ . '/../models/User.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/validation.php';

class AuthController {
    private $userModel;

    public function __construct() {
        $this->userModel = new User();
    }

    /**
     * Processar login
     */
    public function login() {
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $username = sanitizeInput($_POST['username']);
            $password = $_POST['password'];
            
            // Validar campos
            $errors = validateLoginForm([
                'username' => $username, 
                'password' => $password
            ]);
            
            if (empty($errors)) {
                $user = $this->userModel->authenticate($username, $password);
                
                if ($user) {
                    // Criar sessão
                    $_SESSION['user_id'] = $user['id'];
                    $_SESSION['username'] = $user['username'];
                    $_SESSION['full_name'] = $user['full_name'];
                    $_SESSION['user_role'] = $user['role'];
                    $_SESSION['avatar'] = $user['avatar'];
                    $_SESSION['last_activity'] = time();
                    
                    setFlashMessage('success', 'Login realizado com sucesso!');
                    redirect('../views/dashboard.php');
                } else {
                    setFlashMessage('error', 'Usuário ou senha inválidos');
                    redirect('../views/login.php');
                }
            } else {
                $_SESSION['errors'] = $errors;
                redirect('../views/login.php');
            }
        }
    }

    /**
     * Processar logout
     */
    public function logout() {
        session_unset();
        session_destroy();
        redirect('../views/login.php');
    }

    /**
     * Verificar sessão ativa
     */
    public function checkSession() {
        if (!isLoggedIn()) {
            redirect('../views/login.php');
        }
        
        // Timeout de sessão (30 minutos = 1800 segundos)
        if (isset($_SESSION['last_activity']) && 
            (time() - $_SESSION['last_activity'] > 1800)) {
            $this->logout();
        }
        
        $_SESSION['last_activity'] = time();
    }
}

// Processar ações
if (isset($_GET['action'])) {
    $auth = new AuthController();
    
    switch ($_GET['action']) {
        case 'login':
            $auth->login();
            break;
        case 'logout':
            $auth->logout();
            break;
    }
}
```

---

## View: login.php

```php
<?php
session_start();

// Se já estiver logado, redirecionar
if (isset($_SESSION['user_id'])) {
    header('Location: dashboard.php');
    exit;
}

$errors = $_SESSION['errors'] ?? [];
unset($_SESSION['errors']);

$flash = null;
if (isset($_SESSION['flash_message'])) {
    $flash = $_SESSION['flash_message'];
    unset($_SESSION['flash_message']);
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8"/>
    <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
    <title>Login - Sistema</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet"/>
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined" rel="stylesheet"/>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        body { font-family: 'Inter', sans-serif; }
        .material-symbols-outlined { 
            font-variation-settings: 'FILL' 0, 'wght' 400, 'GRAD' 0, 'opsz' 24; 
        }
    </style>
</head>
<body class="bg-gradient-to-br from-blue-50 to-indigo-100 min-h-screen flex items-center justify-center p-4">

<div class="bg-white rounded-2xl shadow-2xl w-full max-w-md p-8">
    <!-- Header -->
    <div class="text-center mb-8">
        <h1 class="text-3xl font-bold text-gray-900 mb-2">Bem-vindo</h1>
        <p class="text-gray-600">Faça login para continuar</p>
    </div>
    
    <!-- Flash Message -->
    <?php if ($flash): ?>
    <div class="mb-4 p-4 rounded-lg <?php echo $flash['type'] === 'success' ? 'bg-green-50 text-green-800 border border-green-200' : 'bg-red-50 text-red-800 border border-red-200'; ?>">
        <div class="flex items-center gap-2">
            <span class="material-symbols-outlined text-sm">
                <?php echo $flash['type'] === 'success' ? 'check_circle' : 'error'; ?>
            </span>
            <span><?php echo htmlspecialchars($flash['message']); ?></span>
        </div>
    </div>
    <?php endif; ?>
    
    <!-- Form -->
    <form action="../controllers/AuthController.php?action=login" method="POST" class="space-y-5">
        <!-- Username -->
        <div>
            <label class="block text-sm font-semibold text-gray-700 mb-2">
                Usuário ou E-mail
            </label>
            <div class="relative">
                <span class="absolute left-3 top-1/2 -translate-y-1/2 text-gray-400">
                    <span class="material-symbols-outlined text-xl">person</span>
                </span>
                <input 
                    type="text" 
                    name="username" 
                    class="w-full pl-11 pr-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500" 
                    placeholder="Digite seu usuário"
                    required
                />
            </div>
            <?php if (isset($errors['username'])): ?>
            <p class="text-sm text-red-600 mt-1"><?php echo $errors['username']; ?></p>
            <?php endif; ?>
        </div>
        
        <!-- Password -->
        <div>
            <label class="block text-sm font-semibold text-gray-700 mb-2">
                Senha
            </label>
            <div class="relative">
                <span class="absolute left-3 top-1/2 -translate-y-1/2 text-gray-400">
                    <span class="material-symbols-outlined text-xl">lock</span>
                </span>
                <input 
                    type="password" 
                    name="password" 
                    id="password"
                    class="w-full pl-11 pr-11 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500" 
                    placeholder="Digite sua senha"
                    required
                />
                <button 
                    type="button" 
                    onclick="togglePassword()" 
                    class="absolute right-3 top-1/2 -translate-y-1/2 text-gray-400 hover:text-gray-600"
                >
                    <span class="material-symbols-outlined text-xl" id="toggle-icon">visibility</span>
                </button>
            </div>
            <?php if (isset($errors['password'])): ?>
            <p class="text-sm text-red-600 mt-1"><?php echo $errors['password']; ?></p>
            <?php endif; ?>
        </div>
        
        <!-- Submit -->
        <button 
            type="submit" 
            class="w-full py-3 bg-blue-600 hover:bg-blue-700 text-white font-semibold rounded-lg transition-colors shadow-lg"
        >
            Entrar
        </button>
    </form>
</div>

<script>
function togglePassword() {
    const passwordInput = document.getElementById('password');
    const toggleIcon = document.getElementById('toggle-icon');
    
    if (passwordInput.type === 'password') {
        passwordInput.type = 'text';
        toggleIcon.textContent = 'visibility_off';
    } else {
        passwordInput.type = 'password';
        toggleIcon.textContent = 'visibility';
    }
}
</script>

</body>
</html>
```

---

## Includes: functions.php

```php
<?php
/**
 * Funções Auxiliares
 */

/**
 * Sanitizar entrada
 */
function sanitizeInput($data) {
    return htmlspecialchars(strip_tags(trim($data)), ENT_QUOTES, 'UTF-8');
}

/**
 * Verificar se usuário está logado
 */
function isLoggedIn() {
    return isset($_SESSION['user_id']);
}

/**
 * Verificar se usuário é admin
 */
function isAdmin() {
    return isset($_SESSION['user_role']) && $_SESSION['user_role'] === 'admin';
}

/**
 * Redirecionar
 */
function redirect($url) {
    header("Location: $url");
    exit;
}

/**
 * Definir mensagem flash
 */
function setFlashMessage($type, $message) {
    $_SESSION['flash_message'] = [
        'type' => $type, 
        'message' => $message
    ];
}

/**
 * Obter mensagem flash
 */
function getFlashMessage() {
    if (isset($_SESSION['flash_message'])) {
        $flash = $_SESSION['flash_message'];
        unset($_SESSION['flash_message']);
        return $flash;
    }
    return null;
}

/**
 * Gerar token CSRF
 */
function generateCSRFToken() {
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Verificar token CSRF
 */
function verifyCSRFToken($token) {
    return isset($_SESSION['csrf_token']) && 
           hash_equals($_SESSION['csrf_token'], $token);
}
```

---

## Includes: validation.php

```php
<?php
/**
 * Funções de Validação
 */

/**
 * Validar campo obrigatório
 */
function validateRequired($value) {
    return !empty(trim($value));
}

/**
 * Validar email
 */
function validateEmail($email) {
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

/**
 * Validar tamanho mínimo
 */
function validateMinLength($value, $min) {
    return strlen($value) >= $min;
}

/**
 * Validar formulário de login
 */
function validateLoginForm($data) {
    $errors = [];
    
    if (!validateRequired($data['username'])) {
        $errors['username'] = 'Usuário é obrigatório';
    }
    
    if (!validateRequired($data['password'])) {
        $errors['password'] = 'Senha é obrigatória';
    }
    
    return $errors;
}

/**
 * Validar senha forte
 */
function validateStrongPassword($password) {
    // Mínimo 8 caracteres, 1 maiúscula, 1 minúscula, 1 número
    return strlen($password) >= 8 &&
           preg_match('/[A-Z]/', $password) &&
           preg_match('/[a-z]/', $password) &&
           preg_match('/[0-9]/', $password);
}
```

---

## Proteção de Páginas

### Header de Páginas Protegidas
```php
<?php
session_start();
require_once __DIR__ . '/../includes/functions.php';

// Verificar se está logado
if (!isLoggedIn()) {
    redirect('../views/login.php');
}

// Verificar timeout (30 minutos)
if (isset($_SESSION['last_activity']) && 
    (time() - $_SESSION['last_activity'] > 1800)) {
    session_unset();
    session_destroy();
    redirect('../views/login.php');
}

$_SESSION['last_activity'] = time();
?>
```

### Verificação de Permissões
```php
// Apenas Admin
if (!isAdmin()) {
    setFlashMessage('error', 'Acesso negado');
    redirect('dashboard.php');
}

// Admin ou Operador
if (!in_array($_SESSION['user_role'], ['admin', 'operador'])) {
    setFlashMessage('error', 'Acesso negado');
    redirect('dashboard.php');
}
```

---

## Segurança

### Hash de Senha
```php
// Criar hash
$hash = password_hash($password, PASSWORD_BCRYPT);

// Verificar senha
if (password_verify($inputPassword, $storedHash)) {
    // Senha correta
}
```

### Proteção SQL Injection
```php
// SEMPRE usar prepared statements
$sql = "SELECT * FROM users WHERE username = :username";
$stmt = $db->prepare($sql);
$stmt->execute(['username' => $username]);
```

### Proteção XSS
```php
// Sanitizar saída
echo htmlspecialchars($data, ENT_QUOTES, 'UTF-8');
```

### Proteção CSRF
```php
// Gerar token no formulário
<input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">

// Verificar no controller
if (!verifyCSRFToken($_POST['csrf_token'])) {
    die('Token CSRF inválido');
}
```

### Timeout de Sessão
```php
// 30 minutos de inatividade
$timeout = 1800;

if (isset($_SESSION['last_activity']) && 
    (time() - $_SESSION['last_activity'] > $timeout)) {
    session_unset();
    session_destroy();
    redirect('login.php');
}

$_SESSION['last_activity'] = time();
```

---

## Configuração de Sessão Segura

### php.ini ou .htaccess
```ini
session.cookie_httponly = 1
session.cookie_secure = 1
session.use_strict_mode = 1
session.cookie_samesite = "Strict"
```

### No código PHP
```php
ini_set('session.cookie_httponly', 1);
ini_set('session.cookie_secure', 1);
ini_set('session.use_strict_mode', 1);

session_start([
    'cookie_lifetime' => 0,
    'cookie_httponly' => true,
    'cookie_secure' => true,
    'cookie_samesite' => 'Strict'
]);
```

---

## Fluxo Completo

### 1. Usuário Acessa login.php
- Verifica se já está logado
- Exibe formulário

### 2. Submete Formulário
```
POST → AuthController.php?action=login
```

### 3. Controller Valida
- Sanitiza entrada
- Valida campos obrigatórios
- Autentica no banco

### 4. Model Autentica
- Busca usuário por username/email
- Verifica se está ativo
- Compara senha com hash
- Atualiza last_login

### 5. Cria Sessão
```php
$_SESSION['user_id'] = $user['id'];
$_SESSION['username'] = $user['username'];
$_SESSION['user_role'] = $user['role'];
$_SESSION['last_activity'] = time();
```

### 6. Redireciona
```
redirect('dashboard.php')
```

---

## Logout

### Botão de Logout
```html
<a href="../controllers/AuthController.php?action=logout">
    Sair
</a>
```

### Processo
```php
public function logout() {
    session_unset();
    session_destroy();
    redirect('../views/login.php');
}
```

---

## Testes

### Criar Usuário de Teste
```php
$user = new User();
$user->create([
    'username' => 'teste',
    'email' => 'teste@email.com',
    'password' => 'senha123',
    'full_name' => 'Usuário Teste',
    'role' => 'user'
]);
```

### Testar Login
1. Usuário correto + senha correta = ✅ Login
2. Usuário correto + senha errada = ❌ Erro
3. Usuário inexistente = ❌ Erro
4. Usuário inativo = ❌ Erro
5. Campos vazios = ❌ Validação

---

## Melhorias Futuras

1. **Rate Limiting**: Limitar tentativas de login
2. **2FA**: Autenticação de dois fatores
3. **Recuperação de Senha**: Email com token
4. **Lembrar-me**: Cookie persistente
5. **Logs de Acesso**: Registrar tentativas
6. **Captcha**: Proteção contra bots
7. **OAuth**: Login social (Google, Facebook)
8. **Bloqueio de Conta**: Após X tentativas
9. **Histórico de Sessões**: Listar dispositivos
10. **Notificação de Login**: Email ao logar
