<?php
require_once __DIR__ . '/setor-service.php';
require_once __DIR__ . '/usuario-service.php';
require_once __DIR__ . '/senha-service.php';

/**
 * Importação das pessoas, setores e configurações do METRICA360 para o Conecta (unificação de 03/11/2026).
 * Usada por database/importar-metrica360.php. Não traz convites, avaliações nem auditoria (eram de teste).
 *
 * `planejar()` é pura: recebe as listas lidas dos dois bancos e devolve o que seria feito, sem tocar em nada
 * (por isso é testável sem banco e serve ao ensaio). `executar()` aplica o plano numa transação.
 *
 * Os setores são ligados pelo NOME (sem diferenciar maiúsculas e acentos), nunca pelo id: os ids dos dois
 * sistemas estão trocados (Cadastro e Cobrança).
 */
class ImportacaoMetrica360 {

    /** Papel do METRICA360 (users.role) → papel do Conecta (usuarios.tipo) */
    const PAPEIS = [
        'admin'              => 'admin',
        'atendente'          => 'operador',
        'operador'           => 'operador',
        'coordenador'        => 'coordenador',
        'supervisora'        => 'supervisor',
        'coordenadora_geral' => 'coordenador_geral',
    ];

    /** Setor de lotação de quem não tem setor no METRICA360 (e precisa de um no Conecta) */
    const SETOR_PADRAO = 'Geral';

    /** Regras do METRICA360 (configuracoes.chave) → chave do Conecta (pesquisa_config.chave) */
    const CONFIG = [
        'quarentena_ativa'           => 'quarentena_ativa',
        'quarentena_dias'            => 'quarentena_dias',
        'reenvio_horas'              => 'reenvio_horas',
        'expiracao_token_dias'       => 'expiracao_token_dias',
        'meta_nota_media'            => 'meta_nota_media',
        'notificar_feedback_critico' => 'notificar_avaliacao_critica',
        'email_notificacao_critico'  => 'email_avaliacao_critica',
        'google_review_url'          => 'google_review_url',
    ];

    /** Minúsculas, sem acentos e com espaços colapsados: chave de comparação de nomes de setor */
    public static function normalizar(string $texto): string {
        $t = trim($texto);
        $semAcento = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $t);
        if ($semAcento !== false && $semAcento !== '') {
            $t = $semAcento;
        }
        $t = strtolower(preg_replace('/[^A-Za-z0-9\s]/', '', $t));
        return trim(preg_replace('/\s+/', ' ', $t));
    }

    public static function papelNoConecta(?string $role): ?string {
        return self::PAPEIS[strtolower(trim((string) $role))] ?? null;
    }

    /**
     * Monta o plano da importação.
     *
     * @param array[] $setoresOrigem  [{id, nome, ativo_painel, coordenador_id}]
     * @param array[] $usuariosOrigem [{id, email, name, username, role, setor_id, atendimento_externo}] só os ativos
     * @param array[] $setoresDestino [{id, nome}]
     * @param array[] $usuariosDestino [{id, email, nome, tipo, setor_id, atendimento_externo}]
     * @return array{setores: array[], usuarios: array[], avisos: string[]}
     */
    public static function planejar(array $setoresOrigem, array $usuariosOrigem, array $setoresDestino, array $usuariosDestino): array {
        $avisos = [];

        $destinoPorChave = [];   // chave do nome → setor do Conecta
        $destinoNomePorId = [];
        foreach ($setoresDestino as $s) {
            $destinoPorChave[self::normalizar($s['nome'])] = $s;
            $destinoNomePorId[(int) $s['id']] = $s['nome'];
        }
        $origemPorId = [];
        foreach ($setoresOrigem as $s) {
            $origemPorId[(int) $s['id']] = $s;
        }
        $usuarioOrigemPorId = [];
        foreach ($usuariosOrigem as $u) {
            $usuarioOrigemPorId[(int) $u['id']] = $u;
        }

        // Setores que entram: os que têm servidor ativo (lotado ou como coordenador)
        $entram = [];
        foreach ($usuariosOrigem as $u) {
            if (!empty($u['setor_id']) && isset($origemPorId[(int) $u['setor_id']])) {
                $entram[(int) $u['setor_id']] = true;
            }
        }
        foreach ($setoresOrigem as $s) {
            if (!empty($s['coordenador_id']) && isset($usuarioOrigemPorId[(int) $s['coordenador_id']])) {
                $entram[(int) $s['id']] = true;
            }
        }

        $setores = [];
        $chavesVistas = [];
        foreach ($setoresOrigem as $s) {
            if (!isset($entram[(int) $s['id']])) {
                continue;
            }
            $chave = self::normalizar($s['nome']);
            if ($chave === '' || isset($chavesVistas[$chave])) {
                $avisos[] = 'Setor repetido ou sem nome no METRICA360 ignorado: "' . $s['nome'] . '".';
                continue;
            }
            $chavesVistas[$chave] = true;
            $existe = $destinoPorChave[$chave] ?? null;
            $setores[] = [
                'chave'          => $chave,
                'nome'           => $existe ? $existe['nome'] : trim($s['nome']),
                'origem_id'      => (int) $s['id'],
                'acao'           => $existe ? 'existe' : 'criar',
                'destino_id'     => $existe ? (int) $existe['id'] : null,
                'pesquisa_painel' => !empty($s['ativo_painel']),
            ];
        }
        $chaveDoSetorOrigem = [];
        foreach ($setores as $s) {
            $chaveDoSetorOrigem[$s['origem_id']] = $s['chave'];
        }
        $nomeDaChave = [];
        foreach ($setores as $s) {
            $nomeDaChave[$s['chave']] = $s['nome'];
        }
        $chavePadrao = self::normalizar(self::SETOR_PADRAO);
        if (!isset($nomeDaChave[$chavePadrao]) && isset($destinoPorChave[$chavePadrao])) {
            $nomeDaChave[$chavePadrao] = $destinoPorChave[$chavePadrao]['nome'];
        }

        // Quem já coordena cada setor no Conecta (contas existentes): um coordenador por setor
        $coordenadorDaChave = [];
        foreach ($usuariosDestino as $d) {
            if (($d['tipo'] ?? '') === 'coordenador' && !empty($d['setor_id']) && isset($destinoNomePorId[(int) $d['setor_id']])) {
                $coordenadorDaChave[self::normalizar($destinoNomePorId[(int) $d['setor_id']])] = strtolower($d['email']);
            }
        }

        $destinoPorEmail = [];
        foreach ($usuariosDestino as $d) {
            $destinoPorEmail[strtolower(trim($d['email']))] = $d;
        }

        $usuarios = [];
        $emailsVistos = [];
        foreach ($usuariosOrigem as $u) {
            $email = strtolower(trim((string) $u['email']));
            $avisosDoUsuario = [];
            if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $avisos[] = 'Usuário sem e-mail válido ignorado: ' . $u['name'];
                continue;
            }
            if (strlen($email) > 100) {
                $avisos[] = 'E-mail com mais de 100 caracteres ignorado (limite do Conecta): ' . $email;
                continue;
            }
            if (isset($emailsVistos[$email])) {
                continue;
            }
            $emailsVistos[$email] = true;
            $tipo = self::papelNoConecta($u['role']);
            if ($tipo === null) {
                $avisos[] = 'Papel desconhecido "' . $u['role'] . '" ignorado: ' . $email;
                continue;
            }

            $principal = null;
            $adicionais = [];
            if ($tipo === 'admin') {
                $principal = null;
            } else {
                $meuSetor = !empty($u['setor_id']) ? ($chaveDoSetorOrigem[(int) $u['setor_id']] ?? null) : null;
                if ($tipo === 'coordenador') {
                    $coordenados = [];
                    foreach ($setoresOrigem as $s) {
                        if ((int) ($s['coordenador_id'] ?? 0) === (int) $u['id'] && isset($chaveDoSetorOrigem[(int) $s['id']])) {
                            $coordenados[] = $chaveDoSetorOrigem[(int) $s['id']];
                        }
                    }
                    sort($coordenados);
                    if ($coordenados) {
                        $principal = ($meuSetor !== null && in_array($meuSetor, $coordenados, true)) ? $meuSetor : $coordenados[0];
                        $adicionais = array_values(array_diff($coordenados, [$principal]));
                    } else {
                        $principal = $meuSetor;
                    }
                    // Um coordenador por setor (conta já existente no Conecta)
                    $livres = [];
                    foreach (array_merge([$principal], $adicionais) as $chave) {
                        $dono = $coordenadorDaChave[$chave] ?? null;
                        if ($chave !== null && $dono !== null && $dono !== $email) {
                            $avisosDoUsuario[] = 'O setor "' . ($nomeDaChave[$chave] ?? $chave) . '" já tem outro coordenador no Conecta: não foi atribuído.';
                            continue;
                        }
                        $livres[] = $chave;
                    }
                    $principal = $livres[0] ?? null;
                    $adicionais = array_slice($livres, 1);
                    if ($principal === null) {
                        $tipo = 'gestor';
                        $principal = $meuSetor;
                        $avisosDoUsuario[] = 'Sem setor livre para coordenar: entra como Gestor.';
                    } else {
                        foreach ($livres as $chave) {
                            $coordenadorDaChave[$chave] = $email;
                        }
                    }
                } else {
                    $principal = $meuSetor;
                }
                if ($principal === null) {
                    $principal = $chavePadrao;
                    $avisosDoUsuario[] = 'Sem setor no METRICA360: lotado em ' . self::SETOR_PADRAO . ' (ajuste na tela de usuários).';
                }
            }

            $existente = $destinoPorEmail[$email] ?? null;
            $linha = [
                'email'               => $email,
                'nome'                => trim((string) $u['name']),
                'username_origem'     => trim((string) ($u['username'] ?? '')),
                'tipo'                => $tipo,
                'setor_chave'         => $principal,
                'setor_nome'          => $principal !== null ? ($nomeDaChave[$principal] ?? $principal) : null,
                'adicionais_chaves'   => $adicionais,
                'adicionais_nomes'    => array_map(fn($c) => $nomeDaChave[$c] ?? $c, $adicionais),
                'atendimento_externo' => !empty($u['atendimento_externo']),
                'acao'                => $existente ? 'existe' : 'criar',
                'destino_id'          => $existente ? (int) $existente['id'] : null,
                'atualizar_atendimento_externo' => false,
                'diferencas'          => [],
                'avisos'              => $avisosDoUsuario,
            ];
            if ($existente) {
                // Conta que já existe: não muda papel nem setor; o que diverge vai para o relatório
                if (($existente['tipo'] ?? '') !== $tipo) {
                    $linha['diferencas'][] = 'papel: no Conecta é ' . $existente['tipo'] . ', no METRICA360 seria ' . $tipo;
                }
                $setorAtual = !empty($existente['setor_id']) ? ($destinoNomePorId[(int) $existente['setor_id']] ?? null) : null;
                if (self::normalizar((string) $setorAtual) !== self::normalizar((string) ($linha['setor_nome'] ?? ''))) {
                    $linha['diferencas'][] = 'setor: no Conecta é "' . ($setorAtual ?? 'nenhum') . '", no METRICA360 seria "' . ($linha['setor_nome'] ?? 'nenhum') . '"';
                }
                $linha['atualizar_atendimento_externo'] = $linha['atendimento_externo'] && empty($existente['atendimento_externo']);
            }
            $usuarios[] = $linha;
        }

        return ['setores' => $setores, 'usuarios' => $usuarios, 'avisos' => $avisos];
    }

    /** Texto do relatório do ensaio/execução */
    public static function relatorio(array $plano, array $origemContagens, bool $executado): string {
        $l = [];
        $l[] = $executado ? 'IMPORTAÇÃO DO METRICA360 — EXECUTADA' : 'IMPORTAÇÃO DO METRICA360 — ENSAIO (nada foi gravado)';
        $l[] = str_repeat('=', 64);
        $criarSetores = array_filter($plano['setores'], fn($s) => $s['acao'] === 'criar');
        $l[] = '';
        $l[] = 'SETORES COM SERVIDOR ATIVO (' . count($plano['setores']) . '): ' . count($criarSetores) . ' a criar, ' . (count($plano['setores']) - count($criarSetores)) . ' já existem';
        foreach ($plano['setores'] as $s) {
            $l[] = sprintf('  %-8s %s%s', $s['acao'] === 'criar' ? '[CRIAR]' : '[existe]', $s['nome'], $s['pesquisa_painel'] ? '' : '  (fora do Painel de Setores)');
        }
        $porPapel = [];
        foreach ($plano['usuarios'] as $u) {
            if ($u['acao'] === 'criar') {
                $porPapel[$u['tipo']] = ($porPapel[$u['tipo']] ?? 0) + 1;
            }
        }
        $criar = array_filter($plano['usuarios'], fn($u) => $u['acao'] === 'criar');
        $l[] = '';
        $l[] = 'USUÁRIOS (' . count($plano['usuarios']) . ' ativos no METRICA360): ' . count($criar) . ' a criar, ' . (count($plano['usuarios']) - count($criar)) . ' já têm conta';
        ksort($porPapel);
        foreach ($porPapel as $papel => $n) {
            $l[] = sprintf('  %3d  %s', $n, $papel);
        }
        $l[] = '  (contas novas: senha desconhecida e troca obrigatória; a pessoa usa "Esqueci a senha")';
        $l[] = '';
        $l[] = 'CONTAS NOVAS';
        foreach ($criar as $u) {
            $l[] = sprintf('  %-34s %-18s %s%s%s', $u['email'], $u['tipo'], $u['setor_nome'] ?? '—',
                $u['adicionais_nomes'] ? ' + ' . implode(', ', $u['adicionais_nomes']) : '', $u['atendimento_externo'] ? '  [atend. externo]' : '');
            foreach ($u['avisos'] as $a) {
                $l[] = '       ! ' . $a;
            }
        }
        $dif = array_filter($plano['usuarios'], fn($u) => $u['acao'] === 'existe' && ($u['diferencas'] || $u['atualizar_atendimento_externo']));
        $l[] = '';
        $l[] = 'CONTAS QUE JÁ EXISTEM NO CONECTA (não são alteradas, só o atendimento externo): ' . count($dif) . ' com diferença';
        foreach ($dif as $u) {
            $l[] = '  ' . $u['email'];
            foreach ($u['diferencas'] as $d) {
                $l[] = '       - ' . $d;
            }
            if ($u['atualizar_atendimento_externo']) {
                $l[] = '       - atendimento externo será ligado';
            }
        }
        if ($plano['avisos']) {
            $l[] = '';
            $l[] = 'AVISOS';
            foreach ($plano['avisos'] as $a) {
                $l[] = '  ! ' . $a;
            }
        }
        $l[] = '';
        $l[] = 'NÃO IMPORTADO (dados de teste): ' . ($origemContagens['convites'] ?? 0) . ' convites, ' . ($origemContagens['feedbacks'] ?? 0) . ' avaliações, auditoria e fotos de perfil.';
        $l[] = 'CONFIGURAÇÕES: ' . ($origemContagens['categorias'] ?? 0) . ' categorias de avaliação, ' . ($origemContagens['frases'] ?? 0) . ' frases do dia e as regras de convite.';
        return implode("\n", $l) . "\n";
    }

    /**
     * Aplica o plano numa transação: cria os setores que faltam (SetorService), as contas novas e os setores
     * adicionais dos coordenadores, e copia categorias, frases e regras. Refaz sem duplicar (tudo confere antes).
     *
     * @return array{setores_criados:int, usuarios_criados:int, atendimento_externo_ligado:int, categorias:int, frases:int, regras:int}
     */
    public static function executar(PDO $db, array $plano, array $categorias, array $frases, array $config): array {
        $contagem = ['setores_criados' => 0, 'usuarios_criados' => 0, 'atendimento_externo_ligado' => 0, 'categorias' => 0, 'frases' => 0, 'regras' => 0];
        // Usa a transação de quem chama, se houver (os testes de integração desfazem tudo no fim)
        $propria = !$db->inTransaction();
        if ($propria) {
            $db->beginTransaction();
        }
        try {
            $idPorChave = [];
            foreach ($db->query("SELECT id, nome FROM setores")->fetchAll(PDO::FETCH_ASSOC) as $s) {
                $idPorChave[self::normalizar($s['nome'])] = (int) $s['id'];
            }
            $painel = $db->prepare("UPDATE setores SET pesquisa_painel = ? WHERE id = ?");
            foreach ($plano['setores'] as $s) {
                if (!isset($idPorChave[$s['chave']])) {
                    $idPorChave[$s['chave']] = SetorService::criar($db, $s['nome']);
                    $contagem['setores_criados']++;
                }
                $painel->execute([$s['pesquisa_painel'] ? 1 : 0, $idPorChave[$s['chave']]]);
            }

            $inserir = $db->prepare("INSERT INTO usuarios (nome, username, email, tipo, setor_id, atendimento_externo, senha, trocar_senha, ativo)
                                     VALUES (?, ?, ?, ?, ?, ?, ?, 1, 1)");
            $extra = $db->prepare("INSERT IGNORE INTO usuario_setores (usuario_id, setor_id) VALUES (?, ?)");
            $ligarExterno = $db->prepare("UPDATE usuarios SET atendimento_externo = 1 WHERE id = ?");
            $usernamesNestaCarga = [];
            foreach ($plano['usuarios'] as $u) {
                if ($u['acao'] === 'existe') {
                    if ($u['atualizar_atendimento_externo'] && $u['destino_id']) {
                        $ligarExterno->execute([$u['destino_id']]);
                        $contagem['atendimento_externo_ligado']++;
                    }
                    continue;
                }
                $username = $u['username_origem'];
                $livre = $username !== '' && preg_match('/^[a-z0-9._-]{3,50}$/', $username) && !in_array($username, $usernamesNestaCarga, true);
                if ($livre) {
                    $existe = $db->prepare("SELECT 1 FROM usuarios WHERE username = ?");
                    $existe->execute([$username]);
                    $livre = !$existe->fetchColumn();
                }
                if (!$livre) {
                    $username = UsuarioService::gerarUsernameUnico($db, $u['nome'], $usernamesNestaCarga);
                }
                $usernamesNestaCarga[] = $username;

                $setorId = $u['setor_chave'] !== null ? ($idPorChave[$u['setor_chave']] ?? null) : null;
                // Senha desconhecida: a pessoa usa "Esqueci a senha" e ainda troca a senha no primeiro acesso
                $inserir->execute([$u['nome'], $username, $u['email'], $u['tipo'], $setorId, $u['atendimento_externo'] ? 1 : 0,
                    SenhaService::hash(bin2hex(random_bytes(24)) . 'Aa1!')]);
                $novoId = (int) $db->lastInsertId();
                foreach ($u['adicionais_chaves'] as $chave) {
                    if (isset($idPorChave[$chave])) {
                        $extra->execute([$novoId, $idPorChave[$chave]]);
                    }
                }
                $contagem['usuarios_criados']++;
            }

            // Categorias de avaliação e frases (sem repetir) e regras (as do METRICA360 prevalecem sobre o padrão)
            $cat = $db->prepare("INSERT IGNORE INTO pesquisa_categorias (nome, emoji, ativo, ordem) VALUES (?, ?, ?, ?)");
            foreach ($categorias as $c) {
                $cat->execute([$c['nome'], $c['emoji'], (int) $c['ativo'], (int) $c['ordem']]);
                $contagem['categorias'] += $cat->rowCount();
            }
            $fraseExiste = $db->prepare("SELECT 1 FROM pesquisa_frases WHERE texto = ?");
            $frase = $db->prepare("INSERT INTO pesquisa_frases (texto, autor, ativo, ordem) VALUES (?, ?, ?, ?)");
            foreach ($frases as $f) {
                $fraseExiste->execute([$f['texto']]);
                if (!$fraseExiste->fetchColumn()) {
                    $frase->execute([$f['texto'], $f['autor'], (int) $f['ativo'], (int) $f['ordem']]);
                    $contagem['frases']++;
                }
            }
            $regra = $db->prepare("INSERT INTO pesquisa_config (chave, valor) VALUES (?, ?) ON DUPLICATE KEY UPDATE valor = VALUES(valor)");
            foreach (self::CONFIG as $deChave => $paraChave) {
                if (array_key_exists($deChave, $config)) {
                    $regra->execute([$paraChave, (string) $config[$deChave]]);
                    $contagem['regras']++;
                }
            }
            if ($propria) {
                $db->commit();
            }
        } catch (Throwable $e) {
            if ($propria && $db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
        return $contagem;
    }
}
