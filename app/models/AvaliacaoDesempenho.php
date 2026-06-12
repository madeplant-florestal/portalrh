<?php
class AvaliacaoDesempenho
{
    public static function ensureSchema(): void
    {
        if (class_exists('MovimentacaoPessoal')) {
            MovimentacaoPessoal::ensureSchema();
        }
    }

    public static function all(array $filters = []): array
    {
        self::ensureSchema();
        $sql = 'SELECT a.*, c.nome AS colaborador_nome, c.matricula, cg.nome AS cargo_nome, s.nome AS setor_nome
                FROM colaborador_avaliacoes a
                INNER JOIN colaboradores c ON c.id = a.colaborador_id
                INNER JOIN cargos cg ON cg.id = c.cargo_id
                LEFT JOIN setores s ON s.id = c.setor_id
                WHERE 1=1';
        $params = [];

        $q = trim((string)($filters['q'] ?? ''));
        if ($q !== '') {
            $sql .= ' AND (c.nome LIKE ? OR a.titulo LIKE ? OR a.periodo_referencia LIKE ?)';
            $like = '%' . $q . '%';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }

        if (!empty($filters['colaborador_id'])) {
            $sql .= ' AND a.colaborador_id = ?';
            $params[] = (int)$filters['colaborador_id'];
        }

        $sql .= ' ORDER BY a.created_at DESC, a.id DESC';
        $stmt = Database::conn()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function find(int $id): ?array
    {
        self::ensureSchema();
        $stmt = Database::conn()->prepare(
            'SELECT a.*, c.nome AS colaborador_nome
             FROM colaborador_avaliacoes a
             INNER JOIN colaboradores c ON c.id = a.colaborador_id
             WHERE a.id = ?
             LIMIT 1'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public static function create(array $data): array
    {
        self::ensureSchema();
        $normalized = self::normalize($data);
        if (!$normalized['ok']) {
            return $normalized;
        }

        $stmt = Database::conn()->prepare(
            'INSERT INTO colaborador_avaliacoes (colaborador_id, titulo, nota, periodo_referencia, resumo)
             VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $normalized['data']['colaborador_id'],
            $normalized['data']['titulo'],
            $normalized['data']['nota'],
            $normalized['data']['periodo_referencia'],
            $normalized['data']['resumo'],
        ]);

        return ['ok' => true, 'id' => (int)Database::conn()->lastInsertId()];
    }

    public static function update(int $id, array $data): array
    {
        self::ensureSchema();
        if (!self::find($id)) {
            return ['ok' => false, 'error' => 'Avaliação não encontrada.'];
        }

        $normalized = self::normalize($data);
        if (!$normalized['ok']) {
            return $normalized;
        }

        $stmt = Database::conn()->prepare(
            'UPDATE colaborador_avaliacoes
             SET colaborador_id = ?, titulo = ?, nota = ?, periodo_referencia = ?, resumo = ?
             WHERE id = ?'
        );
        $stmt->execute([
            $normalized['data']['colaborador_id'],
            $normalized['data']['titulo'],
            $normalized['data']['nota'],
            $normalized['data']['periodo_referencia'],
            $normalized['data']['resumo'],
            $id,
        ]);

        return ['ok' => true];
    }

    public static function delete(int $id): bool
    {
        self::ensureSchema();
        $stmt = Database::conn()->prepare('DELETE FROM colaborador_avaliacoes WHERE id = ?');
        return $stmt->execute([$id]);
    }

    public static function colaboradorOptions(): array
    {
        self::ensureSchema();
        $stmt = Database::conn()->query(
            'SELECT c.id, c.nome, c.matricula, cg.nome AS cargo_nome
             FROM colaboradores c
             INNER JOIN cargos cg ON cg.id = c.cargo_id
             WHERE c.ativo = 1
             ORDER BY c.nome ASC'
        );
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private static function normalize(array $data): array
    {
        $colaboradorId = ctype_digit((string)($data['colaborador_id'] ?? '')) ? (int)$data['colaborador_id'] : 0;
        $titulo = trim((string)($data['titulo'] ?? ''));
        $periodo = trim((string)($data['periodo_referencia'] ?? ''));
        $resumo = trim((string)($data['resumo'] ?? ''));
        $notaRaw = trim((string)($data['nota'] ?? ''));
        $nota = null;

        if ($colaboradorId <= 0 || !Colaborador::find($colaboradorId)) {
            return ['ok' => false, 'error' => 'Selecione um colaborador válido.'];
        }
        if ($titulo === '') {
            return ['ok' => false, 'error' => 'Informe o título da avaliação.'];
        }
        if ($periodo === '') {
            return ['ok' => false, 'error' => 'Informe o período de referência da avaliação.'];
        }
        if ($notaRaw !== '') {
            $notaNormalized = str_replace(',', '.', $notaRaw);
            if (!is_numeric($notaNormalized)) {
                return ['ok' => false, 'error' => 'Informe uma nota válida para a avaliação.'];
            }
            $nota = round((float)$notaNormalized, 2);
            if ($nota < 0 || $nota > 10) {
                return ['ok' => false, 'error' => 'A nota da avaliação deve estar entre 0 e 10.'];
            }
        }

        return [
            'ok' => true,
            'data' => [
                'colaborador_id' => $colaboradorId,
                'titulo' => $titulo,
                'nota' => $nota,
                'periodo_referencia' => $periodo,
                'resumo' => $resumo,
            ],
        ];
    }
}
