<?php
/**
 * Gera as 3 propostas de cabeçalho para os e-mails enviados ao solicitante (mockups/cabecalhos/).
 *
 * Uso: php mockups/cabecalhos/gerar-cabecalhos.php
 *
 * Só pré-visualização: não altera os modelos reais de backend/. Cada opção mostra (1) o cabeçalho
 * nos 7 e-mails ao solicitante — mesmo tamanho e mesmas cores em todos, só o título muda — e
 * (2) o e-mail "Demanda criada" completo com o cabeçalho e o acento de cor padronizados.
 * O HTML do cabeçalho usa tabelas e estilos embutidos de propósito: é o que os clientes de e-mail
 * (Outlook, Gmail) interpretam de forma confiável.
 */

// Só pela linha de comando: grava arquivos e não deve rodar pela web
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}


$saida = __DIR__;
$esc   = function ($t) { return htmlspecialchars((string) $t, ENT_QUOTES, 'UTF-8'); };

// Paleta oficial (mesma do Sycer)
const VINHO_600 = '#a7241c';
const VINHO_700 = '#8b1d17';
const VINHO_100 = '#f5d3d0';
const VINHO_50  = '#fbeceb';
const LARANJA   = '#f89327';
const VERDE     = '#70ab2a';
const NEUTRO_200 = '#ddd6cd';
const NEUTRO_100 = '#efeae4';
const NEUTRO_600 = '#5f5852';

const LOGO = 'http://www.crprs.org.br/assets/frontend/img/logo.png';
const INSTITUICAO = 'Conselho Regional de Psicologia do RS';

$titulos = [
    'Demanda criada', 'Resposta da equipe', 'Cópia da resposta', 'Comentário recebido',
    'Primeira resposta', 'Demanda encerrada', 'Demanda reaberta',
];

$fonte = "font-family:Arial,Helvetica,sans-serif;";

// ---- As 3 propostas ---------------------------------------------------------------------------
$opcoes = [
    'a' => [
        'nome' => 'Opção A — Faixa tricolor e título em vinho',
        'resumo' => 'Fundo branco, discreto. Faixa fina com as três cores do CRPRS no topo (vinho, laranja e verde, como no Sycer), nome da instituição em cinza pequeno e o título em vinho.',
        'cabecalho' => function (string $titulo) use ($esc, $fonte) {
            return '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="border-collapse:collapse;">'
                . '<tr><td style="padding:0;font-size:0;line-height:0;"><table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="border-collapse:collapse;"><tr>'
                . '<td width="60%" height="5" bgcolor="' . VINHO_600 . '" style="font-size:0;line-height:0;height:5px;">&nbsp;</td>'
                . '<td width="20%" height="5" bgcolor="' . LARANJA . '" style="font-size:0;line-height:0;height:5px;">&nbsp;</td>'
                . '<td width="20%" height="5" bgcolor="' . VERDE . '" style="font-size:0;line-height:0;height:5px;">&nbsp;</td>'
                . '</tr></table></td></tr>'
                . '<tr><td bgcolor="#ffffff" style="' . $fonte . 'padding:18px 24px 16px;border:1px solid ' . NEUTRO_200 . ';border-top:none;border-bottom:2px solid ' . NEUTRO_100 . ';">'
                . '<div style="' . $fonte . 'font-size:11px;line-height:14px;font-weight:bold;letter-spacing:1px;text-transform:uppercase;color:' . NEUTRO_600 . ';">' . INSTITUICAO . '</div>'
                . '<div style="' . $fonte . 'font-size:20px;line-height:26px;font-weight:bold;color:' . VINHO_700 . ';padding-top:4px;">' . $esc($titulo) . '</div>'
                . '</td></tr></table>';
        },
    ],
    'b' => [
        'nome' => 'Opção B — Barra vinho compacta',
        'resumo' => 'Uma barra vinho baixa, alinhada à esquerda, com filete laranja embaixo. Instituição em letras pequenas claras e o título em branco. Lembra o menu do Sycer.',
        'cabecalho' => function (string $titulo) use ($esc, $fonte) {
            return '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="border-collapse:collapse;">'
                . '<tr><td bgcolor="' . VINHO_600 . '" style="' . $fonte . 'padding:16px 24px 14px;border-bottom:3px solid ' . LARANJA . ';border-radius:6px 6px 0 0;">'
                . '<div style="' . $fonte . 'font-size:11px;line-height:14px;font-weight:bold;letter-spacing:1px;text-transform:uppercase;color:' . VINHO_100 . ';">' . INSTITUICAO . '</div>'
                . '<div style="' . $fonte . 'font-size:20px;line-height:26px;font-weight:bold;color:#ffffff;padding-top:4px;">' . $esc($titulo) . '</div>'
                . '</td></tr></table>';
        },
    ],
    'c' => [
        'nome' => 'Opção C — Papel timbrado: logotipo e título lado a lado',
        'resumo' => 'Fundo branco com o logotipo do CRPRS à esquerda e o título à direita, separados do corpo por um filete vinho. É a mais institucional e a que mais mostra a marca.',
        'cabecalho' => function (string $titulo) use ($esc, $fonte) {
            return '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="border-collapse:collapse;">'
                . '<tr><td bgcolor="#ffffff" style="' . $fonte . 'padding:14px 24px;border:1px solid ' . NEUTRO_200 . ';border-top:none;border-bottom:3px solid ' . VINHO_600 . ';">'
                . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="border-collapse:collapse;"><tr>'
                . '<td valign="middle" width="40%" style="padding:0;"><img src="' . LOGO . '" alt="CRPRS" width="110" style="display:block;border:0;height:auto;"></td>'
                . '<td valign="middle" align="right" style="' . $fonte . 'padding:0;">'
                . '<div style="' . $fonte . 'font-size:11px;line-height:14px;font-weight:bold;letter-spacing:1px;text-transform:uppercase;color:' . NEUTRO_600 . ';">' . INSTITUICAO . '</div>'
                . '<div style="' . $fonte . 'font-size:20px;line-height:26px;font-weight:bold;color:' . VINHO_700 . ';padding-top:4px;">' . $esc($titulo) . '</div>'
                . '</td></tr></table>'
                . '</td></tr></table>';
        },
    ],
];

// ---- E-mail completo de exemplo: "Demanda criada" já renderizado pelo gerador de mockups -------
$base = file_get_contents(dirname(__DIR__) . '/emails/01-demanda-criada.html');
// Acento do corpo padronizado: vinho em todos os e-mails (hoje varia entre laranja, verde e vinho)
function padronizarCorpo(string $html): string {
    $html = preg_replace('~<div class="header">.*?</div>~s', '{{CABECALHO}}', $html, 1);
    $html = str_replace('#f89327', VINHO_600, $html);
    return $html;
}
$corpoPadrao = padronizarCorpo($base);

foreach ($opcoes as $chave => $o) {
    $emailCompleto = str_replace('{{CABECALHO}}', $o['cabecalho']('Demanda criada'), $corpoPadrao);
    file_put_contents("$saida/opcao-$chave-email.html", $emailCompleto);

    $pilha = '';
    foreach ($titulos as $t) {
        $pilha .= '<div class="amostra-cabecalho">' . $o['cabecalho']($t) . '</div>';
    }

    $pagina = '<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>' . $esc($o['nome']) . '</title>
<link rel="stylesheet" href="../assets/mockup.css">
<script src="../assets/mockup.js" defer></script>
</head>
<body>
<main class="cartao">
  <p class="cartao-grupo">Proposta de cabeçalho para e-mails ao solicitante</p>
  <h1>' . $esc($o['nome']) . '</h1>
  <section class="descricao" aria-label="Descrição da opção">
    <dl>
      <dt>Como é</dt><dd>' . $esc($o['resumo']) . '</dd>
      <dt>Padronização</dt><dd>Mesmo tamanho (título de 20 px, instituição de 11 px) e mesmas cores em todos os e-mails; só o título muda. Sem emojis no cabeçalho. O acento do corpo (barra lateral e etiquetas) passa a ser sempre vinho.</dd>
    </dl>
  </section>
  <h2>O cabeçalho em cada um dos 7 e-mails</h2>
  <div class="pilha-cabecalhos">' . $pilha . '</div>
  <h2>E-mail completo (Demanda criada)</h2>
  <section class="cliente-email" aria-label="Pré-visualização do e-mail">
    <div class="corpo-email"><iframe class="email-quadro" title="E-mail com a ' . $esc($o['nome']) . '" srcdoc="' . $esc($emailCompleto) . '"></iframe></div>
  </section>
</main>
</body>
</html>
';
    file_put_contents("$saida/opcao-$chave.html", $pagina);
}

echo "Geradas " . count($opcoes) . " propostas em $saida\n";
