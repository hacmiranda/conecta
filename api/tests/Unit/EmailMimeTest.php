<?php
use PHPUnit\Framework\TestCase;

/**
 * Leitura de e-mails recebidos (EmailMime) com amostras .eml anonimizadas de clientes reais
 * (api/tests/fixtures/emails/), convertidas para a estrutura do IMAP por EmlParaEstrutura.
 */
final class EmailMimeTest extends TestCase {

    /** @return array{0: string, 1: array} corpo e anexos da amostra */
    private function ler(string $amostra): array {
        [$estrutura, $secoes] = EmlParaEstrutura::ler(__DIR__ . '/../fixtures/emails/' . $amostra . '.eml');
        $buscar = fn(string $secao) => $secoes[$secao] ?? '';
        return [EmailMime::corpoTexto($estrutura, $buscar), EmailMime::anexos($estrutura, $buscar)];
    }

    public function test_outlook_com_anexo_traz_o_texto_e_ignora_o_logo_da_assinatura(): void {
        [$corpo, $anexos] = $this->ler('outlook-anexo-e-assinatura');
        // Antes: texto em multipart/mixed > related > alternative ficava vazio
        $this->assertStringContainsString('Solicito a certidão de regularidade.', $corpo, 'Latin-1 convertido');
        $this->assertStringContainsString("Atenciosamente,\nFulana", $corpo);
        $this->assertSame(['comprovante.pdf'], array_column($anexos, 'nome'), 'logo pequeno da assinatura não conta');
        $this->assertStringStartsWith('%PDF', $anexos[0]['conteudo']);
    }

    public function test_somente_html_vira_texto_sem_css_nem_script_e_com_quebras(): void {
        [$corpo] = $this->ler('somente-html');
        $this->assertStringNotContainsString('color:red', $corpo);
        $this->assertStringNotContainsString('alert', $corpo);
        $this->assertSame("Olá,\nGostaria de saber:\nprazos & valores\n• Pessoa física\n• Pessoa jurídica\nObrigada !", $corpo);
    }

    public function test_apple_mail_texto_inline_e_print_de_tela(): void {
        [$corpo, $anexos] = $this->ler('apple-texto-inline-e-print');
        // Antes: a parte de texto "inline" era descartada como se fosse anexo
        $this->assertSame('Não consigo emitir o boleto. Veja o print.', $corpo);
        $this->assertSame(['Captura de Tela.jpg'], array_column($anexos, 'nome'), 'imagem grande colada no corpo entra como anexo');
    }

    public function test_email_simples_em_latin1(): void {
        [$corpo, $anexos] = $this->ler('simples-latin1');
        // Antes: o e-mail sem partes não tinha o charset convertido
        $this->assertSame("Olá, preciso de informações sobre a inscrição.\nObrigado.", $corpo);
        $this->assertSame([], $anexos);
    }

    public function test_nomes_de_arquivo_rfc2231_e_encoded_word(): void {
        [, $anexos] = $this->ler('nomes-rfc2231');
        $this->assertSame(['relatório anual.pdf', 'declaração de vínculo.pdf', 'ofício nº 3.pdf'], array_column($anexos, 'nome'));
    }

    public function test_email_encaminhado_nao_mistura_o_corpo_interno(): void {
        [$corpo, $anexos] = $this->ler('encaminhado');
        $this->assertSame('Encaminho a mensagem abaixo para análise.', $corpo);
        $this->assertSame(['mensagem_encaminhada_1.eml'], array_column($anexos, 'nome'));
        // O UploadService recusa .eml: vira aviso de anexo rejeitado, não some calado
        $this->assertFalse(UploadService::validar('mensagem_encaminhada_1.eml', 'message/rfc822', 100)['ok']);
    }

    public function test_imagem_pequena_com_content_id_e_embutida_mesmo_marcada_como_anexo(): void {
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
        $imagem = fn(array $extra) => (object) array_merge(['type' => 5, 'subtype' => 'PNG', 'encoding' => 3, 'ifdisposition' => 1, 'disposition' => 'ATTACHMENT',
            'dparameters' => [(object) ['attribute' => 'FILENAME', 'value' => 'icon.png']]], $extra);
        $buscar = fn() => base64_encode($png);
        $texto = (object) ['type' => 0, 'subtype' => 'PLAIN', 'encoding' => 0];

        // Ícone do Gmail/logo do Outlook: "attachment" + Content-ID → embutida, não é anexo
        $comId = (object) ['type' => 1, 'subtype' => 'MIXED', 'parts' => [$texto, $imagem(['ifid' => 1, 'id' => '<icon.png>'])]];
        $this->assertSame([], EmailMime::anexos($comId, $buscar));

        // Imagem pequena anexada de verdade (sem Content-ID) entra
        $semId = (object) ['type' => 1, 'subtype' => 'MIXED', 'parts' => [$texto, $imagem([])]];
        $this->assertSame(['icon.png'], array_column(EmailMime::anexos($semId, $buscar), 'nome'));
    }

    public function test_nome_de_arquivo_nunca_traz_caminho(): void {
        $parte = (object) ['dparameters' => [(object) ['attribute' => 'FILENAME', 'value' => 'C:\\Users\\x\\..\\segredo.pdf']]];
        $this->assertSame('segredo.pdf', EmailMime::nomeArquivo($parte));
    }

    public function test_charset_desconhecido_nao_quebra_o_utf8(): void {
        $this->assertTrue(mb_check_encoding(EmailMime::paraUtf8("Ol\xE1", 'x-desconhecido'), 'UTF-8'));
        $this->assertSame('Olá', EmailMime::paraUtf8("Ol\xE1", 'UTF-8'), 'UTF-8 declarado mas inválido: Windows-1252');
    }
}
