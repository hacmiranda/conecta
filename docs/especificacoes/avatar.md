# Sistema de Avatar - Documentação Completa

## Visão Geral
Sistema completo de upload, edição e exibição de avatares de usuários com crop de imagem, validação e armazenamento.

---

## Estrutura de Arquivos

```
gestaocontratos/
├── public/uploads/avatars/          # Diretório de armazenamento
│   └── avatar_{user_id}_{timestamp}.png
├── controllers/UserController.php   # Lógica de upload
├── models/User.php                  # Persistência no banco
└── views/
    ├── users.php                    # Interface de gestão
    └── layouts/
        └── sidebar.php              # Exibição do avatar
```

---

## Banco de Dados

### Tabela: users
```sql
ALTER TABLE users ADD COLUMN avatar VARCHAR(255) DEFAULT NULL;
```

### Estrutura Completa
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
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);
```

---

## Model: User.php

### Método updateAvatar
```php
/**
 * Atualizar avatar do usuário
 * @param int $id - ID do usuário
 * @param string $filename - Nome do arquivo
 * @return bool
 */
public function updateAvatar($id, $filename) {
    $sql = "UPDATE users SET avatar = :avatar WHERE id = :id";
    $stmt = $this->db->prepare($sql);
    return $stmt->execute(['id' => $id, 'avatar' => $filename]);
}
```

### Método getById (com avatar)
```php
public function getById($id) {
    $sql = "SELECT * FROM users WHERE id = :id";
    $stmt = $this->db->prepare($sql);
    $stmt->execute(['id' => $id]);
    return $stmt->fetch();
}
```

---

## Controller: UserController.php

### Método uploadAvatar
```php
/**
 * Upload e processamento de avatar
 * Validações:
 * - Tipos permitidos: JPG, PNG, GIF
 * - Tamanho máximo: 2MB
 * - Remove avatar antigo
 * - Atualiza sessão se for o próprio usuário
 */
public function uploadAvatar() {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['avatar'])) {
        $userId = (int)$_POST['user_id'];
        
        // Verificar permissão
        if (!canManageUsers() && $userId != $_SESSION['user_id']) {
            setFlashMessage('error', 'Acesso negado');
            redirect('../views/users.php');
        }
        
        $file = $_FILES['avatar'];
        
        // Validar tipo
        $allowedTypes = ['image/jpeg', 'image/png', 'image/gif'];
        if (!in_array($file['type'], $allowedTypes)) {
            setFlashMessage('error', 'Tipo de arquivo inválido. Use JPG, PNG ou GIF');
            redirect('../views/users.php');
        }
        
        // Validar tamanho (2MB)
        if ($file['size'] > 2 * 1024 * 1024) {
            setFlashMessage('error', 'Arquivo muito grande. Máximo 2MB');
            redirect('../views/users.php');
        }
        
        // Gerar nome único
        $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
        $filename = 'avatar_' . $userId . '_' . time() . '.' . $extension;
        $uploadPath = __DIR__ . '/../public/uploads/avatars/' . $filename;
        
        // Remover avatar antigo
        $user = $this->userModel->getById($userId);
        if (!empty($user['avatar'])) {
            $oldFile = __DIR__ . '/../public/uploads/avatars/' . $user['avatar'];
            if (file_exists($oldFile)) {
                unlink($oldFile);
            }
        }
        
        // Upload
        if (move_uploaded_file($file['tmp_name'], $uploadPath)) {
            if ($this->userModel->updateAvatar($userId, $filename)) {
                setFlashMessage('success', 'Avatar atualizado com sucesso!');
                
                // Atualizar sessão se for o próprio usuário
                if ($userId == $_SESSION['user_id']) {
                    $_SESSION['avatar'] = $filename;
                }
            } else {
                setFlashMessage('error', 'Erro ao salvar avatar no banco');
            }
        } else {
            setFlashMessage('error', 'Erro ao fazer upload do arquivo');
        }
        
        redirect('../views/users.php');
    }
}
```

### Rota no Controller
```php
// No final do arquivo UserController.php
if (isset($_GET['action'])) {
    $controller = new UserController();
    
    switch ($_GET['action']) {
        case 'upload-avatar':
            $controller->uploadAvatar();
            break;
        // ... outras actions
    }
}
```

---

## View: Modal de Upload e Crop

### HTML do Modal
```html
<!-- Modal Avatar -->
<div id="avatarModal" class="hidden fixed inset-0 bg-black/50 z-50 flex items-center justify-center p-4">
    <div class="bg-white rounded-xl shadow-2xl max-w-lg w-full">
        <!-- Header -->
        <div class="p-6 border-b border-gray-200">
            <div class="flex items-center justify-between">
                <h2 id="avatarModalTitle" class="text-xl font-bold text-gray-900">Alterar Avatar</h2>
                <button onclick="closeAvatarModal()" class="text-gray-400 hover:text-gray-600">
                    <span class="material-symbols-outlined">close</span>
                </button>
            </div>
        </div>
        
        <!-- Form -->
        <form id="avatarForm" action="../controllers/UserController.php?action=upload-avatar" method="POST" enctype="multipart/form-data" class="p-6">
            <input type="hidden" id="avatarUserId" name="user_id">
            
            <!-- Upload Section -->
            <div id="uploadSection" class="text-center">
                <div class="w-64 h-64 mx-auto mb-4 rounded-full bg-gray-200 flex items-center justify-center overflow-hidden">
                    <span class="material-symbols-outlined text-6xl text-gray-400">person</span>
                </div>
                <label class="inline-flex items-center gap-2 px-4 py-2 bg-primary hover:bg-primary-hover text-white rounded-lg cursor-pointer">
                    <span class="material-symbols-outlined">upload</span>
                    Selecionar Imagem
                    <input type="file" id="avatarInput" accept="image/*" onchange="loadImageToCrop(this)" class="hidden">
                </label>
                <p class="text-xs text-gray-500 mt-2">JPG, PNG ou GIF (máx. 5MB)</p>
            </div>
            
            <!-- Crop Section -->
            <div id="cropSection" class="hidden">
                <div class="mb-4">
                    <div class="w-full h-80 bg-gray-100 rounded-lg overflow-hidden flex items-center justify-center">
                        <img id="imageToCrop" class="max-w-full max-h-full">
                    </div>
                </div>
                
                <div class="flex items-center gap-4 mb-4">
                    <label class="text-sm font-semibold text-gray-700">Zoom:</label>
                    <input type="range" id="zoomSlider" min="0.1" max="3" step="0.1" value="1" class="flex-1">
                    <button type="button" onclick="resetCrop()" class="text-sm text-primary hover:text-primary-hover">
                        Resetar
                    </button>
                </div>
            </div>
            
            <!-- Buttons -->
            <div class="flex gap-3 mt-6">
                <button type="button" onclick="closeAvatarModal()" class="flex-1 px-4 py-2 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-50">
                    Cancelar
                </button>
                <button type="button" id="cropButton" onclick="cropAndSave()" class="hidden flex-1 px-4 py-2 bg-primary hover:bg-primary-hover text-white rounded-lg">
                    Cortar e Salvar
                </button>
            </div>
        </form>
    </div>
</div>
```

### Dependências (CDN)
```html
<!-- Cropper.js -->
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.6.1/cropper.min.css">
<script src="https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.6.1/cropper.min.js"></script>
```

### JavaScript - Funções de Crop
```javascript
let cropper = null;

/**
 * Abrir modal de avatar
 */
function openAvatarModal(userId, userName) {
    document.getElementById('avatarUserId').value = userId;
    document.getElementById('avatarModalTitle').textContent = 'Alterar Avatar - ' + userName;
    document.getElementById('avatarModal').classList.remove('hidden');
}

/**
 * Fechar modal e limpar
 */
function closeAvatarModal() {
    document.getElementById('avatarModal').classList.add('hidden');
    if (cropper) {
        cropper.destroy();
        cropper = null;
    }
    document.getElementById('uploadSection').classList.remove('hidden');
    document.getElementById('cropSection').classList.add('hidden');
    document.getElementById('cropButton').classList.add('hidden');
    document.getElementById('avatarInput').value = '';
}

/**
 * Carregar imagem para crop
 */
function loadImageToCrop(input) {
    if (input.files && input.files[0]) {
        const file = input.files[0];
        
        // Validar tamanho (5MB)
        if (file.size > 5 * 1024 * 1024) {
            Toast.error('Arquivo muito grande. Máximo 5MB');
            return;
        }
        
        const reader = new FileReader();
        reader.onload = function(e) {
            const image = document.getElementById('imageToCrop');
            image.src = e.target.result;
            
            // Mostrar seção de crop
            document.getElementById('uploadSection').classList.add('hidden');
            document.getElementById('cropSection').classList.remove('hidden');
            document.getElementById('cropButton').classList.remove('hidden');
            
            // Destruir cropper anterior
            if (cropper) {
                cropper.destroy();
            }
            
            // Inicializar Cropper.js
            cropper = new Cropper(image, {
                aspectRatio: 1,           // Quadrado
                viewMode: 2,              // Restringir crop à imagem
                dragMode: 'move',         // Mover imagem
                autoCropArea: 0.8,        // 80% da área
                restore: false,
                guides: true,
                center: true,
                highlight: false,
                cropBoxMovable: true,
                cropBoxResizable: true,
                toggleDragModeOnDblclick: false,
            });
            
            // Controle de zoom
            document.getElementById('zoomSlider').oninput = function() {
                cropper.zoomTo(parseFloat(this.value));
            };
        };
        reader.readAsDataURL(file);
    }
}

/**
 * Resetar crop
 */
function resetCrop() {
    if (cropper) {
        cropper.reset();
        document.getElementById('zoomSlider').value = 1;
    }
}

/**
 * Cortar e salvar imagem
 */
function cropAndSave() {
    if (!cropper) return;
    
    // Gerar canvas com imagem cortada (800x800)
    const canvas = cropper.getCroppedCanvas({
        width: 800,
        height: 800,
        imageSmoothingEnabled: true,
        imageSmoothingQuality: 'high',
    });
    
    // Converter para blob
    canvas.toBlob(function(blob) {
        const formData = new FormData();
        formData.append('user_id', document.getElementById('avatarUserId').value);
        formData.append('avatar', blob, 'avatar.png');
        
        // Enviar via AJAX
        fetch('../controllers/UserController.php?action=upload-avatar', {
            method: 'POST',
            body: formData
        })
        .then(response => {
            if (response.ok) {
                Toast.success('Avatar atualizado com sucesso!');
                setTimeout(() => location.reload(), 1000);
            } else {
                Toast.error('Erro ao atualizar avatar');
            }
        })
        .catch(() => {
            Toast.error('Erro ao enviar imagem');
        });
        
        closeAvatarModal();
    }, 'image/png');
}
```

---

## Exibição do Avatar

### Sidebar (views/layouts/sidebar.php)
```php
<div class="relative">
    <button type="button" id="sidebarAvatarBtn" class="h-20 w-20 rounded-full bg-gradient-to-br from-blue-400 to-purple-500 flex items-center justify-center border-4 border-white/20 shadow-xl overflow-hidden cursor-pointer hover:border-white/40 hover:scale-105 transition-all duration-300">
        <?php if (!empty($_SESSION['avatar'])): ?>
        <img src="../public/uploads/avatars/<?php echo htmlspecialchars($_SESSION['avatar']); ?>" class="w-full h-full object-cover no-filter" alt="Avatar">
        <?php else: ?>
        <span class="text-white font-black text-3xl"><?php echo strtoupper(substr($userName, 0, 1)); ?></span>
        <?php endif; ?>
    </button>
</div>
```

### Tabela de Usuários (views/users.php)
```php
<td class="px-6 py-4">
    <div class="flex items-center gap-3">
        <?php if (!empty($user['avatar'])): ?>
        <img src="../public/uploads/avatars/<?php echo htmlspecialchars($user['avatar']); ?>" class="w-10 h-10 rounded-full object-cover border-2 border-gray-200" alt="Avatar">
        <?php else: ?>
        <div class="w-10 h-10 rounded-full bg-gradient-to-br from-blue-500 to-purple-600 flex items-center justify-center text-white font-bold border-2 border-gray-200">
            <?php echo strtoupper(substr($user['full_name'], 0, 1)); ?>
        </div>
        <?php endif; ?>
        <span class="text-sm font-semibold text-gray-900"><?php echo htmlspecialchars($user['full_name']); ?></span>
    </div>
</td>
```

### Botão para Abrir Modal
```html
<button onclick="openAvatarModal(<?php echo $user['id']; ?>, '<?php echo addslashes($user['full_name']); ?>')" class="text-gray-600 hover:text-green-600 transition-colors" title="Alterar Avatar">
    <span class="material-symbols-outlined text-xl">photo_camera</span>
</button>
```

---

## Configuração do Diretório

### Criar Diretório
```bash
mkdir -p public/uploads/avatars
chmod 777 public/uploads/avatars
```

### Permissões (Linux/Mac)
```bash
chmod 755 public/uploads/
chmod 777 public/uploads/avatars/
```

### .htaccess (Segurança)
```apache
# public/uploads/avatars/.htaccess
<Files ~ "\.php$">
    Order allow,deny
    Deny from all
</Files>
```

---

## Validações

### Tipos de Arquivo Permitidos
```php
$allowedTypes = ['image/jpeg', 'image/png', 'image/gif'];
```

### Tamanho Máximo
```php
// Controller: 2MB
if ($file['size'] > 2 * 1024 * 1024) {
    setFlashMessage('error', 'Arquivo muito grande. Máximo 2MB');
}

// JavaScript: 5MB
if (file.size > 5 * 1024 * 1024) {
    Toast.error('Arquivo muito grande. Máximo 5MB');
}
```

### Nomenclatura de Arquivo
```php
$filename = 'avatar_' . $userId . '_' . time() . '.' . $extension;
// Exemplo: avatar_1_1769179541.png
```

---

## Cropper.js - Configurações

### Opções Principais
```javascript
{
    aspectRatio: 1,              // Proporção 1:1 (quadrado)
    viewMode: 2,                 // Restringir crop à imagem
    dragMode: 'move',            // Mover imagem ao arrastar
    autoCropArea: 0.8,           // 80% da área inicial
    restore: false,              // Não restaurar após redimensionar
    guides: true,                // Mostrar linhas guia
    center: true,                // Mostrar indicador central
    highlight: false,            // Não destacar área de crop
    cropBoxMovable: true,        // Permitir mover caixa de crop
    cropBoxResizable: true,      // Permitir redimensionar caixa
    toggleDragModeOnDblclick: false
}
```

### Canvas de Saída
```javascript
cropper.getCroppedCanvas({
    width: 800,                  // Largura final
    height: 800,                 // Altura final
    imageSmoothingEnabled: true, // Suavização
    imageSmoothingQuality: 'high' // Qualidade alta
})
```

---

## Fluxo Completo

### 1. Usuário Clica em "Alterar Avatar"
```javascript
openAvatarModal(userId, userName)
```

### 2. Seleciona Imagem
```javascript
loadImageToCrop(input)
// - Valida tamanho
// - Carrega FileReader
// - Inicializa Cropper.js
```

### 3. Ajusta Crop e Zoom
```javascript
// Zoom via slider
cropper.zoomTo(value)

// Reset
cropper.reset()
```

### 4. Salva Imagem
```javascript
cropAndSave()
// - Gera canvas 800x800
// - Converte para blob PNG
// - Envia via FormData/AJAX
```

### 5. Controller Processa
```php
uploadAvatar()
// - Valida tipo e tamanho
// - Remove avatar antigo
// - Salva novo arquivo
// - Atualiza banco de dados
// - Atualiza sessão
```

### 6. Atualiza Interface
```javascript
location.reload()
```

---

## Segurança

### Validação de Permissões
```php
// Apenas admin ou próprio usuário
if (!canManageUsers() && $userId != $_SESSION['user_id']) {
    setFlashMessage('error', 'Acesso negado');
    redirect('../views/users.php');
}
```

### Sanitização de Nome de Arquivo
```php
$extension = pathinfo($file['name'], PATHINFO_EXTENSION);
$filename = 'avatar_' . $userId . '_' . time() . '.' . $extension;
```

### Remoção de Arquivo Antigo
```php
if (!empty($user['avatar'])) {
    $oldFile = __DIR__ . '/../public/uploads/avatars/' . $user['avatar'];
    if (file_exists($oldFile)) {
        unlink($oldFile);
    }
}
```

---

## Troubleshooting

### Erro: "Arquivo muito grande"
- Verificar `upload_max_filesize` no php.ini
- Verificar `post_max_size` no php.ini
- Aumentar limites se necessário

### Erro: "Permissão negada"
```bash
chmod 777 public/uploads/avatars/
```

### Avatar não aparece
- Verificar caminho relativo: `../public/uploads/avatars/`
- Verificar se arquivo existe no diretório
- Verificar valor no banco de dados

### Cropper.js não funciona
- Verificar se CDN está carregando
- Verificar console do navegador
- Verificar se imagem foi carregada corretamente

---

## Melhorias Futuras

1. **Compressão de Imagem**: Reduzir tamanho do arquivo
2. **WebP**: Converter para formato mais eficiente
3. **Thumbnail**: Gerar versões menores (50x50, 100x100)
4. **Cloud Storage**: AWS S3, Cloudinary
5. **Lazy Loading**: Carregar avatares sob demanda
6. **Cache**: Implementar cache de avatares
7. **Validação MIME**: Verificar tipo real do arquivo
8. **Filtros**: Aplicar filtros e efeitos
9. **Arrastar e Soltar**: Drag & drop de imagens
10. **Preview**: Mostrar preview antes de salvar
