# Relatório de Auditoria de Segurança

**Data:** 19/02/2026
**Responsável:** Agente de IA (Antigravity)
**Status:** ✅ Concluído

## Introdução
Este documento detalha as vulnerabilidades identificadas e as correções aplicadas durante a auditoria de segurança do Sistema de Helpdesk CRPRS.

---

## 🔒 Vulnerabilidades Identificadas e Corrigidas

### 1. Chaves de Criptografia Expostas (Hardcoded Secrets)
- **Problema:** A chave secreta para assinatura de tokens JWT estava fixa no código (`auth.php` e `auth.php` middleware), o que comprometeria toda a segurança se o código fosse vazado.
- **Risco:** Crítico. Permite forja de tokens de administrador.
- **Correção:** 
  - As chaves `jwt_secret` e `csrf_secret` foram movidas para o arquivo `api/config/environment.php`.
  - O `middleware/auth.php` foi atualizado para buscar essas chaves dinamicamente.
  - Chaves fortes e distintas foram geradas para ambientes de desenvolvimento e produção.

### 2. Autenticação Legada Insegura (Password Backdoor)
- **Problema:** O sistema permitia login com a senha fixa '123456' para qualquer usuário, agindo como uma backdoor.
- **Risco:** Crítico. Acesso não autorizado trivial.
- **Correção:** 
  - A lógica `|| $usuario['senha'] === '123456'` foi removida.
  - Mantida apenas a migração de senhas legadas (texto plano -> hash) se a senha fornecida corresponder exatamente à salva no banco.
  - Adicionado `password_needs_rehash` para atualizar algoritmos de hash automaticamente.

### 3. Proteção CORS Permissiva Excessiva
- **Problema:** O header `Access-Control-Allow-Origin: *` permitia que qualquer site fizesse requisições à API.
- **Risco:** Médio/Alto. Facilita ataques CSRF e roubo de dados via XHR.
- **Correção:** 
  - Implementado controle dinâmico de Origin ou restrição para domínios específicos.
  - Adicionados headers de segurança `X-Content-Type-Options: nosniff` e `X-Frame-Options: DENY`.

### 4. Gestão de Sessão (Session Fixation)
- **Problema:** O ID da sessão PHP não era regenerado após o login bem-sucedido.
- **Risco:** Médio. Permite sequestro de sessão se o atacante fixar o ID antes do login.
- **Correção:** 
  - Adicionado `session_regenerate_id(true);` imediatamente após a validação das credenciais.

### 5. Cross-Site Request Forgery (CSRF)
- **Problema:** A validação CSRF estava "mockada" (`return true`) ou usava tokens estáticos.
- **Risco:** Médio. Permite que atacantes executem ações em nome do usuário.
- **Correção:** 
  - Implementada verificação real de tokens CSRF dinâmicos.
  - API preparada para validar tokens enviados via header `X-CSRF-Token` ou body.
  - Definida estratégia de segurança híbrida (JWT + Session).

---

## 🛡️ Medidas de Segurança Adicionais Implementadas

### Headers de Segurança HTTP
Foram adicionados os seguintes cabeçalhos para proteger o navegador do usuário:
```http
X-Content-Type-Options: nosniff
X-Frame-Options: DENY
Access-Control-Allow-Credentials: true
```

### Validação de Dados
- Confirmação de que todas as queries sensíveis em `auth.php` utilizam `PDO Prepared Statements` para prevenir SQL Injection.

## 📝 Recomendações Futuras

1. **Rotação de Chaves:** Implementar um processo para rotacionar as chaves JWT em produção periodicamente.
2. **Rate Limiting:** Implementar limite de tentativas de requisição por IP na API (já existe para login, estender para rotas de tickets).
3. **Logs de Auditoria:** Garantir que o `AuditoriaService` esteja cobrindo todas as operações críticas (DELETE, UPDATE sensíveis).

---
**Setor de Tecnologia da Informação - CRPRS**
