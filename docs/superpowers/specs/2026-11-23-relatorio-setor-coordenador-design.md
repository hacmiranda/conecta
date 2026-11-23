# Relatório de atividades do setor enviado pelo coordenador — desenho

Data: 23/11/2026. Situação: aguardando aprovação do usuário.

## 1. Intenção

Um coordenador quer ele mesmo preencher o Relatório de Atividades de um dos seus setores, com as quantidades de
atividades/tarefas executadas **pelo setor**. Quando ele liga essa opção num setor:

- os servidores daquele setor (gestor e operador) ficam **desobrigados** dos relatórios individuais;
- o coordenador passa a ser **cobrado** pelo relatório do setor, com prazo, pendência, "atrasado" no Painel e abono,
  como os servidores são hoje;
- o coordenador escolhe se o relatório é **semanal** ou **mensal**, e a cobrança segue essa escolha;
- os números enviados entram no acompanhamento do setor (grupo Atividades: Painel, Relatório, Histórico de
  Ausências e Cruzamento por setor).

## 2. Objetivos, não objetivos e o que não pode acontecer

**Objetivos**
- Opção por setor, ligada/desligada pelo coordenador do setor (só nos setores dele) ou pelo admin, com a
  periodicidade semanal ou mensal.
- Relatório do setor com as atividades cadastradas do setor (`atividades_tipos`), quantidade e observação por
  atividade, rascunho salvo sozinho e envio definitivo (mesmo comportamento do relatório individual).
- Cobrança por período, com prazo em dias úteis e feriados do setor, e abono pelo admin ou pelo próprio
  coordenador do setor (decisão do usuário, 23/11/2026).
- Números contados no acompanhamento do setor.

**Não objetivos**
- Não é um quadro novo no Dashboard de demandas (o Dashboard é só de demandas).
- O coordenador não lança em nome de cada servidor.
- Não muda o Registro de Atividades individual de quem não está em setor com a opção ligada.
- Não volta a incluir o coordenador em `AcessoService::PAPEIS_REGISTRAM_ATIVIDADES` (a decisão de 19/11/2026
  continua: coordenador não tem relatório individual).

**Não pode acontecer**
- Contagem dupla: com a opção ligada, servidores do setor não enviam relatório individual.
- Pendências fantasmas: servidor desobrigado não aparece como atrasado no período em que a opção estava ligada,
  nem depois que ela for desligada (o histórico de quando valeu fica guardado).
- Relatórios antigos mudarem de setor quando alguém é transferido (hoje o setor vem da lotação atual).
- Alguém de fora do setor (outro coordenador, gestor, perfis de consulta) abonar o relatório do setor.

## 3. Restrições do projeto

- Regras de acesso só por `AcessoService` (nada de testar papel solto); frontend por `Papeis`.
- Toda escrita auditada (`AuditoriaService`), CSRF em POST/PUT/DELETE, perfis de consulta (supervisor,
  coordenador geral) continuam sem escrever (trava central: `registro-atividades.php` já está liberado na trava,
  então a checagem de papel no controller precisa barrá-los).
- Frontend vanilla, `data-acao` + ouvinte delegado, sem `onclick`/`style` novos, `?v=` novo nos arquivos
  alterados, ícone novo entra na lista `icon_names`, tokens de cor, modo escuro, WCAG 2.2.
- Migração nova: **43**, aplicada por `php database/migrar.php` (MySQL 8.4, sem `ADD COLUMN IF NOT EXISTS`).
- O banco local será replicado em produção: a migração precisa preencher os dados existentes.

## 4. Formas consideradas

1. **Mesma tabela de relatórios (escolhida pelo usuário).** `registros_atividades` ganha `setor_id` e `periodo`;
   as telas de acompanhamento passam a filtrar pelo setor do registro. Reaproveita gravação, itens, histórico,
   CSV e detalhe; corrige de quebra o setor dos relatórios antigos que hoje "mudam" com a transferência da pessoa.
2. **Tabelas próprias para o relatório do setor.** Isolaria o código atual, mas toda tela e CSV de acompanhamento
   teria de juntar duas origens: consultas dobradas e risco de números divergentes.
3. Descartada: coordenador lançando por servidor (não é relatório do setor e não comporta a cobrança mensal).

## 5. Desenho

### 5.1 Banco (migração 43)

**`registros_atividades`**
- `setor_id INT NULL` → preenchido com `usuarios.setor_id` de quem enviou → `NOT NULL`, FK para `setores`
  (`ON DELETE RESTRICT`, como o `usuario_id`). A partir daqui o setor do relatório é o do registro.
- `periodo ENUM('dia','semana','mes') NOT NULL DEFAULT 'dia'`. Relatório individual = `dia`. Relatório do setor
  = `semana` (`data_referencia` = segunda-feira) ou `mes` (`data_referencia` = dia 1º).
- Índice único troca de `(usuario_id, data_referencia)` para `(usuario_id, setor_id, periodo, data_referencia)`.
- Um relatório do setor por setor e período, qualquer que seja o coordenador: coluna gerada
  `chave_setor VARCHAR(40) AS (IF(periodo = 'dia', NULL, CONCAT(setor_id, '-', periodo, '-', data_referencia)))
  STORED` com índice único (vários NULL são permitidos).
- Índice `(setor_id, data_referencia, status)` para as consultas do acompanhamento.

**`atividades_relatorio_setor`** (vigências da opção; guarda o histórico, para a desobrigação e a cobrança
continuarem corretas depois de desligar ou trocar a periodicidade)
- `id`, `setor_id` (FK CASCADE), `periodicidade ENUM('semanal','mensal')`, `inicio DATE NOT NULL`,
  `fim DATE NULL` (NULL = em vigor), `criado_por`, `criado_em`, `encerrado_por`, `encerrado_em`.
- No máximo uma vigência aberta por setor (conferido no código, dentro de transação com `FOR UPDATE` na linha de
  `atividades_config_setor` ou do setor).

**`atividades_abonos_setor`** (abono da pendência do relatório do setor; o abono atual é por usuário e não serve)
- `id`, `setor_id` (FK CASCADE), `periodo ENUM('semana','mes')`, `data_referencia`, `justificativa VARCHAR(1000)`,
  `criado_por`, `criado_em`, único `(setor_id, periodo, data_referencia)`.

### 5.2 Regras de vigência

- **Ligar:** cria a vigência com `inicio` = início do período que contém hoje (segunda-feira da semana ou dia 1º
  do mês). O período corrente já é do coordenador e os servidores ficam desobrigados a partir de `inicio`.
  *(Decisão minha: cobrir o período inteiro evita um pedaço da semana/mês sem ninguém responsável. Efeito
  colateral aceito: relatórios individuais já enviados nesses dias continuam valendo e aparecem no
  acompanhamento; não são apagados.)*
- **Desligar ou trocar a periodicidade:** vale a partir do fim do período corrente. A vigência atual recebe
  `fim` = último dia do período corrente; ao trocar, nasce a nova com `inicio` = dia seguinte. O período em curso
  continua cobrado do coordenador pela regra antiga. *(Decisão minha.)* Desligar sem nova vigência devolve a
  cobrança individual aos servidores a partir do dia seguinte a `fim`.
- Só pode ligar em setor que tenha coordenador ativo (principal ou adicional); senão 400 com a explicação.
  Setores de coordenação (`setor_coordenacao = 1`) também podem: a regra é a mesma. Se o setor ficar sem
  coordenador com a opção ligada, a cobrança continua no setor e o Painel mostra "sem coordenador"; o admin
  pode desligar. Quem assumir a coordenação herda as pendências (elas são do setor, não da pessoa).

### 5.3 Cobrança (`PrazoAtividadeService`)

- Função pura nova `calcularPendenciasRelatorioSetor(array $vigencias, int $prazo, array $feriados,
  array $enviados, array $abonos, string $ini, string $fim, ?DateTimeImmutable $agora)`: percorre cada período
  (semana ou mês) de cada vigência dentro de `[ini, fim]`; para os sem relatório enviado, gera
  `{tipo: 'relatorio_setor', periodo, data_referencia, periodo_fim, prazo_final, status}` com status `pendente`
  (dentro do prazo) ou `atrasado`; abonados viram `abonado` com os dados do abono. Período que ainda não terminou
  não é cobrado (`pendente` só a partir do dia seguinte ao fim do período).
- Prazo: `prazo_dias_atraso` do setor (o mesmo das cobranças dos servidores), em dias úteis contados do dia
  seguinte ao fim do período, com os feriados do setor (`calcularPrazoFinal`). `data_base` do setor também corta.
- **Desobrigação dos servidores:** `pendenciasUsuario()` lê as vigências do setor do servidor e remove de
  `calcularPendencias` todo dia (modo diário/entrega) ou semana (modo semanal) dentro de uma vigência. Semana
  parcialmente coberta (vigência começando ou terminando no meio, só possível no mensal) tem a cota reduzida aos
  dias úteis fora da vigência, pelo mesmo raciocínio de `diasUteisDaSemana()`. Testes puros cobrem isso.
- Método `pendenciasRelatorioSetor(int $setorId, string $ini, string $fim)` carrega vigências, feriados, envios
  (`periodo <> 'dia'`) e abonos do setor e chama a função pura.
- `abonarRelatorioSetor(int $setorId, string $periodo, string $data, string $justificativa, int $criadoPor)`:
  recusa (false → 409) se o relatório daquele período já foi enviado.

### 5.4 Acesso (`AcessoService`)

- `setoresComRelatorioDoCoordenador(array $user): int[]`: setores do coordenador (principal + adicionais) com
  vigência aberta ou com pendência a enviar. Só papel `coordenador`.
- `podeEnviarRelatorioSetor(array $user, int $setorId): bool`.
- Servidor (gestor/operador) cujo setor tem vigência cobrindo a data pedida recebe 409 ao tentar gravar ou enviar
  relatório individual daquela data ("Este setor está com o relatório enviado pelo coordenador…").
- `login`/`me.php` devolvem `relatorio_setor: [{setor_id, nome}]` para o coordenador; `Papeis.enviaRelatorioSetor(u)`.

### 5.5 API

**`registro-atividades.php`** ganha o modo setor, por `?setor_id=` (só coordenador; outros papéis continuam como
estão):
- `GET ?modo=setor&setor_id=&data=` → mesma resposta da tela atual com `periodo`, `periodo_fim`, `setor`,
  pendências do relatório do setor e histórico do setor (últimos 10 relatórios do setor, de qualquer coordenador).
  `data` é normalizada para o início do período da vigência que a contém; sem data = pendência mais antiga ou o
  período corrente. Fora de vigência = 400.
- `PUT ?id=` e `POST ?acao=finalizar&id=` aceitam o registro do setor quando `podeEnviarRelatorioSetor`; itens só
  das atividades ativas do setor do registro; sem modalidade (fica NULL). Auditoria `enviar_relatorio_setor`.
- Rascunho: um por setor e período (pela `chave_setor`); outro coordenador que assumir o setor continua o mesmo
  rascunho. Envio grava `usuario_id` = quem enviou.
- Favoritas continuam por usuário (o coordenador marca as do setor).

**`atividades-config.php`**
- `GET ?setor_id=` devolve também `relatorio_setor: {ligado, periodicidade, inicio, fim_programado, historico[]}`.
- `PUT ?acao=relatorio-setor {setor_id, ligado, periodicidade}`: só admin ou o coordenador do setor (gestor 403).
  Auditoria `alterar_relatorio_setor` com antes/depois.

**`atividades-acompanhamento.php`**
- `POST ?acao=abonar-setor {setor_id, periodo, data, justificativa}` e `DELETE ?acao=abono-setor&id=`: admin ou o
  coordenador do setor (`podeEnviarRelatorioSetor`); 403 aos demais. A justificativa é obrigatória e o abono
  mostra quem abonou. Auditoria `abonar_relatorio_setor` / `remover_abono_relatorio_setor`.

### 5.6 Acompanhamento (contagem nos relatórios do setor)

Regra geral: onde hoje se filtra o setor por `u.setor_id`, passa a ser `r.setor_id` (relatórios enviados), e o
relatório do setor entra nas somas de tarefas/atividades.
- **Painel de Atividades:** em cada setor com a opção (ou com pendência dela), uma linha/cartão
  "📋 Relatório do setor — coordenador: Nome (semanal|mensal)" com a situação do período corrente e do anterior;
  pendências do relatório do setor na lista de pendências, com **Abonar** para o admin e o coordenador do setor. Servidores
  desobrigados aparecem com o selo "Desobrigado (relatório do setor)" em vez de pendência.
- **Relatório de Atividades:** os relatórios do setor aparecem na lista com "Relatório do setor (Nome do
  coordenador)" e "Semana de dd/mm a dd/mm" ou "Mês mm/aaaa"; somam nos indicadores; no Remoto × Presencial
  entram numa terceira categoria "Relatório do setor" (não têm modalidade); filtro de servidor ganha a opção
  "Relatório do setor". CSV com as colunas Tipo e Período.
- **Histórico de Ausências:** períodos sem relatório do setor aparecem como ausência do setor.
- **Cruzamento por setor:** tarefas do relatório do setor somam no total e no "top 5"; na série diária o total do
  período é lançado no **primeiro dia do período** *(decisão minha; o gráfico ganha nota "relatórios do setor
  aparecem no início do período")*; enviados/esperados contam os períodos do setor. Cruzamento por servidor não
  muda.

### 5.7 Telas

- **Configurações → Atividades**, novo bloco "4. Relatório do setor pelo coordenador" (admin e o coordenador do
  setor; gestor vê em leitura): interruptor, semanal/mensal, texto explicando que os servidores ficam
  desobrigados e quando a mudança vale ("O período atual continua semanal até dd/mm"), histórico das vigências.
  A tabela por servidor mostra a faixa "Servidores desobrigados enquanto o relatório do setor estiver ligado" e a
  coluna "O que é cobrado" diz "Nada (relatório do setor)".
- **Registro de Atividades** passa a aparecer para o coordenador quando `relatorio_setor` não está vazio. Com mais
  de um setor, seletor de setor no topo. Escolha do período: campo de semana ou de mês (conforme a vigência),
  avisos de pendência com "Preencher", lista das atividades do setor com −/+ e observação, rascunho automático
  (`cd_ra_rascunho_setor_<setor>_<data>` no `localStorage`), "Finalizar e enviar". Sem campo de modalidade.
- **Servidor desobrigado:** a tela de Registro mostra o aviso "O relatório deste setor é enviado pelo coordenador
  desde dd/mm/aaaa" e não deixa enviar datas cobertas; datas fora da vigência continuam normais.

### 5.8 Testes e documentação

- PHPUnit: `PrazoAtividadeServiceTest` (casos da função pura: semanal, mensal, prazo com feriado, período em
  curso, abono, troca de periodicidade, desobrigação com semana parcial); integração `RelatorioSetorTest`
  (ligar/desligar, envio, 409 do servidor, unicidade por período, migração dos dados preserva totais).
- Matriz de acesso, seção nova [7g]: coordenador do setor liga e envia; coordenador de outro setor 403; gestor e
  operador não ligam; supervisor/coordenador geral não escrevem; admin e o coordenador do setor abonam, coordenador
  de outro setor e gestor recebem 403; servidor desobrigado 409.
- CLAUDE.md (seção "Registro de Atividades e Meu Painel", tabela do frontend, migrações), Ajuda das atividades e
  `docs/implantacao-producao.md` (migração 43).

## 6. Decisões tomadas por mim (para conferir)

1. Ligar vale desde o início do período corrente; desligar/trocar vale ao fim do período corrente.
2. Pendências e rascunho pertencem ao setor; um novo coordenador herda.
3. Relatório do setor sem modalidade, como terceira categoria no Remoto × Presencial.
4. No gráfico diário do Cruzamento, o total do período aparece no primeiro dia do período.
5. Relatórios individuais já enviados dentro de uma vigência continuam contando.
6. A opção só liga em setor com coordenador ativo.

## 7. Deixado ao construtor

Nomes exatos de funções e classes CSS, a divisão do JavaScript (estender `registro-atividades.js` ou criar
`registro-atividades-setor.js`), o texto final das mensagens e a ordem dos commits.
