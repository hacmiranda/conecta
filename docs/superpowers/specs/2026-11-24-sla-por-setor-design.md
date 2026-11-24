# SLA por setor (ligar/desligar e prazos próprios) — desenho

Data: 24/11/2026. Situação: aguardando aprovação do usuário.

## 1. Intenção

Hoje o SLA é igual para o sistema inteiro: um prazo de 1ª resposta e um de resolução por prioridade, no catálogo
global `ticket_prioridades`, sem tela para editar. O usuário quer que **cada setor decida** se conta SLA e com
quais prazos:

- um **interruptor por setor**: ligado, as demandas do setor contam tempo para o SLA; desligado, não contam;
- **prazos próprios por setor**, por prioridade, em **dias úteis e/ou horas**;
- o SLA de hoje preservado como o modelo **"Padrão CRPRS"**, que o admin aplica a um setor;
- configuração editável por **admin e coordenador** (o gestor só vê);
- **desligado por padrão** em todos os setores, **exceto o "Setor de TI"**, que continua com o SLA de hoje.

## 2. Objetivos, não objetivos e o que não pode acontecer

**Objetivos**
- Interruptor "Contar SLA" e prazos por prioridade (1ª resposta e resolução, cada um em dias úteis + horas) por setor.
- Aplicar o modelo "Padrão CRPRS" num setor com um clique (só admin).
- O selo de SLA, o "SLA vencido" e as médias de tempo seguem a configuração do setor da demanda.

**Não objetivos**
- Vários modelos com nome (opção 2, descartada pelo usuário). Existe um modelo só, o "Padrão CRPRS".
- Mudar a contagem em horário útil, o expediente ou os feriados (continuam em Configurações → Horários).
- Mudar o catálogo de prioridades, a ativação de prioridades por setor ou a regra da "Baixíssima (Sem SLA)".

**Não pode acontecer**
- Demanda contando tempo contra um prazo vazio.
- O período em que o SLA esteve desligado contar como tempo gasto quando ele for religado.
- O Setor de TI mudar de comportamento com a migração.
- Gestor, supervisor ou coordenador geral alterarem a configuração; coordenador alterar setor que não é dele.

## 3. Decisões do usuário (24/11/2026)

1. Prazos **separados por prioridade**, com **1ª resposta** e **resolução**, como hoje.
2. "Dia" = **dia útil do setor** (fins de semana e feriados do setor não contam).
3. Padrão **desligado** em todos os setores, exceto o **Setor de TI** (ligado, com os prazos de hoje).
4. Aplicar o modelo: **só o admin**. Editar prazos e ligar/desligar: **admin e coordenador** (nos setores dele).
5. Toda prioridade ativa no setor precisa de prazo ou ser marcada "sem SLA" para o interruptor poder ficar ligado.
6. Desligar: as demandas abertas param de contar na hora (sem selo, fora do "SLA vencido").
   Ligar: voltam a contar a partir desse momento; o período desligado nunca conta.
7. Mudar um prazo vale na hora para as demandas abertas (o selo é recalculado com o tempo já gasto).
8. Transferir a demanda de setor: passa a seguir o SLA do setor novo.
9. As médias de 1ª resposta e de resolução do Dashboard e dos Relatórios deixam de fora as demandas de setores com
   SLA desligado (como já acontece com a Baixíssima); as datas de 1ª resposta e de resolução continuam sendo gravadas.
10. Forma de construir: **opção 1** — prazos na ativação de prioridades do setor; o modelo são os números do catálogo.

## 4. Formas consideradas

1. **Prazos em `setor_prioridades` (escolhida).** A ligação setor × prioridade, que já existe, ganha os prazos; o
   setor ganha o interruptor; o "Padrão CRPRS" são os números que já estão em `ticket_prioridades`. Menor mudança;
   aproveita a tela Ativação por Setor. Limite: um modelo só.
2. **Tabela de modelos com nome.** Mais flexível (o admin criaria outros modelos), mas traz tela e regras a mais
   (o que acontece com o setor quando o modelo muda) além do que foi pedido.

## 5. Desenho

### 5.1 Banco (migração 44)

- `setores.sla_ativo TINYINT(1) NOT NULL DEFAULT 0`.
- `setor_prioridades` ganha, por prioridade do setor:
  `sla_resposta_dias SMALLINT UNSIGNED NULL`, `sla_resposta_minutos SMALLINT UNSIGNED NULL`,
  `sla_resolucao_dias SMALLINT UNSIGNED NULL`, `sla_resolucao_minutos SMALLINT UNSIGNED NULL`,
  `sla_sem_prazo TINYINT(1) NOT NULL DEFAULT 0` ("sem SLA" nesta prioridade neste setor).
  Tudo NULL e `sla_sem_prazo = 0` = prioridade sem prazo definido.
  Guardar dias e minutos separados (e não um total), porque "1 dia" depende da jornada do setor (5.2).
- Carga: o **Setor de TI** (por nome `'Setor de TI'`; se não existir, nenhum) recebe `sla_ativo = 1` e os prazos do
  catálogo: resposta = `0 dias + sla_resposta_minutos`, resolução = `0 dias + sla_resolucao_horas × 60`; a
  prioridade com `ticket_prioridades.sem_sla = 1` recebe `sla_sem_prazo = 1`. Os demais setores ficam com
  `sla_ativo = 0` e prazos vazios.
- **Demandas abertas no momento da migração**, nos setores que ficam desligados: entram em pausa (5.3), para não
  contar tempo enquanto desligado e não somar esse período se o setor for ligado depois. A migração SQL grava
  `ticket_sla.pausado_em = NOW()` onde ainda não há pausa em andamento, só para demandas não concluídas
  (fora de resolvido/encerrado/cancelado/fechado), fora da lixeira e de setor com `sla_ativo = 0`.
- `ticket_prioridades.sla_resposta_minutos`/`sla_resolucao_horas` **continuam**: passam a ser só o modelo
  "Padrão CRPRS" (não são mais lidos para calcular o SLA de uma demanda).

### 5.2 Regra de cálculo (`SLAService`)

- **Jornada do setor** (`jornadaMinutos(?int $setorId): int`): a duração, em minutos, do expediente ativo mais comum
  entre os dias úteis do setor em `horarios_uteis` (empate: a maior). Setor sem expediente: a do Geral, como o
  cálculo já faz hoje. 1 dia útil de prazo = `jornadaMinutos`.
- **Prazo em segundos** de uma prioridade num setor = `(dias × jornadaMinutos + minutos) × 60`. Comparado, como hoje,
  com os segundos úteis decorridos menos as pausas.
- **O setor conta SLA?** `slaAtivo(?int $setorId): bool` (com cache). Setor sem configuração = desligado.
- **Prazos do setor** `prazosDoSetor(int $setorId, string $prioridade): ?array` → `['resposta' => segundos,
  'resolucao' => segundos]` ou `null` quando a prioridade é "sem SLA" no setor (ou no catálogo) ou não tem prazo.
- `situacao()` devolve `null` (sem selo) quando o setor está desligado ou `prazosDoSetor` é `null`; senão usa os
  prazos do setor no lugar de `prazosDaPrioridade` (que sai).
- `pausaSla($status, $prioridade, ?int $setorId)`: pausa também quando o setor está desligado ou a prioridade não
  tem prazo no setor. Esta é a regra única usada por `DemandaService::ajustarPausaSla`.
- **Demanda criada** num setor desligado ou numa prioridade sem prazo nasce pausada (`AcessoService::registrarAbertura`
  já faz isso para a Baixíssima: estender à regra nova).
- `mediaPrimeiraResposta`/`mediaResolucao` deixam de fora as demandas cujo setor está desligado **hoje** ou cuja
  prioridade é sem SLA no setor (as médias são do SLA em vigor).

### 5.3 Efeitos nas demandas abertas

Uma função nova `DemandaService::aplicarSlaDoSetor(PDO $db, int $setorId, bool $estavaContando, bool $contaAgora)`,
na transação de quem chama, percorre as demandas abertas do setor (não concluídas, fora da lixeira, não mescladas):
- **ligado → desligado:** para cada demanda que não estava pausada, inicia a pausa (`pausado_em = NOW()`).
- **desligado → ligado:** para cada demanda que agora não deveria pausar (status que não congela e prioridade com
  prazo no setor), encerra a pausa (`SLAService::retomarPausa`, que soma o período em `tempo_pausado_segundos`).
- Mudar prazos não mexe em pausas: `situacao()` lê os prazos novos na próxima consulta.
- Marcar/desmarcar "sem SLA" numa prioridade com o setor ligado: as demandas abertas dessa prioridade no setor
  entram/saem da pausa (mesma função, filtrando a prioridade).

**Transferência de setor** (`demanda-acoes.php`): hoje já chama `efeitosDaMudancaDePrioridade`; passa a comparar
`pausaSla(status, prioridade, setorAntigo)` com `pausaSla(status, prioridadeNova, setorNovo)` e ajustar a pausa.

### 5.4 Acesso (`AcessoService`)

- `podeConfigurarSlaSetor(array $user, int $setorId): bool` — admin, ou coordenador com o setor em
  `setoresDoUsuario`. Gestor, operador, supervisor e coordenador geral: não.
- `podeAplicarModeloSla(array $user): bool` — só admin.
- Leitura da configuração: quem pode ver a tela Ativação por Setor daquele setor (admin, coordenador, gestor do setor).

### 5.5 API — `api/controllers/setor-sla.php` (novo)

- `GET ?setor_id=` → `{setor_id, sla_ativo, jornada_minutos, pode_alterar, pode_aplicar_modelo,
  prioridades: [{prioridade, nome, ativa_no_setor, sem_sla_catalogo, sem_prazo, resposta: {dias, horas, minutos},
  resolucao: {dias, horas, minutos}}], modelo: [{prioridade, resposta_minutos, resolucao_minutos, sem_sla}]}`
  (horas/minutos separados só para a tela; no banco ficam os minutos).
- `PUT ?setor_id= {sla_ativo, prioridades: [{prioridade, sem_prazo, resposta: {dias, minutos}, resolucao: {dias, minutos}}]}`
  — `podeConfigurarSlaSetor` (403); valida números (dias 0–365, minutos 0–1439 por campo, total > 0 quando não é
  "sem prazo"; resolução ≥ 1ª resposta); com `sla_ativo = 1`, toda prioridade **ativa no setor** precisa de prazo ou
  "sem prazo" (400 dizendo qual falta). Grava, chama `aplicarSlaDoSetor` quando o ligado/desligado ou algum
  "sem prazo" mudou, tudo numa transação. Auditoria `alterar_sla_setor` com antes/depois.
- `POST ?acao=aplicar-modelo&setor_id=` — só admin (403); copia o catálogo para as prioridades do setor
  (como na carga da migração) **sem mudar o interruptor**; devolve o mesmo objeto do GET. Auditoria
  `aplicar_modelo_sla_setor`.
- CSRF nas escritas; perfis de consulta barrados pela trava central (o controller não entra na lista de liberados).

### 5.6 Tela — aba "⏱️ SLA" em Configurações → Ativação por Setor

- Segue o seletor de setor da barra lateral, como as outras abas (com "Todos os setores", pede para escolher um).
- Visível para admin, coordenador e gestor; só admin e coordenador do setor editam (gestor vê em leitura).
- Conteúdo: interruptor "Contar SLA neste setor" (`role="switch"`); texto curto do efeito ("Desligado: as demandas
  deste setor não contam tempo e não aparecem como vencidas"); "1 dia útil = Xh (jornada do setor)" com atalho para
  Horários; tabela por prioridade ativa no setor: Prioridade | 1ª resposta (dias + horas:min) | Resolução (dias +
  horas:min) | Sem SLA (caixa); a Baixíssima aparece travada em "sem SLA"; botão "Aplicar Padrão CRPRS" (só admin,
  com confirmação mostrando os valores); "Salvar" com confirmação ao desligar.
- Padrões do projeto: `data-acao` + ouvinte delegado, sem `onclick`/`style` novos, tokens de cor, modo escuro,
  `?v=` novo, rótulos ligados aos campos, mensagens de erro do servidor pelo padrão `mostrarAlert`.

### 5.7 Onde o SLA aparece (sem mudança de tela)

Selo das listas e do Kanban, detalhe da demanda, card "SLA vencido" e médias do Dashboard, Relatórios: todos já
usam `SLAService::situacao()` e as médias, então seguem a regra nova sem mudar o frontend. A Ajuda (SLA) e o
"Sobre" precisam de texto novo explicando que o SLA é por setor.

### 5.8 Testes e documentação

- PHPUnit: `SLAServiceTest` (jornada mais comum, prazo com dias + horas, setor desligado sem selo, prioridade sem
  prazo, pausa pelo setor desligado); integração `SlaSetorTest` (ligar/desligar pausa e retoma sem contar o período
  desligado, mudar prazo recalcula, transferência entre setor ligado e desligado, aplicar modelo, validação de
  prioridade ativa sem prazo, migração deixou o Setor de TI igual ao catálogo).
- Matriz de acesso, seção nova: admin e coordenador do setor alteram (200); coordenador de outro setor, gestor,
  operador, supervisor e coordenador geral (403); aplicar modelo só admin.
- CLAUDE.md (seções SLA, migrações, tabela do frontend), Ajuda, `docs/implantacao-producao.md` (migração 44 e o
  aviso de que todo setor, menos o de TI, começa sem SLA).

## 6. Decisões tomadas por mim (para conferir)

1. "1 dia útil" = a jornada mais comum do setor em minutos (dá o mesmo resultado do exemplo aprovado e mantém o
   cálculo em segundos úteis, que já trata pausas). Em setor com dias de jornada diferente, os dias curtos contam
   igual aos outros.
2. A migração pausa as demandas abertas dos setores que ficam desligados, para que o período desligado não conte
   quando forem ligados.
3. Aplicar o modelo não liga o setor; o admin liga no interruptor.
4. Resolução não pode ser menor que a 1ª resposta.
5. As médias usam o estado do setor **hoje** (não o de quando a demanda foi atendida).
6. O gestor vê a aba em leitura.

## 7. Deixado ao construtor

Nomes de funções auxiliares e classes CSS, o texto final das mensagens, a divisão do JavaScript (aba dentro de
`ativacao-setor.js` ou arquivo `sla-setor.js` próprio) e a ordem dos commits.
