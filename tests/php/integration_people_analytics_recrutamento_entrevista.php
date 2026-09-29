<?php

/**
 * Integração — Etapa 3 (People Analytics: seções executivas de Recrutamento e Seleção e de
 * Entrevistas de Desligamento, 2026-09).
 *
 * Prova a FIAÇÃO em PeopleAnalyticsService::montarPainel() — reaproveita INTEGRALMENTE
 * RecrutamentoIndicadoresService::montarPainel() e DashboardEntrevistaDesligamentoService::
 * montarPainel() via injeção de dependência, nunca duplicando Funil/Tempo por Etapa/motivos/eNPS.
 * Usa dublês (stubs) desses dois Services: a correção matemática deles já é coberta por
 * integration_dashboard_recrutamento.php e integration_dashboard_entrevista_desligamento.php — aqui
 * provamos só que o painel do People Analytics repassa os números exatamente, traduz Empresa
 * (codigo_empresa do METADADOS -> id local) do mesmo jeito já usado por Vagas Abertas/Fechadas, e
 * nunca confunde o motivo FORMAL do desligamento (RHCONTRATOS) com o motivo DECLARADO na entrevista.
 */

require __DIR__ . '/../../app/core/bootstrap.php';

SchemaManager::ensure();
$pdo = Database::conn();
$falhas = [];
$check = static function (bool $cond, string $msg) use (&$falhas): void {
    if (!$cond) {
        $falhas[] = $msg;
        fwrite(STDERR, "  [FALHOU] {$msg}\n");
    } else {
        echo "  [ok] {$msg}\n";
    }
};

$suffix = (string)time() . '-' . (string)random_int(1000, 9999);
$criados = ['empresas' => []];

try {
    $funilFixture = [
        ['label' => 'Candidatos Inscritos', 'quantidade' => 40],
        ['label' => 'Triagem RH', 'quantidade' => 25],
        ['label' => 'Entrevista RH', 'quantidade' => 15],
        ['label' => 'Entrevista Gestor', 'quantidade' => 8],
        ['label' => 'Contratados', 'quantidade' => 5],
    ];
    $tempoPorEtapaFixture = [
        'triagem-rh' => ['label' => 'Triagem RH', 'media_dias' => 2.5, 'amostra_concluida' => 20, 'atualmente_na_etapa' => 3],
        'entrevista-rh' => ['label' => 'Entrevista RH', 'media_dias' => 4.0, 'amostra_concluida' => 12, 'atualmente_na_etapa' => 2],
        'entrevista-gestor' => ['label' => 'Entrevista Gestor', 'media_dias' => null, 'amostra_concluida' => 0, 'atualmente_na_etapa' => 1],
        'admissao' => ['label' => 'Admissão', 'media_dias' => 1.0, 'amostra_concluida' => 5, 'atualmente_na_etapa' => 0],
    ];
    $recrutamentoStub = new class ($funilFixture, $tempoPorEtapaFixture) extends RecrutamentoIndicadoresService {
        private array $funil;
        private array $tempoPorEtapa;
        public ?array $ultimoFiltro = null;
        public function __construct(array $funil, array $tempoPorEtapa)
        {
            $this->funil = $funil;
            $this->tempoPorEtapa = $tempoPorEtapa;
        }
        public function montarPainel(array $filtros, DateTimeImmutable $inicio, DateTimeImmutable $fim): array
        {
            $this->ultimoFiltro = $filtros;
            return [
                'total_candidaturas_cohort' => 40,
                'vagas' => ['abertas' => 7, 'fechadas_no_periodo' => 3],
                'tempo_contratacao' => ['media_dias' => 18.5, 'amostra' => 5],
                'funil' => $this->funil,
                'tempo_por_etapa' => $this->tempoPorEtapa,
                'proposta_aceite' => ['disponivel' => false],
                'desistencia' => ['disponivel' => false],
                'qualidade' => ['efetivacao' => [], 'pesquisa_experiencia' => []],
            ];
        }
    };

    $entrevistaStub = new class extends DashboardEntrevistaDesligamentoService {
        public ?array $ultimoFiltro = null;
        public function __construct()
        {
        }
        public function montarPainel(array $filtros): array
        {
            $this->ultimoFiltro = $filtros;
            return [
                'periodo' => ['inicio' => $filtros['inicio'], 'fim' => $filtros['fim'], 'bordas_parciais' => false],
                'executivo' => [
                    'desligamentos' => 12, 'geradas' => 10, 'respondidas' => 8, 'taxa_resposta' => 80.0,
                    'satisfacao' => ['media' => 4.2, 'n' => 8],
                    'enps' => ['valor' => 25.0, 'n' => 8, 'promotores' => 4, 'neutros' => 3, 'detratores' => 1],
                    'permanencia' => ['meses' => 14.3, 'n' => 12, 'invalidos' => 0],
                    'cobertura' => ['elegiveis' => 12, 'nao_elegiveis' => 0, 'sem_entrevista' => 2, 'com_entrevista' => 10, 'percentual' => 83.3],
                ],
                'motivos' => ['total' => 8, 'itens' => [
                    ['codigo' => 'X1', 'rotulo' => 'Falta de oportunidade de crescimento', 'quantidade' => 3, 'percentual' => 37.5],
                    ['codigo' => 'X2', 'rotulo' => 'Remuneração', 'quantidade' => 2, 'percentual' => 25.0],
                ]],
                'fatores' => ['total' => 0, 'itens' => []],
                'blocos' => ['lideranca' => [], 'cultura' => [], 'integracao' => []],
                'mensal' => [],
                'unidades' => [],
                'cargos' => [],
            ];
        }
    };

    $paRepo = new PeopleAnalyticsRepository($pdo);
    $recRepo = new RecrutamentoIndicadoresRepository($pdo);
    $consultaRepo = new ColaboradorMetadadosConsultaRepository($pdo);
    $service = new PeopleAnalyticsService($paRepo, $recRepo, $consultaRepo, $recrutamentoStub, $entrevistaStub);

    $inicio = new DateTimeImmutable('2026-01-01');
    $fim = new DateTimeImmutable('2026-01-31');
    $painel = $service->montarPainel([], $inicio, $fim);

    // ---- Recrutamento e Seleção ------------------------------------------------------------------
    $check(($painel['recrutamento']['disponivel'] ?? null) === true, '(1) recrutamento.disponivel = true sem filtro de Empresa');
    $check($painel['recrutamento']['funil'] === $funilFixture, '(2) Funil do People Analytics é EXATAMENTE o que RecrutamentoIndicadoresService devolveu — nenhum recálculo');
    $check($painel['recrutamento']['admitidos'] === 5, '(3) "Admitidos via Recrutamento" = quantidade da ÚLTIMA etapa do funil (posição, não hardcode de label)');
    $check($painel['recrutamento']['candidatos_cohort'] === 40, '(4) Candidatos no processo = total_candidaturas_cohort repassado sem alteração');
    $check($painel['recrutamento']['vagas'] === ['abertas' => 7, 'fechadas_no_periodo' => 3], '(5) Vagas Abertas/Fechadas repassadas sem alteração');
    $check($painel['recrutamento']['tempo_contratacao'] === ['media_dias' => 18.5, 'amostra' => 5], '(6) Tempo Médio de Contratação repassado sem alteração');
    $check($painel['recrutamento']['tempo_por_etapa'] === $tempoPorEtapaFixture, '(7) Tempo por Etapa repassado sem alteração (inclusive null de amostra insuficiente)');
    $check($recrutamentoStub->ultimoFiltro['vaga_id'] === null, '(8) People Analytics nunca filtra Recrutamento por Vaga (só Empresa/período)');
    $check($recrutamentoStub->ultimoFiltro['empresa_id'] === null, '(9) Sem filtro de Empresa no People Analytics: empresa_id repassado como null (universo geral)');
    $check(!array_key_exists('qualidade', $painel['recrutamento']), '(9b) "Qualidade" (Efetivação/Pesquisa de Experiência) NÃO é exposta aqui — já existe em painel[avaliacao_experiencia] e a Pesquisa de Experiência não faz parte do escopo desta seção');

    // ---- Entrevistas de Desligamento --------------------------------------------------------------
    $check($painel['entrevista_desligamento']['executivo']['desligamentos'] === 12, '(10) Desligamentos (Entrevista) repassado sem alteração');
    $check($painel['entrevista_desligamento']['executivo']['respondidas'] === 8, '(11) Entrevistas respondidas repassado sem alteração');
    $check($painel['entrevista_desligamento']['executivo']['enps']['valor'] === 25.0, '(12) eNPS repassado sem alteração');
    $check(count($painel['entrevista_desligamento']['motivos']['itens']) === 2, '(13) Motivos DECLARADOS na entrevista repassados sem alteração');
    $check($painel['entrevista_desligamento']['motivos']['itens'][0]['rotulo'] === 'Falta de oportunidade de crescimento', '(14) Rótulo do motivo declarado preservado');
    $check($entrevistaStub->ultimoFiltro['unidade'] === null, '(15) People Analytics nunca filtra Entrevista de Desligamento por Unidade (sem correspondência segura com o filtro global de Empresa)');
    $check(
        $entrevistaStub->ultimoFiltro['inicio']->format('Y-m-d') === '2026-01-01' && $entrevistaStub->ultimoFiltro['fim']->format('Y-m-d') === '2026-01-31',
        '(16) Período do People Analytics repassado corretamente à Entrevista de Desligamento'
    );

    // ---- Motivo FORMAL (RHCONTRATOS) nunca é confundido com motivo DECLARADO (entrevista) ---------
    $check(isset($painel['desligamentos_por_motivo']), '(17) "Desligamentos por Motivo" (formal, já existente desde a Etapa 1) continua existindo — não foi removido nem substituído');
    $check($painel['desligamentos_por_motivo'] !== $painel['entrevista_desligamento']['motivos'], '(18) As duas fontes de motivo nunca são o mesmo array — motivo formal ≠ motivo declarado na entrevista (§16 da Etapa 3)');

    // ---- Empresa sem correspondência: Recrutamento fica indisponível, nunca finge zero ------------
    $codigoInexistente = 'ZZ_SEM_CORRESP_' . $suffix;
    $painelSemCorresp = $service->montarPainel(['codigo_empresa' => $codigoInexistente], $inicio, $fim);
    $check($painelSemCorresp['recrutamento']['disponivel'] === false, '(19) Empresa sem correspondência no catálogo local: recrutamento fica indisponível (nunca finge zero)');
    $check($painelSemCorresp['recrutamento']['empresa_sem_correspondencia'] === true, '(20) Gap de correspondência reportado explicitamente — mesma convenção já usada por Vagas Abertas/Fechadas');
    $check($painelSemCorresp['vagas']['empresa_sem_correspondencia'] === true, '(21) Mesmo gap já existente em Vagas Abertas/Fechadas — coerência entre os dois indicadores para a mesma Empresa');

    // ---- Empresa com correspondência real: Recrutamento fica disponível e usa o id LOCAL correto --
    $codigoReal = 'ZZPA' . substr($suffix, -10);
    $stmt = $pdo->prepare('INSERT INTO empresas (nome, slug, codigo_empresa, ativo) VALUES (?, ?, ?, 1)');
    $stmt->execute(['ZZ Empresa PA ' . $suffix, 'zz-empresa-pa-' . $suffix, $codigoReal]);
    $empresaLocalId = (int)$pdo->lastInsertId();
    $criados['empresas'][] = $empresaLocalId;

    $painelComEmpresa = $service->montarPainel(['codigo_empresa' => $codigoReal], $inicio, $fim);
    $check($painelComEmpresa['recrutamento']['disponivel'] === true, '(22) Empresa com correspondência real: recrutamento fica disponível');
    $check($recrutamentoStub->ultimoFiltro['empresa_id'] === $empresaLocalId, '(23) codigo_empresa (METADADOS) traduzido para o id LOCAL correto antes de chamar RecrutamentoIndicadoresService — mesma tradução já usada por Vagas Abertas/Fechadas');

    // ---- Marcadores de conteúdo do partial de resultado (view) -------------------------------------
    $conteudoResultado = (string)file_get_contents(APP_PATH . '/views/admin/partials/dashboard/resultado.php');
    $check(str_contains($conteudoResultado, 'Recrutamento e Seleção'), '(24) Seção "Recrutamento e Seleção" presente no partial de resultado do People Analytics');
    $check(str_contains($conteudoResultado, 'Funil de Recrutamento'), '(25) "Funil de Recrutamento" presente (reaproveitado do Dashboard de Recrutamento)');
    $check(str_contains($conteudoResultado, 'Entrevistas de Desligamento'), '(26) Seção "Entrevistas de Desligamento" presente no partial de resultado do People Analytics');
    $check(str_contains($conteudoResultado, 'Motivo apontado na entrevista'), '(27) "Motivo apontado na entrevista" presente, rotulado distinto de "Desligamentos por Motivo"');
    $check(!str_contains($conteudoResultado, 'Nota média da avaliação de experiência'), '(28) "Nota média da avaliação de experiência" (Avaliação 45/90, ainda não implementada) NÃO aparece — nenhum placeholder foi criado (§11 da Etapa 3)');
    $check(
        strpos($conteudoResultado, 'Recrutamento e Seleção') < strpos($conteudoResultado, 'Entrevistas de Desligamento')
            && strpos($conteudoResultado, 'Entrevistas de Desligamento') < strpos($conteudoResultado, "\$secaoDivisor('Dados complementares')"),
        '(29) Ordem narrativa: Recrutamento e Seleção → Entrevistas de Desligamento → Dados complementares (§22 da Etapa 3)'
    );

    echo $falhas === [] ? "\nPEOPLE_ANALYTICS_RECRUTAMENTO_ENTREVISTA_OK\n" : "\n" . count($falhas) . " verificação(ões) falharam.\n";
} finally {
    foreach ($criados['empresas'] as $id) {
        $pdo->prepare('DELETE FROM empresas WHERE id = ?')->execute([$id]);
    }
}

if ($falhas !== []) {
    exit(1);
}
