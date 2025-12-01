# 🔄 RELATÓRIO DE CORREÇÃO - STATUS "EM ATENDIMENTO"

**Data:** 31/01/2025  
**Problema:** Sistema usando "novo" em vez de "em_atendimento"

---

## 🐛 Problemas Identificados

1. **ENUM da tabela tickets** tinha valor `'atendimento'` mas deveria ser `'em_atendimento'`
2. **Tickets com status vazio** (11 tickets encontrados)
3. **Tickets com status "novo" e agente atribuído** (inconsistência de dados)
4. **SLA não estava contando** para tickets novos (já estava correto: congela_sla = 0)

---

## ✅ Correções Aplicadas

### 1. Banco de Dados

```sql
-- Corrigir ENUM da coluna status
ALTER TABLE tickets MODIFY COLUMN status ENUM('novo','em_atendimento','aguardando_resposta','aguardando_terceiros','aguardando_data','aguardando','resolvido','encerrado','cancelado','reaberto') DEFAULT 'novo';

-- Corrigir tickets com status vazio
UPDATE tickets SET status = 'novo' WHERE status = '' OR status IS NULL;

-- Corrigir tickets com status novo que já têm agente atribuído
UPDATE tickets SET status = 'em_atendimento' WHERE status = 'novo' AND agente_id IS NOT NULL;
```

### 2. Arquivos Modificados

- ✅ `api/controllers/tickets.php` - Código já estava correto usando 'em_atendimento'
- ✅ `backend/deploy_producao.sql` - Adicionado script de correção

---

## 📊 Resultados

- **11 tickets** com status vazio corrigidos para "novo"
- **Vários tickets** com status "novo" + agente atribuído corrigidos para "em_atendimento"
- **ENUM** da tabela tickets atualizado
- **SLA** já estava configurado corretamente (congela_sla = 0 para status "novo")

---

## 🚀 Instruções de Deploy para Produção

### 1. Fazer Backup
```bash
# No PHPMyAdmin da Locaweb, exportar banco crpdesk
```

### 2. Executar SQL
```sql
-- Copiar e executar as seções 5 do arquivo deploy_producao.sql
```

### 3. Testar
- [ ] Criar ticket novo - deve ficar com status "novo"
- [ ] Atribuir agente a ticket novo - deve mudar para "em_atendimento"
- [ ] Verificar cor da borda (cinza para em_atendimento)
- [ ] Verificar toast de confirmação
- [ ] Verificar envio de emails de atribuição

---

## 📝 Observações

- O código PHP já estava correto
- O problema era apenas no ENUM do banco de dados
- Tickets com status "novo" devem contar SLA para primeira resposta
- Ao atribuir agente, status muda automaticamente para "em_atendimento"

---

**Status:** ✅ Corrigido no ambiente local  
**Próximo passo:** Deploy em produção (Locaweb)
