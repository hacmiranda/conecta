# Painel de Setores igual ao do METRICA360 (16/11/2026)

Desenho curto, aprovado pelo usuário na conversa de 16/11/2026 ("prossiga!").

## Intenção
O Painel de Setores do módulo Satisfação foi refeito de forma simplificada na fase 6 da unificação (commit
`473befc`): uma tabela por setor. O usuário quer a tela igual à do METRICA360
(`metrica360/src/views/admin/painel_setores.php` + `public/css/pages/admin-painel-setores.css` + `public/js/pages/admin-painel-setores*.js`).

## Objetivos (o que vem de lá, com os mesmos textos e cálculos)
- Subtítulo "Desempenho e atendimentos por setor.", selo "Atualizado às HH:MM" e recarga a cada 5 min.
- Resumo global: Setores, Atendentes, Convites (mês), Avaliações, Média Geral do CRPRS (ponderada, "?"
  explicativo), Taxa de Resposta (verde ≥ 60%, laranja ≥ 40%, vermelho abaixo).
- Barra de ferramentas: CSV, PDF (impressão), ordenação (Nome A–Z, Melhor nota, Pior nota, Mais convites
  (mês), Mais avaliações), cartões/tabela por ícone (preferência guardada no navegador).
- Por setor: faixa com nome, coordenador (foto ou iniciais), "N atendentes", selos ↑ melhor e ↓ pior nota
  (primeiro nome e nota), KPIs Sem. atual e Mês atual com variação, Avaliações, Média setor ("?");
  barra da média "X/5" colorida.
- Cartão por atendente: faixa e anel coloridos pela nota, foto/iniciais, nome, "★ Destaque", papel, selo da
  nota; Sem. passada/atual e Mês passado/atual com minibarras, ▲▼ e as datas ao passar o mouse; anel da
  taxa de resposta "X de Y convites"; avaliações e barra da nota; "Detalhes": último envio (≤ 7 dias verde,
  ≤ 30 laranja, acima vermelho), distribuição de notas, sentimento, evolução de 6 meses, último comentário.
- Vista em tabela por setor: Atendente, Perfil, Sem. ant., Sem. atual, Mês ant., Mês atual, Avaliações,
  Taxa resp., Nota.
- CSV com as colunas do METRICA360 (Perfil, Último Envio) mais Coordenador(a).

## Decisões
- Usuário: refazer igual; manter o filtro de período do Conecta (com período aplicado, o cartão mostra o
  número do período); Destaque e selos melhor/pior pela regra do METRICA360 (sem mínimo de avaliações);
  trazer "Atualizado às" e a recarga de 5 min, sem o relógio do cabeçalho; CSV do METRICA360 + Coordenador(a).
- Minhas (avisadas): cores da paleta do Conecta (a faixa do setor em vinho, não no azul `#004d80` do
  METRICA360, que o Conecta eliminou); nota alta/média/baixa em verde/laranja/vinho; nome do setor e média
  em `--laranja-100` sobre o vinho (contraste); "?" pelo `data-vg-tooltip` do Conecta; "Detalhes" abre com
  `hidden` (sem a animação de altura); arquivos próprios `satisfacao-setores.js/.css`; as funções e classes
  da versão simplificada saem (substituídas).

## Não objetivos
- Relógio do cabeçalho do METRICA360. Mudar os dados de quem aparece em cada setor (a regra do Conecta fica).
