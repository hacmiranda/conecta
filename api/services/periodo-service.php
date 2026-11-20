<?php
/**
 * Período escolhido nos painéis (Dashboard e a seção E-mails Enviados): validação e período anterior.
 * Funções puras (recebem "hoje"), testadas em PeriodoServiceTest.
 */
class PeriodoService {

    /** Período máximo, em dias */
    const MAX_DIAS = 366;

    /**
     * Valida o período (datas AAAA-MM-DD, início ≤ fim, sem futuro, até 366 dias).
     * Sem as duas datas: de $padraoInicio até hoje.
     *
     * @return array{0:string,1:string} [inicio, fim]
     * @throws InvalidArgumentException com a mensagem para a pessoa
     */
    public static function normalizar(?string $inicio, ?string $fim, DateTimeImmutable $hoje, string $padraoInicio): array {
        $hojeTxt = $hoje->format('Y-m-d');
        $inicio = trim((string) $inicio);
        $fim = trim((string) $fim);
        if ($inicio === '' && $fim === '') {
            return [$padraoInicio, $hojeTxt];
        }
        $di = DateTimeImmutable::createFromFormat('!Y-m-d', $inicio);
        $df = DateTimeImmutable::createFromFormat('!Y-m-d', $fim);
        if (!$di || $di->format('Y-m-d') !== $inicio || !$df || $df->format('Y-m-d') !== $fim) {
            throw new InvalidArgumentException('Informe as duas datas do período.');
        }
        if ($inicio > $fim) {
            throw new InvalidArgumentException('A data inicial deve ser anterior ou igual à final.');
        }
        if ($fim > $hojeTxt) {
            throw new InvalidArgumentException('O período não pode terminar no futuro.');
        }
        if ($di->diff($df)->days + 1 > self::MAX_DIAS) {
            throw new InvalidArgumentException('O período pode ter no máximo ' . self::MAX_DIAS . ' dias.');
        }
        return [$inicio, $fim];
    }

    /** Quantidade de dias do período, contando os dois extremos */
    public static function dias(string $inicio, string $fim): int {
        return (new DateTimeImmutable($inicio))->diff(new DateTimeImmutable($fim))->days + 1;
    }

    /**
     * Período anterior de mesmo tamanho, terminando na véspera do início (base da tendência).
     * @return array{0:string,1:string}
     */
    public static function anterior(string $inicio, string $fim): array {
        $dias = self::dias($inicio, $fim);
        $fimAnt = (new DateTimeImmutable($inicio))->modify('-1 day');
        return [$fimAnt->modify('-' . ($dias - 1) . ' days')->format('Y-m-d'), $fimAnt->format('Y-m-d')];
    }

    /**
     * Pontos da série temporal: por dia até 92 dias; acima disso, por mês.
     * @return array{granularidade:string, chaves:string[]} chaves AAAA-MM-DD (dia) ou AAAA-MM (mês)
     */
    public static function pontos(string $inicio, string $fim): array {
        $porDia = self::dias($inicio, $fim) <= 92;
        $chaves = [];
        $cursor = new DateTimeImmutable($porDia ? $inicio : substr($inicio, 0, 7) . '-01');
        $limite = new DateTimeImmutable($fim);
        while ($cursor <= $limite) {
            $chaves[] = $cursor->format($porDia ? 'Y-m-d' : 'Y-m');
            $cursor = $cursor->modify($porDia ? '+1 day' : '+1 month');
        }
        return ['granularidade' => $porDia ? 'dia' : 'mes', 'chaves' => $chaves];
    }
}
