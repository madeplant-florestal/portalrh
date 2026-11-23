<?php

/**
 * Setor responsável ESTRUTURADO da vaga (Etapa 3.2A) — `vagas.setor_id` → `setores.id` (o mesmo conceito/chave de
 * `solicitacoes_vaga.setor_id` e `usuario_setores_gerenciados.setor_id`; nenhum segundo conceito de setor).
 *
 * Regras (servidor; a tela só reflete):
 *  - Vaga NOVA exige setor do catálogo OFICIAL ativo (`CatalogoMetadadosRepository('setores')->listarOficiais()`); vaga originada de
 *    solicitação herda o setor da solicitação e nunca diverge dela em silêncio.
 *  - Vaga HISTÓRICA sem setor pode ser salva sem setor (nada é presumido); ao ganhar setor passa a exigi-lo.
 *  - Trocar o setor de uma vaga que já tem setor E candidaturas exige confirmação explícita e é registrado em log.
 *  - Antes da migration (coluna ausente) o fluxo antigo segue valendo: `schemaSuportaSetor()` é a única chave.
 * Esta classe NÃO concede nem altera permissões de ninguém: `vagas.area` (texto) nunca é identidade de autorização.
 */
final class VagaSetorService
{
    public const CLASSE_A = 'A'; // setor estruturado
    public const CLASSE_B = 'B'; // identificável pela solicitação (vínculo estruturado)
    public const CLASSE_C = 'C'; // texto sugere um setor, sem prova
    public const CLASSE_D = 'D'; // sem informação suficiente

    private static ?bool $suporta = null;
    private PDO $pdo;
    private CatalogoMetadadosRepository $setores;

    public function __construct(?PDO $pdo = null, ?CatalogoMetadadosRepository $setores = null)
    {
        $this->pdo = $pdo ?? Database::conn();
        $this->setores = $setores ?? new CatalogoMetadadosRepository('setores');
    }

    /** O banco já tem `vagas.setor_id`? (a migration é executada à mão; o código publicado antes dela segue funcionando) */
    public static function schemaSuportaSetor(): bool
    {
        if (self::$suporta === null) {
            $st = Database::conn()->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'vagas' AND COLUMN_NAME = 'setor_id'");
            self::$suporta = (int)$st->fetchColumn() > 0;
        }
        return self::$suporta;
    }

    /** Só para testes: força (ou limpa, com null) o resultado da detecção de schema. */
    public static function forcarSchemaParaTeste(?bool $valor): void
    {
        self::$suporta = $valor;
    }

    /** @return array<int,array{id:int,codigo:string,nome:string,descricao_oficial:?string}> setores oficiais ATIVOS (elegíveis para vaga nova) */
    public function setoresElegiveis(): array
    {
        return $this->setores->listarOficiais();
    }

    /** Rótulo de exibição do setor (descrição oficial, ou nome). */
    public static function rotulo(array $setor): string
    {
        $d = trim((string)($setor['descricao_oficial'] ?? ''));
        return $d !== '' ? $d : (string)($setor['nome'] ?? '');
    }

    /** @return array{id:int,nome:string,descricao_oficial:?string,codigo_setor:?string,ativo:int}|null */
    public function setorPorId(int $id): ?array
    {
        $st = $this->pdo->prepare('SELECT id, nome, descricao_oficial, codigo_setor, ativo FROM setores WHERE id = ? LIMIT 1');
        $st->execute([$id]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        return $r ?: null;
    }

    private function elegivel(int $setorId): bool
    {
        $s = $this->setores->oficialPorId($setorId);
        if ($s === null) {
            return false;
        }
        $st = $this->pdo->prepare('SELECT ativo FROM setores WHERE id = ?');
        $st->execute([$setorId]);
        return (int)$st->fetchColumn() === 1;
    }

    /** Valor do POST → id positivo ou null (nunca confia em formato). */
    private static function idDoPost(mixed $v): ?int
    {
        return is_scalar($v) && ctype_digit((string)$v) && (int)$v > 0 ? (int)$v : null;
    }

    /**
     * Criação direta pelo RH.
     *
     * @return array{ok:bool,setor_id:?int,error:?string}
     */
    public function validarParaCriacao(mixed $setorPost): array
    {
        if (!self::schemaSuportaSetor()) {
            return ['ok' => true, 'setor_id' => null, 'error' => null]; // migration pendente: fluxo anterior
        }
        $id = self::idDoPost($setorPost);
        if ($id === null) {
            return ['ok' => false, 'setor_id' => null, 'error' => 'Informe o setor responsável da vaga.'];
        }
        if (!$this->elegivel($id)) {
            return ['ok' => false, 'setor_id' => null, 'error' => 'Setor responsável inválido: escolha um setor oficial ativo do cadastro.'];
        }
        return ['ok' => true, 'setor_id' => $id, 'error' => null];
    }

    /** Setor estruturado da solicitação (NOT NULL por FK), ou null se a solicitação não existe. */
    public function setorDaSolicitacao(int $solicitacaoId): ?int
    {
        $st = $this->pdo->prepare('SELECT setor_id FROM solicitacoes_vaga WHERE id = ? LIMIT 1');
        $st->execute([$solicitacaoId]);
        $v = $st->fetchColumn();
        return $v === false || $v === null ? null : (int)$v;
    }

    /**
     * Vaga gerada de solicitação: herda o setor da solicitação. Ambíguo/inválido/inelegível → bloqueia (não escolhe outro).
     *
     * @return array{ok:bool,setor_id:?int,error:?string}
     */
    public function setorParaRascunho(int $solicitacaoId): array
    {
        if (!self::schemaSuportaSetor()) {
            return ['ok' => true, 'setor_id' => null, 'error' => null];
        }
        $setorId = $this->setorDaSolicitacao($solicitacaoId);
        if ($setorId === null) {
            return ['ok' => false, 'setor_id' => null, 'error' => 'A solicitação não possui setor estruturado: corrija a solicitação antes de gerar a vaga.'];
        }
        if (!$this->elegivel($setorId)) {
            return ['ok' => false, 'setor_id' => null, 'error' => 'O setor da solicitação não é um setor oficial ativo do cadastro: regularize o setor antes de gerar a vaga.'];
        }
        return ['ok' => true, 'setor_id' => $setorId, 'error' => null];
    }

    /** @return array{candidaturas:int,solicitacao:bool,beneficios:int} */
    public function dependencias(int $vagaId): array
    {
        $c = $this->pdo->prepare('SELECT COUNT(*) FROM candidaturas WHERE vaga_id = ?');
        $c->execute([$vagaId]);
        $b = $this->pdo->prepare('SELECT COUNT(*) FROM vaga_beneficios WHERE vaga_id = ?');
        $b->execute([$vagaId]);
        $s = $this->pdo->prepare('SELECT solicitacao_vaga_id FROM vagas WHERE id = ?');
        $s->execute([$vagaId]);
        $sol = $s->fetchColumn();
        return ['candidaturas' => (int)$c->fetchColumn(), 'solicitacao' => $sol !== false && $sol !== null, 'beneficios' => (int)$b->fetchColumn()];
    }

    /**
     * Edição de uma vaga existente.
     *
     * @param array $vaga linha atual de `vagas` (precisa de id, setor_id, solicitacao_vaga_id)
     * @return array{ok:bool,setor_id:?int,error:?string,mudou:bool,regularizou:bool,anterior:?int}
     */
    public function validarParaEdicao(array $vaga, mixed $setorPost, bool $confirmouMudanca): array
    {
        $res = static fn(bool $ok, ?int $id, ?string $erro, bool $mudou = false, bool $reg = false, ?int $ant = null): array => ['ok' => $ok, 'setor_id' => $id, 'error' => $erro, 'mudou' => $mudou, 'regularizou' => $reg, 'anterior' => $ant];
        if (!self::schemaSuportaSetor()) {
            return $res(true, null, null);
        }
        $atual = isset($vaga['setor_id']) && $vaga['setor_id'] !== null ? (int)$vaga['setor_id'] : null;
        $solicitacaoId = isset($vaga['solicitacao_vaga_id']) && $vaga['solicitacao_vaga_id'] !== null ? (int)$vaga['solicitacao_vaga_id'] : null;

        // Originada de solicitação: o setor é o da solicitação — nunca se escolhe outro na edição (divergência silenciosa proibida).
        if ($solicitacaoId !== null) {
            $esperado = $this->setorDaSolicitacao($solicitacaoId);
            if ($esperado === null) {
                return $res(false, null, 'Solicitação vinculada inexistente: não é possível determinar o setor desta vaga.');
            }
            if ($atual !== null && $atual !== $esperado) {
                return $res(false, null, 'O setor desta vaga diverge do setor da solicitação #' . $solicitacaoId . '. Decisão pendente: corrija o vínculo antes de editar o setor.', false, false, $atual);
            }
            $post = self::idDoPost($setorPost);
            if ($post !== null && $post !== $esperado) {
                return $res(false, null, 'O setor de uma vaga originada de solicitação é o setor da solicitação e não pode ser alterado por aqui.', false, false, $atual);
            }
            return $res(true, $esperado, null, false, $atual === null, $atual);
        }

        $post = self::idDoPost($setorPost);
        if ($post === null) {
            if ($atual !== null) {
                return $res(false, null, 'O setor responsável é obrigatório e não pode ser removido de uma vaga que já o possui.', false, false, $atual);
            }
            return $res(true, null, null); // vaga histórica ainda sem setor: segue sem setor (nada é presumido)
        }
        if ($post === $atual) {
            return $res(true, $post, null, false, false, $atual); // mantém (inclusive setor hoje inativo)
        }
        if (!$this->elegivel($post)) {
            return $res(false, null, 'Setor responsável inválido: escolha um setor oficial ativo do cadastro.', false, false, $atual);
        }
        if ($atual !== null) {
            $dep = $this->dependencias((int)$vaga['id']);
            if ($dep['candidaturas'] > 0 && !$confirmouMudanca) {
                return $res(false, null, 'Esta vaga tem ' . $dep['candidaturas'] . ' candidatura(s). Trocar o setor muda quem poderá ver esses candidatos no futuro: marque a confirmação para prosseguir.', true, false, $atual);
            }
            return $res(true, $post, null, true, false, $atual);
        }
        return $res(true, $post, null, false, true, null); // regularização (null → setor)
    }

    /** Registro do evento (Logger + auditoria da solicitação, quando houver). Sem dados pessoais. */
    public function registrarAlteracao(int $vagaId, ?int $anterior, ?int $novo, ?int $actorUserId, ?string $ip, string $tipo): void
    {
        Logger::info('Vaga: setor responsável ' . $tipo, ['vaga_id' => $vagaId, 'setor_anterior' => $anterior, 'setor_novo' => $novo, 'usuario_id' => $actorUserId, 'ip' => $ip]);
        $st = $this->pdo->prepare('SELECT solicitacao_vaga_id FROM vagas WHERE id = ?');
        $st->execute([$vagaId]);
        $sol = $st->fetchColumn();
        if ($sol !== false && $sol !== null) {
            SolicitacaoVaga::registrarEventoAuditoria((int)$sol, $actorUserId, 'vaga_setor_' . $tipo, 'vaga_setor_id', $anterior === null ? null : (string)$anterior, $novo === null ? null : (string)$novo, $ip);
        }
    }

    // ---- Regularização histórica ----------------------------------------------------------------------------------------

    /**
     * Classifica TODAS as vagas (somente leitura; sem CPF/currículo — só contagem de candidaturas).
     *
     * @return array<int,array<string,mixed>> id, titulo, area, solicitacao_vaga_id, setor_atual, setor_sugerido (id), setor_sugerido_nome, classe, observacao, candidaturas
     */
    public function diagnosticoRegularizacao(): array
    {
        $suporta = self::schemaSuportaSetor();
        $sql = 'SELECT v.id, v.titulo, v.area, v.ativo, v.solicitacao_vaga_id, ' . ($suporta ? 'v.setor_id' : 'NULL') . ' AS setor_id,
                       sa.nome AS setor_atual_nome, s.setor_id AS sol_setor_id, ss.nome AS sol_setor_nome,
                       (SELECT COUNT(*) FROM candidaturas c WHERE c.vaga_id = v.id) AS candidaturas
                FROM vagas v
                LEFT JOIN solicitacoes_vaga s ON s.id = v.solicitacao_vaga_id
                LEFT JOIN setores ss ON ss.id = s.setor_id
                ' . ($suporta ? 'LEFT JOIN setores sa ON sa.id = v.setor_id' : 'LEFT JOIN setores sa ON 1 = 0') . '
                ORDER BY v.id';
        $vagas = $this->pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
        $porNome = [];
        foreach ($this->setores->listarOficiais() as $o) {
            foreach ([(string)$o['nome'], (string)($o['descricao_oficial'] ?? '')] as $n) {
                $k = MetadadosTexto::normalizarNome($n);
                if ($k !== '') {
                    $porNome[$k][(int)$o['id']] = (string)$o['nome'];
                }
            }
        }
        $saida = [];
        foreach ($vagas as $v) {
            $linha = [
                'id' => (int)$v['id'], 'titulo' => (string)$v['titulo'], 'area' => (string)($v['area'] ?? ''), 'ativo' => (int)$v['ativo'],
                'solicitacao_vaga_id' => $v['solicitacao_vaga_id'] !== null ? (int)$v['solicitacao_vaga_id'] : null,
                'setor_atual' => $v['setor_id'] !== null ? (int)$v['setor_id'] : null, 'setor_atual_nome' => $v['setor_atual_nome'],
                'setor_sugerido' => null, 'setor_sugerido_nome' => null, 'classe' => self::CLASSE_D, 'observacao' => '', 'candidaturas' => (int)$v['candidaturas'],
            ];
            if ($v['setor_id'] !== null) {
                $linha['classe'] = self::CLASSE_A;
                if ($v['sol_setor_id'] !== null && (int)$v['sol_setor_id'] !== (int)$v['setor_id']) {
                    $linha['observacao'] = 'DIVERGE do setor da solicitação #' . $v['solicitacao_vaga_id'] . ' (' . $v['sol_setor_nome'] . ')';
                }
            } elseif ($v['sol_setor_id'] !== null) {
                $linha['classe'] = self::CLASSE_B;
                $linha['setor_sugerido'] = (int)$v['sol_setor_id'];
                $linha['setor_sugerido_nome'] = $v['sol_setor_nome'];
                $linha['observacao'] = 'Setor da solicitação #' . $v['solicitacao_vaga_id'] . ' (vínculo estruturado).';
            } else {
                $k = MetadadosTexto::normalizarNome((string)($v['area'] ?? ''));
                if ($k !== '' && isset($porNome[$k])) {
                    $linha['classe'] = self::CLASSE_C;
                    if (count($porNome[$k]) === 1) {
                        $linha['setor_sugerido'] = (int)array_key_first($porNome[$k]);
                        $linha['setor_sugerido_nome'] = (string)reset($porNome[$k]);
                        $linha['observacao'] = 'O texto da área coincide com o nome do setor — SUGESTÃO NÃO COMPROVADA; confirme manualmente.';
                    } else {
                        $linha['observacao'] = 'O texto da área coincide com mais de um setor oficial — ambíguo.';
                    }
                } else {
                    $linha['observacao'] = $k === '' ? 'Sem área nem solicitação.' : 'A área textual não corresponde a nenhum setor oficial.';
                }
            }
            $saida[] = $linha;
        }
        return $saida;
    }

    /** @return array{A:int,B:int,C:int,D:int,total:int} */
    public function resumoDiagnostico(array $linhas): array
    {
        $r = ['A' => 0, 'B' => 0, 'C' => 0, 'D' => 0, 'total' => count($linhas)];
        foreach ($linhas as $l) {
            $r[$l['classe']]++;
        }
        return $r;
    }

    /**
     * Regra ÚNICA de aplicação sem intervenção: classe B (vaga sem setor, com solicitação cujo `setor_id` é estruturado, oficial e ativo).
     * Dry-run por padrão: com `$aplicar = false` nada é gravado. Classes C e D NUNCA são aplicadas aqui.
     *
     * @param int[]|null $somenteVagaIds restringe a aplicação a estas vagas (null = todas)
     * @return array{aplicaveis:array<int,array{vaga_id:int,setor_id:int}>,ignoradas:int,aplicadas:int}
     */
    public function regularizarClasseB(bool $aplicar, ?array $somenteVagaIds = null): array
    {
        if (!self::schemaSuportaSetor()) {
            throw new RuntimeException('A migration 2026-11-24-vagas-setor-responsavel.sql ainda não foi executada.');
        }
        $aplicaveis = [];
        $ignoradas = 0;
        foreach ($this->diagnosticoRegularizacao() as $l) {
            if ($somenteVagaIds !== null && !in_array($l['id'], $somenteVagaIds, true)) {
                continue; // escopo restrito (testes/regularização parcial)
            }
            if ($l['classe'] === self::CLASSE_B && $l['setor_sugerido'] !== null && $this->elegivel((int)$l['setor_sugerido'])) {
                $aplicaveis[] = ['vaga_id' => $l['id'], 'setor_id' => (int)$l['setor_sugerido']];
            } elseif ($l['classe'] !== self::CLASSE_A) {
                $ignoradas++;
            }
        }
        $aplicadas = 0;
        if ($aplicar) {
            $up = $this->pdo->prepare('UPDATE vagas SET setor_id = ? WHERE id = ? AND setor_id IS NULL');
            $this->pdo->beginTransaction();
            try {
                foreach ($aplicaveis as $a) {
                    $up->execute([$a['setor_id'], $a['vaga_id']]);
                    $aplicadas += $up->rowCount();
                }
                $this->pdo->commit();
            } catch (\Throwable $e) {
                if ($this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                }
                throw $e;
            }
        }
        return ['aplicaveis' => $aplicaveis, 'ignoradas' => $ignoradas, 'aplicadas' => $aplicadas];
    }
}
