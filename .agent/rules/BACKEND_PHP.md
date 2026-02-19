# REGRAS DE BACKEND (PHP/API)

> **STATUS: ATIVO**
> **ESCOPO: API, Controllers, Services**

## 1. Padrões de API Rest
- **Formato**: Todas as respostas devem ser JSON.
  ```php
  header('Content-Type: application/json');
  ```
- **Códigos HTTP**: Use códigos corretos (200, 201, 400, 401, 403, 404, 500).
- **Tratamento de Erros**:
  - SEMPRE envolva a lógica principal em blocos `try-catch`.
  - Capture `Exception $e`.
  - Logue o erro interno: `error_log("[CONTEXTO] Erro: " . $e->getMessage());`.
  - Retorne JSON amigável e código de erro adequado.

## 2. Injeção e Conexão
- **Database**: Instancie via `new Database()` e obtenha conexão com `$db->getConnection()`.
- **Services**: Services devem receber a conexão DB no construtor.
  ```php
  $service = new AuditoriaService($db);
  ```

## 3. Segurança e Auditoria (CRÍTICO)
- **Registro de Auditoria**: Toda operação de **escrita** (INSERT, UPDATE, DELETE) em tabelas críticas (tickets, usuários, configurações) **DEVE** ser auditada.
  ```php
  $auditoria = new AuditoriaService($db);
  $auditoria->registrar($user_id, 'acao_realizada', 'tabela_afetada', $registro_id, $dados_antes_json, $dados_novos_json);
  ```
- **Login**: Tentativas de login (sucesso ou falha) devem ser registradas via `AuditoriaService->registrarLogin()`.
- **Validação**: Nunca confie no input do usuário. Use Prepared Statements (`PDO::prepare`) sempre.

## 4. Log e Debug
- Use `error_log()` com prefixos claros (ex: `[TICKETS POST]`, `[AUTH]`) para facilitar rastreio no Apache/PHP logs.
- Não deixe `var_dump` ou `print_r` em produção; use logs.

## 5. Estrutura
- **Config**: Use `Environment::get('key')` para configurações sensíveis.
- **Middleware**: Verifique autenticação no início de rotas protegidas.
