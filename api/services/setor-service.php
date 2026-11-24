<?php
/**
 * Regras de criação de setor, em um lugar só: usadas por setores.php (tela) e pelo importador do METRICA360.
 */
class SetorService {

    /**
     * Cria o setor já pronto para uso: todos os status e prioridades ativos, expediente e feriados
     * recorrentes herdados do setor Geral (cada setor edita os seus depois). As categorias são próprias do setor.
     * Não confere nome repetido (quem chama decide o que fazer) e usa a transação de quem chama, se houver.
     *
     * @return int id do setor criado
     */
    public static function criar(PDO $db, string $nome): int {
        $db->prepare("INSERT INTO setores (nome, ativo) VALUES (?, 1)")->execute([$nome]);
        $setorId = (int) $db->lastInsertId();

        $db->prepare("INSERT INTO horarios_uteis (setor_id, dia_semana, hora_inicio, hora_fim, ativo)
                      SELECT ?, h.dia_semana, h.hora_inicio, h.hora_fim, h.ativo
                      FROM horarios_uteis h INNER JOIN setores g ON g.id = h.setor_id AND g.nome = 'Geral'")
           ->execute([$setorId]);
        $db->prepare("INSERT INTO feriados (setor_id, `data`, descricao, recorrente)
                      SELECT ?, f.`data`, f.descricao, f.recorrente
                      FROM feriados f INNER JOIN setores g ON g.id = f.setor_id AND g.nome = 'Geral'
                      WHERE f.recorrente = 1")
           ->execute([$setorId]);
        $db->prepare("INSERT INTO setor_status (setor_id, status_id, ativo, ordem) SELECT ?, id, 1, ordem FROM ticket_status")
           ->execute([$setorId]);
        $db->prepare("INSERT INTO setor_prioridades (setor_id, prioridade_id, ativo, ordem) SELECT ?, id, 1, ordem FROM ticket_prioridades")
           ->execute([$setorId]);

        return $setorId;
    }

    /**
     * Setor de triagem: para onde vai a demanda que não tem setor de destino (integração dos sistemas satélites,
     * caixa de e-mail global antiga). É o setor do .env SETOR_TRIAGEM_LOCAL/PROD (id), se existir e estiver ativo;
     * senão o "Geral", se estiver ativo; senão null — e quem chama recusa, para nada nascer sem setor.
     * Assim o Geral pode ser desativado depois de configurar outro setor de triagem.
     *
     * @param string|null $configurado id vindo do .env (null = lê Environment::get('setor_triagem'))
     */
    public static function setorDeTriagem(PDO $db, ?string $configurado = null): ?int {
        $configurado ??= (string) Environment::get('setor_triagem', '');
        $ativo = $db->prepare("SELECT id FROM setores WHERE id = ? AND ativo = 1");
        if (ctype_digit(trim($configurado))) {
            $ativo->execute([(int) $configurado]);
            $id = $ativo->fetchColumn();
            if ($id) return (int) $id;
        }
        $geral = $db->query("SELECT id FROM setores WHERE nome = 'Geral' AND ativo = 1 LIMIT 1")->fetchColumn();
        return $geral ? (int) $geral : null;
    }
}
