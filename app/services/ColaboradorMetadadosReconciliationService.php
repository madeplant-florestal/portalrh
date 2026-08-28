<?php
/**
 * Reconciliação entre `colaboradores` (local, identificador estável referenciado por 9 FKs) e
 * `colaboradores_metadados` (espelho oficial do METADADOS, sincronizado via MetadadosSyncService).
 *
 * Só analisa e classifica — NUNCA escreve `colaboradores.metadados_id`. A aplicação de vínculos
 * é uma fase futura, ainda não autorizada.
 *
 * Nunca consulta o SQL Server do METADADOS: opera inteiramente sobre o que já está sincronizado
 * localmente em `colaboradores_metadados`, para o relatório ser reprodutível, não depender de
 * conectividade e não correr nenhum risco de escrita na origem.
 *
 * loadLocalRows()/loadMirrorRows() (leem o MySQL local, via connection() preguiçosa) são
 * deliberadamente separados de analyze() (pura lógica de classificação, sem tocar banco) para o
 * algoritmo ser testável com fixtures sintéticas — ver
 * tests/php/unit_colaborador_metadados_reconciliation.php.
 *
 * CPF nunca é usado sozinho para decidir o vínculo: uma pessoa pode ter múltiplos contratos
 * (readmissão). A desambiguação segue a hierarquia: CPF -> data de admissão -> data de demissão
 * -> data de nascimento (validação). Nunca escolhe "o mais recente" nem "o ativo" por padrão.
 */
class ColaboradorMetadadosReconciliationService
{
    public const SEGURA = 'CORRESPONDENCIA_SEGURA';
    public const PROVAVEL = 'CORRESPONDENCIA_PROVAVEL';
    public const AMBIGUA = 'AMBIGUA';
    public const SEM_CORRESPONDENCIA = 'SEM_CORRESPONDENCIA';
    public const JA_VINCULADO = 'JA_VINCULADO';
    public const CONFLITO = 'CONFLITO';

    private ?PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo;
    }

    private function connection(): PDO
    {
        return $this->pdo ??= Database::conn();
    }

    public function run(): array
    {
        return $this->analyze($this->loadLocalRows(), $this->loadMirrorRows());
    }

    public function loadLocalRows(): array
    {
        $stmt = $this->connection()->query(
            'SELECT id, cpf, nome, data_admissao, data_demissao, data_nascimento, ativo, metadados_id
             FROM colaboradores'
        );
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function loadMirrorRows(): array
    {
        $stmt = $this->connection()->query(
            'SELECT id, cpf, nome, admissao, demissao, nascimento, ativo
             FROM colaboradores_metadados'
        );
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * @param array $localRows  Cada item: id, cpf, nome, data_admissao, data_demissao,
     *                          data_nascimento, ativo, metadados_id.
     * @param array $mirrorRows Cada item: id, cpf, nome, admissao, demissao, nascimento, ativo.
     * @return array Um item por colaborador local: colaborador_id, classificacao,
     *               metadados_id_candidato, quantidade_candidatos, cpf (bruto — mascarar antes
     *               de persistir/exibir), data_admissao_local/metadados,
     *               data_demissao_local/metadados, nome_compativel, nascimento_compativel,
     *               situacao_compativel, motivo_classificacao.
     */
    public function analyze(array $localRows, array $mirrorRows): array
    {
        $mirrorById = [];
        $mirrorByCpf = [];
        foreach ($mirrorRows as $m) {
            $mirrorById[(int)$m['id']] = $m;
            $cpf = self::normalizeCpf($m['cpf'] ?? null);
            if ($cpf !== null) {
                $mirrorByCpf[$cpf][] = $m;
            }
        }

        $results = [];
        foreach ($localRows as $local) {
            $results[] = $this->classify($local, $mirrorById, $mirrorByCpf);
        }

        return $this->flagDuplicateLinks($results);
    }

    /**
     * Invariante defensiva: dois registros locais nunca deveriam apontar para o mesmo
     * metadados_id (a migration já impõe isso via UNIQUE) — mas o relatório verifica de novo,
     * caso rode contra uma base anterior à migration ou com dado corrompido por outra via.
     */
    private function flagDuplicateLinks(array $results): array
    {
        $countByMetadadosId = [];
        foreach ($results as $r) {
            if ($r['classificacao'] === self::JA_VINCULADO && $r['metadados_id_candidato'] !== null) {
                $countByMetadadosId[$r['metadados_id_candidato']] = ($countByMetadadosId[$r['metadados_id_candidato']] ?? 0) + 1;
            }
        }

        foreach ($results as &$r) {
            if (
                $r['classificacao'] === self::JA_VINCULADO
                && $r['metadados_id_candidato'] !== null
                && ($countByMetadadosId[$r['metadados_id_candidato']] ?? 0) > 1
            ) {
                $r['classificacao'] = self::CONFLITO;
                $r['motivo_classificacao'] = 'Mais de um registro local aponta para o mesmo metadados_id — viola a unicidade esperada do vínculo.';
            }
        }
        unset($r);

        return $results;
    }

    private function classify(array $local, array $mirrorById, array $mirrorByCpf): array
    {
        $localCpf = self::normalizeCpf($local['cpf'] ?? null);
        $localAdmissao = self::normalizeDate($local['data_admissao'] ?? null);
        $localDemissao = self::normalizeDate($local['data_demissao'] ?? null);
        $localNascimento = self::normalizeDate($local['data_nascimento'] ?? null);
        $localAtivo = self::normalizeBool($local['ativo'] ?? null);
        $localNome = self::normalizeNome($local['nome'] ?? null);

        $base = [
            'colaborador_id' => (int)$local['id'],
            'metadados_id_candidato' => null,
            'quantidade_candidatos' => 0,
            'cpf' => $local['cpf'] ?? null,
            'data_admissao_local' => $localAdmissao,
            'data_admissao_metadados' => null,
            'data_demissao_local' => $localDemissao,
            'data_demissao_metadados' => null,
            'nome_compativel' => null,
            'nascimento_compativel' => null,
            'situacao_compativel' => null,
        ];

        // 1) Já vinculado — valida o vínculo existente em vez de procurar candidatos novos.
        $existingMetadadosId = $local['metadados_id'] ?? null;
        if ($existingMetadadosId !== null) {
            return $this->classifyExistingLink($base, $local, (int)$existingMetadadosId, $mirrorById, $localNascimento, $localAtivo, $localNome);
        }

        // 2) Sem CPF válido — não há evidência principal para buscar candidato.
        if ($localCpf === null) {
            return $base + [
                'classificacao' => self::SEM_CORRESPONDENCIA,
                'motivo_classificacao' => 'CPF local ausente ou inválido — sem evidência principal para reconciliar.',
            ];
        }

        $candidates = $mirrorByCpf[$localCpf] ?? [];
        if ($candidates === []) {
            return $base + [
                'classificacao' => self::SEM_CORRESPONDENCIA,
                'motivo_classificacao' => 'Nenhum vínculo no espelho possui o mesmo CPF.',
            ];
        }

        $totalCandidatos = count($candidates);

        if ($totalCandidatos === 1) {
            return $this->resolveSingleCandidate($base, $candidates[0], $localAdmissao, $localNascimento, $localAtivo, $localNome, $totalCandidatos);
        }

        // Múltiplos contratos oficiais para o mesmo CPF — readmissão. Nunca escolher pelo mais
        // recente/ativo; desambiguar só por admissão (e demissão como apoio).
        $porAdmissao = $localAdmissao !== null
            ? array_values(array_filter($candidates, static fn (array $m) => self::normalizeDate($m['admissao'] ?? null) === $localAdmissao))
            : [];

        if (count($porAdmissao) === 1) {
            return $this->resolveSingleCandidate($base, $porAdmissao[0], $localAdmissao, $localNascimento, $localAtivo, $localNome, $totalCandidatos, true);
        }

        if (count($porAdmissao) > 1 && $localDemissao !== null) {
            $porAdmissaoEDemissao = array_values(array_filter(
                $porAdmissao,
                static fn (array $m) => self::normalizeDate($m['demissao'] ?? null) === $localDemissao
            ));
            if (count($porAdmissaoEDemissao) === 1) {
                return $this->resolveSingleCandidate($base, $porAdmissaoEDemissao[0], $localAdmissao, $localNascimento, $localAtivo, $localNome, $totalCandidatos, true);
            }
        }

        return $base + [
            'quantidade_candidatos' => $totalCandidatos,
            'classificacao' => self::AMBIGUA,
            'motivo_classificacao' => sprintf(
                'CPF corresponde a %d contratos no espelho (readmissão) e a data de admissão local não permite distinguir qual é o vínculo correto.',
                $totalCandidatos
            ),
        ];
    }

    private function classifyExistingLink(
        array $base,
        array $local,
        int $existingMetadadosId,
        array $mirrorById,
        ?string $localNascimento,
        ?bool $localAtivo,
        ?string $localNome
    ): array {
        $linked = $mirrorById[$existingMetadadosId] ?? null;
        if ($linked === null) {
            return $base + [
                'classificacao' => self::CONFLITO,
                'motivo_classificacao' => 'metadados_id preenchido não corresponde a nenhum registro existente em colaboradores_metadados.',
            ];
        }

        $localCpf = self::normalizeCpf($local['cpf'] ?? null);
        $linkedCpf = self::normalizeCpf($linked['cpf'] ?? null);
        $cpfCompativel = $localCpf === null || $linkedCpf === null || $localCpf === $linkedCpf;
        $comparacao = $this->compareEvidence($linked, $localNascimento, $localAtivo, $localNome);

        $resultadoBase = array_merge($base, $comparacao, [
            'metadados_id_candidato' => (int)$linked['id'],
            'quantidade_candidatos' => 1,
        ]);

        if (!$cpfCompativel) {
            return $resultadoBase + [
                'classificacao' => self::CONFLITO,
                'motivo_classificacao' => 'metadados_id preenchido aponta para um vínculo com CPF incompatível com o registro local.',
            ];
        }

        if ($comparacao['nascimento_compativel'] === false) {
            return $resultadoBase + [
                'classificacao' => self::CONFLITO,
                'motivo_classificacao' => 'metadados_id preenchido, mas a data de nascimento diverge claramente do vínculo oficial.',
            ];
        }

        return $resultadoBase + [
            'classificacao' => self::JA_VINCULADO,
            'motivo_classificacao' => 'Já possui metadados_id preenchido e compatível com o vínculo oficial.',
        ];
    }

    private function resolveSingleCandidate(
        array $base,
        array $candidate,
        ?string $localAdmissao,
        ?string $localNascimento,
        ?bool $localAtivo,
        ?string $localNome,
        int $totalCandidatos,
        bool $desambiguadoPorAdmissao = false
    ): array {
        $comparacao = $this->compareEvidence($candidate, $localNascimento, $localAtivo, $localNome);
        $candidateAdmissao = self::normalizeDate($candidate['admissao'] ?? null);

        $resultadoBase = array_merge($base, $comparacao, [
            'metadados_id_candidato' => (int)$candidate['id'],
            'quantidade_candidatos' => $totalCandidatos,
        ]);

        // Nascimento claramente divergente é sinal forte de identificação incorreta — nunca
        // classificar como seguro/provável nesse caso, mesmo com CPF (e admissão) batendo.
        if ($comparacao['nascimento_compativel'] === false) {
            return $resultadoBase + [
                'classificacao' => self::CONFLITO,
                'motivo_classificacao' => 'CPF (e admissão) coincidem, mas a data de nascimento diverge claramente — possível identificação incorreta.',
            ];
        }

        $admissaoBate = $localAdmissao !== null && $localAdmissao === $candidateAdmissao;

        if ($admissaoBate) {
            $motivo = $desambiguadoPorAdmissao
                ? 'CPF corresponde a múltiplos contratos (readmissão); a data de admissão local identificou exatamente um deles.'
                : 'CPF e data de admissão coincidem com o único candidato encontrado no espelho.';
            return $resultadoBase + [
                'classificacao' => self::SEGURA,
                'motivo_classificacao' => $motivo,
            ];
        }

        return $resultadoBase + [
            'classificacao' => self::PROVAVEL,
            'motivo_classificacao' => $localAdmissao === null
                ? 'CPF corresponde a um único candidato no espelho, mas a admissão local está vazia — não é possível confirmar com segurança.'
                : 'CPF corresponde a um único candidato no espelho, mas a data de admissão local diverge da oficial.',
        ];
    }

    private function compareEvidence(array $candidate, ?string $localNascimento, ?bool $localAtivo, ?string $localNome): array
    {
        $candidateNascimento = self::normalizeDate($candidate['nascimento'] ?? null);
        $candidateAtivo = self::normalizeBool($candidate['ativo'] ?? null);
        $candidateNome = self::normalizeNome($candidate['nome'] ?? null);

        return [
            'data_admissao_metadados' => self::normalizeDate($candidate['admissao'] ?? null),
            'data_demissao_metadados' => self::normalizeDate($candidate['demissao'] ?? null),
            'nome_compativel' => ($localNome === null || $candidateNome === null) ? null : ($localNome === $candidateNome),
            'nascimento_compativel' => ($localNascimento === null || $candidateNascimento === null) ? null : ($localNascimento === $candidateNascimento),
            'situacao_compativel' => ($localAtivo === null || $candidateAtivo === null) ? null : ($localAtivo === $candidateAtivo),
        ];
    }

    public static function normalizeCpf($value): ?string
    {
        $digits = preg_replace('/\D+/', '', (string)$value);
        return strlen($digits) === 11 ? $digits : null;
    }

    public static function normalizeDate($value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        $ts = strtotime((string)$value);
        return $ts !== false ? date('Y-m-d', $ts) : null;
    }

    public static function normalizeBool($value): ?bool
    {
        if ($value === null || $value === '') {
            return null;
        }
        return (bool)(int)$value;
    }

    public static function normalizeNome($value): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = trim((string)$value);
        if ($value === '') {
            return null;
        }
        $comAcento = ['á','à','ã','â','ä','é','è','ê','ë','í','ì','î','ï','ó','ò','õ','ô','ö','ú','ù','û','ü','ç','ñ'];
        $semAcento = ['a','a','a','a','a','e','e','e','e','i','i','i','i','o','o','o','o','o','u','u','u','u','c','n'];
        $value = str_replace($comAcento, $semAcento, mb_strtolower($value));
        $value = preg_replace('/\s+/', ' ', $value);
        return mb_strtoupper((string)$value);
    }
}
