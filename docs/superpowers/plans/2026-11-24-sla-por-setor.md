# SLA por setor — plano de implementação

> **Para agentes:** SUB-SKILL OBRIGATÓRIA: use superpowers:subagent-driven-development (recomendado) ou
> superpowers:executing-plans para executar este plano tarefa a tarefa. Os passos usam caixas (`- [ ]`).

**Objetivo:** cada setor liga/desliga a contagem de SLA e define prazos próprios por prioridade (dias úteis e/ou
horas), com o SLA de hoje preservado como o modelo "Padrão CRPRS".

**Arquitetura:** `setores.sla_ativo` + prazos em `setor_prioridades` (migração 44); `SLAService` passa a ler os
prazos e o interruptor do setor da demanda; setor desligado e prioridade sem prazo funcionam como pausa
(`DemandaService`); API nova `setor-sla.php` e aba "⏱️ SLA" em Configurações → Ativação por Setor.

**Tecnologia:** PHP 8 + PDO/MySQL 8.4, PHPUnit (`cd api && composer test`), JavaScript vanilla sem build.

**Desenho:** `docs/superpowers/specs/2026-11-24-sla-por-setor-design.md` (ler antes; as decisões do usuário estão na
seção 3 e as minhas na seção 6).

## Restrições globais

- Tudo em pt-BR (comentários, mensagens, commits). Commits atômicos terminando com
  `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`. **Sem `git push`.** Ao fim, merge local no `main`.
- Acesso só por `AcessoService` (backend) e `Papeis` (frontend); nunca `tipo === '...'` solto em tela.
- Toda escrita: CSRF + `AuditoriaService->registrar()`; JSON com o código HTTP certo e `try/catch`;
  prepared statements sempre.
- Frontend: `Api.*`, `data-acao*` + ouvinte delegado (sem `onclick=`/`style=` novos), tokens de cor
  (`--texto-suave` para texto secundário), modo escuro, `?v=` novo em todo arquivo alterado no `index.html`,
  ícone novo na lista `icon_names`.
- Migração nº **44**: `database/44_sla_por_setor.sql`, aplicada com `php database/migrar.php` depois de um backup
  (`mysqldump` em `C:\Users\hugo.miranda\conecta-backup-banco-2026-11-24\`, fora do repositório).
- Setor que continua com SLA: o de nome **`'Setor de TI'`** (id 41 no banco local). Todos os outros: desligado.
- "1 dia útil" = **jornada mais comum do setor em minutos** (empate: a maior); setor sem expediente usa a do Geral.
- Editar prazos e ligar/desligar: **admin e coordenador do setor**. Aplicar o modelo: **só admin**. Gestor: só lê.
- Atualizar o `CLAUDE.md` no mesmo trabalho (Tarefa 6).

## Pontos de atenção na revisão

1. **Ligar o SLA depois de dias desligado:** o tempo desligado não pode contar (a demanda não pode aparecer vencida
   logo ao ligar). Teste na Tarefa 3.
2. **Ligar com uma prioridade ativa sem prazo:** recusado (400 dizendo qual). Teste na Tarefa 4.
3. **Transferir de setor ligado para desligado e de volta:** pausa e retoma; o período no setor desligado não conta.
   Teste na Tarefa 3.
4. **Demanda já pausada por status (ex.: aguardando) quando o setor liga:** continua pausada até o status mudar.
   Teste na Tarefa 3.
5. **Setor com expediente de dias diferentes:** a jornada é a mais comum, não a do dia corrente. Teste na Tarefa 2.

---

### Task 1: Migração 44

**Arquivos:**
- Criar: `database/44_sla_por_setor.sql`
- Teste: `api/tests/Integracao/SlaSetorTest.php` (criar; transação desfeita, como `RelatorioSetorTest.php`)

**Interfaces — produz (esquema):**
- `setores.sla_ativo TINYINT(1) NOT NULL DEFAULT 0`.
- `setor_prioridades.sla_resposta_dias SMALLINT UNSIGNED NULL`, `sla_resposta_minutos SMALLINT UNSIGNED NULL`,
  `sla_resolucao_dias SMALLINT UNSIGNED NULL`, `sla_resolucao_minutos SMALLINT UNSIGNED NULL`,
  `sla_sem_prazo TINYINT(1) NOT NULL DEFAULT 0`.
- Carga, em ordem: (a) `'Setor de TI'` → `sla_ativo = 1` e, em todas as suas linhas de `setor_prioridades`,
  `sla_resposta_dias = 0, sla_resposta_minutos = p.sla_resposta_minutos, sla_resolucao_dias = 0,
  sla_resolucao_minutos = p.sla_resolucao_horas * 60, sla_sem_prazo = p.sem_sla` (JOIN `ticket_prioridades p`);
  (b) pausa nas demandas abertas de setores com `sla_ativo = 0`: `UPDATE ticket_sla s JOIN tickets t ...
  SET s.pausado = TRUE, s.pausado_em = NOW() WHERE s.pausado_em IS NULL AND t.excluido_em IS NULL AND
  t.mesclada_em IS NULL AND t.status NOT IN ('resolvido','encerrado','cancelado','fechado') AND setor desligado`.
  Atenção: `sla_resolucao_horas * 60` de 40 h = 2400, cabe em SMALLINT UNSIGNED.

- [ ] **Passo 1:** backup do banco e anotar no commit quantas demandas abertas cada setor tem.
- [ ] **Passo 2:** testes `test_migracao_deixou_o_setor_de_ti_igual_ao_catalogo` (para cada prioridade do Setor de TI,
  minutos de resposta = catálogo, de resolução = catálogo × 60, `sla_sem_prazo` = `sem_sla`; `sla_ativo = 1`),
  `test_migracao_desligou_os_demais_setores` (nenhum outro setor com `sla_ativo = 1`),
  `test_demandas_abertas_de_setor_desligado_estao_pausadas`.
- [ ] **Passo 3:** rodar `cd api && vendor/bin/phpunit --filter SlaSetorTest` → falham (colunas inexistentes).
- [ ] **Passo 4:** escrever a migração; `php database/migrar.php`.
- [ ] **Passo 5:** `composer test` → verde.
- [ ] **Passo 6:** commit `feat: migração 44 (SLA por setor)`.

### Task 2: Regra do SLA por setor (`SLAService`)

**Arquivos:**
- Modificar: `api/services/sla-service.php`
- Teste: `api/tests/Unit/SLAServiceTest.php` (SQLite: acrescentar `sla_ativo` em `setores` e criar
  `setor_prioridades (setor_id, prioridade_id, ativo, sla_resposta_dias, sla_resposta_minutos, sla_resolucao_dias,
  sla_resolucao_minutos, sla_sem_prazo)` e `id` em `ticket_prioridades`; os setores 1 e 3 já usados nos testes de
  `situacao` ficam ligados com os prazos do catálogo, para os testes atuais continuarem válidos)

**Interfaces — produz:**
- `SLAService::jornadaMinutos(?int $setorId): int` — duração do expediente ativo mais comum entre os dias do setor;
  empate: a maior; setor sem expediente: a do Geral; sem nada: 540.
- `SLAService::slaAtivo(?int $setorId): bool` (cache; setor nulo ou inexistente = false).
- `SLAService::prazosDoSetor(?int $setorId, string $prioridade): ?array` → `['resposta' => int segundos,
  'resolucao' => int segundos]`, ou `null` quando: setor desligado, prioridade `sem_sla` no catálogo,
  `sla_sem_prazo = 1` no setor, ou sem prazo definido. Segundos = `(dias * jornadaMinutos + minutos) * 60`.
- `situacao(array $ticket, array $sla, ?int $setorId)` — mesma assinatura; usa `prazosDoSetor`; `null` se ele é `null`.
  `prazosDaPrioridade` sai.
- `pausaSla(string $status, string $prioridade, ?int $setorId = null): bool` — `congelaSla($status) ||
  prazosDoSetor($setorId, $prioridade) === null`. (Com `$setorId` nulo, cai em `semSla($prioridade)` como hoje, para
  não quebrar chamadas antigas até a Tarefa 3.)
- `mediaTempos`: pula a linha quando `prazosDoSetor($l['setor_id'] ?? null, $l['prioridade'] ?? '') === null`.

- [ ] **Passo 1:** testes: `test_jornada_e_o_expediente_mais_comum` (seg–qui 8h–17h, sex 8h–12h → 540),
  `test_jornada_empate_fica_com_a_maior`, `test_prazo_em_dias_e_horas_usa_a_jornada` (resposta 1 dia + 2 h em setor
  de 9 h → 39600 s), `test_situacao_null_com_setor_desligado`, `test_situacao_null_com_prioridade_sem_prazo_no_setor`,
  `test_situacao_usa_os_prazos_do_setor_e_nao_os_do_catalogo`, `test_pausa_sla_com_setor_desligado`,
  `test_medias_ignoram_setor_desligado`; os testes antigos de `situacao`/médias continuam passando.
- [ ] **Passo 2:** `vendor/bin/phpunit --filter SLAServiceTest` → falham.
- [ ] **Passo 3:** implementar.
- [ ] **Passo 4:** `--filter SLAServiceTest` e `composer test` → verdes.
- [ ] **Passo 5:** commit `feat: SLA calculado com os prazos e o interruptor do setor`.

### Task 3: Pausas ao ligar/desligar, na abertura e na transferência

**Arquivos:**
- Modificar: `api/services/demanda-service.php`, `api/services/acesso-service.php` (`registrarAbertura`),
  `api/controllers/demanda-acoes.php` (transferência), `api/controllers/tickets.php` (chamadas de `pausaSla` /
  `efeitosDaMudancaDePrioridade`, se passarem o setor)
- Teste: `api/tests/Integracao/SlaSetorTest.php`

**Interfaces:**
- Consome: `SLAService::pausaSla(status, prioridade, setorId)`, `retomarPausa(int $ticketId, ?int $setorId)`.
- Produz: `DemandaService::aplicarSlaDoSetor(PDO $db, int $setorId, ?string $soPrioridade = null): int` — para cada
  demanda aberta do setor (não concluída, fora da lixeira, não mesclada; com `$soPrioridade`, só ela), compara o
  estado de pausa atual (`ticket_sla.pausado_em IS NOT NULL`) com `pausaSla(status, prioridade, setorId)` e ajusta
  por `ajustarPausaSla`; devolve quantas mudaram. Usa a transação de quem chama. **Chamar depois de gravar a
  configuração nova** (o `SLAService` usado deve ser novo, sem cache da configuração antiga).
- `efeitosDaMudancaDeStatus`/`efeitosDaMudancaDePrioridade` passam o `$setorId` que já recebem para `pausaSla`.
- `registrarAbertura`: nasce pausada se `pausaSla(status inicial, prioridade, setorId)` (não só a Baixíssima).
- Transferência: depois de trocar o setor (e a prioridade, se trocou), `ajustarPausaSla(db, id,
  pausaSla(status, prioridadeAntiga, setorAntigo), pausaSla(status, prioridadeFinal, setorNovo), setorNovo)` —
  substitui a chamada de `efeitosDaMudancaDePrioridade` daquele trecho (uma só conferência).

- [ ] **Passo 1:** testes (datas do `criado_em` e do `pausado_em` ajustadas direto no banco para simular o tempo):
  `test_desligar_pausa_as_abertas_e_some_o_selo`, `test_ligar_retoma_sem_contar_o_periodo_desligado` (demanda criada
  há 10 dias úteis com o setor desligado o tempo todo → ao ligar, `situacao` não vencida e `segundos_restantes`
  ≈ prazo cheio), `test_ligar_mantem_pausada_a_demanda_em_status_que_congela`,
  `test_transferir_para_setor_desligado_pausa_e_voltar_retoma`, `test_demanda_nova_em_setor_desligado_nasce_pausada`,
  `test_marcar_prioridade_sem_prazo_pausa_so_ela`.
- [ ] **Passo 2:** falham. **Passo 3:** implementar. **Passo 4:** `composer test` verde.
- [ ] **Passo 5:** commit `feat: pausa do SLA ao ligar/desligar o setor, na abertura e na transferência`.

### Task 4: Acesso e API `setor-sla.php`

**Arquivos:**
- Modificar: `api/services/acesso-service.php`
- Criar: `api/controllers/setor-sla.php`, `api/services/sla-setor-service.php` (leitura/gravação/validação/modelo)
- Teste: `api/tests/Integracao/SlaSetorTest.php`, `api/tests/Unit/AcessoServiceTest.php`

**Interfaces — produz:**
- `AcessoService::podeConfigurarSlaSetor(array $user, int $setorId): bool` (admin; coordenador com o setor em
  `setoresDoUsuario`). `AcessoService::podeAplicarModeloSla(array $user): bool` (só admin).
- `SlaSetorService::__construct(PDO $db)`; `ler(int $setorId): array` (formato do GET, desenho 5.5);
  `salvar(int $setorId, array $entrada): array` → `['antes' => array, 'depois' => array]`, lança
  `InvalidArgumentException` com a mensagem (400) — regras: dias 0–365, horas 0–23, minutos 0–59 por campo; total
  > 0 quando não é "sem prazo"; resolução ≥ 1ª resposta (em minutos, com a jornada do setor); com `sla_ativo = 1`,
  toda prioridade **ativa no setor** (`setor_prioridades.ativo = 1`) precisa de prazo ou "sem prazo"; prioridade
  `sem_sla` do catálogo fica sempre "sem prazo". Chama `DemandaService::aplicarSlaDoSetor` quando o interruptor ou
  algum "sem prazo" mudou. `aplicarModelo(int $setorId): array` (antes/depois; não mexe no interruptor; chama
  `aplicarSlaDoSetor` se o setor está ligado).
- API: `GET ?setor_id=` (leitura: admin, visão total, coordenador do setor, gestor do setor — o mesmo
  `AuthMiddleware::mesmoSetor` das outras abas), `PUT ?setor_id=` (403 sem `podeConfigurarSlaSetor`),
  `POST ?acao=aplicar-modelo&setor_id=` (403 sem `podeAplicarModeloSla`). Transação, CSRF, auditoria
  `alterar_sla_setor` / `aplicar_modelo_sla_setor` (só quando antes ≠ depois).

- [ ] **Passo 1:** testes: `test_ligar_com_prioridade_ativa_sem_prazo_recusa` (mensagem cita a prioridade),
  `test_resolucao_menor_que_resposta_recusa`, `test_aplicar_modelo_copia_o_catalogo_e_nao_liga`,
  `test_salvar_e_ler_dias_horas_minutos`, `test_prioridade_baixissima_fica_sem_prazo`, `test_mudar_prazo_vale_na_hora_para_as_abertas` (situacao muda depois do salvar, sem mexer na pausa); unitários de acesso (admin e
  coordenador do setor sim; coordenador de outro setor, gestor, supervisor, coordenador geral não; modelo só admin).
- [ ] **Passo 2:** falham. **Passo 3:** implementar. **Passo 4:** `composer test` verde.
- [ ] **Passo 5:** conferir por HTTP local (token como em `api/tests/matriz-de-acesso.php`): GET como gestor 200,
  PUT como gestor 403, PUT como admin 200; dados `zz_` criados e apagados.
- [ ] **Passo 6:** commit `feat: API de SLA por setor (setor-sla.php)`.

### Task 5: Aba "⏱️ SLA" na Ativação por Setor

**Arquivos:**
- Criar: `frontend/sla-setor.js`, `frontend/sla-setor.css`
- Modificar: `frontend/ativacao-setor.js` (botão da aba ao lado de Status/Prioridades/Envio de E-mails, visível para
  admin, coordenador e gestor via `Papeis`; ao clicar chama `SlaSetor.carregar(setorId)`), `frontend/index.html`
  (scripts/estilos com `?v=`)

**Interfaces:** consome a API da Tarefa 4. Produz `SlaSetor.carregar(setorId)` (global).
- Conteúdo (desenho 5.6): interruptor `role="switch"` "Contar SLA neste setor"; texto do efeito; "1 dia útil = Xh
  (jornada do setor)"; tabela por prioridade ativa: Prioridade | 1ª resposta (dias, horas, minutos) | Resolução (dias,
  horas, minutos) | Sem SLA; Baixíssima travada em "sem SLA"; botão "Aplicar Padrão CRPRS" só se
  `pode_aplicar_modelo` (confirmação com a lista de valores); "Salvar" (confirmação ao desligar); tudo em leitura se
  `pode_alterar` for falso. Erros pelo padrão `mostrarAlert('Erro', mensagemDeErro(e, ...))`. Ações
  `data-acao="sla-salvar" | "sla-modelo"` com um ouvinte delegado que não acumula.

- [ ] **Passo 1:** implementar.
- [ ] **Passo 2:** navegador (Playwright) com dados `zz_` (setor de teste, admin, coordenador do setor, gestor):
  ligar sem prazo → mensagem; aplicar modelo → valores aparecem; salvar ligado; selo aparece numa demanda de teste
  do setor; desligar → selo some; gestor vê em leitura; modo escuro; console sem erros. Apagar tudo ao final.
- [ ] **Passo 3:** commit `feat: aba SLA na Ativação por Setor`.

### Task 6: Matriz de acesso, ajuda e documentação

**Arquivos:**
- Modificar: `api/tests/matriz-de-acesso.php` (seção nova `[7h]` depois da `[7g]`), `frontend/ajuda.js` (guia do
  SLA), `frontend/script.js` ("Sobre", se citar o SLA), `CLAUDE.md`, `docs/implantacao-producao.md`

- [ ] **Passo 1:** matriz `[7h]`: GET como gestor do setor 200 e de outro setor 403; PUT como admin 200, coordenador
  do setor 200, coordenador de outro setor 403, gestor 403, operador 403, supervisor 403, coordenador geral 403;
  aplicar modelo como admin 200 e como coordenador 403; ligar com prioridade sem prazo 400. Dados `zz_` criados e
  apagados pela seção.
- [ ] **Passo 2:** `php api/tests/matriz-de-acesso.php` → tudo OK (429 na [10]: esperar 5 min) e `composer test`
  verde; anotar os totais.
- [ ] **Passo 3:** Ajuda (SLA): o SLA é por setor, ligar/desligar, dias úteis = jornada, modelo Padrão CRPRS.
  CLAUDE.md: seção datada "SLA por setor (24/11/2026)", ajuste da seção "SLA" (os prazos não são mais do catálogo),
  migração 44 na lista, `sla-setor.js` na tabela do frontend, contagens de testes. `implantacao-producao.md`:
  migração 44 e o aviso de que só o Setor de TI começa com SLA.
- [ ] **Passo 4:** commit `docs: SLA por setor (CLAUDE.md, ajuda, implantação)`.
