# 📋 REGRA: Alterações no Banco de Dados

## ⚠️ REGRA OBRIGATÓRIA

**Sempre que houver alteração no banco de dados, ajuste o arquivo `deploy_producao.sql`.**

### Procedimento

1. Abrir arquivo: `backend/deploy_producao.sql`
2. Adicionar alterações **NO FIM DO ARQUIVO**
3. Incluir comentários explicativos:
   - Data da alteração
   - Referência ao que se refere
   - Descrição clara

### Formato Padrão

```sql
-- ========================================
-- N. TÍTULO DA ALTERAÇÃO
-- Data: DD/MM/AAAA
-- Ref: Descrição do motivo/ticket/issue
-- ========================================
-- SQL statements aqui
ALTER TABLE ...
UPDATE ...
INSERT ...

```

### Exemplo

```sql
-- ========================================
-- 4. CORRIGIR TICKETS COM STATUS VAZIO
-- Data: 31/01/2025
-- Ref: Correção de bug na criação de tickets
-- ========================================
UPDATE tickets SET status = 'novo' WHERE status = '' OR status IS NULL;
```

### Tipos de Alterações que Requerem Atualização

- ✅ ALTER TABLE (adicionar/remover colunas)
- ✅ CREATE TABLE (novas tabelas)
- ✅ UPDATE em massa (correções de dados)
- ✅ INSERT de configurações/status
- ✅ Mudanças em índices ou constraints
- ✅ Procedures, triggers, functions

### Arquivo de Referência

**Localização:** `backend/deploy_producao.sql`

Este arquivo é usado para sincronizar o banco local com produção (Locaweb).
