<?php
/**
 * Acesso à tabela espelho colaboradores_metadados. Camada de LEITURA para o restante do
 * Portal — a única escrita legítima é o upsert feito pela sincronização
 * (MetadadosSyncService). Ver database/migrations/2026-08-27-colaboradores-metadados.sql.
 */
class ColaboradorMetadadosRepository
{
    private const COMPARABLE_FIELDS = [
        'identificador', 'cpf', 'nome', 'empresa', 'nascimento', 'sexo', 'admissao', 'cargo',
        'demissao', 'motivo_rescisao_codigo', 'motivo_rescisao_descricao', 'unidade',
        'setor', 'centro_custo', 'codigo_setor', 'codigo_cargo', 'codigo_centro_custo',
        'ativo', 'salario_atual', 'data_inicio_cargo', 'atualizado_em_origem',
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

    public function upsert(array $row, string $origem): string
    {
        $codigoEmpresa = (string)$row['codigo_empresa'];
        $codigoUnidade = (string)$row['codigo_unidade'];
        $numeroContrato = (string)$row['numero_contrato'];

        $existing = $this->findByVinculo($codigoEmpresa, $codigoUnidade, $numeroContrato);

        if ($existing === null) {
            $this->insert($row, $origem);
            return 'inserted';
        }

        // Uma chave que reaparece precisa sempre limpar a sinalização de ausência (ver
        // reconciliarAusentes()), mesmo que nenhum campo comparável tenha mudado — por isso o
        // caminho "unchanged" só é tomado se o registro já não estiver marcado como ausente.
        $estavaAusente = (int)($existing['ausente_na_origem'] ?? 0) === 1;
        if (!$this->rowsDiffer($existing, $row) && !$estavaAusente) {
            return 'unchanged';
        }

        $this->update((int)$existing['id'], $row, $origem);
        return 'updated';
    }

    private function insert(array $row, string $origem): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO colaboradores_metadados (
                identificador, codigo_empresa, codigo_unidade, numero_contrato, codigo_pessoa,
                cpf, nome, empresa, nascimento, sexo, admissao, cargo, demissao,
                motivo_rescisao_codigo, motivo_rescisao_descricao, unidade, setor, centro_custo,
                codigo_setor, codigo_cargo, codigo_centro_custo,
                ativo, origem_metadados, salario_atual, data_inicio_cargo, atualizado_em_origem,
                ausente_na_origem, ausente_desde
            ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,0,NULL)'
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
            self::nullableString($row['sexo'] ?? null),
            self::nullableString($row['admissao'] ?? null),
            self::nullableString($row['cargo'] ?? null),
            self::nullableString($row['demissao'] ?? null),
            self::nullableString($row['motivo_rescisao_codigo'] ?? null),
            self::nullableString($row['motivo_rescisao_descricao'] ?? null),
            self::nullableString($row['unidade'] ?? null),
            self::nullableString($row['setor'] ?? null),
            self::nullableString($row['centro_custo'] ?? null),
            self::nullableString($row['codigo_setor'] ?? null),
            self::nullableString($row['codigo_cargo'] ?? null),
            self::nullableString($row['codigo_centro_custo'] ?? null),
            array_key_exists('ativo', $row) && $row['ativo'] !== null ? (int)$row['ativo'] : null,
            $origem,
            self::nullableString($row['salario_atual'] ?? null),
            self::nullableString($row['data_inicio_cargo'] ?? null),
            self::nullableString($row['atualizado_em_origem'] ?? null),
        ]);
    }

    private function update(int $id, array $row, string $origem): void
    {
        // ausente_na_origem/ausente_desde sempre voltam a 0/NULL aqui: update() só é chamado para
        // uma chave que VEIO no lote atual (ver upsert()) — reaparecer sempre limpa a sinalização
        // de ausência, sem exigir nenhuma ação manual (ver reconciliarAusentes()).
        $stmt = $this->pdo->prepare(
            'UPDATE colaboradores_metadados SET
                identificador = ?, codigo_pessoa = ?, cpf = ?, nome = ?, empresa = ?,
                nascimento = ?, sexo = ?, admissao = ?, cargo = ?, demissao = ?,
                motivo_rescisao_codigo = ?, motivo_rescisao_descricao = ?,
                unidade = ?, setor = ?, centro_custo = ?,
                codigo_setor = ?, codigo_cargo = ?, codigo_centro_custo = ?,
                ativo = ?, origem_metadados = ?,
                salario_atual = ?, data_inicio_cargo = ?, atualizado_em_origem = ?,
                ausente_na_origem = 0, ausente_desde = NULL
             WHERE id = ?'
        );
        $stmt->execute([
            (string)$row['identificador'],
            (string)$row['codigo_pessoa'],
            self::nullableString($row['cpf'] ?? null),
            (string)$row['nome'],
            self::nullableString($row['empresa'] ?? null),
            self::nullableString($row['nascimento'] ?? null),
            self::nullableString($row['sexo'] ?? null),
            self::nullableString($row['admissao'] ?? null),
            self::nullableString($row['cargo'] ?? null),
            self::nullableString($row['demissao'] ?? null),
            self::nullableString($row['motivo_rescisao_codigo'] ?? null),
            self::nullableString($row['motivo_rescisao_descricao'] ?? null),
            self::nullableString($row['unidade'] ?? null),
            self::nullableString($row['setor'] ?? null),
            self::nullableString($row['centro_custo'] ?? null),
            self::nullableString($row['codigo_setor'] ?? null),
            self::nullableString($row['codigo_cargo'] ?? null),
            self::nullableString($row['codigo_centro_custo'] ?? null),
            array_key_exists('ativo', $row) && $row['ativo'] !== null ? (int)$row['ativo'] : null,
            $origem,
            self::nullableString($row['salario_atual'] ?? null),
            self::nullableString($row['data_inicio_cargo'] ?? null),
            self::nullableString($row['atualizado_em_origem'] ?? null),
            $id,
        ]);
    }

    /**
     * Reconciliação de ausência (ver migration 2026-09-24-colaboradores-metadados-reconciliacao-
     * ausencia.sql): marca como `ausente_na_origem = 1` toda chave hoje vigente
     * (`ausente_na_origem = 0`) que NÃO está no conjunto de `identificador` recebido no lote
     * atual. Nunca faz DELETE, nunca mexe em `ativo`/`demissao`/qualquer outro campo — só
     * sinaliza. Uma chave já marcada como ausente nunca tem `ausente_desde` sobrescrito aqui
     * (a condição `ausente_na_origem = 0` já a exclui do UPDATE).
     *
     * Só deve ser chamada por MetadadosSyncService::applyRows() quando o próprio chamador
     * confirma que o lote é um sync completo e bem-sucedido (ver docstring de applyRows()) —
     * nunca em teste isolado, dry-run ou lote parcial.
     *
     * Proteção mínima contra acidente de origem: um lote vazio nunca reconcilia — é o único
     * sinal inequívoco de que a leitura da origem falhou ou voltou vazia. Qualquer outro
     * tamanho é aceito: a verdade é o CONJUNTO de chaves recebidas, nunca uma contagem fixa
     * esperada (o lote de hoje pode legitimamente ter menos ou mais registros que o anterior).
     *
     * @param string[] $identificadoresRecebidos Todo `identificador` do lote atual (a dimensão
     *                                            inteira, nunca uma amostra).
     * @return array{marcados_ausentes:int, ignorado_lote_vazio:bool}
     */
    public function reconciliarAusentes(array $identificadoresRecebidos): array
    {
        $identificadoresRecebidos = array_values(array_unique(array_map('strval', $identificadoresRecebidos)));
        if ($identificadoresRecebidos === []) {
            return ['marcados_ausentes' => 0, 'ignorado_lote_vazio' => true];
        }

        $placeholders = implode(',', array_fill(0, count($identificadoresRecebidos), '?'));
        $stmt = $this->pdo->prepare(
            "UPDATE colaboradores_metadados
             SET ausente_na_origem = 1, ausente_desde = NOW()
             WHERE ausente_na_origem = 0 AND identificador NOT IN ($placeholders)"
        );
        $stmt->execute($identificadoresRecebidos);

        return ['marcados_ausentes' => $stmt->rowCount(), 'ignorado_lote_vazio' => false];
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
