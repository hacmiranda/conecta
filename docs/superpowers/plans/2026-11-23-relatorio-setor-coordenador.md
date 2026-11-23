# Relatório de atividades do setor pelo coordenador — plano de implementação

> **Para agentes:** SUB-SKILL OBRIGATÓRIA: use superpowers:subagent-driven-development (recomendado) ou
> superpowers:executing-plans para executar este plano tarefa a tarefa. Os passos usam caixas (`- [ ]`).

**Objetivo:** permitir que o coordenador ligue, num setor dele, o relatório de atividades do setor (semanal ou
mensal), cobrado com prazo, pendência e abono, desobrigando os servidores do setor e somando no acompanhamento.

**Arquitetura:** a mesma tabela `registros_atividades` passa a ter `setor_id` e `periodo`; uma tabela de vigências
(`atividades_relatorio_setor`) diz quando a opção valeu; um serviço novo (`RelatorioSetorService`) cuida das
vigências e da cobrança do setor; `PrazoAtividadeService` ganha a função pura da cobrança por período e a
desobrigação; telas existentes (Registro, Configurações → Atividades, Acompanhamento) são estendidas.

**Tecnologia:** PHP 8 + PDO/MySQL 8.4, PHPUnit (`cd api && composer test`), JavaScript vanilla sem build.

**Desenho:** `docs/superpowers/specs/2026-11-23-relatorio-setor-coordenador-design.md` (ler antes de começar).

## Restrições globais

- Tudo em pt-BR (comentários, mensagens, commits). Commits atômicos ao fim de cada tarefa, **sem `git push`**.
- Acesso só por `AcessoService` no backend e `Papeis` no frontend; nunca `tipo === 'coordenador'` solto em tela.
- Toda escrita: CSRF + `AuditoriaService->registrar()`. Respostas JSON com o código HTTP certo e `try/catch`.
- Prepared statements sempre; listas de inteiros só por `AcessoService::filtroSetorSql`.
- Frontend: `Api.*`, `data-acao*` + ouvinte delegado (sem `onclick=`/`style=` novos), tokens de cor
  (`--texto-suave` para texto secundário), modo escuro, `?v=` novo em todo arquivo alterado no `index.html`,
  ícone novo na lista `icon_names`, títulos `h2.titulo-pagina`.
- Migração nº **43**, arquivo `database/43_relatorio_setor_coordenador.sql`, aplicada com
  `php database/migrar.php`. Fazer backup antes (`mysqldump` fora do repositório).
- Perfis de consulta (supervisor, coordenador geral) nunca escrevem nada disto, nem pelo `registro-atividades.php`
  (que está liberado na trava central): a conferência de papel no controller barra.
- Abono do relatório do setor: **admin ou o coordenador do setor** (decisão do usuário, 23/11/2026).
- Atualizar o `CLAUDE.md` no mesmo trabalho (tarefa 10).

## Pontos de atenção na revisão

1. **Coordenador com dois setores com a opção ligada, mesmo período**: cada setor tem o próprio rascunho e envio
   (índice inclui `setor_id`). Teste na tarefa 5.
2. **Troca de coordenador no meio do período**: o novo coordenador abre o mesmo rascunho (pela `chave_setor`), não
   cria outro. Teste na tarefa 5.
3. **Mensal começando no meio de uma semana**: o servidor do modo semanal tem a cota da semana reduzida aos dias
   úteis fora da vigência, não zerada nem cheia. Teste na tarefa 3.
4. **Desligar e religar no mesmo período**: não pode abrir duas vigências sobrepostas; religar antes do fim
   programado só cancela o fim (`fim = NULL`). Teste na tarefa 4.
5. **Transferência de servidor depois da migração**: relatório antigo continua no setor onde foi enviado (o
   acompanhamento filtra por `r.setor_id`). Teste na tarefa 7.

---

### Task 1: Migração 43

**Arquivos:**
- Criar: `database/43_relatorio_setor_coordenador.sql`
- Teste: `api/tests/Integracao/RelatorioSetorTest.php` (criar; reaproveitar a montagem de transação de
  `RegistroAtividadeTest.php`)

**Interfaces — produz (esquema):**
- `registros_atividades.setor_id INT NOT NULL` (FK `setores`, RESTRICT), `periodo ENUM('dia','semana','mes') NOT
  NULL DEFAULT 'dia'`, `chave_setor VARCHAR(40) AS (IF(periodo = 'dia', NULL, CONCAT(setor_id,'-',periodo,'-',
  data_referencia))) STORED` com `UNIQUE uq_registros_atividades_chave_setor`; único
  `uq_registros_atividades_usuario_setor_periodo_data (usuario_id, setor_id, periodo, data_referencia)` no lugar de
  `uq_registros_atividades_usuario_data`; índice `idx_registros_atividades_setor_data (setor_id, data_referencia,
  status)`.
- `atividades_relatorio_setor (id PK, setor_id FK CASCADE, periodicidade ENUM('semanal','mensal') NOT NULL,
  inicio DATE NOT NULL, fim DATE NULL, criado_por INT NULL FK SET NULL, criado_em DATETIME DEFAULT
  CURRENT_TIMESTAMP, encerrado_por INT NULL FK SET NULL, encerrado_em DATETIME NULL, KEY (setor_id, inicio))`.
- `atividades_abonos_setor (id PK, setor_id FK CASCADE, periodo ENUM('semana','mes') NOT NULL, data_referencia
  DATE NOT NULL, justificativa VARCHAR(1000) NOT NULL, criado_por INT NULL FK SET NULL, criado_em DATETIME DEFAULT
  CURRENT_TIMESTAMP, UNIQUE (setor_id, periodo, data_referencia))`.
- Ordem no SQL: `ADD COLUMN setor_id NULL` → `UPDATE registros_atividades r JOIN usuarios u ON u.id = r.usuario_id
  SET r.setor_id = u.setor_id` → registros cujo usuário não tem setor: `DELETE` só de rascunhos; se sobrar enviado
  sem setor, a migração deve falhar com mensagem (conferir antes no banco local: `SELECT COUNT(*) …`) →
  `MODIFY setor_id NOT NULL` → FK, índices, coluna gerada.

- [ ] **Passo 1:** conferir no banco local quantos registros têm usuário sem setor e anotar no commit.
- [ ] **Passo 2:** teste `test_migracao_preencheu_setor_dos_registros_existentes`: nenhum
  `registros_atividades.setor_id` nulo, e `SUM(quantidade)` por setor (via `r.setor_id`) igual à soma via
  `u.setor_id` para usuários não transferidos.
- [ ] **Passo 3:** teste `test_chave_setor_impede_dois_relatorios_do_mesmo_setor_e_periodo`: inserir dois
  registros `periodo='semana'` mesmo setor/data com usuários diferentes → `PDOException` 23000.
- [ ] **Passo 4:** rodar os testes → falham (colunas não existem).
- [ ] **Passo 5:** escrever a migração; backup; `php database/migrar.php`.
- [ ] **Passo 6:** `cd api && composer test` → tudo verde (os 304 anteriores + os novos).
- [ ] **Passo 7:** ajustar `RegistroAtividadeService::buscarOuCriarRascunho` para gravar `setor_id` (assinatura
  muda para `buscarOuCriarRascunho(int $usuarioId, int $setorId, string $data, string $periodo = 'dia'): array`)
  e o controller passar `$setorId`; `composer test` verde.
- [ ] **Passo 8:** commit `feat: migração 43 (setor no registro de atividades e relatório do setor)`.

### Task 2: Cobrança por período (função pura)

**Arquivos:**
- Modificar: `api/services/prazo-atividade-service.php`
- Teste: `api/tests/Unit/PrazoAtividadeServiceTest.php`

**Interfaces — produz:**
- `PrazoAtividadeService::periodosDaVigencia(array $vigencia, string $ini, string $fim): array` →
  `[['periodo' => 'semana'|'mes', 'data_referencia' => 'Y-m-d', 'periodo_fim' => 'Y-m-d'], ...]`; `$vigencia` =
  `['periodicidade' => 'semanal'|'mensal', 'inicio' => 'Y-m-d', 'fim' => ?'Y-m-d']`. Semana = segunda a domingo;
  mês = dia 1º ao último dia.
- `PrazoAtividadeService::inicioDoPeriodo(string $periodicidade, string $data): string` e
  `fimDoPeriodo(string $periodicidade, string $data): string`.
- `PrazoAtividadeService::calcularPendenciasRelatorioSetor(array $vigencias, int $prazo, ?string $dataBase,
  array $feriados, array $enviados, array $abonos, string $ini, string $fim, ?DateTimeImmutable $agora = null):
  array` — `$enviados` = `['semana|2026-11-23' => true, ...]`; `$abonos` = mesmo formato de chave → info do abono.
  Cada item: `['tipo' => 'relatorio_setor', 'periodo', 'data_referencia', 'periodo_fim', 'prazo_final' => 'Y-m-d',
  'status' => 'pendente'|'atrasado'|'abonado', ...info do abono]`. Período só é cobrado depois de terminar; o
  prazo é `calcularPrazoFinal(periodo_fim, $prazo, $feriados)` (prazo 0 = vence no fim do período, como hoje).
  `atrasado` quando `$agora` > fim do dia do prazo.

- [ ] **Passo 1:** testes (valores com `$agora` fixo):
  - `test_semanal_sem_envio_vira_pendente_e_depois_atrasado` (vigência semanal desde 2026-11-02, prazo 2, agora
    2026-11-10 → semana 02/11 `pendente`; agora 2026-11-12 → `atrasado`).
  - `test_semana_em_curso_nao_e_cobrada` (agora 2026-11-25, semana 23/11 fora da lista).
  - `test_mensal_cobra_o_mes_inteiro` (vigência mensal desde 2026-10-01, envio `mes|2026-10-01` → nada; sem envio
    → `data_referencia` 2026-10-01, `periodo_fim` 2026-10-31).
  - `test_prazo_pula_feriado_do_setor` (prazo 1, fim 2026-11-15 domingo, feriado 2026-11-16 → prazo 2026-11-17).
  - `test_abono_marca_abonado`.
  - `test_troca_de_semanal_para_mensal` (semanal 2026-11-02..2026-11-30, mensal desde 2026-12-01: semanas até
    23/11 e depois o mês de dezembro).
  - `test_data_base_corta_periodos_anteriores`.
- [ ] **Passo 2:** rodar `cd api && vendor/bin/phpunit --filter PrazoAtividadeServiceTest` → falham.
- [ ] **Passo 3:** implementar.
- [ ] **Passo 4:** rodar → passam.
- [ ] **Passo 5:** commit `feat: cobrança por período do relatório do setor`.

### Task 3: Desobrigação dos servidores (função pura + banco)

**Arquivos:**
- Modificar: `api/services/prazo-atividade-service.php` (`calcularPendencias`, `pendenciasUsuario`,
  `diasUteisDaSemana`, `statusSetor`)
- Teste: `api/tests/Unit/PrazoAtividadeServiceTest.php`, `api/tests/Integracao/RelatorioSetorTest.php`

**Interfaces:**
- Consome: vigências no formato da tarefa 2.
- Produz: `calcularPendencias(..., ?DateTimeImmutable $agora = null, array $vigenciasSetor = [])` — parâmetro novo
  no fim (chamadas antigas não mudam). Dia coberto por vigência não é cobrado (modo diário e entrega); no semanal,
  semana inteira coberta some, semana parcial tem a cota limitada aos dias úteis **fora** da vigência (estender
  `diasUteisDaSemana` com os intervalos a excluir).
- `PrazoAtividadeService::vigenciasDoSetor(int $setorId): array` (todas, ordenadas por `inicio`).
- `PrazoAtividadeService::desobrigadoEm(array $vigencias, string $data): bool` (pura).
- `statusSetor()` devolve também `'desobrigado' => bool` por servidor (vigência cobrindo hoje).

- [ ] **Passo 1:** testes puros: `test_dia_dentro_da_vigencia_nao_e_cobrado_no_modo_diario`,
  `test_entrega_dentro_da_vigencia_nao_e_cobrada`, `test_semana_coberta_some_no_modo_semanal`,
  `test_semana_parcial_limita_a_cota_aos_dias_fora_da_vigencia` (cota 3, vigência mensal começa quinta
  2026-10-01 → semana 28/09 cobra no máximo 3 dias úteis seg–qua; com cota 4 → limitada a 3),
  `test_depois_do_fim_da_vigencia_volta_a_cobrar`.
- [ ] **Passo 2:** teste integração `test_pendencias_do_servidor_respeitam_a_vigencia_do_setor`.
- [ ] **Passo 3:** rodar → falham. **Passo 4:** implementar. **Passo 5:** `composer test` verde.
- [ ] **Passo 6:** commit `feat: servidores desobrigados enquanto vale o relatório do setor`.

### Task 4: Vigências e acesso (`RelatorioSetorService` + `AcessoService`)

**Arquivos:**
- Criar: `api/services/relatorio-setor-service.php`
- Modificar: `api/services/acesso-service.php`
- Teste: `api/tests/Integracao/RelatorioSetorTest.php`, `api/tests/Unit/AcessoServiceTest.php`

**Interfaces — produz:**
- `RelatorioSetorService::__construct(PDO $db)`.
- `vigenciaAtual(int $setorId, ?string $hoje = null): ?array` (a que cobre hoje ou a aberta com `fim` futuro).
- `alterar(int $setorId, bool $ligado, string $periodicidade, int $porUsuarioId, ?string $hoje = null): array`
  → devolve `['antes' => ?array, 'depois' => ?array]` para a auditoria. Regras (desenho 5.2): ligar sem vigência
  → `inicio = inicioDoPeriodo(hoje)`; desligar → `fim = fimDoPeriodo(periodicidade atual, hoje)`; religar com
  `fim` futuro e mesma periodicidade → `fim = NULL`; trocar → fecha no fim do período corrente e abre a nova no
  dia seguinte (se já havia uma "próxima" aberta, substitui). Tudo numa transação com `SELECT … FROM setores WHERE
  id = ? FOR UPDATE`. Lança `InvalidArgumentException` com mensagem para: periodicidade inválida; ligar em setor
  sem coordenador ativo.
- `coordenadorDoSetor(int $setorId): ?array` (`id`, `nome`; principal ou `usuario_setores`, tipo `coordenador`,
  ativo).
- `AcessoService::podeEnviarRelatorioSetor(array $user, int $setorId): bool` — coordenador com o setor em
  `setoresDoUsuario`.
- `AcessoService::podeAbonarRelatorioSetor(array $user, int $setorId): bool` — admin ou
  `podeEnviarRelatorioSetor`.
- `AcessoService::podeConfigurarRelatorioSetor(array $user, int $setorId): bool` — admin ou
  `podeEnviarRelatorioSetor`.
- `RelatorioSetorService::setoresDoCoordenador(PDO $db, array $user): array` → `[['setor_id','nome',
  'periodicidade']]` com vigência que cobre hoje ou pendência aberta (estático, usado em login/me).

- [ ] **Passo 1:** testes: `test_ligar_comeca_no_inicio_da_semana`, `test_ligar_mensal_comeca_no_dia_primeiro`,
  `test_desligar_vale_ate_o_fim_do_periodo`, `test_religar_antes_do_fim_cancela_o_fim` (Ponto de atenção 4: uma
  vigência só), `test_trocar_periodicidade_abre_a_nova_no_dia_seguinte`, `test_nao_liga_sem_coordenador_ativo`;
  unitários de `AcessoService` (coordenador do setor sim, de outro setor não, gestor não, admin só configura e
  abona, supervisor não).
- [ ] **Passo 2:** falham. **Passo 3:** implementar. **Passo 4:** `composer test` verde.
- [ ] **Passo 5:** commit `feat: vigências do relatório do setor e regras de acesso`.

### Task 5: Envio do relatório do setor (API)

**Arquivos:**
- Modificar: `api/services/registro-atividade-service.php`, `api/controllers/registro-atividades.php`,
  `api/controllers/me.php`, `api/controllers/auth.php`, `api/controllers/auth-sso.php` (se também monta o usuário)
- Teste: `api/tests/Integracao/RelatorioSetorTest.php`

**Interfaces:**
- Consome: tarefas 1–4.
- Produz (serviço): `buscarOuCriarRascunhoSetor(int $setorId, string $periodo, string $dataReferencia, int
  $usuarioId): array` (procura pela `chave_setor`; cria com `usuario_id` = quem abriu; no envio, `usuario_id`
  passa a ser quem enviou); `historicoSetor(int $setorId, int $limite = 10): array` (mesmo formato de
  `historico()` + `periodo`, `usuario_nome`); `salvarRascunho`/`finalizar` deixam de exigir `usuario_id` igual
  quando chamados pelo caminho do setor: criar `salvarRascunhoSetor(int $registroId, array $itens): bool` e
  `finalizarSetor(int $registroId, int $usuarioId): bool` (modalidade fica NULL).
- Produz (API): `GET registro-atividades.php?modo=setor&setor_id=&data=` → `{modo:'setor', setor:{id,nome},
  periodo, data, periodo_fim, registro, atividades, pendencias, historico}`; `PUT ?modo=setor&id=` e
  `POST ?modo=setor&acao=finalizar&id=` (409 se enviado; 403 se `!podeEnviarRelatorioSetor` para o setor do
  registro; 400 se a data não está numa vigência). Auditoria `enviar_relatorio_setor`
  (`{setor_id, periodo, data, atividades}`).
- Coordenador: o controller deixa de dar 403 geral para ele quando `modo=setor`; sem `modo=setor` continua 403.
- Servidor desobrigado: `GET` normal devolve `desobrigado: {desde, coordenador}` quando a data está numa
  vigência; `PUT`/`POST finalizar` de data coberta → 409 "O relatório deste setor é enviado pelo coordenador
  desde dd/mm/aaaa."
- `me.php` e login devolvem `relatorio_setor` (`setoresDoCoordenador`; `[]` para os demais papéis).

- [ ] **Passo 1:** testes: `test_coordenador_envia_relatorio_semanal_do_setor`,
  `test_coordenador_de_dois_setores_envia_os_dois_no_mesmo_periodo` (Ponto 1),
  `test_novo_coordenador_continua_o_mesmo_rascunho` (Ponto 2), `test_relatorio_enviado_nao_muda`,
  `test_servidor_desobrigado_recebe_409_e_fora_da_vigencia_envia_normal`.
- [ ] **Passo 2:** falham. **Passo 3:** implementar. **Passo 4:** `composer test` verde.
- [ ] **Passo 5:** commit `feat: envio do relatório do setor pelo coordenador (API)`.

### Task 6: Configurações → Atividades (bloco 4)

**Arquivos:**
- Modificar: `api/controllers/atividades-config.php`, `frontend/atividades-config.js`,
  `frontend/atividades-config.css`, `frontend/index.html` (`?v=`)

**Interfaces:**
- Consome: `RelatorioSetorService::alterar`, `vigenciaAtual`, `vigenciasDoSetor`, `podeConfigurarRelatorioSetor`.
- Produz: `GET ?setor_id=` + `relatorio_setor: {ligado, periodicidade, inicio, fim_programado, proxima, coordenador,
  pode_alterar, historico[]}`; `PUT ?acao=relatorio-setor {setor_id, ligado, periodicidade}` (403 sem
  `podeConfigurarRelatorioSetor`; 400 com a mensagem da `InvalidArgumentException`; auditoria
  `alterar_relatorio_setor` com antes/depois). Cada servidor da lista ganha `desobrigado`.
- Tela: bloco "4. Relatório do setor pelo coordenador" depois do bloco 3, interruptor `role="switch"`, rádios
  Semanal/Mensal, texto de quando vale (desenho 5.7), histórico em `<details>`; gestor vê em leitura; faixa de
  servidores desobrigados acima da tabela; `resumoCobrancaCfg` devolve "Nada (relatório do setor)" para quem está
  desobrigado. Ações `data-acao="cfg-relatorio-setor"`.

- [ ] **Passo 1:** caso na matriz fica para a tarefa 10; aqui conferir por HTTP local (coordenador: liga → 200;
  gestor → 403; setor sem coordenador → 400).
- [ ] **Passo 2:** implementar API e tela.
- [ ] **Passo 3:** no navegador (Playwright), como admin e como coordenador: ligar semanal, trocar para mensal,
  desligar; textos de vigência corretos; sem erros de console; modo escuro.
- [ ] **Passo 4:** commit `feat: opção do relatório do setor em Configurações → Atividades`.

### Task 7: Acompanhamento (backend)

**Arquivos:**
- Modificar: `api/services/atividades-acompanhamento-service.php`,
  `api/controllers/atividades-acompanhamento.php`, `api/services/prazo-atividade-service.php` (abono do setor)
- Teste: `api/tests/Integracao/RelatorioSetorTest.php`, `api/tests/Unit/AtividadesAcompanhamentoServiceTest.php`

**Interfaces:**
- `filtros()`, `relatorio()`, `detalhe()`, `atividadesDoUsuario()`, `historicoAusencias()`, `painel()`:
  setor do registro por `r.setor_id` (não `u.setor_id`); relatórios `periodo <> 'dia'` entram nas somas;
  `u.tipo IN (...)` não pode excluir o coordenador quando `r.periodo <> 'dia'` (condição
  `(r.periodo <> 'dia' OR u.tipo IN (...))`).
- `relatorio()`: linhas ganham `periodo`, `periodo_fim`, `tipo` (`'servidor'|'setor'`); filtro
  `usuario_id = -1` = só relatórios do setor; `modalidade` aceita `'setor'`; Remoto × Presencial ganha a linha
  `setor`.
- `painel()`: cada setor ganha `relatorio_setor: {periodicidade, coordenador, atual: {data_referencia,
  periodo_fim, enviado}, anterior: {...}, pendencias: [...]}` (null sem vigência nem pendência); servidores com
  `desobrigado`.
- `pendenciasDoEscopo()`: inclui as do setor como `['setor_id','setor_nome','pendencia' => …]`.
- `historicoAusencias()`: linha do setor com períodos sem envio.
- `atividadesDoSetor()`: soma tarefas/atividades do relatório do setor, lançadas no `data_referencia` na série;
  `total_esperado/total_enviados` somam os períodos do setor; devolve `relatorio_setor_na_serie: bool`.
- `PrazoAtividadeService::pendenciasRelatorioSetor(int $setorId, string $ini, string $fim): array` e
  `abonarRelatorioSetor(int $setorId, string $periodo, string $data, string $justificativa, int $criadoPor): bool`
  (false se já enviado); `removerAbonoSetor(int $abonoId): ?array`.
- Controller: `POST ?acao=abonar-setor {setor_id, periodo, data, justificativa}` (justificativa ≥ 5 caracteres,
  `podeAbonarRelatorioSetor` senão 403, 409 se enviado; auditoria `abonar_relatorio_setor`) e
  `DELETE ?acao=abono-setor&id=` (mesma permissão; `remover_abono_relatorio_setor`). Setor sempre dentro de
  `setorEscopo`. CSVs ganham as colunas Tipo e Período.

- [ ] **Passo 1:** testes: `test_relatorio_do_setor_soma_no_relatorio_e_no_cruzamento`,
  `test_registro_antigo_fica_no_setor_onde_foi_enviado_apos_transferencia` (Ponto 5),
  `test_painel_mostra_pendencia_do_setor_e_servidor_desobrigado`, `test_coordenador_abona_o_proprio_setor`,
  `test_abonar_periodo_ja_enviado_recusa`.
- [ ] **Passo 2:** falham. **Passo 3:** implementar. **Passo 4:** `composer test` verde.
- [ ] **Passo 5:** commit `feat: relatório do setor no acompanhamento e abono pelo coordenador`.

### Task 8: Registro de Atividades no navegador (coordenador e servidor desobrigado)

**Arquivos:**
- Criar: `frontend/registro-atividades-setor.js` (modo setor; reaproveita as funções de lista/−/+/rascunho de
  `registro-atividades.js` expostas como globais)
- Modificar: `frontend/registro-atividades.js`, `frontend/registro-atividades.css`, `frontend/papeis.js`,
  `frontend/script.js` (`podeRegistrarAtividades`), `frontend/index.html`

**Interfaces:**
- `Papeis.enviaRelatorioSetor(u)` → `true` se `u.relatorio_setor` não vazio; `podeRegistrarAtividades()` =
  `Papeis.registraAtividades() || Papeis.enviaRelatorioSetor()`.
- Ao abrir a tela, coordenador vai para o modo setor (`RegistroSetor.abrir(setorId?)`); seletor de setor quando há
  mais de um (`cd_raSetorEscolhido`); campo `<input type="week">` ou `<input type="month">` conforme a
  periodicidade, convertido para `data` (segunda/dia 1º); título "Relatório do setor — Nome"; pendências com
  "Preencher"; sem campo de modalidade; rascunho em `localStorage` `cd_ra_rascunho_setor_<setor>_<data>`;
  histórico do setor com o nome de quem enviou.
- Servidor com `desobrigado`: faixa `role="status"` "O relatório deste setor é enviado pelo coordenador desde
  dd/mm/aaaa" e botões de envio desabilitados nessas datas.

- [ ] **Passo 1:** implementar.
- [ ] **Passo 2:** no navegador: coordenador com dois setores (um semanal, um mensal) salva rascunho, recarrega,
  restaura, envia; operador do setor vê a faixa e não envia; 360 px; teclado; modo escuro; console limpo.
- [ ] **Passo 3:** commit `feat: tela do relatório do setor para o coordenador`.

### Task 9: Acompanhamento no navegador

**Arquivos:**
- Modificar: `frontend/atividades-acompanhamento.js`, `frontend/atividades-acompanhamento.css`,
  `frontend/index.html`

**Interfaces:** consome as respostas da tarefa 7.
- Painel: cartão/linha "Relatório do setor — coordenador: Nome (semanal|mensal)", selo "Desobrigado (relatório do
  setor)" nos servidores, pendências do setor com Abonar (visível se admin ou coordenador do setor) e remover
  abono; ações `data-acao="acomp-abonar-setor"`/`"acomp-remover-abono-setor"`; justificativa lida de
  `#modal-prompt-input` (`mostrarPrompt` só entrega true/false).
- Relatório: opção "Relatório do setor" no filtro de servidor e de modalidade; rótulo do período ("Semana de
  dd/mm a dd/mm", "Mês mm/aaaa"); terceira barra no Remoto × Presencial.
- Histórico de Ausências: linha do setor. Cruzamento por setor: nota quando `relatorio_setor_na_serie`.

- [ ] **Passo 1:** implementar. **Passo 2:** navegador como admin e coordenador (abonar, remover, filtros, CSV
  abre no Excel com acentos). **Passo 3:** commit `feat: relatório do setor nas telas de acompanhamento`.

### Task 10: Matriz de acesso, documentação e conferência final

**Arquivos:**
- Modificar: `api/tests/matriz-de-acesso.php` (seção nova `[7g]` depois da `[7f]`), `CLAUDE.md`,
  `frontend/ajuda.js` (guia das atividades), `docs/implantacao-producao.md`

- [ ] **Passo 1:** matriz [7g]: coordenador do setor liga (200) e envia (200); coordenador de outro setor 403;
  gestor liga 403; operador do setor envia data coberta 409; supervisor e coordenador geral PUT/POST 403;
  coordenador abona o próprio setor 200; gestor abona 403; admin abona 200; abonar período enviado 409. Dados
  `zz_` criados e apagados pela própria seção (vigências inclusive).
- [ ] **Passo 2:** `php api/tests/matriz-de-acesso.php` → tudo OK (esperar 5 min se a [10] der 429).
- [ ] **Passo 3:** `cd api && composer test` → tudo verde; anotar o total novo.
- [ ] **Passo 4:** CLAUDE.md: seção datada "Relatório do setor pelo coordenador (23/11/2026)", migração 43 na lista,
  linhas da tabela do frontend (`registro-atividades-setor.js`), exceção do coordenador no Registro de
  Atividades, abono pelo coordenador, contagens de testes. Ajuda e `implantacao-producao.md` (migração 43).
- [ ] **Passo 5:** commit `docs: relatório do setor pelo coordenador (CLAUDE.md, ajuda, implantação)`.
