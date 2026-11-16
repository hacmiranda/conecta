# Central de Feedbacks igual à do METRICA360 (16/11/2026)

Desenho curto, aprovado pelo usuário na conversa de 16/11/2026 (título e Google Reviews decididos por ele).

## Intenção
A tela Satisfação → Avaliações tem as funções da "Central de Feedbacks" do METRICA360
(`metrica360/src/views/admin/dashboard.php`, `public/css/dashboard.css`, `public/js/dashboard.js`), mas não o
visual nem vários recursos. O usuário quer a tela igual à de lá, sem perder o que o Conecta tem a mais.

## Objetivos
- Título "Central de Feedbacks" (o item do menu continua "Avaliações") e o subtítulo de lá.
- Frase do dia em faixa, com atalho para editar as frases (só admin, abre Configurações → Pesquisa de satisfação).
- **Google Reviews no topo** (widget Elfsight, o mesmo do Meu Painel).
- "Métricas Gerais": 6 quadros com ícone, contagem animada, tendência de 7 dias em Total, Pendentes (invertida),
  Resolvidos e Média, barras de % pendentes, taxa de resolução e média/5.
- "Evolução de Nota Média": botões 7/30/90/180 dias, linha suave com área, pontos pelo volume, barras de volume,
  linha da meta com rótulo, eixos "Nota" e "Volume", legenda e dica ao passar o mouse.
- "Categorias mais mencionadas": Mais elogiadas / Mais criticadas, barra = % da categoria.
- Filtros recolhíveis (abertos se houver filtro), "Filtros Ativos" e "Limpar".
- Cartão "Feedbacks Recebidos" com contagem, CSV e PDF; colunas Registro (#id, NOVO < 1 h, data, hora e tempo
  decorrido), Avaliação (anel + estrelas), Relato (corta acima de 120 caracteres, "Ver tudo"), Servidor (do Conecta),
  Status (selo com menu; "há N dias" em pendente com 3+ dias, urgente com 7+), Ações (anotação destacada se já
  existe; ver detalhes) e painel lateral de anotação.
- Paginação completa ("Exibindo X–Y de N (filtrado)", primeira/última, números) e mensagens de lista vazia de lá.
- Recarga a cada 5 min e número de pendentes no menu.

## Decisões
- Usuário: título "Central de Feedbacks" e menu "Avaliações"; Google Reviews no topo.
- Minhas (avisadas): paleta do Conecta (o azul vira vinho); gráfico desenhado em canvas pelo Conecta, sem
  Chart.js; o filtro de setor das categorias é o seletor do cabeçalho (vale para a tela toda); selo "NOVO" em vez de
  "NEW" (regra de idioma do projeto); o texto da anotação diz "não é vista pelo cidadão" (no Conecta quem vê é a
  equipe do módulo, não só o administrador); o CSV continua o do Conecta (tem as colunas de lá e mais
  Categorias, Servidor e Setor); a anotação sai da linha da tabela e fica no painel lateral, como lá.

## Não objetivos
- Relógio do cabeçalho. Nota do Google Places (o METRICA360 guardava, mas a tela não mostrava).
