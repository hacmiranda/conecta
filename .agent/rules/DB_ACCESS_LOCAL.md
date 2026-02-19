# REGRAS DE ACESSO AO BANCO DE DADOS (LOCAL)

> **STATUS: ATIVO E OBRIGATÓRIO**
> **ESCOPO: Acesso Direto, Consulta SQL, Debugging**

## 1. Princípio de Acesso Local
Todas as operações de consulta direta, edição de schema ou debugging no banco de dados **DEVEM** ser realizadas na instância local do MySQL.

**NUNCA utilize dumps desatualizados (ex: `crpdesk.sql` na raiz) como fonte da verdade.** O estado real dos dados está no servidor MySQL em execução.

## 2. Parâmetros de Conexão Obrigatórios
Para qualquer interação direta via terminal ou script de manutenção:

- **Executável MySQL**: `C:\xampp\mysql\bin\mysql.exe`
- **Host**: `localhost`
- **Database**: `crpdesk`
- **Usuário**: `root`
- **Senha**: `(vazio)`

### Comando Padrão de Acesso
```bash
C:\xampp\mysql\bin\mysql.exe -u root -e "USE crpdesk; SUA_QUERY_AQUI;"
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
- O arquivo `crpdesk.sql` na raiz é um **backup estático** e pode estar desatualizado.
- Para obter o esquema/dados atuais, gere um novo dump:
  ```bash
  C:\xampp\mysql\bin\mysqldump.exe -u root crpdesk > crpdesk_atual.sql
  ```
