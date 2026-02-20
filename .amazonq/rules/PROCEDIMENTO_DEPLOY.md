# 🚀 PROCEDIMENTO DE DEPLOY - CRPDESK

## ⚠️ IMPORTANTE

**Ambiente Local:** XAMPP (C:\xampp\htdocs\crpdesk)  
**Ambiente Produção:** Locaweb (https://crp07.org.br/crpdesk/)

Os arquivos locais são cópias da produção. Todas as alterações devem ser sincronizadas manualmente.

---

## 📋 CHECKLIST DE DEPLOY

### Ao Final de CADA Tarefa, Fornecer:

1. **Lista de Arquivos Modificados**
   - Caminho completo de cada arquivo alterado
   - Tipo de alteração (criado/modificado/deletado)

2. **Instruções de Cópia**
   - Quais arquivos copiar para a Locaweb
   - Estrutura de diretórios a manter

3. **Alterações de Banco de Dados**
   - Script SQL completo para executar no PHPMyAdmin
   - Ordem de execução (se houver dependências)
   - Backup recomendado antes da execução

4. **Testes Necessários**
   - Funcionalidades que devem ser testadas após deploy
   - Pontos críticos de validação

---

## 📝 TEMPLATE DE RELATÓRIO

```markdown
## 🔄 RELATÓRIO DE ALTERAÇÕES

### Arquivos Modificados
- [ ] `caminho/arquivo1.php` - Descrição da alteração
- [ ] `caminho/arquivo2.js` - Descrição da alteração

### Arquivos Criados
- [ ] `caminho/novo-arquivo.php` - Descrição

### Arquivos Deletados
- [ ] `caminho/arquivo-antigo.php` - Motivo

### Alterações no Banco de Dados
```sql
-- Executar no PHPMyAdmin da Locaweb
-- Fazer backup antes!

ALTER TABLE tabela ADD COLUMN nova_coluna VARCHAR(255);
```

### Instruções de Deploy
1. Fazer backup do banco de dados
2. Copiar arquivos via FTP/Painel Locaweb
3. Executar SQL no PHPMyAdmin
4. Testar funcionalidade X, Y, Z

### Testes Obrigatórios
- [ ] Funcionalidade A
- [ ] Funcionalidade B
```

---

**Versão:** 1.0  
**Data:** Janeiro 2025
