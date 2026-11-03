<?php
use PHPUnit\Framework\TestCase;

/**
 * Cron de e-mail (auditoria 01/11/2026): anexo grande ou proibido recusado sem baixar (BUG-08), só as
 * mensagens novas são lidas (DES-01) e DOCX/XLSX precisam ser pacotes do Office de verdade (SEG-09).
 * Sem IMAP e sem banco.
 */
final class EmailCronRobustezTest extends TestCase {

    /** Parte MIME no formato de imap_fetchstructure(). */
    private function parte(int $tipo, string $subtipo, int $encoding, int $bytes, ?string $nome, string $disposicao = 'attachment'): object {
        $p = (object) ['type' => $tipo, 'subtype' => strtoupper($subtipo), 'encoding' => $encoding, 'bytes' => $bytes, 'ifdisposition' => 1, 'disposition' => $disposicao];
        $p->dparameters = $nome !== null ? [(object) ['attribute' => 'filename', 'value' => $nome]] : [];
        $p->parameters = [];
        return $p;
    }

    private function estrutura(array $partes): object {
        $texto = (object) ['type' => 0, 'subtype' => 'PLAIN', 'encoding' => 0, 'bytes' => 20, 'parameters' => [(object) ['attribute' => 'charset', 'value' => 'UTF-8']]];
        return (object) ['type' => 1, 'subtype' => 'MIXED', 'parts' => array_merge([$texto], $partes)];
    }

    public function test_anexo_de_40mb_e_recusado_sem_ser_baixado(): void {
        $estrutura = $this->estrutura([$this->parte(3, 'pdf', 3, (int) (40 * 1024 * 1024 * 4 / 3), 'enorme.pdf')]);
        $baixadas = [];
        $buscar = function (string $secao) use (&$baixadas) {
            $baixadas[] = $secao;
            return $secao === '1' ? 'texto' : '';
        };

        $anexos = EmailMime::anexos($estrutura, $buscar, [EmailService::class, 'motivoRecusaAntesDeBaixar']);

        $this->assertSame([], $baixadas, 'o anexo não pode ser lido para a memória');
        $this->assertCount(1, $anexos);
        $this->assertSame('enorme.pdf', $anexos[0]['nome']);
        $this->assertSame('Tamanho excede 20MB', $anexos[0]['recusado']);
        $this->assertSame('', $anexos[0]['conteudo']);
    }

    public function test_extensao_proibida_e_recusada_sem_baixar(): void {
        $estrutura = $this->estrutura([$this->parte(3, 'zip', 3, 5000, 'fotos.zip')]);
        $anexos = EmailMime::anexos($estrutura, function () { $this->fail('não deveria baixar'); }, [EmailService::class, 'motivoRecusaAntesDeBaixar']);
        $this->assertSame('Tipo não permitido', $anexos[0]['recusado']);
    }

    public function test_anexo_permitido_continua_sendo_baixado(): void {
        $pdf = "%PDF-1.4\n%%EOF\n";
        $estrutura = $this->estrutura([$this->parte(3, 'pdf', 3, (int) ceil(strlen($pdf) * 4 / 3), 'ok.pdf')]);
        $anexos = EmailMime::anexos($estrutura, fn($s) => $s === '2' ? base64_encode($pdf) : 'texto', [EmailService::class, 'motivoRecusaAntesDeBaixar']);
        $this->assertSame($pdf, $anexos[0]['conteudo']);
        $this->assertArrayNotHasKey('recusado', $anexos[0]);
    }

    public function test_logotipo_pequeno_da_assinatura_nao_passa_pelo_pre_filtro(): void {
        // GIF pequeno embutido: continua descartado em silêncio (não vira "anexo recusado")
        $gif = base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7');
        $estrutura = $this->estrutura([$this->parte(5, 'gif', 3, 80, 'image001.gif', 'inline')]);
        $anexos = EmailMime::anexos($estrutura, fn($s) => $s === '2' ? base64_encode($gif) : 'texto', [EmailService::class, 'motivoRecusaAntesDeBaixar']);
        $this->assertSame([], $anexos);
    }

    public function test_tamanho_estimado_nunca_passa_do_real_em_base64(): void {
        $real = 21 * 1024 * 1024;
        $codificado = strlen(chunk_split(base64_encode(str_repeat('a', 3000)), 76, "\r\n")) * ($real / 3000);
        $estimado = EmailMime::tamanhoEstimado((object) ['bytes' => (int) $codificado, 'encoding' => 3]);
        $this->assertLessThanOrEqual($real, $estimado);
        $this->assertGreaterThan(UploadService::MAX_BYTES_ARQUIVO, $estimado, '21 MB reais continuam acima do limite');
        $this->assertNull(EmailMime::tamanhoEstimado((object) ['bytes' => 100, 'encoding' => 4]), 'quoted-printable não é estimado');
        $this->assertNull(EmailMime::tamanhoEstimado((object) ['encoding' => 3]), 'sem bytes, sem estimativa');
    }

    public function test_so_as_mensagens_nao_processadas_seguem(): void {
        $visao = [
            (object) ['uid' => 12, 'msgno' => 3, 'message_id' => '<c@x>'],
            (object) ['uid' => 10, 'msgno' => 1, 'message_id' => '<a@x>'],
            (object) ['uid' => 11, 'msgno' => 2, 'message_id' => '<b@x>'],
            (object) ['uid' => 13, 'msgno' => 4],
        ];
        $pendentes = EmailService::filtrarPendentes($visao, ['<a@x>', '<c@x>']);
        $this->assertSame([
            ['uid' => 11, 'msgno' => 2, 'message_id' => '<b@x>'],
            ['uid' => 13, 'msgno' => 4, 'message_id' => null],
        ], $pendentes, 'sem Message-ID na visão geral: segue para conferir pelo cabeçalho');
    }

    public function test_memoria_de_uids_so_confere_os_desconhecidos(): void {
        $this->assertSame([14, 15], EmailService::uidsAConferir([10, 11, 12, 13, 14, 15], [10, 11, 12, 13, 9]));
        $this->assertSame([10, 11], EmailService::uidsAConferir([10, 11], []), 'sem memória: confere todas');
    }

    public function test_memoria_de_uids_fica_so_com_a_janela(): void {
        // 9 saiu da janela de busca; 15 falhou (não foi tratado) e fica de fora para ser tentado de novo
        $this->assertSame([10, 11, 12, 14], EmailService::novaMemoriaUids([10, 11, 12, 14, 15], [9, 10, 11], [12, 14]));
    }

    public function test_motivo_de_recusa_antes_de_baixar(): void {
        $this->assertNull(EmailService::motivoRecusaAntesDeBaixar('a.PDF', 1000));
        $this->assertSame('Tipo não permitido', EmailService::motivoRecusaAntesDeBaixar('a.exe', 1000));
        $this->assertSame('Tamanho excede 20MB', EmailService::motivoRecusaAntesDeBaixar('a.pdf', UploadService::MAX_BYTES_ARQUIVO + 1));
    }

    public function test_zip_renomeado_para_docx_e_recusado(): void {
        if (!class_exists('ZipArchive')) {
            $this->markTestSkipped('Extensão zip ausente');
        }
        $zipQualquer = $this->zip(['fotos/a.txt' => 'x']);
        $docx = $this->zip(['[Content_Types].xml' => '<Types/>', 'word/document.xml' => '<w:document/>']);

        $r = UploadService::validarConteudo('contrato.docx', 'application/zip', $zipQualquer);
        $this->assertFalse($r['ok']);
        $this->assertSame('Conteúdo não corresponde ao tipo do arquivo', $r['motivo']);
        $this->assertTrue(UploadService::validarConteudo('contrato.docx', 'application/zip', $docx)['ok']);
        $this->assertFalse(UploadService::validarConteudo('planilha.xlsx', 'application/zip', $docx)['ok'], 'pacote do Word com extensão de Excel');
    }

    private function zip(array $arquivos): string {
        $caminho = tempnam(sys_get_temp_dir(), 'tz');
        $zip = new ZipArchive();
        $zip->open($caminho, ZipArchive::OVERWRITE);
        foreach ($arquivos as $nome => $conteudo) {
            $zip->addFromString($nome, $conteudo);
        }
        $zip->close();
        $dados = (string) file_get_contents($caminho);
        @unlink($caminho);
        return $dados;
    }
}
