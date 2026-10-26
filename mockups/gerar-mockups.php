<?php
/**
 * Gera os mockups dos e-mails enviados pelo Conecta CRPRS (pasta mockups/).
 *
 * Uso: php mockups/gerar-mockups.php
 *
 * Os e-mails em HTML usam os modelos reais de backend/ preenchidos com dados fictícios, do mesmo
 * jeito que api/services/email-sender.php faz. Os e-mails montados direto no código (agente→agente,
 * lembrete, recuperação de senha, avisos em texto puro) são cópias do texto do código: se o texto
 * original mudar, atualize o catálogo abaixo e rode o gerador de novo.
 *
 * (O e-mail entra na moldura por srcdoc: o .htaccess do sistema manda X-Frame-Options DENY e bloquearia um iframe por src.)
 * Saída: emails/*.html (e-mail puro), paginas/*.html (e-mail dentro de uma moldura de cliente de
 * e-mail, com o momento do envio e o destinatário), index.html e README.md.
 * Os PNGs de imagens/ são tirados das páginas (ver README.md).
 */

$raiz   = dirname(__DIR__);
$saida  = __DIR__;
$esc    = function ($t) { return htmlspecialchars((string) $t, ENT_QUOTES, 'UTF-8'); };
require_once $raiz . '/api/services/email-cabecalho.php';
require_once $raiz . '/api/services/email-historico.php';
require_once $raiz . '/api/services/email-tipografia.php';
require_once $raiz . '/api/services/email-assinatura-resposta.php';

// Conversa de exemplo (mais recente primeiro) — só mensagens visíveis ao solicitante; uma nota interna
// da equipe existiria na demanda, mas nunca aparece aqui.
$demandaExemplo = [
    'id' => 123, 'protocolo' => 'CRPRS2026-0001', 'titulo' => 'Dúvida sobre inscrição de pessoa jurídica',
    'descricao' => '<p>Boa tarde,</p><p>Gostaria de saber quais documentos são necessários para a inscrição de uma pessoa jurídica no CRPRS.</p><p>Obrigada.</p>',
    'criado_em' => '2026-10-23 14:35:00', 'usuario_id' => 1,
    'email_solicitante' => 'maria.souza@exemplo.com.br', 'usuario_email' => 'maria.souza@exemplo.com.br', 'usuario_nome' => 'Maria Souza',
];
$msgA = ['id' => 3, 'usuario_id' => 2, 'nome' => 'Carlos Pereira', 'tipo' => 'operador', 'criado_em' => '2026-10-24 09:20:00', 'anexos' => [],
    'comentario' => '<p>Recebi o contrato social. Falta apenas o CNPJ atualizado.</p>'];
$msgB = ['id' => 2, 'usuario_id' => 1, 'nome' => 'Maria Souza', 'tipo' => 'cliente', 'criado_em' => '2026-10-23 15:42:00', 'anexos' => ['contrato-social.pdf'],
    'comentario' => '<p>Obrigada! Segue o contrato social em anexo.</p>'];
$msgC = ['id' => 1, 'usuario_id' => 2, 'nome' => 'Carlos Pereira', 'tipo' => 'operador', 'criado_em' => '2026-10-23 15:10:00', 'anexos' => [],
    'comentario' => '<p>Olá, Maria! Para a inscrição de pessoa jurídica, envie o contrato social, o CNPJ e o documento do responsável técnico.</p>'];

// Lê o modelo, troca o cabeçalho e o bloco "demanda registrada + histórico" (como EmailSender::carregarModelo)
$modelo = function (string $arquivo, array $mensagens = []) use ($raiz, $demandaExemplo) {
    $html = EmailCabecalho::aplicar(file_get_contents($raiz . '/backend/' . $arquivo));
    $bloco = EmailHistorico::renderizar($demandaExemplo, $mensagens, count($mensagens));
    return strtr($html, ['{{DEMANDA_E_HISTORICO}}' => $bloco]);
};

// ---- Dados fictícios -------------------------------------------------------------------------
$num        = 'CRPRS2026-0001'; // protocolo único do Conselho (sem "#": os modelos já têm o "#" literal)
$titulo     = 'Dúvida sobre inscrição de pessoa jurídica';
$solicitante = 'Maria Souza';
$emailSolic = 'maria.souza@exemplo.com.br';
$agente     = 'Carlos Pereira';
$setorNome  = 'Cadastro';
$caixaSetor = 'administrador@crprs.org.br';
$remetenteSetor = "Conecta CRPRS - {$setorNome} <{$caixaSetor}>";
$remetenteGlobal = 'administrador@crprs.org.br (remetente global do sistema)';
$abertura   = '23/10/2026 14:35';
$resposta   = '23/10/2026 15:10';
$descricao  = "Boa tarde,\n\nGostaria de saber quais documentos são necessários para a inscrição de uma pessoa jurídica no CRPRS.\n\nObrigada.";

// Assinatura do e-mail do solicitante (cópia de EmailSender::getAssinatura(), setor com caixa própria)
$assinatura = '<table width="100%" border="0" cellspacing="3" cellpadding="3" style="margin-top: 30px; padding-top: 20px; border-top: 1px solid #ddd;">
  <tr>
    <td width="13%" valign="top"><img src="http://www.crprs.org.br/assets/frontend/img/logo.png" width="150"/></td>
    <td width="87%" valign="top">
      <font face="Arial, Helvetica, sans-serif" size="2" color="#000000">Equipe ' . $esc($setorNome) . '</font><br />
      <font face="Arial, Helvetica, sans-serif" size="2">' . $esc($caixaSetor) . '</font><br /><br />
      <font face="Arial, Helvetica, sans-serif" size="2" color="#000000">Conselho Regional de Psicologia do Rio Grande do Sul</font><br />
      <font face="Arial, Helvetica, sans-serif" size="2" color="#000000">Av. Protásio Alves, 2854/301 - Porto Alegre/RS</font><br />
      <font face="Arial, Helvetica, sans-serif" size="2" color="#000000">(51) 3334-6799</font><br />
      <font face="Arial, Helvetica, sans-serif" size="2">www.crprs.org.br</font>
    </td>
  </tr>
</table>';

function preencher(string $html, array $mapa): string {
    return str_replace(array_keys($mapa), array_values($mapa), $html);
}

// Cópia de EmailSender::inserirAvisoAnexosRejeitados() (método privado)
$avisoAnexos = '<div style="background-color:#fff3cd;border-left:4px solid #ffc107;border-radius:0 6px 6px 0;padding:10px 16px;margin:14px 0;line-height:1.4">'
    . '<h3 style="color:#856404;margin:0 0 6px 0;font-size:14px;text-transform:uppercase;letter-spacing:0.3px">⚠️ Anexos rejeitados</h3>'
    . '<ul style="margin:0 0 6px 0;padding-left:20px"><li><b>planilha.exe:</b> Tipo de arquivo não permitido</li></ul>'
    . '<p style="margin:0"><b>Limites:</b> 5 arquivos, 20MB total, tipos: PDF, DOC, DOCX, TXT, CSV, XLS, XLSX, PNG, JPG</p></div>';

// O texto de exemplo traz tamanhos e fontes diferentes de propósito; EmailTipografia os uniformiza, como no envio real
$comentarioAgente = EmailTipografia::padronizar('<h2>Documentos recebidos</h2><p>Recebi o <span style="font-size:26px;font-family:Georgia">contrato social</span>. <span style="font-family:Courier New;font-size:11px">Falta apenas o CNPJ atualizado.</span></p><p><strong>Qualquer dúvida</strong>, é só responder a este e-mail.</p>')
    . EmailAssinaturaResposta::html($agente, $setorNome); // como no envio real: assinatura de quem respondeu

// ---- Catálogo --------------------------------------------------------------------------------
$emails = [];

$emails[] = [
    'slug' => 'demanda-criada', 'grupo' => 'Para o solicitante', 'formato' => 'html',
    'titulo' => 'Confirmação de demanda criada',
    'de' => $remetenteSetor, 'para' => "{$solicitante} <{$emailSolic}>",
    'assunto' => "🎫 [#{$num}] Demanda criada - {$titulo}",
    'quando' => 'Assim que uma demanda é aberta: por um e-mail recebido na caixa do setor, pelo formulário "Nova Demanda" do sistema ou por um sistema integrado (API).',
    'quem' => 'O solicitante da demanda.',
    'obs' => 'Sai pela caixa de e-mail do setor da demanda (ou pelo remetente global, se o setor não tiver caixa). Traz a demanda registrada (título e descrição).',
    'corpo' => preencher($modelo('email-template.html'), [
        '{{NOME_USUARIO}}' => $esc($solicitante), '{{NUMERO_TICKET}}' => $num, '{{TITULO_TICKET}}' => $esc($titulo),
        '{{DATA_ABERTURA}}' => $abertura, '{{ASSINATURA_EMAIL}}' => $assinatura,
    ]),
];

$emails[] = [
    'slug' => 'demanda-criada-anexos-rejeitados', 'grupo' => 'Para o solicitante', 'formato' => 'html',
    'titulo' => 'Confirmação de demanda criada (com anexos rejeitados)',
    'de' => $remetenteSetor, 'para' => "{$solicitante} <{$emailSolic}>",
    'assunto' => "🎫 [#{$num}] Demanda criada - {$titulo}",
    'quando' => 'Mesmo momento do e-mail anterior, mas só quando a demanda nasce de um e-mail com anexos que o sistema recusou (tipo não permitido, mais de 5 arquivos ou mais de 20MB).',
    'quem' => 'O solicitante da demanda.',
    'obs' => 'É o e-mail anterior com um aviso amarelo, logo abaixo das informações da demanda, listando cada anexo recusado e o motivo.',
    // O aviso entra logo depois do cartão "Informações da Demanda", como no envio real
    'corpo' => preg_replace('#(<div class="ticket-info">.*?</div>)#s', '$1' . str_replace(['\\', '$'], ['\\\\', '\\$'], $avisoAnexos), preencher($modelo('email-template.html'), [
        '{{NOME_USUARIO}}' => $esc($solicitante), '{{NUMERO_TICKET}}' => $num, '{{TITULO_TICKET}}' => $esc($titulo),
        '{{DATA_ABERTURA}}' => $abertura, '{{ASSINATURA_EMAIL}}' => $assinatura,
    ]), 1),
];

$emails[] = [
    'slug' => 'resposta-da-equipe', 'grupo' => 'Para o solicitante', 'formato' => 'html',
    'titulo' => 'Resposta da equipe',
    'de' => $remetenteSetor, 'para' => "{$solicitante} <{$emailSolic}>",
    'assunto' => "🎫 [#{$num}] Resposta da Equipe - {$titulo}",
    'quando' => 'Quando um atendente escreve um comentário na demanda com a opção "Enviar por e-mail" marcada (também no botão "Enviar e Fechar"). Notas internas nunca geram este e-mail.',
    'quem' => 'O solicitante da demanda. Os anexos do comentário seguem junto.',
    'obs' => 'Sai pela caixa de e-mail do setor da demanda e assina como a equipe desse setor.',
    'corpo' => preencher($modelo('email-template-resposta.html', [$msgB, $msgC]), [
        '{{NOME_USUARIO}}' => $esc($solicitante), '{{NUMERO_TICKET}}' => $num, '{{TITULO_TICKET}}' => $esc($titulo),
        '{{NOME_AGENTE}}' => $esc($agente), '{{DATA_RESPOSTA}}' => $resposta, '{{COMENTARIO_AGENTE}}' => $comentarioAgente,
        '{{ASSINATURA_EMAIL}}' => $assinatura,
    ]),
];

$htmlCopia = preencher($modelo('email-template-emcopia.html', [$msgB, $msgC]), [
    '{{NUMERO_TICKET}}' => $num, '{{TITULO_TICKET}}' => $esc($titulo), '{{NOME_AGENTE}}' => $esc($agente),
    '{{DATA_RESPOSTA}}' => $resposta, '{{COMENTARIO_AGENTE}}' => $comentarioAgente, '{{ASSINATURA_EMAIL}}' => $assinatura,
]);
$htmlCopia = str_replace('<strong>Solicitante:</strong> <br>', '<strong>Solicitante:</strong> ' . $esc($solicitante) . ' (' . $esc($emailSolic) . ')<br>', $htmlCopia);
$emails[] = [
    'slug' => 'resposta-em-copia', 'grupo' => 'Para o solicitante', 'formato' => 'html',
    'titulo' => 'Cópia da resposta da equipe',
    'de' => $remetenteSetor, 'para' => 'diretoria@exemplo.com.br (endereço informado no campo "Com cópia")',
    'assunto' => "🎫 [#{$num}] Resposta da Equipe - {$titulo}",
    'quando' => 'Junto com a "Resposta da equipe", quando o atendente preenche o campo "Com cópia" ao comentar. Sai uma mensagem para cada endereço informado.',
    'quem' => 'Cada endereço do campo "Com cópia" (pessoas que não são o solicitante).',
    'obs' => 'Mostra quem é o solicitante e assina como a equipe do setor. Se alguém dessa lista responder ao e-mail com o número da demanda, vira comentário.',
    'corpo' => $htmlCopia,
];

$emails[] = [
    'slug' => 'comentario-recebido', 'grupo' => 'Para o solicitante', 'formato' => 'html',
    'titulo' => 'Confirmação de comentário recebido',
    'de' => $remetenteSetor, 'para' => "{$solicitante} <{$emailSolic}>",
    'assunto' => "🎫 [#{$num}] Comentário recebido - {$titulo}",
    'quando' => 'Quando o solicitante responde por e-mail a uma demanda (assunto com #NNNNN) e a resposta é registrada como comentário.',
    'quem' => 'Quem enviou a resposta (o remetente do e-mail recebido).',
    'obs' => 'Se houver anexos recusados, um aviso amarelo com os motivos é incluído antes do agradecimento.',
    'corpo' => preencher($modelo('email-template-comentario.html', [$msgA, $msgB, $msgC]), [
        '{{NOME_USUARIO}}' => $esc($solicitante), '{{NUMERO_TICKET}}' => $num, '{{TITULO_TICKET}}' => $esc($titulo),
        '{{DATA_COMENTARIO}}' => '23/10/2026, 15:42', '{{ASSINATURA_EMAIL}}' => $assinatura,
    ]),
];

$emails[] = [
    'slug' => 'primeira-resposta', 'grupo' => 'Para o solicitante', 'formato' => 'html',
    'titulo' => 'Primeira resposta (demanda assumida)',
    'de' => $remetenteSetor, 'para' => "{$solicitante} <{$emailSolic}>",
    'assunto' => "🎫 [#{$num}] Primeira resposta - {$titulo}",
    'quando' => 'Quando uma demanda que ainda não tinha responsável é atribuída a um atendente pela primeira vez (inclusive quando o atendente a assume). Também marca a primeira resposta do SLA.',
    'quem' => 'O solicitante da demanda.',
    'obs' => 'Só sai na primeira atribuição; reatribuições posteriores não geram este e-mail.',
    'corpo' => preencher($modelo('email-template-primeira-resposta.html'), [
        '{{NOME_USUARIO}}' => $esc($solicitante), '{{NUMERO_TICKET}}' => $num, '{{TITULO_TICKET}}' => $esc($titulo),
        '{{NOME_AGENTE}}' => $esc($agente), '{{DATA_RESPOSTA}}' => $resposta, '{{ASSINATURA_EMAIL}}' => $assinatura,
    ]),
];

$emails[] = [
    'slug' => 'demanda-encerrada', 'grupo' => 'Para o solicitante', 'formato' => 'html',
    'titulo' => 'Demanda encerrada',
    'de' => $remetenteSetor, 'para' => "{$solicitante} <{$emailSolic}>",
    'assunto' => "🎫 [#{$num}] Demanda encerrada - {$titulo}",
    'quando' => 'Quando a demanda é encerrada (status encerrado/resolvido que a move para o Arquivo).',
    'quem' => 'O solicitante da demanda.',
    'obs' => 'Cancelar uma demanda não envia este e-mail.',
    'corpo' => preencher($modelo('email-template-fechamento.html', [$msgA, $msgB, $msgC]), [
        '{{NOME_USUARIO}}' => $esc($solicitante), '{{NUMERO_TICKET}}' => $num, '{{TITULO_TICKET}}' => $esc($titulo),
        '{{NOME_AGENTE}}' => $esc($agente), '{{DATA_FECHAMENTO}}' => '23/10/2026 17:20', '{{ASSINATURA_EMAIL}}' => $assinatura,
    ]),
];

$emails[] = [
    'slug' => 'demanda-reaberta-solicitante', 'grupo' => 'Para o solicitante', 'formato' => 'html',
    'titulo' => 'Demanda reaberta (aviso ao solicitante)',
    'de' => $remetenteSetor, 'para' => "{$solicitante} <{$emailSolic}>",
    'assunto' => "🎫 [#{$num}] Demanda reaberta - {$titulo}",
    'quando' => 'Quando o solicitante responde por e-mail a uma demanda já encerrada e o sistema a reabre automaticamente.',
    'quem' => 'O solicitante da demanda.',
    'obs' => 'Assina como a equipe do setor da demanda.',
    'corpo' => preencher($modelo('email-template-reabertura.html', [$msgA, $msgB, $msgC]), [
        '{{NOME_USUARIO}}' => $esc($solicitante), '{{NUMERO_TICKET}}' => $num, '{{TITULO_TICKET}}' => $esc($titulo),
        '{{DATA_REABERTURA}}' => '24/10/2026 09:05', '{{ASSINATURA_EMAIL}}' => $assinatura,
    ]),
];

// ---- E-mails internos (equipe) ----------------------------------------------------------------
$assuntoNova = "🎫 [NOVA DEMANDA] #{$num} - {$titulo}";
$emails[] = [
    'slug' => 'nova-demanda-gestores', 'grupo' => 'Para a equipe (internos)', 'formato' => 'texto',
    'titulo' => 'Aviso de nova demanda',
    'de' => $remetenteGlobal, 'para' => 'Gestores do setor da demanda (se o setor não tiver gestor, os administradores)',
    'assunto' => $assuntoNova,
    'quando' => 'Junto com a confirmação ao solicitante, sempre que uma demanda é aberta (e-mail recebido, formulário do sistema ou integração).',
    'quem' => 'Os gestores do setor da demanda; um e-mail para cada gestor.',
    'obs' => 'Texto puro, sem modelo HTML. A descrição é cortada em 500 caracteres.',
    'corpo' => "🎫 NOVA DEMANDA CRIADA\n\nNúmero: #{$num}\nTítulo: {$titulo}\nSolicitante: {$emailSolic}\nData: {$abertura}\nStatus: Novo\nPrioridade: Media\n\nDescrição:\n" . trim($descricao) . "\n\nAcesse o sistema para atribuir e responder a demanda.\n\nAtenciosamente,\nSistema Conecta CRPRS",
];

$emails[] = [
    'slug' => 'novo-comentario-equipe', 'grupo' => 'Para a equipe (internos)', 'formato' => 'texto',
    'titulo' => 'Aviso de novo comentário do solicitante',
    'de' => $remetenteGlobal, 'para' => 'Responsável pela demanda; sem responsável, os gestores do setor',
    'assunto' => "🎫 [#{$num}] Novo comentário - {$titulo}",
    'quando' => 'Quando o solicitante responde por e-mail a uma demanda e a resposta vira comentário.',
    'quem' => 'O atendente responsável pela demanda. Se ainda não há responsável, os gestores do setor (ou os administradores, se não houver gestor).',
    'obs' => 'Texto puro. O corpo do comentário não vai no e-mail: só o aviso para abrir o sistema.',
    'corpo' => "Novo comentário adicionado à demanda #{$num}\n\nDemanda: {$titulo}\nAutor: {$solicitante}\nData: 23/10/2026 15:42\n\nAcesse o sistema para visualizar o comentário.\n\nAtenciosamente,\nSistema Conecta CRPRS",
];

$emails[] = [
    'slug' => 'demanda-atribuida', 'grupo' => 'Para a equipe (internos)', 'formato' => 'texto',
    'titulo' => 'Demanda atribuída para você',
    'de' => $remetenteGlobal, 'para' => "{$agente} <carlos.pereira@crprs.org.br> (atendente que recebeu a demanda)",
    'assunto' => "👤 Demanda #{$num} atribuída para você",
    'quando' => 'Quando uma demanda é atribuída a um atendente (por um gestor, administrador ou pelo próprio atendente).',
    'quem' => 'O atendente que passou a ser o responsável.',
    'obs' => 'Texto puro. O sistema também cria uma notificação no sino.',
    'corpo' => "👤 DEMANDA ATRIBUÍDA PARA VOCÊ\n\nDemanda: #{$num}\nTítulo: {$titulo}\nAtribuído por: Helena Martins\nData: 23/10/2026 14:50\n\nAcesse o sistema para atender esta demanda.\n",
];

$emails[] = [
    'slug' => 'demanda-fechada-por-outro', 'grupo' => 'Para a equipe (internos)', 'formato' => 'texto',
    'titulo' => 'Demanda fechada por outro usuário',
    'de' => $remetenteSetor, 'para' => 'Atendente responsável pela demanda (quando quem encerrou foi outra pessoa)',
    'assunto' => "🔒 Demanda #{$num} foi fechada",
    'quando' => 'Junto com o aviso "Demanda encerrada", quando quem encerrou a demanda não é o atendente responsável por ela.',
    'quem' => 'O atendente responsável, se ativo.',
    'obs' => 'Texto puro. Sai pela caixa do setor da demanda (o código seleciona o remetente do setor antes do fechamento).',
    'corpo' => "🔒 DEMANDA FECHADA POR OUTRO USUÁRIO\n\nDemanda: #{$num}\nTítulo: {$titulo}\nFechado por: Helena Martins\nData: 23/10/2026 17:20\n",
];

$emails[] = [
    'slug' => 'demanda-reaberta-equipe', 'grupo' => 'Para a equipe (internos)', 'formato' => 'texto',
    'titulo' => 'Demanda reaberta (aviso ao responsável)',
    'de' => $remetenteGlobal, 'para' => 'Atendente responsável pela demanda',
    'assunto' => "🔄 Demanda #{$num} foi reaberta",
    'quando' => 'Quando uma demanda encerrada é reaberta: por um atendente que muda o status ou automaticamente quando o solicitante responde por e-mail.',
    'quem' => 'O atendente responsável pela demanda (se houver).',
    'obs' => 'Texto puro.',
    'corpo' => "🔄 DEMANDA REABERTA\n\nDemanda: #{$num}\nTítulo: {$titulo}\nSolicitante: {$solicitante}\nData: 24/10/2026 09:05\n\nAcesse o sistema para verificar.\n",
];

$trecho = 'Maria enviou os documentos pelo e-mail e já confirmei que o CNPJ está regular. Pode seguir com a inscrição.';
$emails[] = [
    'slug' => 'comentario-entre-agentes', 'grupo' => 'Para a equipe (internos)', 'formato' => 'html',
    'titulo' => 'Novo comentário na sua demanda (de outro atendente)',
    'de' => $remetenteGlobal, 'para' => 'Atendente responsável pela demanda',
    'assunto' => "💬 Novo comentário na Demanda #{$num} - {$titulo}",
    'quando' => 'Quando outro atendente, gestor ou administrador comenta numa demanda que está atribuída a você. O status da demanda passa para "Nova Resposta".',
    'quem' => 'O atendente responsável pela demanda.',
    'obs' => 'Notas internas não geram este e-mail (só a notificação do sistema).',
    'corpo' => "
            <div style='font-family: Arial, sans-serif; color: #333; max-width: 600px; margin: 0 auto;'>
                <div style='background: #8b1d17; padding: 20px; border-radius: 8px 8px 0 0;'>
                    <h2 style='color: #fff; margin: 0; font-size: 18px;'>💬 Novo Comentário na Sua Demanda</h2>
                </div>
                <div style='background: #f9f9f9; padding: 24px; border-radius: 0 0 8px 8px; border: 1px solid #e0e0e0;'>
                    <p style='margin: 0 0 12px 0;'>Olá, <strong>{$esc($agente)}</strong>!</p>
                    <p style='margin: 0 0 16px 0;'>
                        O agente <strong>Helena Martins</strong> adicionou um novo comentário na demanda
                        <strong>#{$num}</strong> que está atribuída a você.
                    </p>
                    <div style='background: #fff; border-left: 4px solid #e0a800; padding: 14px 18px; border-radius: 4px; margin-bottom: 20px;'>
                        <p style='margin: 0 0 6px 0; font-size: 13px; color: #888;'>Demanda: <strong>#{$num}</strong> — {$esc($titulo)}</p>
                        <p style='margin: 0; font-size: 14px; color: #333;'>{$esc($trecho)}</p>
                    </div>
                    <p style='margin: 0 0 20px 0; font-size: 13px; color: #555;'>
                        O status da demanda foi atualizado para <strong>\"Nova Resposta\"</strong>.
                        Acesse o sistema para verificar e responder.
                    </p>
                    " . str_replace(['Equipe ' . $esc($setorNome), $esc($caixaSetor)], ['Conecta CRPRS', 'administrador@crprs.org.br'], $assinatura) . "
                </div>
            </div>",
];

$emails[] = [
    'slug' => 'lembrete', 'grupo' => 'Para a equipe (internos)', 'formato' => 'html',
    'titulo' => 'Lembrete de demanda',
    'de' => $remetenteGlobal, 'para' => 'Destinatário escolhido no lembrete; sem destinatário, o responsável pela demanda (ou os gestores do setor)',
    'assunto' => "🔔 [Lembrete] Demanda #{$num} - {$titulo}",
    'quando' => 'Na data e hora agendadas no lembrete (o cron de lembretes dispara a cada rodada). Lembretes de demandas encerradas ou canceladas são cancelados e não enviados.',
    'quem' => 'A pessoa escolhida ao criar o lembrete; se não houve escolha, o responsável pela demanda ou, sem responsável, os gestores do setor.',
    'obs' => 'O sistema também cria uma notificação no sino. Lembretes de demandas em "Aguardando data" voltam a demanda para "Em atendimento".',
    'corpo' => "
            <h2>🔔 Lembrete de Demanda</h2>
            <p><strong>Demanda:</strong> #{$num} - {$esc($titulo)}</p>
            <p><strong>Lembrete:</strong> Cobrar o envio do contrato social assinado</p>
            <p><strong>Data/Hora:</strong> 28/10/2026 10:00</p>
            <p><a href='https://crp07.org.br/conecta/frontend/index.html'>Acessar o Conecta CRPRS</a></p>
        ",
];

$emails[] = [
    'slug' => 'recuperacao-de-senha', 'grupo' => 'Para a equipe (internos)', 'formato' => 'html',
    'titulo' => 'Recuperação de senha',
    'de' => $remetenteGlobal, 'para' => 'O usuário que pediu a recuperação (apenas contas ativas que podem entrar no sistema)',
    'assunto' => 'Recuperação de Senha - Conecta CRPRS',
    'quando' => 'Quando alguém usa "Esqueci minha senha" na tela de login. É enviado na hora, sem passar pela fila.',
    'quem' => 'O usuário da conta. Solicitantes externos (cliente) não recebem.',
    'obs' => 'O link vale 1 hora e só pode ser usado uma vez. A resposta da tela é sempre a mesma, exista a conta ou não.',
    'corpo' => "
        <h2>Recuperação de Senha</h2>
        <p>Olá, Carlos Pereira!</p>
        <p>Você solicitou a recuperação de senha do Conecta CRPRS.</p>
        <p>Clique no link abaixo para redefinir sua senha:</p>
        <p><a href=\"#\" style=\"background-color: #f89327; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px; display: inline-block;\">Redefinir Senha</a></p>
        <p>Ou copie e cole este link no navegador:</p>
        <p>https://crp07.org.br/conecta/frontend/redefinir-senha.html?token=EXEMPLO</p>
        <p><strong>Este link expira em 1 hora.</strong></p>
        <p>Se você não solicitou esta recuperação, ignore este email.</p>
    ",
];

// ---- Gravação ---------------------------------------------------------------------------------
foreach (['emails', 'paginas', 'imagens', 'assets'] as $pasta) {
    if (!is_dir("$saida/$pasta")) {
        mkdir("$saida/$pasta", 0755, true);
    }
}
// Limpa só o que o gerador cria (nunca os PNGs, tirados à parte)
foreach (array_merge(glob("$saida/emails/*.html"), glob("$saida/paginas/*.html")) as $velho) {
    unlink($velho);
}

$n = 0;
foreach ($emails as &$m) {
    $n++;
    $m['nome'] = sprintf('%02d-%s', $n, $m['slug']);
    $texto = $m['formato'] === 'texto';

    if ($texto) {
        file_put_contents("$saida/emails/{$m['nome']}.txt", $m['corpo']);
    } else {
        // Remove o marcador de BOM e quebras que alguns modelos trazem, sem mexer no conteúdo
        file_put_contents("$saida/emails/{$m['nome']}.html", $m['corpo']);
    }
    $m['arquivo_email'] = $m['nome'] . ($texto ? '.txt' : '.html');

    $conteudo = $texto
        ? '<pre class="email-texto">' . $esc($m['corpo']) . '</pre>'
        : '<iframe class="email-quadro" title="E-mail: ' . $esc($m['titulo']) . '" srcdoc="' . $esc($m['corpo']) . '"></iframe>';

    $pagina = '<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>' . $esc($m['titulo']) . ' - Mockup de e-mail</title>
<link rel="stylesheet" href="../assets/mockup.css">
<script src="../assets/mockup.js" defer></script>
</head>
<body>
<main class="cartao">
  <p class="cartao-grupo">' . $esc($m['grupo']) . ' · ' . sprintf('%02d', $n) . ' de ' . count($emails) . '</p>
  <h1>' . $esc($m['titulo']) . '</h1>
  <section class="descricao" aria-label="Quando e para quem">
    <dl>
      <dt>Quando é enviado</dt><dd>' . $esc($m['quando']) . '</dd>
      <dt>Para quem</dt><dd>' . $esc($m['quem']) . '</dd>' . (!empty($m['obs']) ? '
      <dt>Observação</dt><dd>' . $esc($m['obs']) . '</dd>' : '') . '
    </dl>
  </section>
  <section class="cliente-email" aria-label="Pré-visualização do e-mail">
    <dl class="cabecalho-email">
      <dt>De</dt><dd>' . $esc($m['de']) . '</dd>
      <dt>Para</dt><dd>' . $esc($m['para']) . '</dd>
      <dt>Assunto</dt><dd class="assunto">' . $esc($m['assunto']) . '</dd>
      <dt>Formato</dt><dd>' . ($texto ? 'Texto puro (sem formatação)' : 'HTML') . '</dd>
    </dl>
    <div class="corpo-email">' . $conteudo . '</div>
  </section>
</main>
</body>
</html>
';
    file_put_contents("$saida/paginas/{$m['nome']}.html", $pagina);
}
unset($m);

// ---- index.html e README.md -------------------------------------------------------------------
$grupos = [];
foreach ($emails as $m) {
    $grupos[$m['grupo']][] = $m;
}

$cartoes = '';
foreach ($grupos as $grupo => $lista) {
    $cartoes .= '<h2>' . $esc($grupo) . '</h2><div class="grade">';
    foreach ($lista as $m) {
        $cartoes .= '<a class="miniatura" href="paginas/' . $m['nome'] . '.html"><img src="imagens/' . $m['nome'] . '.png" alt="Mockup: ' . $esc($m['titulo']) . '" loading="lazy"><span>' . $esc($m['titulo']) . '</span></a>';
    }
    $cartoes .= '</div>';
}
file_put_contents("$saida/index.html", '<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Mockups dos e-mails - Conecta CRPRS</title>
<link rel="stylesheet" href="assets/mockup.css">
</head>
<body>
<main class="cartao cartao-indice">
  <h1>E-mails enviados pelo Conecta CRPRS</h1>
  <p class="intro">' . count($emails) . ' mockups com dados fictícios. Clique em um para ver o momento do envio e o destinatário.</p>
  ' . $cartoes . '
</main>
</body>
</html>
');

$md  = "# Mockups dos e-mails do Conecta CRPRS\n\n";
$md .= "Todos os e-mails que o sistema envia, com dados fictícios (solicitante *Maria Souza*, demanda *#{$num}*, setor *{$setorNome}*). ";
$md .= "Abra `index.html` no navegador para a galeria, ou veja as imagens abaixo.\n\n";
$md .= "## Resumo\n\n| # | E-mail | Quando é enviado | Para quem |\n|---|---|---|---|\n";
foreach ($emails as $i => $m) {
    $md .= '| ' . ($i + 1) . ' | [' . $m['titulo'] . '](imagens/' . $m['nome'] . '.png) | ' . $m['quando'] . ' | ' . $m['quem'] . " |\n";
}
$md .= "\n## Detalhes\n";
foreach ($grupos as $grupo => $lista) {
    $md .= "\n### {$grupo}\n";
    foreach ($lista as $m) {
        $md .= "\n#### " . $m['titulo'] . "\n\n";
        $md .= "- **De:** " . $m['de'] . "\n- **Para:** " . $m['para'] . "\n- **Assunto:** " . $m['assunto'] . "\n";
        $md .= "- **Quando:** " . $m['quando'] . "\n- **Quem recebe:** " . $m['quem'] . "\n";
        if (!empty($m['obs'])) {
            $md .= "- **Observação:** " . $m['obs'] . "\n";
        }
        $md .= "\n![" . $m['titulo'] . "](imagens/" . $m['nome'] . ".png)\n";
    }
}
$md .= "\n## Como foi feito e como atualizar\n\n";
$md .= "- `gerar-mockups.php` monta tudo a partir dos modelos reais de `backend/email-template-*.html`. Rode `php mockups/gerar-mockups.php` depois de mudar um modelo ou um texto de e-mail.\n";
$md .= "- Os e-mails montados direto no código (agente→agente, lembrete, recuperação de senha e os avisos em texto puro) são cópias do texto original; atualize o catálogo do gerador quando o código mudar.\n";
$md .= "- Todos os e-mails ao solicitante trazem no final a **demanda registrada** (título e descrição) e o **histórico da conversa** com o CRPRS, do mais recente para o mais antigo. Notas internas e comentários da equipe que não foram enviados ao solicitante nunca aparecem. A \"Resposta da equipe\" e a \"Cópia da resposta\" não repetem a mensagem que acabou de ser enviada.\n";
$md .= "- `emails/` guarda o e-mail puro (HTML ou texto), `paginas/` a moldura com o momento do envio, `imagens/` os PNGs.\n";
$md .= "- O logotipo dos e-mails vem de `www.crprs.org.br`; sem internet, a imagem não aparece na pré-visualização.\n\n";
$md .= "- Os e-mails internos (equipe) continuam em texto puro, exceto os avisos montados em HTML (comentário de outro atendente, lembrete e recuperação de senha).
";
file_put_contents("$saida/README.md", $md);

echo "Gerados " . count($emails) . " mockups em $saida\n";
