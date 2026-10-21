/**
 * Gerenciamento de Categorias e Macrocategorias (exibidas como "Categoria"/"Subcategoria"
 * na interface). Extraído de script.js na Fase 12 (onda 3 da divisão por domínio).
 */

let todasCategorias = [];

// Admin trocou o setor no cabeçalho: refaz a lista de subcategorias usada nos filtros
document.addEventListener('setor-contexto-alterado', function () {
    if (typeof carregarCategorias === 'function') carregarCategorias();
});

async function carregarCategorias() {
    console.log('📁 Carregando categorias...');
    const usuario = JSON.parse(localStorage.getItem('cd_usuario') || '{}');
    // Setor do contexto: o do usuário ou, para o admin, o escolhido no cabeçalho
    const setorContextoCat = window.CabecalhoSetor ? window.CabecalhoSetor.obterSetorContexto() : (usuario.setor_id || null);
    const filtroSetor = setorContextoCat ? `?setor_id=${setorContextoCat}` : '';

    try {
        todasCategorias = await Api.get('categorias.php' + filtroSetor);
        console.log('📁 Categorias carregadas:', todasCategorias.length);
        // NÃO configurar pesquisa automaticamente para não interferir na navegação
    } catch (e) {
        console.error('❌ Erro ao carregar categorias:', e.status, e.message);
    }
}

// Configurar sistema de pesquisa de categoria
function configurarPesquisaCategoria() {
    const input = document.getElementById('categoria-busca');
    const lista = document.getElementById('categoria-lista-form');

    if (!input || !lista) {
        console.log('⚠️ Elementos de categoria não encontrados');
        return;
    }

    console.log('🔧 Configurando pesquisa de categoria...');

    // Limpar eventos anteriores
    input.removeEventListener('click', mostrarListaCategorias);
    input.removeEventListener('focus', mostrarListaCategorias);
    input.removeEventListener('input', filtrarCategorias);

    // Renderizar todas as categorias inicialmente (ocultas)
    renderizarCategoriasForm(todasCategorias);

    // Função para mostrar lista
    function mostrarListaCategorias() {
        if (todasCategorias.length > 0) {
            renderizarCategoriasForm(todasCategorias);
            lista.classList.add('show');
        }
    }

    // Função para filtrar categorias
    function filtrarCategorias() {
        const termo = input.value.toLowerCase().trim();
        let categoriasFiltradas;

        if (termo === '') {
            categoriasFiltradas = todasCategorias;
        } else {
            categoriasFiltradas = todasCategorias.filter(cat =>
                cat.nome.toLowerCase().includes(termo)
            );
        }

        renderizarCategoriasForm(categoriasFiltradas);
        lista.classList.add('show');
    }

    // Mostrar lista ao clicar no campo
    input.addEventListener('click', mostrarListaCategorias);

    // Mostrar lista ao focar no campo
    input.addEventListener('focus', mostrarListaCategorias);

    // Filtrar ao digitar
    input.addEventListener('input', filtrarCategorias);

    // Navegação por teclado
    input.addEventListener('keydown', function (e) {
        const itens = lista.querySelectorAll('.categoria-item-form');
        let itemAtivo = lista.querySelector('.categoria-item-form.selected');

        if (e.key === 'ArrowDown') {
            e.preventDefault();
            if (!itemAtivo) {
                if (itens.length > 0) {
                    itens[0].classList.add('selected');
                }
            } else {
                itemAtivo.classList.remove('selected');
                const proximo = itemAtivo.nextElementSibling;
                if (proximo) {
                    proximo.classList.add('selected');
                    proximo.scrollIntoView({ block: 'nearest' });
                } else {
                    itens[0].classList.add('selected');
                    itens[0].scrollIntoView({ block: 'nearest' });
                }
            }
        } else if (e.key === 'ArrowUp') {
            e.preventDefault();
            if (!itemAtivo) {
                if (itens.length > 0) {
                    itens[itens.length - 1].classList.add('selected');
                }
            } else {
                itemAtivo.classList.remove('selected');
                const anterior = itemAtivo.previousElementSibling;
                if (anterior) {
                    anterior.classList.add('selected');
                    anterior.scrollIntoView({ block: 'nearest' });
                } else {
                    itens[itens.length - 1].classList.add('selected');
                    itens[itens.length - 1].scrollIntoView({ block: 'nearest' });
                }
            }
        } else if (e.key === 'Enter') {
            e.preventDefault();
            if (itemAtivo) {
                itemAtivo.click();
            }
        } else if (e.key === 'Escape') {
            lista.classList.remove('show');
            input.blur();
        }
    });

    // Ocultar lista ao clicar fora
    document.addEventListener('click', function (e) {
        if (!e.target.closest('.categoria-container')) {
            lista.classList.remove('show');
        }
    });

    console.log('✅ Pesquisa de categoria configurada com sucesso!');
}

// Renderizar categorias no formulário
function renderizarCategoriasForm(categorias) {
    const lista = document.getElementById('categoria-lista-form');
    if (!lista) return;

    lista.innerHTML = '';

    if (categorias.length === 0) {
        const item = document.createElement('div');
        item.className = 'categoria-item-form';
        item.textContent = 'Nenhuma subcategoria encontrada';
        item.style.color = '#999';
        item.style.fontStyle = 'italic';
        item.style.cursor = 'default';
        lista.appendChild(item);
        return;
    }

    categorias.forEach((categoria, index) => {
        const item = document.createElement('div');
        item.className = 'categoria-item-form';
        item.textContent = categoria.nome;
        item.setAttribute('data-categoria-id', categoria.id);

        item.onclick = function () {
            // Remover seleção anterior
            document.querySelectorAll('.categoria-item-form').forEach(i => i.classList.remove('selected'));
            // Selecionar atual
            item.classList.add('selected');
            // Definir valores
            document.getElementById('categoria-busca').value = categoria.nome;
            document.getElementById('categoria-id').value = categoria.id;
            // Ocultar lista
            document.getElementById('categoria-lista-form').classList.remove('show');

            console.log('📁 Categoria selecionada:', categoria.nome, 'ID:', categoria.id);
        };

        // Adicionar hover para navegação por teclado
        item.addEventListener('mouseenter', function () {
            document.querySelectorAll('.categoria-item-form').forEach(i => i.classList.remove('selected'));
            item.classList.add('selected');
        });

        lista.appendChild(item);
    });
}

// Mostrar categorias no dropdown (manter para compatibilidade)
function mostrarCategoriasDropdown(categorias) {
    const dropdown = document.getElementById('categoria-lista');
    if (!dropdown) return;

    dropdown.innerHTML = '';

    if (categorias.length === 0) {
        dropdown.innerHTML = '<div class="categoria-item">Nenhuma subcategoria encontrada</div>';
        dropdown.classList.add('show');
        return;
    }

    categorias.forEach(categoria => {
        const item = document.createElement('div');
        item.className = 'categoria-item';
        item.textContent = categoria.nome;

        item.onclick = function () {
            document.getElementById('categoria').value = categoria.nome;
            document.getElementById('categoria-id').value = categoria.id;
            dropdown.classList.remove('show');
        };

        dropdown.appendChild(item);
    });

    dropdown.classList.add('show');
}

// Mostrar gerenciamento de categorias e subcategorias
// (nomes internos de função/variável seguem usando "categoria"/"macrocategoria" —
// mesma nomenclatura das tabelas do banco — só o texto exibido ao usuário mudou)
function mostrarCategorias() {
    // Ativar botão
    document.querySelectorAll('.btn-config').forEach(btn => btn.classList.remove('active')); // nav
    const _bbtn_categorias = document.getElementById('btn-categorias'); if (_bbtn_categorias) _bbtn_categorias.classList.add('active');

    // Salvar subseção
    localStorage.setItem('cd_subSecaoAtiva', 'categorias');
    const configTitle = document.getElementById('config-title'); if (configTitle) configTitle.textContent = String.fromCodePoint(0x1F4C1) + ' Categorias e Subcategorias';

    // Admin precisa escolher o setor no cabeçalho para criar/editar (a lista mostra todos)
    const usuarioCategorias = JSON.parse(localStorage.getItem('cd_usuario') || '{}');
    if (usuarioCategorias.tipo === 'admin' && window.CabecalhoSetor && window.CabecalhoSetor.obterSetorContexto() === null) {
        mostrarToast('Escolha um setor', 'Selecione um setor no cabeçalho para criar ou editar categorias.', 'info');
    }

    const content = document.getElementById('config-content');
    content.style.animation = 'none';
    setTimeout(() => {
        content.style.animation = '';
        carregarListaCategorias();
    }, 10);
}

// Carregar macrocategorias (exibidas como "Categoria") para o select de subcategoria
async function carregarMacrocategorias() {
    try {
        const macrocategorias = await Api.get(urlComSetor('macrocategorias.php'));
        const select = document.getElementById('macrocategoria-categoria');

        if (select) {
            select.innerHTML = '<option value="">Selecione uma categoria</option>';
            macrocategorias.forEach(macro => {
                const option = document.createElement('option');
                option.value = macro.id;
                option.textContent = macro.nome;
                select.appendChild(option);
            });
        }
    } catch (e) {
        console.error('❌ Erro ao carregar categorias:', e.status, e.message);
    }
}

// Carregar lista de subcategorias (tabela "categorias")
async function carregarListaCategorias() {
    try {
        const categorias = await Api.get(urlComSetor('categorias.php'));
        // Carregar macrocategorias (Categoria) para exibição agrupada
        const macrocategorias = await Api.get(urlComSetor('macrocategorias.php'));
        mostrarListaCategorias(categorias, macrocategorias);
    } catch (e) {
        document.getElementById('config-content').innerHTML = '<p>Erro ao carregar subcategorias.</p>';
    }
}

// Mostrar lista de categorias (grupos) e subcategorias
function mostrarListaCategorias(categorias, macrocategorias) {
    const content = document.getElementById('config-content');

    // Agrupar subcategorias por categoria
    const categoriasPorMacro = {};
    const macroMap = {};

    macrocategorias.forEach(macro => {
        macroMap[macro.id] = macro.nome;
        categoriasPorMacro[macro.id] = { nome: macro.nome, itens: [] };
    });

    const semCategoria = { nome: 'Sem Categoria', itens: [] };

    categorias.forEach(categoria => {
        const grupo = categoriasPorMacro[categoria.macrocategoria_id] || semCategoria;
        grupo.itens.push(categoria);
    });

    let categoriasHtml = '';

    // Grupos de subcategorias (uma seção por Categoria, com botões de editar/excluir na própria Categoria)
    macrocategorias.forEach(macro => {
        const grupo = categoriasPorMacro[macro.id];
        categoriasHtml += `
            <div class="usuario-card" style="margin-bottom: 0.75rem;">
                <div class="usuario-info">
                    <h4>📁 ${sanitizeHTML(macro.nome)} <span style="font-weight: normal; color: #666;">(${grupo.itens.length} subcategoria${grupo.itens.length !== 1 ? 's' : ''})</span></h4>
                </div>
                <div class="usuario-acoes">
                    <button class="btn-editar" onclick="editarMacrocategoria(${macro.id}, decodeURIComponent('${argJs(macro.nome)}'))">Editar</button>
                    <button class="btn-excluir" onclick="excluirMacrocategoria(${macro.id})">Excluir</button>
                </div>
            </div>
        `;
        if (grupo.itens.length > 0) {
            categoriasHtml += '<div class="usuarios-grid" style="margin-bottom: 1.5rem;">';
            grupo.itens.forEach(categoria => {
                categoriasHtml += `
                    <div class="usuario-card">
                        <div class="usuario-info">
                            <h4>${sanitizeHTML(categoria.nome)}</h4>
                            <p><strong>Criado:</strong> ${new Date(categoria.criado_em).toLocaleDateString('pt-BR')}</p>
                        </div>
                        <div class="usuario-acoes">
                            <button class="btn-editar" onclick="editarCategoria(${categoria.id})">Editar</button>
                            <button class="btn-excluir" onclick="excluirCategoria(${categoria.id})">Excluir</button>
                        </div>
                    </div>
                `;
            });
            categoriasHtml += '</div>';
        }
    });

    if (semCategoria.itens.length > 0) {
        categoriasHtml += `<h3>📁 Sem Categoria (${semCategoria.itens.length})</h3><div class="usuarios-grid" style="margin-bottom: 1.5rem;">`;
        semCategoria.itens.forEach(categoria => {
            categoriasHtml += `
                <div class="usuario-card">
                    <div class="usuario-info">
                        <h4>${sanitizeHTML(categoria.nome)}</h4>
                        <p><strong>Criado:</strong> ${new Date(categoria.criado_em).toLocaleDateString('pt-BR')}</p>
                    </div>
                    <div class="usuario-acoes">
                        <button class="btn-editar" onclick="editarCategoria(${categoria.id})">Editar</button>
                        <button class="btn-excluir" onclick="excluirCategoria(${categoria.id})">Excluir</button>
                    </div>
                </div>
            `;
        });
        categoriasHtml += '</div>';
    }

    content.innerHTML = `
        <div style="display: flex; gap: 0.75rem; margin-bottom: 1rem; flex-wrap: wrap;">
            <button onclick="toggleFormMacrocategoria()" class="btn-primary">➕ Adicionar Nova Categoria</button>
            <button onclick="toggleFormCategoria()" class="btn-primary">➕ Adicionar Nova Subcategoria</button>
        </div>

        <div class="form-usuario" id="form-nova-macrocategoria" style="display: none; overflow: hidden; max-height: 0; transition: max-height 0.3s ease-out;">
            <h3>Adicionar Nova Categoria</h3>
            <form id="form-macrocategoria" onsubmit="criarMacrocategoria(event)">
                <div class="form-group">
                    <label for="nome-macrocategoria">Nome:</label>
                    <input type="text" id="nome-macrocategoria" required>
                </div>
                <button type="submit" class="btn-primary">Adicionar Categoria</button>
            </form>
        </div>

        <div class="form-usuario" id="form-nova-categoria" style="display: none; overflow: hidden; max-height: 0; transition: max-height 0.3s ease-out;">
            <h3>Adicionar Nova Subcategoria</h3>
            <form id="form-categoria" onsubmit="criarCategoria(event)">
                <div class="form-group">
                    <label for="nome-categoria">Nome:</label>
                    <input type="text" id="nome-categoria" required placeholder="Ex: Sistema *RFID*">
                    <small style="color: #666; font-size: 0.85rem; margin-top: 0.25rem; display: block;">
                        📝 <strong>Dica:</strong> Use *asteriscos* para preservar a formatação de palavras específicas (ex: Sistema *RFID* → Sistema RFID)
                    </small>
                </div>
                <div class="form-group">
                    <label for="macrocategoria-categoria">Categoria:</label>
                    <select id="macrocategoria-categoria" required>
                        <option value="">Selecione uma categoria</option>
                    </select>
                </div>
                <button type="submit" class="btn-primary">Adicionar Subcategoria</button>
            </form>
        </div>

        ${categoriasHtml}
    `;

    // Carregar categorias após renderizar o HTML
    setTimeout(() => carregarMacrocategorias(), 100);
}

// Alternar exibição do formulário de nova categoria (macrocategoria)
function toggleFormMacrocategoria() {
    const form = document.getElementById('form-nova-macrocategoria');
    const isVisible = form.style.display === 'block';

    if (isVisible) {
        form.style.maxHeight = '0';
        setTimeout(() => form.style.display = 'none', 300);
    } else {
        form.style.display = 'block';
        setTimeout(() => form.style.maxHeight = form.scrollHeight + 'px', 10);
    }
}

// Criar nova categoria (macrocategoria)
async function criarMacrocategoria(event) {
    event.preventDefault();

    const nome = document.getElementById('nome-macrocategoria').value.trim();
    if (!nome) return;

    try {
        await Api.post(urlComSetor('macrocategorias.php'), { nome });
        document.getElementById('form-macrocategoria').reset();
        carregarListaCategorias();
        mostrarToast('Sucesso', 'Categoria criada com sucesso!', 'success');
    } catch (e) {
        mostrarAlert('Erro', (e.dados && e.dados.error) || 'Erro ao criar categoria');
    }
}

// Editar categoria (macrocategoria)
function editarMacrocategoria(macroId, nomeAtual) {
    document.getElementById('modal-prompt-message').innerHTML = `
        <div class="form-group">
            <label for="edit-nome-macro">Nome:</label>
            <input type="text" id="edit-nome-macro" value="${sanitizeHTML(nomeAtual)}" required>
        </div>
    `;
    document.getElementById('modal-prompt-title').textContent = 'Editar Categoria';
    document.getElementById('modal-prompt-input').style.display = 'none';
    document.getElementById('modal-overlay').classList.add('show');
    document.getElementById('modal-prompt').classList.add('show');

    modalCallback = function (confirmado) {
        if (confirmado) {
            const nome = document.getElementById('edit-nome-macro').value.trim();
            if (nome) executarEdicaoMacrocategoria(macroId, nome);
        }
        document.getElementById('modal-prompt-input').style.display = 'block';
        document.getElementById('modal-prompt-message').innerHTML = '';
    };
}

// Executar edição de categoria (macrocategoria)
async function executarEdicaoMacrocategoria(macroId, nome) {
    try {
        await Api.put(urlComSetor(`macrocategorias.php/${macroId}`), { nome });
        carregarListaCategorias();
        mostrarToast('Sucesso', 'Categoria atualizada com sucesso!', 'success');
    } catch (e) {
        mostrarAlert('Erro', (e.dados && e.dados.error) || 'Erro ao atualizar categoria');
    }
}

// Excluir categoria (macrocategoria)
function excluirMacrocategoria(macroId) {
    mostrarConfirm(
        'Excluir Categoria',
        'Tem certeza que deseja excluir esta categoria? Ela precisa estar sem subcategorias vinculadas.',
        function (confirmado) {
            if (!confirmado) return;
            executarExclusaoMacrocategoria(macroId);
        }
    );
}

// Executar exclusão de categoria (macrocategoria)
async function executarExclusaoMacrocategoria(macroId) {
    try {
        await Api.delete(urlComSetor(`macrocategorias.php/${macroId}`));
        mostrarToast('Sucesso', 'Categoria excluída com sucesso!', 'success');
        carregarListaCategorias();
    } catch (e) {
        mostrarAlert('Erro', (e.dados && e.dados.error) || 'Erro ao excluir categoria');
    }
}

// Criar nova subcategoria (categoria)
async function criarCategoria(event) {
    event.preventDefault();

    const nomeOriginal = document.getElementById('nome-categoria').value.trim();
    const nome = formatarNomeCategoria(nomeOriginal);
    const macrocategoria_id = document.getElementById('macrocategoria-categoria').value;

    if (!macrocategoria_id) {
        mostrarAlert('Erro', 'Selecione uma categoria!');
        return;
    }

    try {
        // Verificar se subcategoria já existe
        const categorias = await Api.get(urlComSetor('categorias.php'));
        const categoriaExiste = categorias.some(cat => cat.nome.toLowerCase() === nome.toLowerCase());

        if (categoriaExiste) {
            mostrarAlert('Erro', 'Esta subcategoria já existe!');
            return;
        }

        // Criar subcategoria
        await Api.post(urlComSetor('categorias.php'), { nome, macrocategoria_id });
        document.getElementById('form-categoria').reset();
        carregarListaCategorias();
        mostrarToast('Sucesso', 'Subcategoria criada com sucesso!', 'success');
    } catch (e) {
        mostrarAlert('Erro', (e.dados && e.dados.error) || 'Erro ao criar subcategoria');
    }
}

// Formatar nome da subcategoria
function formatarNomeCategoria(nome) {
    const abreviaturas = ['SEI', 'BRC', 'VPN', 'TI', 'RH', 'CEO', 'CTO', 'CFO', 'CPF', 'CNPJ', 'PDF', 'XML', 'HTML', 'CSS', 'JS', 'SQL', 'API', 'URL', 'HTTP', 'HTTPS', 'FTP', 'SSH', 'DNS', 'IP', 'TCP', 'UDP', 'USB', 'HD', 'SSD', 'RAM', 'CPU', 'GPU', 'RFID', 'NFC', 'QR'];

    // Preservar palavras entre asteriscos
    const palavrasPreservadas = [];
    let nomeProcessado = nome.replace(/\*([^*]+)\*/g, (match, palavra) => {
        const placeholder = `__PRESERVAR_${palavrasPreservadas.length}__`;
        palavrasPreservadas.push(palavra);
        return placeholder;
    });

    // Formatar o resto normalmente
    nomeProcessado = nomeProcessado.split(' ').map(palavra => {
        // Se é um placeholder, pular formatação
        if (palavra.includes('__PRESERVAR_')) {
            return palavra;
        }

        const palavraUpper = palavra.toUpperCase();
        if (abreviaturas.includes(palavraUpper)) {
            return palavraUpper;
        }
        return palavra.charAt(0).toUpperCase() + palavra.slice(1).toLowerCase();
    }).join(' ');

    // Restaurar palavras preservadas
    palavrasPreservadas.forEach((palavra, index) => {
        nomeProcessado = nomeProcessado.replace(`__PRESERVAR_${index}__`, palavra);
    });

    return nomeProcessado;
}

// Editar subcategoria (categoria)
async function editarCategoria(categoriaId) {
    try {
        // Buscar dados atuais da subcategoria
        const categorias = await Api.get(urlComSetor('categorias.php'));
        const categoria = categorias.find(c => c.id === categoriaId);

        if (categoria) {
            mostrarFormularioEdicaoCategoria(categoria);
        } else {
            console.error('❌ Subcategoria não encontrada');
        }
    } catch (e) {
        console.error('❌ Erro ao carregar subcategoria:', e.status, e.message);
    }
}

// Mostrar formulário de edição de subcategoria
async function mostrarFormularioEdicaoCategoria(categoria) {
    let macrocategorias;
    try {
        // Carregar categorias (macrocategorias)
        macrocategorias = await Api.get(urlComSetor('macrocategorias.php'));
    } catch (e) {
        console.error('❌ Erro ao carregar categorias:', e.status, e.message);
        return;
    }

    let opcoesHtml = '<option value="">Selecione uma categoria</option>';
    macrocategorias.forEach(macro => {
        const selected = macro.id === categoria.macrocategoria_id ? 'selected' : '';
        opcoesHtml += `<option value="${macro.id}" ${selected}>${sanitizeHTML(macro.nome)}</option>`;
    });

    const content = `
        <div class="form-group">
            <label for="edit-nome-cat">Nome:</label>
            <input type="text" id="edit-nome-cat" value="${sanitizeHTML(categoria.nome)}" required>
            <small style="color: #666; font-size: 0.85rem; margin-top: 0.25rem; display: block;">
                📝 Use *asteriscos* para preservar formatação (ex: Sistema *RFID*)
            </small>
        </div>
        <div class="form-group">
            <label for="edit-macro-cat">Categoria:</label>
            <select id="edit-macro-cat" required>
                ${opcoesHtml}
            </select>
        </div>
    `;

    document.getElementById('modal-prompt-message').innerHTML = content;
    document.getElementById('modal-prompt-title').textContent = 'Editar Subcategoria';
    document.getElementById('modal-prompt-input').style.display = 'none';

    document.getElementById('modal-overlay').classList.add('show');
    document.getElementById('modal-prompt').classList.add('show');

    modalCallback = function (confirmado) {
        if (confirmado === true) {
            const nome = document.getElementById('edit-nome-cat').value;
            const macrocategoriaId = document.getElementById('edit-macro-cat').value;

            if (nome && macrocategoriaId) {
                executarEdicaoCategoria(categoria.id, nome.trim(), macrocategoriaId);
            } else {
                mostrarAlert('Erro', 'Preencha todos os campos obrigatórios');
            }
        }

        // Restaurar modal
        document.getElementById('modal-prompt-input').style.display = 'block';
        document.getElementById('modal-prompt-message').innerHTML = '';
    };
}

// Executar edição de subcategoria
async function executarEdicaoCategoria(categoriaId, nomeOriginal, macrocategoriaId) {
    const nome = formatarNomeCategoria(nomeOriginal);

    try {
        await Api.put(urlComSetor(`categorias.php/${categoriaId}`), { nome, macrocategoria_id: macrocategoriaId });
        carregarListaCategorias();
        carregarCategorias();
        mostrarToast('Sucesso', 'Subcategoria atualizada com sucesso!', 'success');
    } catch (e) {
        mostrarAlert('Erro', (e.dados && e.dados.error) || 'Erro ao atualizar subcategoria');
    }
}

// Excluir subcategoria (categoria)
function excluirCategoria(categoriaId) {
    mostrarConfirm(
        'Excluir Subcategoria',
        'Tem certeza que deseja excluir esta subcategoria?',
        function (confirmado) {
            if (!confirmado) return;

            executarExclusaoCategoria(categoriaId);
        }
    );
}

// Executar exclusão de subcategoria
async function executarExclusaoCategoria(categoriaId) {
    try {
        await Api.delete(urlComSetor(`categorias.php/${categoriaId}`));
        mostrarToast('Sucesso', 'Subcategoria excluída com sucesso!', 'success');
        carregarListaCategorias();
        carregarCategorias();
    } catch (e) {
        mostrarAlert('Erro', (e.dados && e.dados.error) || 'Erro ao excluir subcategoria');
    }
}

// Toggle formulário de nova categoria
function toggleFormCategoria() {
    const form = document.getElementById('form-nova-categoria');
    const isVisible = form.style.display === 'block';

    if (isVisible) {
        form.style.maxHeight = '0';
        setTimeout(() => form.style.display = 'none', 300);
    } else {
        form.style.display = 'block';
        setTimeout(() => form.style.maxHeight = form.scrollHeight + 'px', 10);
    }
}
