// Sistema de Avatar - Conecta CRPRS
let cropper = null;

// Adicionar evento ao botão quando DOM carregar
document.addEventListener('DOMContentLoaded', function() {
    const btnAlterar = document.getElementById('btn-alterar-avatar');
    if (btnAlterar) {
        btnAlterar.addEventListener('click', function(e) {
            e.preventDefault();
            e.stopPropagation();
            abrirModalAvatar();
            // Fechar dropdown
            const dropdown = document.getElementById('header-avatar-dropdown');
            if (dropdown) dropdown.classList.remove('open');
        });
    }
});

/**
 * Abrir modal de avatar (wrapper)
 */
function abrirModalAvatar() {
    const usuario = JSON.parse(localStorage.getItem('cd_usuario'));
    if (usuario) {
        openAvatarModal(usuario.id, usuario.nome);
    }
}

/**
 * Abrir modal de avatar
 */
function openAvatarModal(userId, userName) {
    document.getElementById('avatarUserId').value = userId;
    document.getElementById('avatarModalTitle').textContent = 'Alterar Avatar - ' + userName;
    document.getElementById('modal-overlay').classList.add('show');
    document.getElementById('modal-avatar').classList.add('show');
}

/**
 * Fechar modal e limpar
 */
function closeAvatarModal() {
    document.getElementById('modal-overlay').classList.remove('show');
    document.getElementById('modal-avatar').classList.remove('show');
    if (cropper) {
        cropper.destroy();
        cropper = null;
    }
    document.getElementById('uploadSection').style.display = 'block';
    document.getElementById('cropSection').style.display = 'none';
    document.getElementById('cropButton').style.display = 'none';
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
            mostrarToast('Erro', 'Arquivo muito grande. Máximo de 5 MB.', 'error');
            return;
        }
        
        const reader = new FileReader();
        reader.onload = function(e) {
            const image = document.getElementById('imageToCrop');
            image.src = e.target.result;
            
            // Mostrar seção de crop
            document.getElementById('uploadSection').style.display = 'none';
            document.getElementById('cropSection').style.display = 'block';
            document.getElementById('cropButton').style.display = 'inline-block';
            
            // Destruir cropper anterior
            if (cropper) {
                cropper.destroy();
            }
            
            // Inicializar Cropper.js
            cropper = new Cropper(image, {
                aspectRatio: 1,
                viewMode: 2,
                dragMode: 'move',
                autoCropArea: 0.8,
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
    
    mostrarLoader();
    
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
        const xhr = new XMLHttpRequest();
        xhr.open('POST', window.APP_CONFIG.apiBase + 'avatar.php', true);
        setSecurityHeaders(xhr);
        
        xhr.onload = function() {
            ocultarLoader();
            if (xhr.status === 200) {
                const response = JSON.parse(xhr.responseText);
                if (response.success) {
                    // Atualizar localStorage com novo avatar
                    const usuario = JSON.parse(localStorage.getItem('cd_usuario'));
                    usuario.avatar = response.avatar;
                    localStorage.setItem('cd_usuario', JSON.stringify(usuario));
                    mostrarToast('Avatar atualizado com sucesso!', 'success');
                    setTimeout(() => location.reload(), 1000);
                } else {
                    mostrarToast(response.error || 'Erro ao atualizar avatar', 'error');
                }
            } else {
                const error = JSON.parse(xhr.responseText);
                mostrarToast(error.error || 'Erro ao atualizar avatar', 'error');
            }
        };
        
        xhr.onerror = function() {
            ocultarLoader();
            mostrarToast('Erro ao enviar imagem', 'error');
        };
        
        xhr.send(formData);
        closeAvatarModal();
    }, 'image/png');
}

/**
 * Atualizar exibição do avatar no header
 */
function atualizarAvatarHeader() {
    const usuario = JSON.parse(localStorage.getItem('cd_usuario'));
    if (!usuario || !usuario.avatar) return;
    
    const avatarUrl = `${window.APP_CONFIG.rootBase}backend/uploads/avatars/${usuario.avatar}`;
    
    ['header-avatar-circle', 'header-avatar-circle-sm'].forEach(id => {
        const el = document.getElementById(id);
        if (el) {
            el.style.backgroundImage = `url('${avatarUrl}')`;
            el.style.backgroundSize = 'cover';
            el.style.backgroundPosition = 'center';
            el.innerHTML = '';
        }
    });
}

// Atualizar avatar ao carregar página (após inicializarAvatar do script.js)
document.addEventListener('DOMContentLoaded', function() {
    setTimeout(atualizarAvatarHeader, 200);
});
