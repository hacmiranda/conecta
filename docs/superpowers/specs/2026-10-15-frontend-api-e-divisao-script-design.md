# Design — `api.js` e divisão do `script.js` por domínio

Data: 15/10/2026 · Item pendente da Fase 12 da auditoria (`docs/plano-auditoria-2026-10-15.md`, P1).

## Contexto

`frontend/script.js` tem 10.071 linhas e 267 funções. Cresceu sem nunca ser cortado nas
costuras que já existem: boa parte do arquivo já está organizada em blocos marcados por
comentário (`=== GERENCIAMENTO DE SETORES ===`, `=== GERENCIAMENTO DE USUÁRIOS ===` etc.), mas
tudo continua no mesmo arquivo. Dentro desses blocos, 83 chamadas `XMLHttpRequest` repetem
quase sempre o mesmo padrão: `open` → `setRequestHeader` (se tiver corpo) →
`setSecurityHeaders(xhr)` (Authorization + CSRF) → `onload` checando `status === 200` →
`JSON.parse` → sucesso ou `mostrarAlert(erro)`.

O projeto não tem bundler nem build step (`CLAUDE.md`: "Vanilla JS puro e modular. Não
adicionar React/Angular/Vue"). Funções são chamadas por `onclick="..."` tanto no HTML estático
(`index.html`) quanto em HTML gerado dinamicamente dentro do próprio `script.js` (mais de 100
ocorrências), o que exige que todo o código continue em escopo global — módulos ES
(`type="module"`) exigiriam expor cada uma dessas funções em `window.*` manualmente, com o
risco de esquecer uma e só descobrir em tempo de execução, ao clicar.

**Objetivo:** reduzir `script.js` a um núcleo pequeno (sessão, modais, navegação entre seções)
e mover cada domínio de tela para seu próprio arquivo, do mesmo jeito que `macros.js`,
`avatar.js`, `notificacoes.js`, `horarios-sla.js` e `graficos-dashboard.js` já fazem hoje — sem
inventar um mecanismo novo. De carona, centralizar as 83 chamadas de rede num cliente único
(`api.js`), para não repetir a mesma canalização de cabeçalhos/erro em cada uma.

**Por quem e por quê:** ninguém fora da equipe de desenvolvimento lida com este arquivo
diretamente; o ganho é só para quem mantém o código (menos risco de duplicar função sem notar —
já aconteceu 4 vezes, corrigido na Fase 12 — e arquivos menores são mais fáceis de revisar de
uma vez). Não há mudança visível para quem usa o sistema.

## Decisões já tomadas (aprovadas pelo usuário)

1. **Mecanismo:** vários `<script>` globais carregados em sequência, igual ao padrão já usado no
   projeto. Funções continuam no escopo global; nenhum `onclick="..."` muda.
2. **Ritmo:** em ondas por domínio, cada uma com commit próprio, testada antes de seguir para a
   próxima — não tudo de uma vez.
3. **Migração das chamadas de rede:** junto com cada onda. Ao mover um domínio, as chamadas
   `XMLHttpRequest` daquele domínio trocam para `Api.get/post/put/delete`; o resto do
   `script.js` continua com `XMLHttpRequest` até a sua própria onda.
4. **Validação:** a cada onda, testar no navegador (Playwright) as telas afetadas — console sem
   erro, ações principais funcionando — antes de avisar que a onda terminou.

## `api.js` — cliente HTTP único

```js
const Api = {
    async request(metodo, caminho, { corpo, signal } = {}) {
        const url = caminho.startsWith('http') ? caminho : window.APP_CONFIG.apiBase + caminho;
        const headers = getAuthHeaders();   // já existe em script.js: Authorization + X-CSRF-Token
        if (corpo !== undefined) headers['Content-Type'] = 'application/json';
        const resp = await fetch(url, { method: metodo, headers, signal,
            body: corpo !== undefined ? JSON.stringify(corpo) : undefined });
        const texto = await resp.text();
        const dados = texto ? JSON.parse(texto) : null;
        if (!resp.ok) {
            const erro = new Error((dados && dados.error) || `Erro ${resp.status}`);
            erro.status = resp.status;
            erro.dados = dados;
            throw erro;
        }
        return dados;
    },
    get(caminho, opts)         { return this.request('GET', caminho, opts); },
    post(caminho, corpo, opts) { return this.request('POST', caminho, { ...opts, corpo }); },
    put(caminho, corpo, opts)  { return this.request('PUT', caminho, { ...opts, corpo }); },
    delete(caminho, opts)      { return this.request('DELETE', caminho, opts); },
};
```

Decisões de design:

- **Baseado em `fetch`, não em `XMLHttpRequest`.** O monitor global de sessão (desde a Fase 9,
  em `script.js`) já intercepta todo `fetch` da aplicação para tratar 401/403 — `api.js` não
  precisa reimplementar isso.
- **Não decide como o erro aparece na tela.** Cada chamador continua escolhendo (toast, alert,
  silencioso) com `try/catch`, exatamente como escolhe hoje em cada `xhr.onload`/`else`. Isso
  evita que mover uma chamada para `api.js` mude, de carona, o que o usuário vê na tela.
- **Usa `getAuthHeaders()`**, que já existe em `script.js` (linha 20) e monta os mesmos
  cabeçalhos que `setSecurityHeaders(xhr)` monta hoje para `XMLHttpRequest` — `api.js` não
  duplica essa lógica, só a reaproveita no formato de objeto de cabeçalhos do `fetch`.
- **Exemplo de migração** (`carregarSetores`, de `script.js`):

  Antes:
  ```js
  function carregarSetores() {
      const usuarioLogado = JSON.parse(localStorage.getItem('cd_usuario') || '{}');
      const ehAdmin = usuarioLogado.tipo === 'admin';
      const xhr = new XMLHttpRequest();
      xhr.open('GET', window.APP_CONFIG.apiBase + 'setores.php' + (ehAdmin ? '?incluir_inativos=1' : ''), true);
      setSecurityHeaders(xhr);
      xhr.onload = function () {
          if (xhr.status === 200) {
              let setores = JSON.parse(xhr.responseText);
              if (!ehAdmin) setores = setores.filter(s => s.id == usuarioLogado.setor_id);
              mostrarListaSetores(setores);
          } else {
              document.getElementById('config-content').innerHTML = '<p>Erro ao carregar setores.</p>';
          }
      };
      xhr.send();
  }
  ```

  Depois:
  ```js
  async function carregarSetores() {
      const usuarioLogado = JSON.parse(localStorage.getItem('cd_usuario') || '{}');
      const ehAdmin = usuarioLogado.tipo === 'admin';
      try {
          let setores = await Api.get('setores.php' + (ehAdmin ? '?incluir_inativos=1' : ''));
          if (!ehAdmin) setores = setores.filter(s => s.id == usuarioLogado.setor_id);
          mostrarListaSetores(setores);
      } catch (e) {
          document.getElementById('config-content').innerHTML = '<p>Erro ao carregar setores.</p>';
      }
  }
  ```

## Divisão em domínios (ondas)

Do maior para o menor (linhas aproximadas no `script.js` de hoje):

| Domínio | ~Linhas | Observação |
|---|---|---|
| Demandas (lista, Kanban, filtros, ordenação, mesclar) | 1.800 | maior e mais usado — fica para depois de validar o método |
| Detalhe da demanda (ver, comentar, anexos, ações) | 1.100 | idem |
| Auditoria | 900 | |
| Arquivo/Fechadas (lista, paginação, mesclar, reabrir) | 700 | |
| Dashboard (números + 3 gráficos em canvas) | 550 | |
| Ajuda/Guias (SLA, status, prioridades, atalhos) | 500 | |
| Usuários | 470 | |
| Categorias/Macrocategorias | 470 | |
| Ativação por setor + E-mail por setor | 430 | |
| Relatórios | 300 | |
| Configurações (Personalização, Config. de e-mail, Alterar senha, Modo escuro) | 250 | |
| **Setores** | **180** | **Onda 1** |
| **Lixeira** | **95** | **Onda 1** |

O que sobra forma o **núcleo**, que continua em `script.js`: sessão/login (`getAuthHeaders`,
`fazerLogout`, monitor de 401/403), `mostrarSecao` (troca de seção visível), os modais
(`mostrarAlert`/`Confirm`/`Prompt`/`Select`/`Toast`/`fecharModal`...), o loader, o dropdown do
avatar no cabeçalho, modo escuro, dicas iniciais, `aplicarVisibilidadePorPapel`. Estimativa
final: 2.000-2.500 linhas — o que praticamente toda tela usa, sem motivo para espalhar.

### Onda 1 (a próxima a implementar)

1. `frontend/api.js` — o cliente novo, sem alterar nenhuma chamada existente.
2. `frontend/setores.js` — CRUD de Setores (admin/gestor).
3. `frontend/lixeira.js` — tela de Lixeira (admin).

Critério de escolha: os dois domínios mais isolados e menores do arquivo (sem dependência de
outro domínio fora do núcleo) — servem para validar o método de corte, revisão e teste antes de
entrar nos domínios grandes (Demandas, Detalhe da Demanda), onde um erro custa mais caro.

**Mecânica do corte** (vale para toda onda, não só a 1ª):
1. Criar `frontend/<dominio>.js` com as funções do bloco, coladas sem alterar corpo (exceto a
   troca de `XMLHttpRequest` por `Api.*`).
2. Remover o bloco de `script.js`.
3. Adicionar `<script src="<dominio>.js?v=1"></script>` em `index.html`, depois de `script.js`
   (mesma posição relativa dos arquivos de domínio que já existem).
4. Bump da versão (`?v=`) de `script.js` e do novo arquivo, para não servir versão em cache.
5. Testar no navegador (Playwright): console sem erro, as ações principais do domínio
   funcionando (listar, criar, editar, excluir/ativar-desativar conforme o caso).
6. Um commit por onda.

### Ondas seguintes (ordem sugerida, ajustável a qualquer momento)

2. Usuários
3. Categorias/Macrocategorias
4. Ativação por setor + E-mail por setor
5. Relatórios
6. Configurações
7. Ajuda/Guias
8. Dashboard
9. Auditoria
10. Arquivo/Fechadas
11. Detalhe da demanda
12. Demandas (lista/Kanban) — por último: maior, mais usado, e depois de validar o método nos
    outros 11

## Escopo do plano de implementação que segue este design

O plano detalhado (próximo passo) cobre só a **Onda 1** (`api.js` + `setores.js` + `lixeira.js`).
A tabela de domínios e a ordem das ondas seguintes acima são o roteiro para planejar depois —
cada onda ganha seu próprio plano curto quando chegar a vez, repetindo a mecânica descrita
nesta seção, não um plano gigante escrito de uma vez para as 12 ondas.

## Fora de escopo deste design

- Não migra para módulos ES nem introduz build step.
- Não muda nenhum comportamento visível ao usuário (mensagens de erro, toasts, confirmações
  continuam idênticas).
- Não resolve P8 (padronizar o formato de resposta da API) nem P10 (SLA só no backend) — fica
  para outra fase, como já registrado em `docs/plano-auditoria-2026-10-15.md`.
