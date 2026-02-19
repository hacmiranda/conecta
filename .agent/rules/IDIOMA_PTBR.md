# IDIOMA_PTBR.md - Diretriz de Idioma Obrigatória

> **STATUS: ATIVO E OBRIGATÓRIO**
> **ESCOPO: GLOBAL (Pensamento, Escrita, Código e Documentação)**

## 1. Princípio Fundamental
A partir deste momento, **O PORTUGUÊS DO BRASIL (pt-BR)** é o idioma oficial e mandatório para todas as interações, processos cognitivos, documentação e comentários de código neste projeto.

## 2. Regras de Aplicação

### 🧠 Raciocínio (Chain of Thought - Pensamento)
- O processo interno de análise, planejamento e tomada de decisão da IA deve ser realizado **inteiramente em português**.
- Ao "pensar passo a passo", descreva a lógica e os desafios encontrados no idioma nativo do projeto (pt-BR).

### 💬 Comunicação com o Usuário
- Respostas, perguntas e sugestões devem ser escritas em **Português Brasileiro**.
- Mantenha um tom profissional, direto e claro.
- Evite anglicismos desnecessários (preferir "implantação" em vez de "deploy" quando possível, mas mantenha termos técnicos consagrados se necessário para clareza).

### 💻 Desenvolvimento e Código
1.  **Comentários de Código**:
    -   Todos os comentários (`//`, `/** */`, `#`) devem explicar a lógica **em português**.
    -   **Exceção**: Nenhuma.
    
    *Errado:*
    ```php
    // Check if user exists
    if (!$user) { ... }
    ```

    *Correto:*
    ```php
    // Verifica se o usuário existe
    if (!$usuario) { ... }
    ```

2.  **Variáveis e Funções**:
    -   Deve-se seguir o padrão existente no projeto para consistência.
    -   Se o projeto já utiliza inglês (ex: `getUser`), mantenha. Se utiliza português (ex: `obterUsuario`), mantenha.
    -   Se for código novo/independente, dê preferência a nomes semânticos em português se solicitado ou se o projeto for misto.

3.  **Mensagens de Commit**:
    -   Devem ser descritivas e escritas em português.
    -   Exemplo: `fix: correção no redirecionamento de login` (e não `fix: login redirect bug`).

### 📄 Documentação (Docs)
- Arquivos `.md`, PHPDoc, READMEs e changelogs devem ser escritos exclusivamente em português.

## 3. Glossário e Termos Técnicos
Termos técnicos universais podem ser mantidos em inglês quando a tradução comprometer o entendimento técnico, mas o contexto deve ser em português.

- **Aceitável**: "Fazer o deploy da aplicação no servidor."
- **Aceitável**: "O bug está no loop do frontend."
- **Inaceitável**: "Fazer o schedule da task." -> Use: "Agendar a tarefa."

---
**FIM DAS DIRETRIZES DE IDIOMA**
