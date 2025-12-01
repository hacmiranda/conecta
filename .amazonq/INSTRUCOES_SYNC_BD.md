# 🔄 INSTRUÇÕES - SINCRONIZAR BANCO DE DADOS

## 📥 EXPORTAR DA PRODUÇÃO (Locaweb)

### Opção 1: Via PHPMyAdmin (Recomendado)

1. Acesse o PHPMyAdmin da Locaweb
2. Selecione o banco de dados `crpdesk`
3. Clique na aba **"Exportar"**
4. Selecione:
   - **Método de exportação:** Personalizado
   - **Formato:** SQL
   - **Tabelas:** Selecionar todas
   - **Estrutura:**
     - ✅ Adicionar DROP TABLE / VIEW / PROCEDURE / FUNCTION / EVENT / TRIGGER
     - ✅ Adicionar CREATE TABLE
     - ✅ Adicionar IF NOT EXISTS
   - **Dados:**
     - ✅ Incluir dados (se quiser copiar os dados também)
     - OU ❌ Desmarcar se quiser apenas a estrutura
5. Clique em **"Executar"**
6. Salvar arquivo como: `crpdesk_producao_YYYYMMDD.sql`

### Opção 2: Via mysqldump (Se tiver acesso SSH)

```bash
# Apenas estrutura (sem dados)
mysqldump -u usuario -p --no-data crpdesk > crpdesk_estrutura.sql

# Estrutura + dados
mysqldump -u usuario -p crpdesk > crpdesk_completo.sql
```

---

## 📤 IMPORTAR NO LOCAL (XAMPP)

### Passo 1: Fazer Backup do Banco Local (Segurança)

```cmd
cd C:\xampp\mysql\bin
mysql -u root crpdesk -e "SHOW TABLES;" > backup_tabelas_antes.txt
mysqldump -u root crpdesk > backup_local_antes_sync.sql
```

### Passo 2: Limpar Banco Local

```cmd
cd C:\xampp\mysql\bin
mysql -u root -e "DROP DATABASE IF EXISTS crpdesk;"
mysql -u root -e "CREATE DATABASE crpdesk CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
```

### Passo 3: Importar SQL da Produção

```cmd
cd C:\xampp\mysql\bin
mysql -u root crpdesk < C:\xampp\htdocs\crpdesk\backend\crpdesk_producao_YYYYMMDD.sql
```

### Passo 4: Verificar Importação

```cmd
cd C:\xampp\mysql\bin
mysql -u root crpdesk -e "SHOW TABLES;"
mysql -u root crpdesk -e "SELECT COUNT(*) as total FROM tickets;"
mysql -u root crpdesk -e "DESCRIBE ticket_status;"
```

---

## ✅ CHECKLIST PÓS-IMPORTAÇÃO

- [ ] Todas as tabelas foram criadas
- [ ] Tabela `ticket_status` existe
- [ ] Tabela `ticket_sla` existe
- [ ] Tabela `ticket_historico` existe
- [ ] Função `calcular_segundos_uteis()` existe
- [ ] Triggers estão criados
- [ ] Dados foram importados (se aplicável)
- [ ] Sistema local funciona sem erros

---

## 🔍 VERIFICAR TABELAS ESSENCIAIS

```cmd
cd C:\xampp\mysql\bin

# Listar todas as tabelas
mysql -u root crpdesk -e "SHOW TABLES;"

# Verificar tabelas críticas
mysql -u root crpdesk -e "SHOW TABLES LIKE 'ticket_%';"

# Verificar funções
mysql -u root crpdesk -e "SHOW FUNCTION STATUS WHERE Db = 'crpdesk';"

# Verificar triggers
mysql -u root crpdesk -e "SHOW TRIGGERS FROM crpdesk;"
```

---

## 🐛 TROUBLESHOOTING

### Erro: "Table already exists"
```cmd
# Adicionar --force para ignorar erros
mysql -u root --force crpdesk < arquivo.sql
```

### Erro: "Unknown database"
```cmd
# Criar banco antes
mysql -u root -e "CREATE DATABASE crpdesk CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
```

### Erro: "Access denied"
```cmd
# Verificar usuário root
mysql -u root -p
# Se pedir senha, adicionar -p no comando
```

### Importação muito lenta
```cmd
# Desabilitar verificações temporariamente
mysql -u root crpdesk -e "SET FOREIGN_KEY_CHECKS=0; SET UNIQUE_CHECKS=0;"
mysql -u root crpdesk < arquivo.sql
mysql -u root crpdesk -e "SET FOREIGN_KEY_CHECKS=1; SET UNIQUE_CHECKS=1;"
```

---

## 📋 SCRIPT COMPLETO (COPIAR E COLAR)

```cmd
@echo off
echo ========================================
echo SINCRONIZACAO BANCO DE DADOS CRPDESK
echo ========================================
echo.

cd C:\xampp\mysql\bin

echo [1/5] Fazendo backup do banco local...
mysqldump -u root crpdesk > C:\xampp\htdocs\crpdesk\backend\backup_local_%date:~-4,4%%date:~-7,2%%date:~-10,2%.sql
echo Backup criado!
echo.

echo [2/5] Removendo banco local...
mysql -u root -e "DROP DATABASE IF EXISTS crpdesk;"
echo Banco removido!
echo.

echo [3/5] Criando banco limpo...
mysql -u root -e "CREATE DATABASE crpdesk CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
echo Banco criado!
echo.

echo [4/5] Importando SQL da producao...
echo IMPORTANTE: Coloque o arquivo SQL da producao em: C:\xampp\htdocs\crpdesk\backend\
set /p arquivo="Digite o nome do arquivo SQL (ex: crpdesk_producao.sql): "
mysql -u root crpdesk < C:\xampp\htdocs\crpdesk\backend\%arquivo%
echo Importacao concluida!
echo.

echo [5/5] Verificando tabelas...
mysql -u root crpdesk -e "SHOW TABLES;"
echo.

echo ========================================
echo SINCRONIZACAO CONCLUIDA!
echo ========================================
pause
```

**Salvar como:** `C:\xampp\htdocs\crpdesk\backend\sync_database.bat`

---

**Data:** Novembro 2025  
**Versão:** 1.0
