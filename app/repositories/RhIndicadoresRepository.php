<?php

/**
 * Acesso a dados da camada analítica de RH — lê exclusivamente `colaboradores_metadados`
 * (espelho oficial do METADADOS, ver docs/claude/roadmap-tecnico.md), nunca `colaboradores`
 * (tabela hub legada do Portal, usada só para cadastro/fluxos internos — Fase 4 não a usa como
 * fonte de indicadores oficiais, ver docs/claude/indicadores-rh.md §"Fonte de dados").
 *
 * Nunca acessa o SQL Server do METADADOS — só o espelho MySQL local, já sincronizado.
 *
 * Nunca seleciona CPF, nome, data de nascimento ou salário: a camada analítica trabalha
 * exclusivamente com dimensões organizacionais e datas de vínculo, nunca com dados pessoais ou
 * remuneração individual (ver docs/claude/indicadores-rh.md §"Privacidade").
 */
class RhIndicadoresRepository
{
    private ?PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo;
    }

    private function connection(): PDO
    {
        if ($this->pdo === null) {
            $this->pdo = Database::conn();
        }
        return $this->pdo;
    }

    /**
     * Todos os contratos do espelho que atendem aos filtros de dimensão — sem filtro de data: o
     * cálculo de headcount histórico/turnover/tempo de permanência precisa do histórico completo
     * de cada contrato (admissão/demissão), não só do período exibido na tela.
     *
     * @param array $filtros Chaves aceitas: codigo_empresa, codigo_unidade, cargo, setor,
     *                       centro_custo. Ausente/vazio = sem filtro naquela dimensão.
     * @return array Cada item: id, codigo_empresa, empresa, codigo_unidade, unidade, cargo,
     *               setor, centro_custo, admissao, demissao, motivo_rescisao_descricao, ativo.
     */
    public function buscarContratos(array $filtros = []): array
    {
        $where = [];
        $params = [];

        $mapaFiltros = [
            'codigo_empresa' => 'codigo_empresa',
            'codigo_unidade' => 'codigo_unidade',
            'cargo' => 'cargo',
            'setor' => 'setor',
            'centro_custo' => 'centro_custo',
        ];
        foreach ($mapaFiltros as $chave => $coluna) {
            if (!empty($filtros[$chave])) {
                $where[] = "{$coluna} = ?";
                $params[] = $filtros[$chave];
            }
        }

        $sql = 'SELECT id, codigo_empresa, empresa, codigo_unidade, unidade, cargo, setor,
                       centro_custo, admissao, demissao, motivo_rescisao_descricao, ativo
                FROM colaboradores_metadados';
        if ($where !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }

        $stmt = $this->connection()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Valores distintos disponíveis para os filtros do dashboard. Empresa/unidade são agrupadas
     * pelo código (chave estável) e exibem o nome mais recente associado a esse código — o mesmo
     * codigo_empresa já apareceu associado a mais de um nome de empresa ao longo do histórico
     * (ex.: razão social alterada), então agrupar pelo nome duplicaria a mesma empresa na lista.
     */
    public function opcoesFiltro(): array
    {
        $pdo = $this->connection();

        $empresas = $pdo->query(
            'SELECT codigo_empresa, MAX(empresa) AS empresa
             FROM colaboradores_metadados
             GROUP BY codigo_empresa
             ORDER BY empresa'
        )->fetchAll(PDO::FETCH_ASSOC);

        $unidades = $pdo->query(
            'SELECT codigo_unidade, MAX(unidade) AS unidade
             FROM colaboradores_metadados
             GROUP BY codigo_unidade
             ORDER BY unidade'
        )->fetchAll(PDO::FETCH_ASSOC);

        $cargos = $pdo->query(
            'SELECT DISTINCT cargo FROM colaboradores_metadados
             WHERE cargo IS NOT NULL AND cargo <> \'\' ORDER BY cargo'
        )->fetchAll(PDO::FETCH_COLUMN);

        $setores = $pdo->query(
            'SELECT DISTINCT setor FROM colaboradores_metadados
             WHERE setor IS NOT NULL AND setor <> \'\' ORDER BY setor'
        )->fetchAll(PDO::FETCH_COLUMN);

        $centrosCusto = $pdo->query(
            'SELECT DISTINCT centro_custo FROM colaboradores_metadados
             WHERE centro_custo IS NOT NULL AND centro_custo <> \'\' ORDER BY centro_custo'
        )->fetchAll(PDO::FETCH_COLUMN);

        return [
            'empresas' => $empresas,
            'unidades' => $unidades,
            'cargos' => $cargos,
            'setores' => $setores,
            'centrosCusto' => $centrosCusto,
        ];
    }

    /** Menor admissão e maior admissão/demissão registradas — usado para limitar seletores de período. */
    public function periodoDisponivel(): array
    {
        $pdo = $this->connection();
        $row = $pdo->query(
            'SELECT MIN(admissao) AS primeira_admissao,
                    MAX(admissao) AS ultima_admissao,
                    MAX(demissao) AS ultima_demissao
             FROM colaboradores_metadados'
        )->fetch(PDO::FETCH_ASSOC);
        return $row ?: ['primeira_admissao' => null, 'ultima_admissao' => null, 'ultima_demissao' => null];
    }
}
