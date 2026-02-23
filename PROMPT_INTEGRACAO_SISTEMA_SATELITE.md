# Tarefa: Integração com o Helpdesk (CRPDESK) via API REST

**Contexto:**
Você atua no desenvolvimento/manutenção do sistema da **Ouvidoria** (ou outro sistema satélite do crp07.org.br).
Precisamos implementar um botão/funcionalidade chamado `"Encaminhar para o Suporte da TI"` ou `"Reportar Falha Técnica"`.
Este botão vai acionar uma API invisível do nosso Helpdesk (CRPDESK), criando um ticket de suporte automaticamente para a equipe de tecnologia resolver o incidente, sem necessitar que o usuário saia do sistema atual.

O backend da TI já desenvolveu e expôs o endpoint que você vai consumir.

---

### 📡 Especificações Técnicas da API M2M (CRPDESK)

**URL do Endpoint (Produção):** `https://crp07.org.br/crpdesk/api/controllers/integracoes/abrir_chamado.php`
*Nota: Para testes locais, substitua o domínio por `http://localhost/crpdesk...`*

**Método HTTP:** `POST`

**Headers Obrigatórios:**
- `Content-Type: application/json`
- `X-API-KEY: crpdesk_integracao_secreta_2026` 
*(O X-API-KEY atua como validação unificada entre os sistemas; certifique-se de blindar essa chave num `.env` ou variável de servidor PHP no lado da Ouvidoria, não expondo-a via fetch JS no front-end).*

---

### 📦 Estrutura do Payload (Corpo JSON)
O envio deve ser estruturado conforme os parâmetros abaixo:

```json
{
  "origem": "ouvidoria_publica", 
  "titulo": "Falha constatada na tela de Denúncias - Ticket automático",
  "descricao": "<p>Mensagem detalhada do erro. <strong>Pode conter HTML simples.</strong></p>",
  "email_solicitante": "email_do_usuario_logado_no_satelite@exemplo.com",
  "nome_solicitante": "Nome do Denunciante ou Funcionário",
  "prioridade": "normal",
  "categoria_id": 1,
  "metadados": {
    "navegador": "Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/119.0.0.0",
    "URL_do_erro": "https://crp07.org.br/ouvidoria/abertura.php",
    "resolucao_tela": "1920x1080",
    "ultimo_erro_console": "TypeError: null is not an object (evaluating 'token.id')"
  },
  "anexo_base64": "iVBORw0KGgoAAAANSUhEUgAA...", 
  "anexo_nome": "print_tela_erro.png"
}
```

#### Dicionário de Dados:
- **`origem` (obrigatório)**: String que vai alimentar diretamente os relatórios da TI. Use `"ouvidoria_interna"` para chamados abertos pelos funcionários do setor restrito. Use `"ouvidoria_publica"` para erros reportados pelo portal do cidadão.
   - *Atenção:* Ao usar a flag `ouvidoria_interna`, o Helpdesk forçará a adoção do e-mail da Ouvidoria para tratativas no ticket, independente do e-mail enviado.
- **`titulo` (obrigatório)**: Assunto do chamado.
- **`descricao` (obrigatório)**: O corpo do relatório técnico ou do usuário. O Back-end da TI aceita HTML formatado (ex. `<b>`, `<p>`), que será renderizado limpo para o analista na mesa de trabalho após higienização. 
- **`email_solicitante`**: Extremamente fundamental se a origem for "pública". O Helpdesk atrelará o chamado, contabilizará no SLA e enviará as atualizações da TI diretamente para este e-mail.
- **`prioridade` (opcional)**: Limite-se aos valores `baixa`, `normal`, `alta` ou `urgente`. Padrão é `normal`.
- **`categoria_id` (opcional)**: O ID da categoria cadastrada no banco da TI. Se não souber, envie null ou 1.
- **`anexo_base64` (opcional)**: A string em Base64 de um print-screen ou arquivo PDF. Se houver metadados MIME no início (ex: `data:image/png;base64,...`), a API tratará automaticamente. Envie caso o sistema tenha botão de "Anexar Print".
- **`anexo_nome` (opcional)**: O nome sugerido para o arquivo ao bater no Helpdesk. Ex: `"print_erro.png"`. Se você mandar o `anexo_base64` vazio, este campo é ignorado.
- **`metadados` (opcional)**: Um bloco JSON chave-valor livre. **Use e abuse disso.** O Dev Front-End da Ouvidoria pode recolher a URL exata do cidadão (`window.location.href`), o navegador (`navigator.userAgent`) e enviar. A TI agradecerá infinitamente por ter essas pistas no rodapé do ticket e acelerará muito o prazo de correção.

---

### ✨ Sua Missão de Implementação

1. Confeccione a interface (Botão ou Checkbox "Isto é um Erro de Sistema") na UI apropriada da Ouvidoria.
2. Crie uma função **Backend/PHP (cURL)** na Ouvidoria que empacota os dados (Session ID do funcionário, Payload do Erro, Contexto JSON) e os envie à nossa URL da TI usando a especificação acima.
   - **Gatilho de Segurança:** *Nunca deixe a chave X-API-KEY visível no Client-Side JavaScript.* As requisições devem sair sempre do Backend do seu servidor para não quebrar nosso pacto M2M.
3. Tratamento do Retorno: A API responderá no formato `{ "success": true, "ticket_id": 133, ... }` com Código HTTP 200. Parseie essa resposta. Caso tenha sucesso, mostre um Modal na tela do sistema informando: *"Chamado Técnico aberto para a TI. Protocolo de Acompanhamento: #133"*.
4. Teste em ambiente de Homologação confirmando se os campos chegam preenchidos dentro do banco do CRPDESK (`tabela: tickets` na coluna `origem_api`), respeitando a *Shadow Account* base do e-mail enviado.
