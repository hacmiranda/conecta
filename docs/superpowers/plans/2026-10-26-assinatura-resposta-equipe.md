# Assinatura de quem responde no card "Resposta da equipe"

**Objetivo:** quando um gestor, operador ou administrador responde ao solicitante por e-mail, a assinatura
pessoal aparece logo abaixo do texto, dentro do card "Resposta da equipe":

```
Atenciosamente,

Nome completo
Nome do setor
```

**Decisões (26/10/2026):**
- **Setor:** gestor e operador usam o próprio setor (`usuarios.setor_id`). Se estiver vazio (dado antigo),
  usam o setor da demanda. O administrador, que não tem setor, usa o setor da demanda.
- **Onde aparece:** só nos e-mails com texto escrito por uma pessoa. São eles "Resposta da equipe"
  (`email-template-resposta.html`) e "Cópia da resposta" (`email-template-emcopia.html`). O botão "Enviar e Fechar"
  passa pelo mesmo envio e também fica assinado. O aviso automático "Primeira resposta" **não** leva assinatura.
- **Só no e-mail:** a assinatura é montada na hora do envio. Não é gravada no comentário, então não aparece
  repetida na tela da demanda nem no "Histórico das Interações", onde cada cartão já mostra o nome do autor.

## Arquivos

| Arquivo | Mudança |
|---|---|
| `api/services/email-assinatura-resposta.php` (novo) | `EmailAssinaturaResposta::html(string $nome, string $setor): string`. Monta o bloco com nome e setor escapados. Não acessa o banco, para poder ser testado sozinho. |
| `api/services/email-sender.php` | Em `enviarComentarioParaSolicitante()`, o SELECT passa a trazer o tipo e o setor do autor e o setor da demanda. A assinatura é acrescentada ao texto do comentário depois de `EmailTipografia::padronizar()`, e o mesmo resultado vai para o e-mail ao solicitante e para a cópia. |
| `backend/email-template-resposta.html`, `backend/email-template-emcopia.html` | Sai o `<p>Atenciosamente,<br></p>` vazio do rodapé, que ficaria duplicado. Entra no `<style>` uma regra `.assinatura-resposta` (margem acima, sem margem nos parágrafos). |
| `api/tests/Unit/EmailAssinaturaRespostaTest.php` (novo) | Ver "Testes". |
| `mockups/gerar-mockups.php` | O comentário de exemplo do e-mail 03/04 passa a ter a assinatura ("Carlos Pereira / Cadastro"). Rodar o gerador e refazer `mockups/imagens/03-*.png` e `04-*.png`. |
| `CLAUDE.md` | Registrar a regra na seção "Cartões do corpo" dos e-mails. |

## Passos

1. **Teste primeiro.** Criar `EmailAssinaturaRespostaTest`: monta "Atenciosamente," + nome + setor nessa ordem;
   escapa HTML no nome e no setor (`<script>`); setor vazio mostra só o nome; nome vazio devolve `''` (sem assinatura).
   Rodar `cd api && composer test` e ver o teste falhar.
2. **Serviço.** Criar `email-assinatura-resposta.php`:
   ```php
   class EmailAssinaturaResposta {
       public static function html(string $nome, string $setor): string {
           $nome = trim($nome);
           if ($nome === '') return '';
           $e = fn($t) => htmlspecialchars($t, ENT_QUOTES, 'UTF-8');
           return '<div class="assinatura-resposta" style="margin-top:14px;">'
               . '<p style="margin:0 0 10px 0;">Atenciosamente,</p>'
               . '<p style="margin:0;font-weight:bold;">' . $e($nome) . '</p>'
               . (trim($setor) !== '' ? '<p style="margin:0;">' . $e(trim($setor)) . '</p>' : '')
               . '</div>';
       }
   }
   ```
   Estilos embutidos (o Outlook ignora parte do `<style>`). Fonte e tamanho são herdados do `.resposta-box`,
   que segue a tipografia padrão das respostas. Rodar os testes de novo: devem passar.
3. **Envio.** Em `enviarComentarioParaSolicitante()`:
   - SELECT: `uc.tipo AS autor_tipo, su.nome AS autor_setor, st.nome AS demanda_setor`, com
     `LEFT JOIN setores su ON su.id = uc.setor_id` e `LEFT JOIN setores st ON st.id = t.setor_id`.
   - Assina só se o autor é `admin`, `gestor` ou `operador`, para nunca assinar um comentário de cliente
     caso esse método seja chamado num caminho não previsto. Setor: `autor_setor`, e se estiver vazio
     `demanda_setor`; o admin sempre usa `demanda_setor`.
   - `$comentario_email = EmailTipografia::padronizar($comentario_email) . EmailAssinaturaResposta::html(...)`.
     A assinatura entra depois da tipografia, para não ser alterada por ela.
4. **Modelos.** Tirar o "Atenciosamente," vazio do rodapé dos 2 modelos, mantendo CRLF. Acrescentar ao `<style>`:
   `.assinatura-resposta p { margin: 0; }`. A primeira linha tem margem própria embutida.
5. **Mockups.** No gerador, acrescentar `EmailAssinaturaResposta::html('Carlos Pereira', 'Cadastro')` ao
   `$comentarioAgente`. Rodar `php mockups/gerar-mockups.php`, conferir no navegador o 03 e o 04 e refazer os 2 PNGs.
6. **Verificação real.** Com o banco local: comentar numa demanda de teste com "Enviar por e-mail" marcado,
   uma vez como operador e outra como admin, e conferir em `email_fila` que o corpo traz nome e setor certos.
   Admin deve sair com o setor da demanda. Fazer também "Enviar e Fechar" e conferir que a assinatura sai.
   Uma nota interna não gera e-mail, como antes.
7. **Documentação e commit.** Atualizar o CLAUDE.md e fazer o commit:
   `feat: assinatura de quem responde no card Resposta da equipe`.

## Fora do escopo
- Assinatura configurável por usuário (cargo, telefone). Hoje é só nome e setor.
- Mudar o rodapé institucional (`getAssinatura()`, equipe do setor + endereço), que continua no fim de todos os e-mails.
