# 🔄 RELATÓRIO - Sistema de Dupla Verificação de Emails

## 📅 Data: 31/01/2025

## 🎯 Problema Identificado

Emails de domínios específicos (crprs.org.br, hotmail.com) não estavam sendo processados consistentemente, resultando em tickets não criados ou comentários perdidos.

## 🔍 Causas Identificadas

1. **Conexão IMAP instável** - Falhas intermitentes na conexão SSL
2. **Falta de rastreamento** - Sem controle de emails já processados
3. **Sem tratamento de erros** - Emails com erro eram perdidos
4. **Busca simples** - Não verificava emails perdidos
5. **Marcação prematura** - Emails marcados como lidos antes do processamento completo

---

## ✅ Soluções Implementadas

### 1. Sistema de Rastreamento de Emails

**Tabela: `emails_processados`**
- Registra cada email processado com Message-ID único
- Previne processamento duplicado
- Rastreia tipo de processamento (novo ticket ou comentário)

**Tabela: `emails_erro`**
- Armazena emails que falharam no processamento
- Permite reprocessamento automático (até 3 tentativas)
- Mantém histórico de erros

### 2. Conexão IMAP Melhorada

**Antes:**
```php
$mailbox = "{email-ssl.com.br:993/imap/ssl}INBOX";
$inbox = imap_open($mailbox, $user, $pass);
```

**Depois:**
```php
// Tenta com novalidate-cert (Locaweb)
$mailbox = "{email-ssl.com.br:993/imap/ssl/novalidate-cert}INBOX";
$inbox = @imap_open($mailbox, $user, $pass, 0, 3);

// Fallback sem novalidate-cert
if (!$inbox) {
    $mailbox = "{email-ssl.com.br:993/imap/ssl}INBOX";
    $inbox = @imap_open($mailbox, $user, $pass);
}
```

### 3. Uso de UID (Unique ID)

**Antes:** Usava número de mensagem (instável)
**Depois:** Usa UID do IMAP (permanente e confiável)

```php
$emails = imap_search($inbox, 'UNSEEN', SE_UID);
foreach ($emails as $uid) {
    $msgno = imap_msgno($inbox, $uid);
    // Processar...
    imap_setflag_full($inbox, $uid, "\\Seen", ST_UID);
}
```

### 4. Verificação de Duplicatas

```php
private function emailJaProcessado($message_id) {
    $query = "SELECT id FROM emails_processados WHERE message_id = :message_id";
    $stmt = $this->db->prepare($query);
    $stmt->bindParam(':message_id', $message_id);
    $stmt->execute();
    return $stmt->fetch() !== false;
}
```

### 5. Marcação Segura

**Antes:** Email marcado como lido imediatamente
**Depois:** Email marcado APENAS após processamento bem-sucedido

```php
try {
    $this->processarEmail($inbox, $msgno, $uid);
    // Só marca como lido se não houver exceção
    imap_setflag_full($inbox, $uid, "\\Seen", ST_UID);
} catch (Exception $e) {
    // Email permanece não lido para reprocessar
    log_error($e->getMessage());
}
```

### 6. Script de Verificação Manual

Permite varredura completa da caixa de entrada para identificar emails perdidos.

---

## 📝 Arquivos Criados/Modificados

### ✅ Arquivos Criados (4)

1. **`backend/email_tracking.sql`**
   - Schema das tabelas de rastreamento
   - Executar no PHPMyAdmin

2. **`api/services/email-service-v2.php`**
   - Versão aprimorada do serviço (referência futura)

3. **`api/cron/verificar-emails-perdidos.php`**
   - Script de verificação manual
   - Acesso: `http://crp07.org.br/crpdesk/api/cron/verificar-emails-perdidos.php`

4. **`RELATORIO_EMAIL_DUPLA_VERIFICACAO.md`**
   - Este relatório

### ✅ Arquivos Modificados (1)

1. **`api/services/email-service.php`**
   - Conexão IMAP melhorada
   - Uso de UID
   - Verificação de duplicatas
   - Marcação segura

---

## 📋 Instruções de Deploy

### Passo 1: Backup

```bash
# Backup do banco
mysqldump -u root crpdesk > backup_antes_email_tracking.sql

# Backup do arquivo
cp api/services/email-service.php api/services/email-service.php.backup
```

### Passo 2: Criar Tabelas

**No PHPMyAdmin da Locaweb:**

1. Acessar banco `crpdesk`
2. Ir em SQL
3. Executar conteúdo de `backend/email_tracking.sql`
4. Verificar se tabelas foram criadas:
   - `emails_processados`
   - `emails_erro`

### Passo 3: Atualizar Arquivos

**Via FTP ou Painel Locaweb:**

1. Substituir: `/crpdesk/api/services/email-service.php`
2. Criar: `/crpdesk/api/cron/verificar-emails-perdidos.php`

### Passo 4: Testar Conexão

**Acessar:**
```
https://<servidor>/<pasta>/api/cron/verificar-emails.php   (header X-Cron-Key: <CRON_KEY_PROD do .env>)
```

**Verificar logs:**
```
/crpdesk/backend/logs/email-cron.log
```

### Passo 5: Verificar Emails Perdidos

**Executar uma vez:**
```
http://crp07.org.br/crpdesk/api/cron/verificar-emails-perdidos.php
```

Isso irá:
- Analisar últimos 30 dias
- Identificar emails não processados
- Marcar como não lidos para reprocessamento

### Passo 6: Monitorar

**Verificar por 7 dias:**
- Logs de processamento
- Tabela `emails_processados`
- Tabela `emails_erro`

---

## 🧪 Testes Obrigatórios

### Teste 1: Email de Domínio Interno (crprs.org.br)

1. Enviar email de `@crprs.org.br` para `suporte@crprs.org.br`
2. Aguardar 1 minuto (cron)
3. Verificar se ticket foi criado
4. Verificar registro em `emails_processados`

### Teste 2: Email de Hotmail

1. Enviar email de `@hotmail.com` para `suporte@crprs.org.br`
2. Aguardar 1 minuto
3. Verificar criação de ticket
4. Verificar registro

### Teste 3: Resposta a Ticket

1. Responder email de ticket existente
2. Verificar se comentário foi adicionado
3. Verificar registro como tipo 'comentario'

### Teste 4: Email com Anexo

1. Enviar email com anexo
2. Verificar se anexo foi salvo
3. Verificar ticket criado

### Teste 5: Duplicata

1. Reenviar mesmo email
2. Verificar que não cria ticket duplicado
3. Verificar log "Email já processado"

### Teste 6: Verificação Manual

1. Executar `verificar-emails-perdidos.php`
2. Verificar relatório gerado
3. Confirmar emails não processados

---

## 📊 Monitoramento

### Queries Úteis

**Emails processados hoje:**
```sql
SELECT COUNT(*) as total, tipo 
FROM emails_processados 
WHERE DATE(processado_em) = CURDATE() 
GROUP BY tipo;
```

**Emails com erro:**
```sql
SELECT * FROM emails_erro 
WHERE tentativas < 3 
ORDER BY criado_em DESC;
```

**Últimos 10 emails processados:**
```sql
SELECT * FROM emails_processados 
ORDER BY processado_em DESC 
LIMIT 10;
```

**Taxa de sucesso:**
```sql
SELECT 
    (SELECT COUNT(*) FROM emails_processados WHERE DATE(processado_em) = CURDATE()) as processados,
    (SELECT COUNT(*) FROM emails_erro WHERE DATE(criado_em) = CURDATE()) as erros;
```

---

## 🔧 Troubleshooting

### Problema: Emails ainda não processam

**Verificar:**
1. Extensão IMAP habilitada: `php -m | grep imap`
2. Credenciais corretas em `email-config.php`
3. Firewall não bloqueando porta 993
4. Logs de erro: `backend/logs/email-cron.log`

**Solução:**
```bash
# Testar conexão manualmente
php api/cron/verificar-emails.php
```

### Problema: Duplicatas sendo criadas

**Verificar:**
1. Tabela `emails_processados` existe
2. Coluna `message_id` tem índice UNIQUE
3. Função `emailJaProcessado()` está sendo chamada

**Solução:**
```sql
-- Verificar estrutura
DESCRIBE emails_processados;

-- Verificar índice
SHOW INDEX FROM emails_processados;
```

### Problema: Emails marcados como erro

**Verificar:**
```sql
SELECT * FROM emails_erro ORDER BY criado_em DESC LIMIT 10;
```

**Reprocessar manualmente:**
```php
// Executar cron novamente
// Sistema tentará reprocessar automaticamente
```

---

## 📈 Melhorias Futuras

### Curto Prazo
- [ ] Dashboard de monitoramento de emails
- [ ] Alertas quando emails ficam em erro
- [ ] Relatório diário de processamento

### Médio Prazo
- [ ] Webhook para notificação instantânea
- [ ] API REST para consulta de status
- [ ] Retry inteligente com backoff exponencial

### Longo Prazo
- [ ] Machine learning para detectar spam
- [ ] Processamento assíncrono com fila
- [ ] Múltiplas caixas de entrada

---

## 📞 Suporte

**Logs:**
- `/backend/logs/email-cron.log` - Processamento normal
- `/backend/logs/verificacao-manual.log` - Verificação manual

**Comandos Úteis:**
```bash
# Ver últimas 50 linhas do log
tail -n 50 backend/logs/email-cron.log

# Monitorar em tempo real
tail -f backend/logs/email-cron.log

# Contar emails processados hoje
grep "$(date +%Y-%m-%d)" backend/logs/email-cron.log | grep "processado com sucesso" | wc -l
```

---

**Sistema de dupla verificação implementado com sucesso! ✅**

**Desenvolvido por:** Amazon Q Developer  
**Data:** 31/01/2025  
**Versão:** 2.0
