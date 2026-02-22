// Componente Alpine.js para encapsulamento do Editor de Texto Rico (Quill.js)
// Isso remove variáveis espalhadas do escopo Global (window) e as isola no Virtual DOM

document.addEventListener('alpine:init', () => {
    Alpine.data('quillEditorComponent', (initialPlaceholder = 'Digite aqui...') => ({
        content: '',
        quillInstance: null,

        initQuill() {
            // Inicializar apenas se não existir
            if (!this.quillInstance) {
                this.quillInstance = new Quill(this.$refs.editorContainer, {
                    theme: 'snow',
                    placeholder: initialPlaceholder,
                    modules: {
                        toolbar: [
                            ['bold', 'italic', 'underline', 'strike'],
                            ['blockquote', 'code-block'],
                            [{ 'list': 'ordered' }, { 'list': 'bullet' }],
                            [{ 'script': 'sub' }, { 'script': 'super' }],
                            [{ 'indent': '-1' }, { 'indent': '+1' }],
                            [{ 'size': ['small', false, 'large', 'huge'] }],
                            [{ 'color': [] }, { 'background': [] }],
                            ['link', 'image', 'video'],
                            ['clean']
                        ]
                    }
                });

                // Atualizar o state Alpine sempre que o usúario digitar no editor
                this.quillInstance.on('text-change', () => {
                    this.content = this.quillInstance.root.innerHTML;

                    // Limpar <p><br></p> vazio
                    if (this.quillInstance.getText().trim().length === 0) {
                        this.content = '';
                    }

                    // Refletir no input invisível (para o Formulário Tradicional JS enxergar)
                    if (this.$refs.hiddenInput) {
                        this.$refs.hiddenInput.value = this.content;
                    }
                });
            }
        },

        // Função útil invocável externamente ao disparar eventos
        clearEditor() {
            if (this.quillInstance) {
                this.quillInstance.root.innerHTML = '';
                this.content = '';
                if (this.$refs.hiddenInput) this.$refs.hiddenInput.value = '';
            }
        },

        // Passa o conteudo pra API externa/legada
        setEditorContent(html) {
            if (this.quillInstance) {
                this.quillInstance.root.innerHTML = html;
                this.content = html;
            }
        }
    }));
});
