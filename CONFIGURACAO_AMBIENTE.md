# 🔧 Configuração de Ambiente - CRPDESK

## 📅 Data: 31/01/2025

## 🎯 Objetivo

Sistema de configuração automática que detecta se está rodando em **localhost** ou **produção** e aplica as configurações corretas automaticamente.

---

## 📝 Arquivos Criados/Modificados

### ✅ Arquivos Criados (2)

1. **`api/config/environment.php`**
   - Detecta ambiente automaticamente
   - Centraliza todas as configurações
   - Métodos: `isProduction()`, `get()`

2. **`api/test/test-environment.php`**
   - Script de teste de configuração
   - Verifica banco, email e caminhos

### ✅ Arquivos Modificados (3)

1. **`api/config/database.php`**
   - Usa `Environment::get()` para credenciais

2. **`api/config/email-config.php`**
   - Usa `Environment::get()` para configurações

3. **`api/services/email-service.php`**
   - Usa `EmailConfig::get()` para acessar configs

---

## 🔍 Como Funciona

### Detecção Automática

```php
// Verifica o hostname
$host = $_SERVER['HTTP_HOST'];

// Se for localhost, 127.0.0.1 ou ::1 = LOCALHOST
// Caso contrário = PRODUÇÃO
```

### Configurações por Ambiente

**LOCALHOST:**
- Banco: `localhost` / `root` / sem senha
- Email: Conta real (para testes)
- Debug: Ativado
- Caminhos: `C:/xampp/htdocs/crpdesk/`

**PRODUÇÃO:**
- Banco: `crpdesk.vpshost0269.mysql.dbaas.com.br`
- Email: Conta real
- Debug: Desativado
- Caminhos: Relativos ao servidor

---

## 🚀 Uso

### No Código

```php
// Verificar ambiente
if (Environment::isProduction()) {
    // Código específico de produção
} else {
    // Código específico de desenvolvimento
}

// Obter configuração
$db_host = Environment::get('db_host');
$upload_path = Environment::get('upload_path');
```

### Testar Configuração

**Localhost:**
```
http://localhost/crpdesk/api/test/test-environment.php
```

**Produção:**
```
https://crp07.org.br/crpdesk/api/test/test-environment.php
```

---

## 📋 Configurações Disponíveis

### Banco de Dados
- `db_host` - Host do banco
- `db_name` - Nome do banco
- `db_user` - Usuário
- `db_pass` - Senha
- `db_charset` - Charset (utf8mb4)

### Email
- `imap_host` - Servidor IMAP
- `imap_port` - Porta IMAP (993)
- `imap_user` - Usuário IMAP
- `imap_pass` - Senha IMAP
- `smtp_host` - Servidor SMTP
- `smtp_port` - Porta SMTP (465)
- `smtp_user` - Usuário SMTP
- `smtp_pass` - Senha SMTP
- `from_email` - Email remetente
- `from_name` - Nome remetente

### Caminhos
- `base_url` - URL base do sistema
- `upload_path` - Caminho para anexos
- `log_path` - Caminho para logs

### Debug
- `debug` - Ativar debug (true/false)
- `display_errors` - Exibir erros (true/false)

---

## 🔧 Personalizar Configurações

### Editar Localhost

Abrir `api/config/environment.php` e modificar método `localhost()`:

```php
private static function localhost() {
    return [
        'db_host' => 'localhost',
        'db_user' => 'root',
        'db_pass' => '',
        // ...
    ];
}
```

### Editar Produção

Modificar método `production()`:

```php
private static function production() {
    return [
        'db_host' => 'crpdesk.vpshost0269.mysql.dbaas.com.br',
        'db_user' => 'crpdesk',
        'db_pass' => 'WlxjB#YRSsu7s',
        // ...
    ];
}
```

---

## 📦 Deploy

### Passo 1: Copiar Arquivos

**Novos:**
- `api/config/environment.php`
- `api/test/test-environment.php`

**Modificados:**
- `api/config/database.php`
- `api/config/email-config.php`
- `api/services/email-service.php`

### Passo 2: Testar

1. Acessar `test-environment.php`
2. Verificar se ambiente é detectado corretamente
3. Verificar conexão com banco
4. Verificar configurações de email

### Passo 3: Validar

- ✅ Login funciona
- ✅ Tickets são criados
- ✅ Emails são processados
- ✅ Anexos são salvos

---

## ✅ Vantagens

1. **Automático** - Detecta ambiente sem configuração manual
2. **Centralizado** - Todas configs em um lugar
3. **Seguro** - Credenciais separadas por ambiente
4. **Fácil** - Mesmo código funciona em ambos ambientes
5. **Testável** - Script de teste incluído

---

## 🧪 Testes

### Teste 1: Detecção de Ambiente

**Localhost:**
```
Ambiente Detectado: LOCALHOST
```

**Produção:**
```
Ambiente Detectado: PRODUÇÃO
```

### Teste 2: Banco de Dados

- ✅ Conexão estabelecida
- ✅ Query executada
- ✅ Dados retornados

### Teste 3: Email

- ✅ Configurações carregadas
- ✅ IMAP conecta
- ✅ SMTP funciona

---

## 📞 Troubleshooting

### Problema: Ambiente errado detectado

**Causa:** Hostname não reconhecido

**Solução:**
```php
// Adicionar hostname em environment.php
$host = $_SERVER['HTTP_HOST'];
self::$isProduction = !in_array($host, [
    'localhost', 
    '127.0.0.1', 
    '::1',
    'seu-hostname-local' // Adicionar aqui
]);
```

### Problema: Erro de conexão com banco

**Verificar:**
1. Credenciais corretas em `environment.php`
2. Servidor de banco acessível
3. Firewall não bloqueando

**Testar:**
```bash
# Localhost
mysql -u root -p crpdesk

# Produção
mysql -h crpdesk.vpshost0269.mysql.dbaas.com.br -u crpdesk -p
```

---

## 🔒 Segurança

### Boas Práticas

1. **Nunca commitar** `environment.php` com senhas reais
2. **Usar .gitignore** para proteger credenciais
3. **Permissões** corretas nos arquivos (644)
4. **Debug desativado** em produção

### .gitignore Recomendado

```
api/config/environment.php
backend/logs/*.log
backend/anexos/*
```

---

## 💾 Versionamento OBRIGATÓRIO de Banco de Dados

**Regra de Ouro:**
1. **Migrations via Arquivos SQL:** Qualquer mínima alteração de schema, trigger, criação de tabela ou alteração de tipo de dado (`ALTER TABLE`, `CREATE TABLE`, `UPDATE` estrutural) realizada no banco local **DEVE** ser transformada em um arquivo `.sql` limpo.
2. **Padrão de Nomenclatura:** Os scripts devem ser salvos unicamente na pasta `database/` seguindo a sequência cronológica numérica atualizada. (Exemplo: Se o último arquivo for `7_create_notificacoes.sql`, o seu arquivo será `8_sua_nova_alteracao.sql`).
3. O intuito é garantir que toda refatoração executada no Localhost pelo agente seja aplicada de forma segura, exata e auditável no servidor final de **Produção** via importação sequencial manual pela equipe do CRPRS.

---
2. **Sem Binários/Pacotes Externos:** Componentes que exigem compilação, instalação via terminal direto no servidor de produção (como Redis, extensões pecl nativas compiladas na hora, ou demônios de background) não podem ser utilizados.
3. **Serviços Contínuos:** Serviços como servidores Ratchet/WebSocket em portas customizadas rodando via linha de comando PHP não são viáveis.

**Ação:** Qualquer solução arquitetural (como notificações assíncronas) **deve** fluir via protocolo HTTP padrão, utilizando exclusivamente recursos nativos da linguagem PHP na web (ex: *Server-Sent Events* - SSE, CRON jobs locais simples ou Long-Polling).

---

**Sistema de configuração de ambiente implementado! ✅**

**Desenvolvido por:** Amazon Q Developer  
**Data:** 31/01/2025  
**Versão:** 1.0
