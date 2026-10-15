# REGRAS DE ACESSO AO BANCO DE DADOS (LOCAL)

> **STATUS: ATIVO E OBRIGATÓRIO**
> **ESCOPO: Acesso Direto, Consulta SQL, Debugging**

## 1. Princípio de Acesso Local
Todas as operações de consulta direta, edição de schema ou debugging no banco de dados **DEVEM** ser realizadas na instância local do MySQL.

**NUNCA utilize dumps desatualizados (ex: `database/crpdesk.sql`) como fonte da verdade.** O estado real dos dados está no servidor MySQL em execução.

## 2. Parâmetros de Conexão Obrigatórios
Para qualquer interação direta via terminal ou script de manutenção:

- **Executável MySQL**: `C:\Program Files\MySQL\MySQL Server 8.4\bin\mysql.exe` (o MySQL do XAMPP **não** é o usado: falha com o plugin `caching_sha2_password`)
- **Host / Database / Usuário / Senha**: ler de `api/config/.env` (`DB_HOST_LOCAL`, `DB_NAME_LOCAL` = `db_conecta`, `DB_USER_LOCAL`, `DB_PASS_LOCAL`). Nunca registrar a senha em arquivos versionados.

### Comando Padrão de Acesso
```bash
"C:\Program Files\MySQL\MySQL Server 8.4\bin\mysql.exe" -u root -p -e "USE db_conecta; SUA_QUERY_AQUI;"
```

## 3. Procedimentos Permitidos
- **Consultas (SELECT)**: Permitido livremente para fins de debug e verificação de integridade.
- **Edição de Estrutura (ALTER, CREATE)**: Permitido APENAS se houver migração correspondente ou script de correção, e com BACKUP prévio se crítico.
- **Edição de Dados (UPDATE, DELETE)**: EXTREMA CAUTELA.
  - Verificar se a operação afeta integridade referencial.
  - Se for correção de bug, registre a ação.
  - **SEMPRE** use `WHERE` com chaves primárias (`id`).

## 4. Scripts PHP de Manutenção
Se for necessário criar scripts PHP temporários para manutenção do banco:
- Use `require_once __DIR__ . '/api/config/database.php';` para garantir conexão consistente.
- Instancie via `new Database()` e `$db->getConnection()`.
- Remova o script após o uso (ex: `test_db.php`).

## 5. Dumps e Backups
- Os arquivos `database/crpdesk.sql` e `database/squema_crpdesk.sql` são **estáticos** (esquema base, sem as tabelas de setores) e podem estar desatualizados.
- Para obter o esquema/dados atuais, gere um novo dump:
  ```bash
  "C:\Program Files\MySQL\MySQL Server 8.4\bin\mysqldump.exe" -u root -p db_conecta > db_conecta_atual.sql   # guardar fora do repositório
  ```
