<?php
/**
 * Catálogo dos e-mails que o sistema envia e se cada um está ligado para um setor
 * (`setor_notificacoes_email`). Ausência de linha = ativado: um setor novo, ou um código novo
 * criado depois, nasce com tudo ligado — só o que foi explicitamente desligado fica registrado.
 *
 * A recuperação de senha não entra aqui: não tem setor e é sempre enviada (segurança da conta).
 */
class NotificacaoEmailService {
    const GRUPO_SOLICITANTE = 'solicitante';
    const GRUPO_INTERNO = 'interno';

    /**
     * código => [grupo, nome, quando é enviado]. A ordem é a ordem de exibição na tela.
     * Mesmos textos de mockups/README.md (mantenha os dois em sincronia se um e-mail mudar).
     */
    const CATALOGO = [
        'sol_demanda_criada' => [
            self::GRUPO_SOLICITANTE, 'Demanda criada',
            'Assim que a demanda é aberta (e-mail recebido, formulário do sistema ou integração). Inclui o aviso de anexos recusados, quando houver.',
        ],
        'sol_resposta_equipe' => [
            self::GRUPO_SOLICITANTE, 'Resposta da equipe',
            'Quando um atendente comenta na demanda com a opção "Enviar por e-mail" marcada (inclusive em "Enviar e Fechar").',
        ],
        'sol_copia_resposta' => [
            self::GRUPO_SOLICITANTE, 'Cópia da resposta',
            'Junto com a "Resposta da equipe", para cada endereço informado no campo "Com cópia".',
        ],
        'sol_comentario_recebido' => [
            self::GRUPO_SOLICITANTE, 'Comentário recebido',
            'Quando o solicitante responde por e-mail a uma demanda e a resposta vira comentário.',
        ],
        'sol_primeira_resposta' => [
            self::GRUPO_SOLICITANTE, 'Primeira resposta',
            'Quando uma demanda sem responsável é atribuída a um atendente pela primeira vez.',
        ],
        'sol_demanda_encerrada' => [
            self::GRUPO_SOLICITANTE, 'Demanda encerrada',
            'Quando a demanda é encerrada (status que a move para o Arquivo).',
        ],
        'sol_demanda_reaberta' => [
            self::GRUPO_SOLICITANTE, 'Demanda reaberta',
            'Quando uma demanda encerrada é reaberta automaticamente, por resposta do solicitante.',
        ],
        'sol_pesquisa_satisfacao' => [
            self::GRUPO_SOLICITANTE, 'Pesquisa de satisfação',
            'Na primeira vez que a demanda de um solicitante externo (fora de @crprs.org.br) é resolvida ou encerrada. Enviada pelo METRICA360, que cuida dos prazos, reenvio e notas.',
        ],
        'int_nova_demanda' => [
            self::GRUPO_INTERNO, 'Aviso de nova demanda',
            'Junto com a confirmação ao solicitante, para os gestores do setor da demanda.',
        ],
        'int_novo_comentario_solicitante' => [
            self::GRUPO_INTERNO, 'Aviso de novo comentário do solicitante',
            'Quando o solicitante responde por e-mail, para o responsável pela demanda (ou os gestores do setor, sem responsável).',
        ],
        'int_demanda_atribuida' => [
            self::GRUPO_INTERNO, 'Demanda atribuída para você',
            'Quando uma demanda é atribuída a um atendente.',
        ],
        'int_demanda_fechada_por_outro' => [
            self::GRUPO_INTERNO, 'Demanda fechada por outro usuário',
            'Junto com "Demanda encerrada", quando quem encerrou não é o atendente responsável.',
        ],
        'int_demanda_reaberta' => [
            self::GRUPO_INTERNO, 'Demanda reaberta (aviso ao responsável)',
            'Quando uma demanda encerrada é reaberta, para o atendente responsável.',
        ],
        'int_comentario_entre_agentes' => [
            self::GRUPO_INTERNO, 'Novo comentário na sua demanda (de outro atendente)',
            'Quando outro atendente, gestor ou administrador comenta numa demanda atribuída a você.',
        ],
        'int_lembrete' => [
            self::GRUPO_INTERNO, 'Lembrete de demanda',
            'Na data e hora agendadas no lembrete.',
        ],
    ];

    // Cache por requisição: {setor_id}:{codigo} => bool
    private static array $cache = [];

    /** Verdadeiro se não há setor (remetente global) ou se não há linha desligando o código. */
    public static function ativa(PDO $db, ?int $setorId, string $codigo): bool {
        if (!$setorId) {
            return true;
        }
        $chave = $setorId . ':' . $codigo;
        if (!array_key_exists($chave, self::$cache)) {
            $stmt = $db->prepare('SELECT ativo FROM setor_notificacoes_email WHERE setor_id = ? AND codigo = ?');
            $stmt->execute([$setorId, $codigo]);
            $valor = $stmt->fetchColumn();
            self::$cache[$chave] = $valor === false ? true : (bool) $valor;
        }
        return self::$cache[$chave];
    }

    /** Limpa o cache (usado pelos testes e depois de uma gravação, para refletir a mudança na mesma requisição). */
    public static function limparCache(): void {
        self::$cache = [];
    }

    public static function nomeGrupo(string $grupo): string {
        return $grupo === self::GRUPO_SOLICITANTE ? 'Para solicitantes' : 'Para agentes internos';
    }
}
