# Login por e-mail ou nome de usuário + importação das pessoas do METRICA360 (16/11/2026)

Desenho curto, aprovado pelo usuário na conversa de 16/11/2026.

## Intenção
- Quem vem do METRICA360 entrava com o nome de usuário (`nome.sobrenome`); o Conecta só aceitava e-mail.
  O login passa a aceitar os dois.
- Trazer para o banco local do Conecta (que será replicado na produção) as pessoas do METRICA360 local
  e os setores em que estão lotadas.

## Objetivos
- Campo único "E-mail ou usuário" no `login.html`; o servidor (`auth.php`) decide: com `@` procura por
  e-mail, sem `@` procura por `username` (sem diferenciar maiúsculas).
- Nome de usuário válido = `^[a-z0-9._-]{3,50}$` (minúsculas, sem `@`), conferido no servidor (POST e PUT
  de `usuarios.php`) e avisado na tela. Assim um nome de usuário nunca se confunde com um e-mail.
- Na edição, deixar o nome de usuário vazio mantém o atual (antes gerava outro, ex. `hanna.fagundes2`).
- A conta id 1 (Hugo Miranda), sem nome de usuário, recebe `hugo.miranda` (o mesmo do METRICA360).
- Importação: 35 contas (das 37 do ensaio) e os setores delas, mais a configuração da pesquisa
  (categorias, frases, regras), como no plano da unificação. Backup do `db_conecta` antes do `--executar`.

## Não objetivos
- "Esqueci a senha" continua só por e-mail.
- SSO (`auth-sso.php`) não muda.
- As contas que já existem no Conecta não mudam (a Hanna segue gestora).

## Restrições
- O limite de tentativas por conta deve contar pela conta encontrada (id), não pelo texto digitado
  (senão alternar e-mail/usuário dobraria as tentativas sem atraso); sem conta, pelo texto digitado.
- A falha de login com conta inexistente grava o identificador mascarado na auditoria (pode ser a senha
  digitada no campo errado).
- O JSON do login aceita `login` (novo) e `email` (antigo: testes, matriz de acesso).

## Abordagens
1. **Um campo, o servidor decide pelo `@`** (escolhida): simples, sem ambiguidade porque o nome de
   usuário não pode ter `@`.
2. Procurar nas duas colunas (`email = ? OR username = ?`): ambíguo se um nome de usuário coincidir com
   o e-mail de outra pessoa; descartada.
3. Seletor "Entrar com e-mail / usuário" na tela: um passo a mais para o usuário, sem ganho.

Importação: opção nova `--ignorar=email1,email2` no `database/importar-metrica360.php` (o filtro fica em
`ImportacaoMetrica360::planejar()`, que é pura e testável, e aparece no relatório), em vez de apagar as
contas depois.

## Decisões do usuário
- Não importar `admin@crprs.org.br` ("Administrador Chefe") nem `administrador@crrs.org.br`
  ("Administrador CRPRS"): administradores genéricos, sem dono.

## Fica para quem constrói
- Ícone do campo (`person`, incluir em `icon_names`) e textos das mensagens de erro.
