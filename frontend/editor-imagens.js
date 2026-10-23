/**
 * Imagens nos editores de texto (Quill): botão de imagem da barra, colar (Ctrl+V, ex.: print de
 * tela) e arrastar arquivos para dentro do texto.
 *
 * A imagem fica no corpo do comentário/descrição (base64 em <img src="data:...">, guardada junto com
 * o texto) e vai embutida no e-mail ao solicitante. Antes de inserir, a imagem é reduzida
 * (no máximo 1600 px de lado) e regravada em JPEG/PNG: fotos e prints de tela sem peso excessivo,
 * e só formatos que o servidor aceita (HTML Purifier: png, jpeg, gif).
 */
(function () {
    'use strict';

    const LADO_MAXIMO = 1600;          // px
    const TAMANHO_MAXIMO_ORIGINAL = 15 * 1024 * 1024; // 15 MB (antes de reduzir)
    const QUALIDADE_JPEG = 0.85;
    const TIPOS_ACEITOS = ['image/png', 'image/jpeg', 'image/gif', 'image/webp'];

    function lerComoDataUrl(arquivo) {
        return new Promise(function (resolve, reject) {
            const leitor = new FileReader();
            leitor.onload = function () { resolve(leitor.result); };
            leitor.onerror = function () { reject(new Error('Não foi possível ler a imagem.')); };
            leitor.readAsDataURL(arquivo);
        });
    }

    function carregarImagem(dataUrl) {
        return new Promise(function (resolve, reject) {
            const img = new Image();
            img.onload = function () { resolve(img); };
            img.onerror = function () { reject(new Error('Arquivo de imagem inválido.')); };
            img.src = dataUrl;
        });
    }

    /**
     * Reduz a imagem e devolve um data URL (JPEG, ou PNG quando há transparência). GIF animado
     * é mantido como está se for pequeno (reduzir perderia a animação).
     */
    async function prepararImagem(arquivo) {
        if (TIPOS_ACEITOS.indexOf(arquivo.type) === -1) {
            throw new Error('Use imagens PNG, JPG, GIF ou WebP.');
        }
        if (arquivo.size > TAMANHO_MAXIMO_ORIGINAL) {
            throw new Error('A imagem é muito grande (máximo de 15 MB).');
        }
        const original = await lerComoDataUrl(arquivo);
        if (arquivo.type === 'image/gif' && arquivo.size <= 1024 * 1024) {
            return original;
        }

        const img = await carregarImagem(original);
        const escala = Math.min(1, LADO_MAXIMO / Math.max(img.naturalWidth, img.naturalHeight));
        const largura = Math.max(1, Math.round(img.naturalWidth * escala));
        const altura = Math.max(1, Math.round(img.naturalHeight * escala));

        const tela = document.createElement('canvas');
        tela.width = largura;
        tela.height = altura;
        const ctx = tela.getContext('2d');

        const podeTerTransparencia = arquivo.type === 'image/png' || arquivo.type === 'image/webp' || arquivo.type === 'image/gif';
        if (!podeTerTransparencia) {
            ctx.fillStyle = '#ffffff';
            ctx.fillRect(0, 0, largura, altura);
        }
        ctx.drawImage(img, 0, 0, largura, altura);

        // PNG só quando necessário (transparência); caso contrário JPEG, bem menor
        let saida = podeTerTransparencia ? tela.toDataURL('image/png') : tela.toDataURL('image/jpeg', QUALIDADE_JPEG);
        if (podeTerTransparencia && saida.length > 600 * 1024) {
            // Print de tela grande: sobre fundo branco em JPEG fica muito mais leve
            ctx.globalCompositeOperation = 'destination-over';
            ctx.fillStyle = '#ffffff';
            ctx.fillRect(0, 0, largura, altura);
            saida = tela.toDataURL('image/jpeg', QUALIDADE_JPEG);
        }
        return saida;
    }

    function inserir(quill, dataUrl) {
        const faixa = quill.getSelection(true);
        const indice = faixa ? faixa.index : quill.getLength();
        quill.insertEmbed(indice, 'image', dataUrl, 'user');
        quill.setSelection(indice + 1, 0, 'silent');
    }

    function avisar(mensagem) {
        if (typeof mostrarToast === 'function') {
            mostrarToast('Imagem', mensagem, 'error');
        } else {
            alert(mensagem);
        }
    }

    async function processarArquivos(quill, arquivos) {
        for (const arquivo of arquivos) {
            try {
                inserir(quill, await prepararImagem(arquivo));
            } catch (e) {
                avisar(e.message || 'Não foi possível inserir a imagem.');
            }
        }
    }

    function imagensDe(lista) {
        return Array.prototype.filter.call(lista || [], function (a) { return a && /^image\//.test(a.type); });
    }

    /**
     * Liga o suporte a imagens num editor Quill já criado.
     * @param {Quill} quill
     */
    window.habilitarImagensNoEditor = function (quill) {
        if (!quill || quill.__imagensHabilitadas) return;
        quill.__imagensHabilitadas = true;

        // Botão "imagem" da barra de ferramentas (se existir no editor)
        const barra = quill.getModule('toolbar');
        if (barra) {
            barra.addHandler('image', function () {
                const entrada = document.createElement('input');
                entrada.type = 'file';
                entrada.accept = TIPOS_ACEITOS.join(',');
                entrada.multiple = true;
                entrada.addEventListener('change', function () {
                    processarArquivos(quill, imagensDe(entrada.files));
                });
                entrada.click();
            });
        }

        // Colar: print de tela e imagens copiadas
        // Fase de captura + stopPropagation: o tratamento de colar do próprio Quill não roda
        // quando o conteúdo é arquivo de imagem (ele só entende texto/HTML)
        quill.root.addEventListener('paste', function (e) {
            const arquivos = imagensDe(e.clipboardData && e.clipboardData.files);
            if (arquivos.length === 0) return;
            e.preventDefault();
            e.stopImmediatePropagation();
            processarArquivos(quill, arquivos);
        }, true);

        // Arrastar arquivos de imagem para o texto
        quill.root.addEventListener('drop', function (e) {
            const arquivos = imagensDe(e.dataTransfer && e.dataTransfer.files);
            if (arquivos.length === 0) return;
            e.preventDefault();
            processarArquivos(quill, arquivos);
        });
        quill.root.addEventListener('dragover', function (e) {
            if (e.dataTransfer && Array.prototype.indexOf.call(e.dataTransfer.types || [], 'Files') !== -1) {
                e.preventDefault();
            }
        });
    };
})();
