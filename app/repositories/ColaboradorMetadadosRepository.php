<?php
/**
 * Acesso à tabela espelho colaboradores_metadados. Camada de LEITURA para o restante do
 * Portal — a única escrita legítima é o upsert feito pela sincronização
 * (MetadadosSyncService). Ver database/migrations/2026-08-27-colaboradores-metadados.sql.
 */
class ColaboradorMetadadosRepository
{
    private const COMPARABLE_FIELDS = [
        'identificador', 'cpf', 'nome', 'empresa', 'nascimento', 'admissao', 'cargo',
        'demissao', 'motivo_rescisao_codigo', 'motivo_rescisao_descricao', 'unidade',
        'setor', 'centro_custo', 'ativo', 'salario_atual', 'data_inicio_cargo',
        'atualizado_em_origem',
    ];

    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Database::conn();
    }

    public function connection(): PDO
    {
        return $this->pdo;
    }

    public function findByVinculo(string $codigoEmpresa, string $codigoUnidade, string $numeroContrato): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM colaboradores_metadados
             WHERE codigo_empresa = ? AND codigo_unidade = ? AND numero_contrato = ?
             LIMIT 1'
        );
        $stmt->execute([$codigoEmpresa, $codigoUnidade, $numeroContrato]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * @return string 'inserted'|'updated'|'unchanged'
     */
    public function upsert(array $row): string
    {
        $codigoEmpresa = (string)$row['codigo_empresa'];
        $codigoUnidade = (string)$row['codigo_unidade'];
        $numeroContrato = (string)$row['numero_contrato'];

        $existing = $this->findByVinculo($codigoEmpresa, $codigoUnidade, $numeroContrato);

        if ($existing === null) {
            $this->insert($row);
            return 'inserted';
        }

        if (!$this->rowsDiffer($existing, $row)) {
            return 'unchanged';
        }

        $this->update((int)$existing['id'], $row);
        return 'updated';
    }

    private function insert(array $row): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO colaboradores_metadados (
                identificador, codigo_empresa, codigo_unidade, numero_contrato, codigo_pessoa,
                cpf, nome, empresa, nascimento, admissao, cargo, demissao,
                motivo_rescisao_codigo, motivo_rescisao_descricao, unidade, setor, centro_custo,
                ativo, salario_atual, data_inicio_cargo, atualizado_em_origem
            ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
        );
        $stmt->execute([
            (string)$row['identificador'],
            (string)$row['codigo_empresa'],
            (string)$row['codigo_unidade'],
            (string)$row['numero_contrato'],
            (string)$row['codigo_pessoa'],
            self::nullableString($row['cpf'] ?? null),
            (string)$row['nome'],
            self::nullableString($row['empresa'] ?? null),
            self::nullableString($row['nascimento'] ?? null),
            self::nullableString($row['admissao'] ?? null),
            self::nullableString($row['cargo'] ?? null),
            self::nullableString($row['demissao'] ?? null),
            self::nullableString($row['motivo_rescisao_codigo'] ?? null),
            self::nullableString($row['motivo_rescisao_descricao'] ?? null),
            self::nullableString($row['unidade'] ?? null),
            self::nullableString($row['setor'] ?? null),
            self::nullableString($row['centro_custo'] ?? null),
            array_key_exists('ativo', $row) && $row['ativo'] !== null ? (int)$row['ativo'] : null,
            self::nullableString($row['salario_atual'] ?? null),
            self::nullableString($row['data_inicio_cargo'] ?? null),
            self::nullableString($row['atualizado_em_origem'] ?? null),
        ]);
    }

    private function update(int $id, array $row): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE colaboradores_metadados SET
                identificador = ?, codigo_pessoa = ?, cpf = ?, nome = ?, empresa = ?,
                nascimento = ?, admissao = ?, cargo = ?, demissao = ?,
                motivo_rescisao_codigo = ?, motivo_rescisao_descricao = ?,
                unidade = ?, setor = ?, centro_custo = ?, ativo = ?,
                salario_atual = ?, data_inicio_cargo = ?, atualizado_em_origem = ?
             WHERE id = ?'
        );
        $stmt->execute([
            (string)$row['identificador'],
            (string)$row['codigo_pessoa'],
            self::nullableString($row['cpf'] ?? null),
            (string)$row['nome'],
            self::nullableString($row['empresa'] ?? null),
            self::nullableString($row['nascimento'] ?? null),
            self::nullableString($row['admissao'] ?? null),
            self::nullableString($row['cargo'] ?? null),
            self::nullableString($row['demissao'] ?? null),
            self::nullableString($row['motivo_rescisao_codigo'] ?? null),
            self::nullableString($row['motivo_rescisao_descricao'] ?? null),
            self::nullableString($row['unidade'] ?? null),
            self::nullableString($row['setor'] ?? null),
            self::nullableString($row['centro_custo'] ?? null),
            array_key_exists('ativo', $row) && $row['ativo'] !== null ? (int)$row['ativo'] : null,
            self::nullableString($row['salario_atual'] ?? null),
            self::nullableString($row['data_inicio_cargo'] ?? null),
            self::nullableString($row['atualizado_em_origem'] ?? null),
            $id,
        ]);
    }

    /**
     * Compara só os campos que podem legitimamente mudar na origem — nunca dispara UPDATE
     * por diferença de tipo (string "1" vs int 1, etc.), só por diferença de valor real.
     */
    private function rowsDiffer(array $existing, array $incoming): bool
    {
        foreach (self::COMPARABLE_FIELDS as $field) {
            $existingValue = $existing[$field] ?? null;
            $incomingValue = $incoming[$field] ?? null;
            if ($field === 'ativo') {
                $existingValue = $existingValue === null ? null : (int)$existingValue;
                $incomingValue = $incomingValue === null ? null : (int)$incomingValue;
            } elseif ($field === 'salario_atual') {
                // Comparação numérica canonizada em 2 casas — evita UPDATE espúrio só por
                // diferença de formatação de string decimal (ex.: "1234.5" vindo da origem vs.
                // "1234.50" devolvido pelo MySQL para a mesma coluna DECIMAL(11,2)).
                $existingValue = $existingValue === null ? null : number_format((float)$existingValue, 2, '.', '');
                $incomingValue = $incomingValue === null ? null : number_format((float)$incomingValue, 2, '.', '');
            } else {
                $existingValue = $existingValue === null ? null : (string)$existingValue;
                $incomingValue = $incomingValue === null ? null : (string)$incomingValue;
            }
            if ($existingValue !== $incomingValue) {
                return true;
            }
        }
        return false;
    }

    private static function nullableString($value): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = trim((string)$value);
        return $value !== '' ? $value : null;
    }
}
