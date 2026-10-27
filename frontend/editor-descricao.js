/**
 * Editor da descrição em "Nova Demanda" (Quill), em JS puro.
 * Substitui o componente Alpine.js (auditoria fase 8, 27/10/2026): era o único uso do Alpine, e o
 * Alpine precisa de 'unsafe-eval' na Content-Security-Policy. O conteúdo vai para o campo oculto
 * #descricao, que o formulário envia. API: EditorDescricao.limpar() / EditorDescricao.definir(html).
 */
(function () {
    'use strict';

    let quill = null;

    function campoOculto() {
        return document.getElementById('descricao');
    }

    function sincronizar() {
        const campo = campoOculto();
        if (!campo || !quill) return;
        // Editor vazio: o Quill deixa "<p><br></p>"; o campo fica vazio para o "obrigatório" valer
        campo.value = quill.getText().trim().length === 0 && !quill.root.querySelector('img') ? '' : quill.root.innerHTML;
    }

    function iniciar() {
        const area = document.getElementById('editor-descricao');
        if (!area || quill || typeof Quill === 'undefined') return;
        quill = new Quill(area, {
            theme: 'snow',
            placeholder: area.dataset.placeholder || 'Digite aqui...',
            modules: {
                toolbar: [
                    ['bold', 'italic', 'underline', 'strike'],
                    ['blockquote', 'code-block'],
                    [{ 'list': 'ordered' }, { 'list': 'bullet' }],
                    [{ 'script': 'sub' }, { 'script': 'super' }],
                    [{ 'indent': '-1' }, { 'indent': '+1' }],
                    [{ 'size': ['small', false, 'large', 'huge'] }],
                    [{ 'color': [] }, { 'background': [] }],
                    ['link', 'image'],
                    ['clean']
                ]
            }
        });
        // O texto de ajuda do formulário promete colar imagens: ativa colar/arrastar/botão
        if (typeof habilitarImagensNoEditor === 'function') habilitarImagensNoEditor(quill);
        // Nome acessível da área de edição (o rótulo do formulário aponta para o campo oculto)
        quill.root.setAttribute('aria-label', 'Descrição detalhada');
        quill.root.setAttribute('aria-multiline', 'true');
        quill.root.setAttribute('role', 'textbox');
        quill.on('text-change', sincronizar);
    }

    window.EditorDescricao = {
        limpar() {
            if (quill) quill.setText('');
            const campo = campoOculto();
            if (campo) campo.value = '';
        },
        definir(html) {
            if (!quill) return;
            quill.root.innerHTML = htmlSeguro(html);
            sincronizar();
        },
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', iniciar);
    } else {
        iniciar();
    }
})();
