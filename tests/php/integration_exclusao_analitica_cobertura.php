<?php

/**
 * Cobertura ESTÁTICA da exclusão analítica GLOBAL (contratos marcados em
 * colaboradores_metadados_exclusoes_analiticas nunca entram em listagem, contagem, média, filtro,
 * exportação nem relatório do RH — ver ColaboradorMetadadosElegibilidadeService).
 *
 * Varre app/ e, para cada `FROM|JOIN` sobre `colaboradores_metadados` OU sobre uma tabela de módulo
 * que guarda o vínculo em `metadados_id`, exige que a FUNÇÃO que contém a consulta:
 *   (a) use a cláusula central (clausulaSqlExclusao / clausulaSqlExclusaoPorMetadadosId), OU
 *   (b) chame um helper do MESMO arquivo que, ele próprio, aplica a cláusula (ex.: montarFiltros(),
 *       condicoesContrato(), filtroSnapshot()) — o helper é verificado, não presumido, OU
 *   (c) esteja na lista EXPLÍCITA de exceções abaixo, cada uma com o motivo.
 * Uma consulta nova que esqueça a exclusão faz este teste FALHAR, em vez de reabrir a divergência
 * entre módulos.
 *
 * Exceções legítimas: sincronização/reconciliação do METADADOS (precisam ver TODOS os contratos);
 * busca PONTUAL por id/token (administração, autenticação, vínculo de Usuários, edição de um
 * registro já aberto — a rastreabilidade administrativa continua possível); constantes de consulta
 * cujo filtro é aplicado nos pontos de uso (verificados pelas funções que as usam).
 */

$raiz = realpath(__DIR__ . '/../../app');
$tabelas = [
    // contratos do METADADOS e tabelas de módulo que guardam metadados_id
    'colaboradores_metadados', 'pdis', 'avaliacoes_experiencia', 'avaliacoes_desempenho',
    'entrevistas_desligamento', 'entrevista_desligamento_liberacoes', 'feedbacks', 'pesquisas_integracao',
    // Fase C: cadastro LOCAL (colaboradores.metadados_id) e processos que referenciam colaboradores locais
    'colaboradores', 'solicitacoes_vaga', 'movimentacoes_pessoal', 'usuario_colaboradores', 'colaborador_avaliacoes',
];

/** "arquivo::função" (ou "arquivo::*") => motivo. "(const)" = constante de classe cujo filtro é aplicado nos pontos de uso. */
$excecoes = [
    // --- sincronização, reconciliação e administração do espelho ---
    'services/MetadadosSyncService.php::originConflict' => 'sincronização do METADADOS: precisa ver TODOS os contratos para detectar mistura de origem',
    'repositories/ColaboradorMetadadosRepository.php::findByVinculo' => 'upsert do sincronizador, pela chave natural',
    'services/ColaboradorMetadadosReconciliationService.php::loadLocalRows' => 'reconciliação local×METADADOS (análise administrativa)',
    'services/ColaboradorMetadadosReconciliationService.php::loadMirrorRows' => 'reconciliação local×METADADOS (análise administrativa)',
    'services/ColaboradorMetadadosLinkService.php::validateAgainstDatabase' => 'serviço de vínculo local×METADADOS (técnico)',
    'services/ColaboradorMetadadosLinkService.php::apply' => 'serviço de vínculo local×METADADOS (técnico)',
    'services/ColaboradorExtensaoLocalService.php::obterOuCriar' => 'materialização técnica da extensão local por id',
    'services/ColaboradorExtensaoLocalService.php::buscarIdPorMetadadosId' => 'operação técnica da extensão local',
    'services/ColaboradorExtensaoLocalService.php::gerarSlugUnico' => 'operação técnica da extensão local (unicidade de slug)',
    'services/ColaboradorMetadadosElegibilidadeService.php::excluirPorVinculo' => 'cadastro da exclusão: valida a existência do vínculo',
    'services/ColaboradorMetadadosElegibilidadeService.php::listarExcluidos' => 'auditoria das exclusões',
    'services/ColaboradorMetadadosElegibilidadeService.php::vinculoPorMetadadosId' => 'resolve a chave natural do contrato (mecanismo da exclusão)',
    'services/ColaboradorMetadadosElegibilidadeService.php::metadadosIdDoColaboradorLocal' => 'resolve o contrato de um colaborador local (mecanismo da exclusão)',
    // --- busca PONTUAL por id/token (administração, autenticação, registro já aberto) ---
    'models/User.php::vincularMetadados' => 'valida existência do contrato ao vincular um usuário (por id, administrativo)',
    'services/UsuarioContextoOrganizacionalService.php::resolverContextoOficial' => 'herança de setor/cargo do vínculo do usuário (por id; não quebra login)',
    'models/Colaborador.php::mesclarComEspelhoOficial' => 'mescla o espelho de UM contrato por id (cadastro local)',
    'repositories/ColaboradorMetadadosConsultaRepository.php::findById' => 'um contrato por id (administração de Usuários / auditoria)',
    'repositories/AvaliacaoExperienciaRepository.php::codigoSetorAtual' => 'setor atual de UM contrato (checagem técnica de acesso por Setor Gerenciado, por id)',
    'repositories/PdiRepository.php::pdisPorOrigem' => 'navegação reversa a partir de UM documento de origem já protegido pela barreira individual',
    'models/PesquisaIntegracaoQr.php::buscarPesquisaDoEvento' => 'checagem de duplicidade de UM contrato/evento',
    'models/PesquisaIntegracaoQr.php::contratosPorIds' => 'nome/cargo de respondentes já listados (por id)',
    // --- constantes de consulta: o filtro é aplicado onde são usadas (verificado pelas funções que as usam) ---
    'models/PesquisaIntegracaoQr.php::(const)' => 'SELECT_CONTRATO: filtro aplicado em contratosAtivosPorCpfENascimento()/contratoAtivoPorId()',
    'repositories/DashboardIntegracaoRepository.php::(const)' => 'SELECT_BASE: filtro aplicado em montarWhere()',
    'repositories/EntrevistaDesligamentoRepository.php::(const)' => 'SELECT_CONTRATO/FROM_OPERACIONAL: filtro em condicoesElegiveis()/listarEntrevistas(); usos por id são pontuais',
    'repositories/PdiRepository.php::(const)' => 'SELECT_CONTRATO: filtro em buscarContratos(); buscarContrato() é por id',
    // --- Fase C: contexto organizacional técnico do usuário, seeds de desenvolvimento e importações técnicas ---
    'models/MovimentacaoPessoal.php::userAccessProfile' => 'perfil de acesso técnico do usuário logado (contexto organizacional; não quebra login)',
    'models/MovimentacaoPessoal.php::seedReferenceData' => 'seed de dados de desenvolvimento (não é leitura operacional)',
    'models/SolicitacaoVaga.php::seedReferenceData' => 'seed de dados de desenvolvimento (não é leitura operacional)',
    'models/SolicitacaoVaga.php::findUserColaboradorByColaboradorId' => 'resolve o usuário de um colaborador JÁ validado por requiredEntityId() (técnico, por id)',
    'models/Candidatura.php::all' => 'lista de CANDIDATOS (pessoas externas) e Kanban de recrutamento: só lê sv.setor_id da vaga; CONDICIONADA (ver $condicoes) a nunca ler colaborador/contrato — provado por integration_exclusao_analitica_candidaturas.php',
    'repositories/UsuarioColaboradorRepository.php::findByColaboradorId' => 'vínculo técnico usuário×colaborador (contexto organizacional do usuário)',
    'repositories/UsuarioColaboradorRepository.php::findByUsuarioId' => 'vínculo técnico usuário×colaborador (contexto organizacional do usuário)',
    'services/CollaboratorSpreadsheetImportService.php::findCollaboratorsByCompanyAndCode' => 'importação XLSX: deduplicação técnica contra o cadastro local',
    'services/CollaboratorSpreadsheetImportService.php::findCollaboratorsByCpfAndAdmission' => 'importação XLSX: deduplicação técnica contra o cadastro local',
    'services/CollaboratorSpreadsheetImportService.php::findActiveCollaboratorsByCompanyAndCpf' => 'importação XLSX: deduplicação técnica contra o cadastro local',
    'services/CollaboratorSpreadsheetImportService.php::collaboratorSlugExists' => 'importação XLSX: unicidade técnica de slug',
    'models/AvaliacaoDesempenho.php::delete' => 'remoção administrativa por id de avaliação legada (DELETE, não é leitura analítica)',
];

// Cláusula SQL (listas/agregados) OU barreira de acesso individual (um registro por id/token).
$marcadores = '/clausulaSqlExclusao|acessoIndividual\(|filtrarRegistroIndividual\(|filtrarProcessoIndividual\(|colaboradorLocalExcluido\(/';

/**
 * Exceções CONDICIONADAS: a função só continua isenta enquanto o corpo dela NÃO casar com o padrão proibido.
 * Se alguém passar a ler colaborador/contrato nela, a exceção deixa de valer e o teste falha.
 */
$condicoes = [
    'models/Candidatura.php::all' => '/(?:FROM|JOIN)\s+(?:colaboradores|colaboradores_metadados|usuario_colaboradores|movimentacoes_pessoal|colaborador_avaliacoes)\b'
        . '|\bmetadados_id\b|\bsv\.(?:gestor_solicitante|colaborador_substituido|lider_imediato|nome_contratado)/i',
];

$falhas = [];
$achados = 0;
$usadas = [];

$ls = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($raiz, FilesystemIterator::SKIP_DOTS));
$arquivos = [];
foreach ($ls as $arquivo) {
    if ($arquivo->getExtension() === 'php') {
        $arquivos[str_replace('\\', '/', substr($arquivo->getPathname(), strlen($raiz) + 1))] = $arquivo->getPathname();
    }
}

foreach ($arquivos as $rel => $path) {
    $linhas = preg_split('/\R/', (string)file_get_contents($path));

    $funcoes = [];
    foreach ($linhas as $i => $l) {
        if (preg_match('/^\s*(?:(?:public|private|protected|static|final)\s+)*function\s+(\w+)\s*\(/', $l, $m)) {
            $funcoes[] = ['ini' => $i, 'nome' => $m[1]];
        }
    }
    foreach ($funcoes as $k => $f) {
        $funcoes[$k]['fim'] = isset($funcoes[$k + 1]) ? $funcoes[$k + 1]['ini'] - 1 : count($linhas) - 1;
        $funcoes[$k]['corpo'] = implode("\n", array_slice($linhas, $f['ini'], $funcoes[$k]['fim'] - $f['ini'] + 1));
    }
    // Helpers que, eles mesmos, aplicam a cláusula (verificados, não presumidos).
    $aplicadoras = [];
    foreach ($funcoes as $f) {
        if (preg_match($marcadores, $f['corpo'])) {
            $aplicadoras[] = $f['nome'];
        }
    }

    foreach ($linhas as $i => $l) {
        foreach ($tabelas as $t) {
            if (!preg_match('/\b(?:FROM|JOIN)\s+' . preg_quote($t, '/') . '(?![A-Za-z0-9_])/i', $l)) {
                continue;
            }
            if (preg_match('/^\s*(\*|\/\/|#)/', $l)) {
                continue; // comentário
            }
            $achados++;

            $fn = null;
            foreach ($funcoes as $f) {
                if ($i >= $f['ini'] && $i <= $f['fim']) {
                    $fn = $f;
                    break;
                }
            }
            $nome = $fn['nome'] ?? '(const)';
            $corpo = $fn['corpo'] ?? $l;

            if (isset($excecoes["{$rel}::{$nome}"])) {
                $usadas["{$rel}::{$nome}"] = true;
                if (isset($condicoes["{$rel}::{$nome}"]) && preg_match($condicoes["{$rel}::{$nome}"], $corpo) === 1) {
                    $falhas[] = sprintf('%s:%d  %s(): a exceção condicionada foi VIOLADA — a função passou a ler colaborador/contrato; aplique a exclusão analítica', $rel, $i + 1, $nome);
                }
                continue; // exceção justificada (sempre por FUNÇÃO — não há exceção genérica por arquivo)
            }
            if (preg_match($marcadores, $corpo)) {
                continue; // (a)
            }
            $viaHelper = false;
            foreach ($aplicadoras as $h) {
                if ($h !== $nome && preg_match('/(?:->|::)' . preg_quote($h, '/') . '\s*\(/', $corpo)) {
                    $viaHelper = true; // (b)
                    break;
                }
            }
            if ($viaHelper) {
                continue;
            }
            $falhas[] = sprintf('%s:%d  %s() lê %s sem a exclusão analítica', $rel, $i + 1, $nome, $t);
        }
    }
}

// Cada exceção precisa continuar apontando para algo que existe (evita lista de exceções morta).
foreach ($excecoes as $chave => $motivo) {
    [$arq, $fn] = explode('::', $chave, 2);
    if (str_ends_with($chave, '::*')) {
        $falhas[] = "exceção GENÉRICA por arquivo não é permitida (use uma exceção por função, com motivo): {$chave}";
    } elseif (!isset($arquivos[$arq])) {
        $falhas[] = "exceção aponta para arquivo inexistente: {$chave}";
    } elseif (!isset($usadas[$chave])) {
        $falhas[] = "exceção sem efeito (a função não lê mais essas tabelas ou foi renomeada) — remova: {$chave}";
    }
}

echo "Pontos de leitura analisados: {$achados}\n";
if ($falhas !== []) {
    fwrite(STDERR, "\nPontos SEM exclusão analítica (corrija ou, se for exceção legítima, registre com motivo em \$excecoes):\n");
    foreach ($falhas as $f) {
        fwrite(STDERR, "  [FALHOU] {$f}\n");
    }
    fwrite(STDERR, "\n" . count($falhas) . " ponto(s) sem cobertura.\n");
    exit(1);
}
echo "\nEXCLUSAO_ANALITICA_COBERTURA_OK\n";
