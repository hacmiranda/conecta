# Prompt: Implementação Cloudflare Turnstile CAPTCHA

## Contexto
Implemente o Cloudflare Turnstile (CAPTCHA invisível) em um formulário PHP para proteção contra bots e ataques automatizados.

## Informações Necessárias
Antes de começar, preciso saber:
1. **Caminho do arquivo do formulário** (ex: `/admin/login.php`)
2. **Suas chaves Turnstile** (Site Key e Secret Key)
3. **Ambiente**: Desenvolvimento local (localhost) ou Produção

## Requisitos de Implementação

### 1. Criar arquivo de validação
**Arquivo**: `includes/turnstile.php`

```php
<?php
/**
 * Cloudflare Turnstile - Validação de CAPTCHA
 */

// Chaves Turnstile
define('TURNSTILE_SITE_KEY', 'SUA_SITE_KEY_AQUI');
define('TURNSTILE_SECRET_KEY', 'SUA_SECRET_KEY_AQUI');
define('TURNSTILE_ENABLED', true);

/**
 * Valida o token Turnstile com a API da Cloudflare
 * 
 * @param string $token Token recebido do formulário
 * @param string $ip IP do usuário (opcional)
 * @return bool True se válido, False se inválido
 */
function validateTurnstile($token, $ip = null) {
    if (!TURNSTILE_ENABLED) {
        return true;
    }
    
    if (empty($token)) {
        return false;
    }
    
    $data = [
        'secret' => TURNSTILE_SECRET_KEY,
        'response' => $token
    ];
    
    if ($ip) {
        $data['remoteip'] = $ip;
    }
    
    $ch = curl_init('https://challenges.cloudflare.com/turnstile/v0/siteverify');
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    
    // Para ambiente local (localhost), desabilitar verificação SSL
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);
    
    if ($curlError) {
        error_log('Turnstile cURL Error: ' . $curlError);
        return false;
    }
    
    if ($httpCode !== 200) {
        error_log('Turnstile HTTP Error: ' . $httpCode);
        return false;
    }
    
    $result = json_decode($response, true);
    
    if (!isset($result['success'])) {
        error_log('Turnstile Response: ' . $response);
        return false;
    }
    
    if (!$result['success'] && isset($result['error-codes'])) {
        error_log('Turnstile Error: ' . implode(', ', $result['error-codes']));
    }
    
    return $result['success'] === true;
}
```

### 2. Modificar o formulário PHP

**No início do arquivo**, adicione:
```php
require_once 'includes/turnstile.php';
```

**Na validação do POST**, adicione ANTES das outras validações:
```php
if ($_POST) {
    $turnstileToken = $_POST['cf-turnstile-response'] ?? '';
    
    // Validar Turnstile primeiro
    if (!validateTurnstile($turnstileToken, $_SERVER['REMOTE_ADDR'])) {
        $error = 'Verificação de segurança falhou. Tente novamente.';
    } else {
        // Suas validações normais aqui
    }
}
```

**No HTML `<head>`**, adicione:
```html
<script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>
```

**No formulário HTML**, adicione ANTES do botão de submit:
```html
<div style="margin: 1.5rem 0;">
    <?php if (TURNSTILE_ENABLED): ?>
    <div class="cf-turnstile" 
         data-sitekey="<?= TURNSTILE_SITE_KEY ?>" 
         data-theme="light"></div>
    <?php endif; ?>
</div>
```

### 3. Configurar no painel Cloudflare

1. Acesse: https://dash.cloudflare.com/
2. Vá em **Turnstile**
3. Crie um novo site ou edite existente
4. Em **Domains**, adicione:
   - Para localhost: `localhost`, `127.0.0.1`
   - Para produção: `seudominio.com.br`
5. Copie as chaves: **Site Key** e **Secret Key**

### 4. Chaves de Teste (Opcional)

Para testar sem configurar no Cloudflare, use:
```php
define('TURNSTILE_SITE_KEY', '1x00000000000000000000AA');
define('TURNSTILE_SECRET_KEY', '1x0000000000000000000000000000000AA');
```
**Nota**: Essas chaves sempre passam na validação.

## Problemas Comuns e Soluções

### Erro: "SSL certificate problem"
**Solução**: Adicione no cURL:
```php
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
```

### Erro: 401 Unauthorized
**Solução**: Adicione `localhost` nos domínios permitidos no painel Cloudflare.

### Widget não aparece
**Solução**: Verifique se o script está carregando no `<head>` e se a Site Key está correta.

### Validação sempre falha
**Solução**: 
1. Verifique os logs: `error_log` do PHP
2. Teste com chaves de teste primeiro
3. Confirme que cURL está habilitado no PHP

## Personalização

### Tema escuro
```html
<div class="cf-turnstile" data-theme="dark"></div>
```

### Callbacks JavaScript
```html
<div class="cf-turnstile" 
     data-callback="onSuccess"
     data-error-callback="onError"></div>

<script>
function onSuccess(token) {
    console.log('CAPTCHA validado!');
}

function onError(error) {
    console.error('Erro no CAPTCHA:', error);
}
</script>
```

### Desabilitar temporariamente
```php
define('TURNSTILE_ENABLED', false);
```

## Checklist de Implementação

- [ ] Criar arquivo `includes/turnstile.php`
- [ ] Adicionar `require_once` no formulário
- [ ] Adicionar script no `<head>`
- [ ] Adicionar widget no formulário
- [ ] Adicionar validação no POST
- [ ] Configurar chaves (teste ou produção)
- [ ] Configurar domínios no Cloudflare
- [ ] Testar com credenciais corretas
- [ ] Testar com credenciais incorretas
- [ ] Verificar logs de erro

## Exemplo Completo Mínimo

```php
<?php
require_once 'includes/turnstile.php';

$error = '';

if ($_POST) {
    $turnstileToken = $_POST['cf-turnstile-response'] ?? '';
    
    if (!validateTurnstile($turnstileToken, $_SERVER['REMOTE_ADDR'])) {
        $error = 'Verificação de segurança falhou.';
    } else {
        // Processar formulário
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>
</head>
<body>
    <?php if ($error): ?>
        <div class="error"><?= $error ?></div>
    <?php endif; ?>
    
    <form method="POST">
        <input type="text" name="username" required>
        <input type="password" name="password" required>
        
        <div class="cf-turnstile" data-sitekey="<?= TURNSTILE_SITE_KEY ?>"></div>
        
        <button type="submit">Enviar</button>
    </form>
</body>
</html>
```

## Segurança em Produção

1. **Remover** `CURLOPT_SSL_VERIFYPEER` e `CURLOPT_SSL_VERIFYHOST` se tiver SSL válido
2. **Usar** chaves reais (não as de teste)
3. **Configurar** domínios corretos no Cloudflare
4. **Monitorar** logs de erro
5. **Limitar** tentativas de submit por IP

## Suporte

- Documentação oficial: https://developers.cloudflare.com/turnstile/
- Dashboard: https://dash.cloudflare.com/
- Verificar logs: `error_log` do PHP ou `apache/logs/error.log`

---

**Desenvolvido com base em implementação real e testada em ambiente PHP/MySQL.**
