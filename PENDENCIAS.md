# Pendências do Conecta CRPRS

Lista de tarefas abertas, com caixas para marcar. Atualizada em 03/11/2026.
Detalhes e motivos: `docs/relatorios/AUDITORIA_2026-11-01.md` (seção 7), `docs/relatorios/AUDITORIA_2026-11-01_EXECUCAO.md`, `docs/relatorios/INTEGRACAO_METRICA360_CONECTA_2026-11-01.md` e `docs/implantacao-producao.md`.

## 1. Segurança urgente

- [x] Trocar a senha SMTP do METRICA360 (estava em `database/backup_24072026_db_metrica360.sql`, já removida do histórico, mas continua valendo)
- [x] Trocar a senha do banco do METRICA360 (estava no `.env` que foi ao Git)
- [x] Trocar a chave do Google Places (estava em `metrica360-slide.html`)
- [x] Trocar a senha da caixa IMAP/SMTP institucional e a do banco antigo do Conecta (expostas no GitHub até 27/10/2026)
- [x] Trocar a chave secreta do Turnstile e definir `TURNSTILE_SECRET_KEY_PROD` no `.env`
- [ ] Trocar o endereço do remote do METRICA360: `git remote set-url origin https://github.com/hacmiranda/metrica360.git`
- [ ] Apagar os backups do histórico antigo quando não forem mais necessários (`C:\Users\hugo.miranda\conecta-backup-antes-reescrita-2026-10-27\` e `C:\Users\hugo.miranda\metrica360-backup-antes-reescrita-2026-11-03\`)
- [x] Desligar a tarefa agendada do Windows que roda `verificar-emails.php` a cada 2 minutos contra a caixa real (grava no banco que vai para a produção)

## 2. Decisões que dependem do usuário

- [x] **Subdomínio (SEG-01):** decidido em 03/11/2026 — fica em subpasta (`crp07.org.br/conecta`), sem subdomínio
- [ ] **Banco que vai para a produção (INC-01):**
  - [ ] Religar os 15 e-mails do setor Cadastro (estão desligados)
  - [ ] Cadastrar os feriados (a tabela está vazia)
  - [ ] Apagar as 24 demandas e os 7 solicitantes de teste
  - [ ] Apagar os 3 usuários `zz_nav_*` (de 27/10) e as 2 linhas `zz` da fila de e-mails (de 21 e 22/10)
- [ ] **Feriados (BUG-03):** setor sem feriados próprios usa os do setor Geral? Quem cadastra o calendário de 2026 e 2027?
- [ ] **Regras de fluxo:**
  - [ ] Só o responsável pode comentar e mudar o status de uma demanda? (BUG-11)
  - [ ] Transferir de setor volta a demanda para "novo"? (BUG-20)
  - [ ] A equipe deixa de receber o e-mail "seu comentário foi adicionado"? (BUG-15)
- [ ] **Banco e LGPD:**
  - [ ] Aprovar a futura migração 41 (índices novos, DES-04/09; era a "39" da auditoria, o número foi usado pelos papéis de coordenação)
  - [ ] Aprovar a futura migração 42 (chaves estrangeiras e colunas sem uso, INC-04/07; era a "40" da auditoria)
  - [ ] Definir os prazos de guarda dos dados (SEG-15/DES-13)
- [ ] Decidir os demais itens da auditoria que aguardam aprovação: SEG-02, SEG-05, SEG-08, SEG-10, SEG-11, SEG-12, BUG-13, BUG-17, INC-08, INC-09, DES-06, MAN-02, MAN-03
- [x] **Integração com o METRICA360:** decidido em 03/11/2026 — o METRICA360 vira o módulo "Satisfação" do Conecta (mesmo banco, login único, papéis novos). Plano de 8 fases em andamento (ver "Unificação" abaixo)

## 3. Unificação Conecta + METRICA360 (em andamento desde 03/11/2026)

Plano: `C:\Users\hugo.miranda\.claude\plans\1-quero-continuar-iterative-quasar.md`. Um commit por fase.

- [x] Fase 1 — decisões nos documentos
- [x] Fase 2 — papéis novos no backend (migração 39: `coordenador`, `supervisor`, `coordenador_geral`, `usuario_setores`, `atendimento_externo`)
- [x] Fase 3 — papéis novos no frontend (somente leitura, seletor de setores, tela de usuários)
- [~] Fase 4 — migração 40 e importador prontos; **falta você revisar o ensaio** (`C:\Users\hugo.miranda\ensaio-importacao-metrica360.txt`) e autorizar o `--executar`
- [ ] Fase 5 — backend do módulo (convites, avaliações, painéis, página pública)
- [ ] Fase 6 — telas do grupo "Satisfação" e Configurações → Pesquisa de satisfação
- [ ] Fase 7 — página pública `avaliar.html`
- [ ] Fase 8 — desligar o METRICA360 (301 de `/metrica360`, arquivar repositório, remover `Metrica360Client`)
- [ ] Depois: avisar quem tem o link `/metrica360/avaliar` (nada em produção ainda, então só se algum convite de teste foi enviado de verdade)

## 4. Antes do primeiro deploy em produção

- [ ] Definir no `.env` do servidor todas as chaves `*_PROD` (`SETOR_EMAIL_ENCRYPTION_KEY_PROD`, `INTEGRATION_API_KEY_PROD`, `CRON_KEY_PROD`, JWT/CSRF etc.; lista em `.env.example`)
- [ ] Confirmar `db_host` e `db_user` de produção
- [ ] Aplicar as migrações 9–40 com backup (`php database/migrar.php`), ou confirmar que a réplica do banco local já vem com elas
- [ ] Depois do deploy: `php database/recifrar-senhas-caixas.php` (senhas das caixas para AES-GCM)
- [ ] Definir `PROXIES_CONFIAVEIS` com as faixas da Cloudflare, se o site ficar atrás dela
- [ ] Não enviar ao servidor: `frontend/node_modules/`, `mockups/`, `api/tests/`
- [ ] Agendar o cron de e-mail no cron-job.org (a cada 2 min, via HTTP com o cabeçalho `X-Cron-Key`)
- [ ] Checklist pós-deploy: login do primeiro admin, `migrar.php --status`, matriz de acesso, teste real de e-mail por setor, HTTPS e cabeçalhos de segurança, backup automático
- [ ] Pesquisa de satisfação: com o módulo dentro do Conecta não há mais deploy do METRICA360 nem chaves de integração; basta a migração 40 e o cron de e-mail já agendado (roteiro novo em `docs/implantacao-producao.md` ao fim da Fase 8)
- [ ] Criar no Conecta as contas e os setores dos servidores que só existem no METRICA360 — agora é a Fase 4 da unificação (`database/importar-metrica360.php`, casando setores por nome; senha desconhecida + "Esqueci a senha")
- [ ] Registro de Atividades: cadastrar atividades e regras por setor e avisar os servidores (passos 2, 3 e 5 da seção 7 de `docs/implantacao-producao.md`)
- [ ] Passada com leitor de tela (NVDA) nos fluxos principais e teclado nas telas de Configurações

## 5. Conferir no navegador (não testado)

- [ ] Lista de demandas
- [ ] Detalhe de uma demanda mesclada
- [ ] Arquivo
- [ ] Exportação CSV da Auditoria
- [ ] Registro de Atividades
- [ ] Meu Painel
- [ ] Cron de e-mail com uma mensagem nova de verdade (nos últimos dias não chegou e-mail na caixa local)
- [ ] Compressão gzip e cabeçalhos HTTP reais (o Apache local não tem `mod_deflate`)
- [ ] Rodar a matriz de acesso (`php api/tests/matriz-de-acesso.php`) com o Apache ligado

## 6. Correções e melhorias técnicas

- [ ] `email-sender.php`: os `catch (Exception $e)` só pegam a exceção do PHPMailer por causa do `use PHPMailer\PHPMailer\Exception;`; um erro de banco escapa para quem chamou (15 métodos de envio)
- [ ] Avisar quem mantém os sistemas satélites que o texto de `message` na resposta de `abrir_chamado.php` mudou (os campos são os mesmos)
- [ ] CSP sem `'unsafe-inline'`: projeto à parte, tela por tela (trocar `onclick=` e `style=` por delegação com `data-acao` e classes)
- [ ] Consolidar os ~150 seletores CSS repetidos e as três bases de modo escuro
- [ ] Avaliar a troca do Quill 1.3.6 (sem manutenção) pelo Quill 2, em projeto próprio
- [ ] Remover `auth-sso.php`, se não houver painel agregador previsto (só com aprovação)

## 7. Concluídas recentemente

- [x] Auditoria de 01/11/2026 e relatório (65 achados)
- [x] Correção dos achados que não exigiam aprovação: 31 corrigidos, 8 em parte (commit `7d281fd`)
- [x] Plano de integração Conecta/METRICA360 (`docs/relatorios/INTEGRACAO_METRICA360_CONECTA_2026-11-01.md`)
- [x] Histórico do METRICA360 limpo no GitHub: `.env`, backups e dumps do banco e a chave do Google removidos (03/11/2026)
