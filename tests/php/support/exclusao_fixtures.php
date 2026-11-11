<?php

/**
 * Fixtures ZZ* compartilhadas pelos testes de exclusão analítica (contratos do METADADOS + um registro
 * de cada módulo ligado a eles + colaboradores locais). Tudo com prefixo "ZZEXF", rastreado e removido
 * em limpar(); nunca toca em dados reais. Requer a migration 2026-11-11 (chave natural).
 */
final class ExclusaoFixtures
{
    public string $suf;
    public string $emp;
    public string $uni;
    /** @var array<string,int[]> */
    public array $criados = [
        'cm' => [], 'avexp' => [], 'avdes' => [], 'fb' => [], 'pdi' => [], 'ent' => [], 'pesq' => [],
        'col' => [], 'uc' => [], 'sv' => [], 'mp' => [], 'setor' => [], 'usuario' => [], 'lib' => [], 'cav' => [],
    ];

    public function __construct(public PDO $pdo)
    {
        $this->suf = substr((string)time(), -5) . random_int(10, 99);
        $this->emp = 'ZX' . $this->suf;
        $this->uni = 'ZU' . $this->suf;
        $this->pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
    }

    /** @param array{cargo?:string,codigo_cargo?:string,setor?:?string,codigo_setor?:?string,admissao?:string,demissao?:?string,ativo?:int,cpf?:?string,nascimento?:?string,motivo?:?string} $o */
    public function contrato(string $ctr, string $pessoa, string $nome, array $o = []): int
    {
        $this->pdo->prepare(
            'INSERT INTO colaboradores_metadados (identificador, codigo_empresa, codigo_unidade, numero_contrato, codigo_pessoa, cpf,
                nome, empresa, unidade, cargo, codigo_cargo, setor, codigo_setor, sexo, nascimento, admissao, demissao,
                motivo_rescisao_codigo, ativo, origem_metadados)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            "{$this->emp}-{$this->uni}-{$ctr}", $this->emp, $this->uni, $ctr, $pessoa, $o['cpf'] ?? null,
            $nome, "ZZEXF Empresa {$this->emp}", "ZZEXF Unidade {$this->uni}", $o['cargo'] ?? 'ZZEXF CARGO', $o['codigo_cargo'] ?? 'ZXCG',
            $o['setor'] ?? 'ZZEXF SETOR', $o['codigo_setor'] ?? 'ZXST', 'F', $o['nascimento'] ?? null, $o['admissao'] ?? '2024-03-10',
            $o['demissao'] ?? null, $o['motivo'] ?? null, $o['ativo'] ?? 1, 'zzexf-teste',
        ]);
        return $this->reg('cm', (int)$this->pdo->lastInsertId());
    }

    public function excluir(string $ctr, string $motivo = 'ZZEXF teste'): void
    {
        $r = ColaboradorMetadadosElegibilidadeService::excluirPorVinculo($this->emp, $this->uni, $ctr, $motivo, 1);
        if (!($r['ok'] ?? false)) {
            throw new RuntimeException('Falha ao excluir fixture: ' . ($r['error'] ?? '?'));
        }
    }

    public function avaliacaoExperiencia(int $mid, string $nome): int
    {
        return $this->ins('avexp', 'INSERT INTO avaliacoes_experiencia (metadados_id, tipo, status, snap_nome, snap_codigo_empresa, snap_codigo_unidade, snap_codigo_setor,
                snap_admissao, gestor_usuario_id, gestor_nome_snapshot, data_prevista, data_realizacao, parecer, parecer_justificativa, criado_por_usuario_id, criado_em, atualizado_em)
              VALUES (?, \'45\', \'concluido\', ?, ?, ?, \'ZXST\', \'2024-03-10\', 1, \'Gestor\', \'2025-06-01\', \'2025-06-01\', \'efetivacao_acompanhamento\', \'ZZEXF\', 1, NOW(), NOW())',
            [$mid, $nome, $this->emp, $this->uni]);
    }

    public function avaliacaoDesempenho(int $mid, string $nome): int
    {
        return $this->ins('avdes', 'INSERT INTO avaliacoes_desempenho (metadados_id, status, snap_nome, snap_codigo_empresa, snap_codigo_unidade, snap_codigo_setor,
                snap_admissao, gestor_usuario_id, gestor_nome_snapshot, ciclo, periodo_inicio, periodo_fim, data_realizacao, resultado_final, criado_por_usuario_id, criado_em, atualizado_em)
              VALUES (?, \'concluido\', ?, ?, ?, \'ZXST\', \'2024-03-10\', 1, \'Gestor\', \'ZZEXF 2025\', \'2025-01-01\', \'2025-06-30\', \'2025-06-30\', \'atende_expectativas\', 1, NOW(), NOW())',
            [$mid, $nome, $this->emp, $this->uni]);
    }

    public function feedback(int $mid, string $nome): int
    {
        return $this->ins('fb', 'INSERT INTO feedbacks (metadados_id, status, snap_nome, snap_codigo_empresa, snap_codigo_unidade, snap_codigo_setor, gestor_usuario_id,
                gestor_nome_snapshot, tipo, data_feedback, resultado_geral, concluido_em, criado_por_usuario_id, criado_em, atualizado_em)
              VALUES (?, \'concluido\', ?, ?, ?, \'ZXST\', 1, \'Gestor\', \'desenvolvimento\', \'2025-06-10\', \'em_desenvolvimento\', \'2025-06-10 10:00:00\', 1, NOW(), NOW())',
            [$mid, $nome, $this->emp, $this->uni]);
    }

    public function pdi(int $mid, string $nome): int
    {
        return $this->ins('pdi', 'INSERT INTO pdis (metadados_id, status, snap_nome, snap_codigo_empresa, snap_codigo_unidade, gestor_usuario_id,
                gestor_nome_snapshot, origem_tipo, data_abertura, data_prevista_conclusao, criado_por_usuario_id, criado_em, atualizado_em)
              VALUES (?, \'em_andamento\', ?, ?, ?, 1, \'Gestor\', \'desenvolvimento_carreira\', \'2025-06-01\', \'2027-06-01\', 1, NOW(), NOW())',
            [$mid, $nome, $this->emp, $this->uni]);
    }

    public function entrevista(int $mid, string $nome, string $tokenRaw): int
    {
        return $this->ins('ent', 'INSERT INTO entrevistas_desligamento (metadados_id, token_hash, gerada_em, gerada_por_usuario_id, expira_em, respondida_em, enps,
                snap_nome, snap_codigo_empresa, snap_codigo_unidade, snap_demissao, snap_codigo_cargo)
              VALUES (?, ?, \'2025-03-01 10:00:00\', 1, \'2027-03-01 10:00:00\', \'2025-03-02 10:00:00\', 10, ?, ?, ?, \'2025-03-15\', \'ZXCG\')',
            [$mid, hash('sha256', $tokenRaw), $nome, $this->emp, $this->uni]);
    }

    /** Pesquisa de integração ligada direto ao contrato (metadados_id) OU, se $mid for null, só ao colaborador local. */
    public function pesquisa(?int $mid, ?int $colaboradorId, string $tokenRaw): int
    {
        return $this->ins('pesq', 'INSERT INTO pesquisas_integracao (colaborador_id, metadados_id, integracao_data_relacionada, token_hash, nota_nps, nota_clareza,
                nota_acolhimento, nota_normas, nota_utilidade, nota_satisfacao_geral, respondida_em)
              VALUES (?, ?, \'2025-06-01\', ?, 9, 5, 5, 5, 5, 5, \'2025-06-02 10:00:00\')',
            [$colaboradorId, $mid, hash('sha256', $tokenRaw)]);
    }

    /** Colaborador LOCAL (tabela colaboradores), opcionalmente ligado a um contrato do METADADOS. */
    public function colaboradorLocal(string $nome, ?int $metadadosId, array $extra = []): int
    {
        $slug = 'zzexf-' . strtolower(preg_replace('/[^a-z0-9]+/i', '-', $nome)) . '-' . $this->suf . count($this->criados['col']);
        $cargoId = (int)$this->pdo->query('SELECT id FROM cargos ORDER BY id LIMIT 1')->fetchColumn();
        $this->pdo->prepare('INSERT INTO colaboradores (nome, slug, cargo_id, metadados_id, ativo) VALUES (?, ?, ?, ?, ?)')
            ->execute([$nome, $slug, $cargoId, $metadadosId, $extra['ativo'] ?? 1]);
        return $this->reg('col', (int)$this->pdo->lastInsertId());
    }

    /** Usuário do Portal (e-mail único por fixture). Opcionalmente já vinculado a um contrato do METADADOS. */
    public function usuario(string $nome, string $role = 'rh', ?int $contratoId = null): int
    {
        $email = 'zzexf.' . $this->suf . '.' . count($this->criados['usuario']) . '@example.invalid';
        $id = $this->inserirGenerico('usuarios', ['nome' => $nome, 'email' => $email, 'senha_hash' => 'x', 'role' => $role,
            'colaborador_metadados_id' => $contratoId, 'email_verified_at' => '2026-01-01 00:00:00']);
        return $this->reg('usuario', $id);
    }

    /** usuario_colaboradores (PK composta): vincula um usuário a um colaborador LOCAL. */
    public function usuarioColaborador(int $usuarioId, int $colaboradorId, ?int $liderColaboradorId = null): void
    {
        $this->pdo->prepare('INSERT INTO usuario_colaboradores (usuario_id, colaborador_id, lider_colaborador_id) VALUES (?, ?, ?)')
            ->execute([$usuarioId, $colaboradorId, $liderColaboradorId]);
        $this->criados['uc'][] = [$usuarioId, $colaboradorId];
    }

    /** @param array<string,mixed> $v colunas explícitas (as demais NOT NULL são preenchidas com valores neutros) */
    public function solicitacaoVaga(array $v): int
    {
        $v += ['setor_id' => $this->primeiroId('setores'), 'cargo_id' => $this->primeiroId('cargos'), 'solicitante_usuario_id' => 1];
        return $this->reg('sv', $this->inserirGenerico('solicitacoes_vaga', $v));
    }

    /** @param array<string,mixed> $v */
    public function movimentacao(array $v): int
    {
        $v += ['setor_id' => $this->primeiroId('setores'), 'cargo_atual_id' => $this->primeiroId('cargos'), 'gestor_solicitante_usuario_id' => 1,
            'tipo_movimentacao' => 'merito', 'data_solicitacao' => '2026-01-10', 'matricula_snapshot' => 'ZZEXF'];
        return $this->reg('mp', $this->inserirGenerico('movimentacoes_pessoal', $v));
    }

    /** Avaliação do modelo LEGADO (colaborador_avaliacoes), ligada a um colaborador local. */
    public function avaliacaoLegada(int $colaboradorId, string $titulo): int
    {
        return $this->reg('cav', $this->inserirGenerico('colaborador_avaliacoes', ['colaborador_id' => $colaboradorId, 'titulo' => $titulo, 'nota' => 8, 'periodo_referencia' => 'ZZEXF']));
    }

    public function liberacaoEntrevista(int $metadadosId): int
    {
        return $this->reg('lib', $this->inserirGenerico('entrevista_desligamento_liberacoes', ['metadados_id' => $metadadosId, 'liberada_em' => '2026-01-10 10:00:00', 'liberada_por_usuario_id' => 1]));
    }

    private function primeiroId(string $tabela): int
    {
        return (int)$this->pdo->query("SELECT id FROM `{$tabela}` ORDER BY id LIMIT 1")->fetchColumn();
    }

    /** INSERT preenchendo as colunas NOT NULL sem default que não foram informadas (valores neutros por tipo). */
    public function inserirGenerico(string $tabela, array $valores): int
    {
        $stmt = $this->pdo->prepare(
            "SELECT COLUMN_NAME, DATA_TYPE, COLUMN_TYPE, CHARACTER_MAXIMUM_LENGTH FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND IS_NULLABLE = 'NO' AND COLUMN_DEFAULT IS NULL
               AND EXTRA NOT LIKE '%auto_increment%' AND EXTRA NOT LIKE '%GENERATED%'"
        );
        $stmt->execute([$tabela]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $c) {
            if (array_key_exists($c['COLUMN_NAME'], $valores)) {
                continue;
            }
            $t = strtolower((string)$c['DATA_TYPE']);
            $valores[$c['COLUMN_NAME']] = match (true) {
                $t === 'enum' => (preg_match("/^enum\\('([^']*)'/i", (string)$c['COLUMN_TYPE'], $m) ? $m[1] : ''),
                in_array($t, ['tinyint', 'smallint', 'mediumint', 'int', 'bigint', 'decimal', 'float', 'double', 'year'], true) => 0,
                $t === 'date' => '2026-01-15',
                in_array($t, ['datetime', 'timestamp'], true) => '2026-01-15 10:00:00',
                $t === 'time' => '10:00:00',
                $t === 'json' => '{}',
                default => substr('zz' . $this->suf, 0, max(1, (int)($c['CHARACTER_MAXIMUM_LENGTH'] ?: 8))),
            };
        }
        $cols = array_keys($valores);
        $this->pdo->prepare('INSERT INTO `' . $tabela . '` (`' . implode('`, `', $cols) . '`) VALUES (' . implode(', ', array_fill(0, count($cols), '?')) . ')')
            ->execute(array_values($valores));
        return (int)$this->pdo->lastInsertId();
    }

    public function ins(string $grupo, string $sql, array $params): int
    {
        $this->pdo->prepare($sql)->execute($params);
        return $this->reg($grupo, (int)$this->pdo->lastInsertId());
    }

    public function reg(string $grupo, int $id): int
    {
        $this->criados[$grupo][] = $id;
        return $id;
    }

    public function limpar(): void
    {
        $in = static fn(array $ids): string => $ids === [] ? '0' : implode(',', array_map('intval', $ids));
        try {
            foreach ($this->criados['cm'] as $mid) {
                $this->pdo->prepare('DELETE FROM colaboradores_metadados_exclusoes_analiticas WHERE metadados_id = ?
                    OR (codigo_empresa, codigo_unidade, numero_contrato) IN (SELECT codigo_empresa, codigo_unidade, numero_contrato FROM colaboradores_metadados WHERE id = ?)')->execute([$mid, $mid]);
            }
            foreach ($this->criados['uc'] as [$uid, $cid]) {
                $this->pdo->prepare('DELETE FROM usuario_colaboradores WHERE usuario_id = ? AND colaborador_id = ?')->execute([$uid, $cid]);
            }
            $mapa = ['avexp' => 'avaliacoes_experiencia', 'avdes' => 'avaliacoes_desempenho', 'fb' => 'feedbacks', 'pdi' => 'pdis',
                'ent' => 'entrevistas_desligamento', 'pesq' => 'pesquisas_integracao', 'lib' => 'entrevista_desligamento_liberacoes',
                'sv' => 'solicitacoes_vaga', 'mp' => 'movimentacoes_pessoal', 'cav' => 'colaborador_avaliacoes', 'setor' => 'setores', 'usuario' => 'usuarios',
                'col' => 'colaboradores', 'cm' => 'colaboradores_metadados'];
            foreach ($mapa as $grupo => $tabela) {
                if (!empty($this->criados[$grupo])) {
                    $this->pdo->exec("DELETE FROM `{$tabela}` WHERE id IN (" . $in($this->criados[$grupo]) . ')');
                }
            }
        } finally {
            $this->pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
        }
    }
}
