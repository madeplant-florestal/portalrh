<?php
/**
 * Classificação central de MOVIMENTAÇÃO entre contratos do espelho METADADOS. Só IDENTIFICA os
 * pares (Cenário A — transferência contínua sem rescisão real; Cenário B — rescisão + novo
 * contrato); nunca decide sozinha nem aplica nada sobre Turnover/Admissões/Desligamentos — quem
 * aplica é `PeopleAnalyticsService::montarPainel()`, via `classificarMovimentacoes()` (ponto único
 * de entrada), que traduz o resultado em conjuntos de exclusão consumidos pelos parâmetros
 * dedicados de `RhIndicadoresService` (regra de negócio congelada em 2026-09: transferência
 * interempresa é movimentação interna, nunca Turnover/Admissão/Desligamento real).
 *
 * Regra determinada por investigação real contra RHMADEPLANT (nunca RHTESTE):
 *   - `CONTRATOANTERIOR`/`UNIDADEANTERIOR`/`ESTABANTERIOR` são DESCARTADOS como link — testados
 *     com o JOIN corretamente normalizado (empresa anterior = CAST(ESTABANTERIOR AS INT)), só 1
 *     dos 34 casos encontrou QUALQUER correspondência em RHCONTRATOS, e mesmo esse 1 é de pessoa
 *     diferente (CPF distinto). Não usar para nada.
 *   - Não existe motivo de rescisão oficial de transferência em uso: os códigos CLT/eSocial 011 e
 *     012 ("Transferência de Estab. c/Ônus/s/Ônus p/Cedente") têm ZERO ocorrência em RHMADEPLANT.
 *   - O motivo `016` ("Rescisão por Acordo entre as Partes") aparece em 17 dos 18 casos reais de
 *     transferência identificados por CPF+empresa+gap curto — mas também aparece em dezenas de
 *     rescisões legítimas sem qualquer readmissão depois. Motivo isolado NUNCA basta: é só sinal
 *     de reforço, nunca obrigatório (1 dos 18 casos reais usa motivo `046`, não `016`).
 *   - A única combinação que bateu com 100% dos 18 casos reais: mesmo CPF, empresa diferente,
 *     0 a 10 dias entre a rescisão da origem e a admissão do destino (o maior gap real observado
 *     foi 7 dias).
 *
 * Contrato de entrada: os arrays de contrato aqui PRECISAM ter `cpf` (chave 'cpf') — diferente de
 * `RhIndicadoresRepository::buscarContratos()`/`PeopleAnalyticsRepository::buscarContratos()`, que
 * deliberadamente NUNCA selecionam CPF/nome (ver docs/claude/indicadores-rh.md §"Privacidade").
 * Identificar transferência é, por natureza, uma operação de ligar a MESMA pessoa entre dois
 * registros — precisa de uma fonte de dados própria com `cpf`, nunca reaproveitar a consulta
 * privacy-scrubbed dos indicadores. Este serviço não busca dados sozinho: recebe os contratos já
 * carregados (ver §19 do diagnóstico) e devolve só a classificação, sem persistir nada.
 */
class MetadadosMovimentacaoService
{
    /** Maior gap real observado nos 18 casos confirmados foi 7 dias; 10 dá margem sem abrir demais. */
    public const GAP_MAXIMO_DIAS = 10;

    /**
     * Cenário A — transferência/recodificação interempresa SEM rescisão real: a chave antiga some
     * da origem (fica `ausente_na_origem = 1`, sem nunca ter recebido `demissao`) e a mesma pessoa
     * (CPF) segue vigente em outra empresa. O vínculo trabalhista nunca foi encerrado.
     */
    public const TIPO_CONTINUA = 'TRANSFERENCIA_INTEREMPRESA_CONTINUA';

    /** Cenário B — rescisão real seguida de novo contrato em outra empresa (classificarTransferencia() abaixo). */
    public const TIPO_RECONTRATACAO = 'TRANSFERENCIA_INTEREMPRESA_RECONTRATACAO';

    /**
     * Classifica se `$destino` é, com razoável confiança, a continuação de `$origem` por
     * transferência entre empresas (nunca recontratação, nunca sobreposição de vínculos).
     *
     * Regras OBRIGATÓRIAS (todas precisam valer):
     *   1. CPF presente e igual entre origem e destino;
     *   2. `codigo_empresa` diferente entre origem e destino;
     *   3. origem tem `demissao` preenchida;
     *   4. destino tem `admissao` preenchida;
     *   5. `0 <= (admissao_destino - demissao_origem em dias) <= GAP_MAXIMO_DIAS`.
     * Gap negativo (destino admitido ANTES da rescisão da origem — vínculos sobrepostos) nunca é
     * classificado automaticamente como transferência: é um caso diferente, tratado à parte.
     *
     * Sinal complementar, NUNCA obrigatório: `motivo_rescisao_codigo` da origem (016 hoje; 011/012
     * se um dia passarem a ser usados) — só informado no retorno, não entra na decisão.
     *
     * @return array{eh_transferencia:bool, contrato_origem:?string, contrato_destino:?string, gap_dias:?int, motivo_origem:?string}
     */
    public static function classificarTransferencia(array $origem, array $destino): array
    {
        $resultado = [
            'eh_transferencia' => false,
            'contrato_origem' => self::stringOuNull($origem['identificador'] ?? null),
            'contrato_destino' => self::stringOuNull($destino['identificador'] ?? null),
            'gap_dias' => null,
            'motivo_origem' => self::stringOuNull($origem['motivo_rescisao_codigo'] ?? null),
        ];

        // Regra 1 — CPF presente e igual.
        $cpfOrigem = trim((string)($origem['cpf'] ?? ''));
        $cpfDestino = trim((string)($destino['cpf'] ?? ''));
        if ($cpfOrigem === '' || $cpfOrigem !== $cpfDestino) {
            return $resultado;
        }

        // Regra 2 — empresa diferente (PESSOA/codigo_pessoa é numerado por empresa — nunca usado
        // isoladamente como identificador; CPF já resolveu "é a mesma pessoa" acima).
        $empresaOrigem = trim((string)($origem['codigo_empresa'] ?? ''));
        $empresaDestino = trim((string)($destino['codigo_empresa'] ?? ''));
        if ($empresaOrigem === '' || $empresaDestino === '' || $empresaOrigem === $empresaDestino) {
            return $resultado;
        }

        // Regra 3 — origem precisa ter sido encerrada.
        $demissaoOrigem = self::normalizeDate($origem['demissao'] ?? null);
        if ($demissaoOrigem === null) {
            return $resultado;
        }

        // Regra 4 — destino precisa ter uma admissão de fato.
        $admissaoDestino = self::normalizeDate($destino['admissao'] ?? null);
        if ($admissaoDestino === null) {
            return $resultado;
        }

        // Regra 5 — gap dentro da janela, nunca negativo (sobreposição não se classifica aqui).
        $gap = (int)$demissaoOrigem->diff($admissaoDestino)->format('%r%a');
        $resultado['gap_dias'] = $gap;
        if ($gap < 0 || $gap > self::GAP_MAXIMO_DIAS) {
            return $resultado;
        }

        $resultado['eh_transferencia'] = true;
        return $resultado;
    }

    /**
     * Percorre TODOS os contratos, agrupa por CPF e classifica cada par cronologicamente
     * CONSECUTIVO (nunca o primeiro contrato encontrado, nunca um contrato de episódio anterior
     * ou posterior ao imediato — ver §16 do diagnóstico: comparar sempre com o próximo elegível
     * na linha do tempo daquele CPF, para não relacionar origem com uma recontratação de anos
     * depois nem com um contrato simultâneo). Não modifica `$contratos`; não persiste nada.
     *
     * @param array $contratos Cada item precisa ter, no mínimo: cpf, codigo_empresa, admissao,
     *                         demissao, motivo_rescisao_codigo (opcional), identificador (opcional).
     * @return array<int, array{eh_transferencia:bool, contrato_origem:?string, contrato_destino:?string, gap_dias:?int, motivo_origem:?string}>
     *         Só os pares classificados como transferência (eh_transferencia sempre true aqui).
     */
    public static function identificarTransferencias(array $contratos): array
    {
        $porCpf = [];
        foreach ($contratos as $contrato) {
            $cpf = trim((string)($contrato['cpf'] ?? ''));
            if ($cpf === '') {
                continue;
            }
            $porCpf[$cpf][] = $contrato;
        }

        $classificacoes = [];
        foreach ($porCpf as $grupo) {
            if (count($grupo) < 2) {
                continue;
            }
            usort($grupo, static function (array $a, array $b): int {
                return strcmp((string)($a['admissao'] ?? ''), (string)($b['admissao'] ?? ''));
            });
            for ($i = 0, $total = count($grupo); $i < $total - 1; $i++) {
                $classificacao = self::classificarTransferencia($grupo[$i], $grupo[$i + 1]);
                if ($classificacao['eh_transferencia']) {
                    $classificacoes[] = $classificacao;
                }
            }
        }
        return $classificacoes;
    }


    /**
     * Classifica se `$destino` é, com razoável confiança, a CONTINUAÇÃO DE `$origem` por
     * transferência/recodificação interempresa SEM rescisão real (Cenário A — ver TIPO_CONTINUA).
     * Nunca confundir com classificarTransferencia() (Cenário B, rescisão + novo contrato).
     *
     * Regras OBRIGATÓRIAS (todas precisam valer):
     *   1. CPF presente e igual entre origem e destino;
     *   2. `codigo_empresa` diferente entre origem e destino;
     *   3. origem está `ausente_na_origem = 1` — a chave antiga sumiu da fonte atual;
     *   4. origem NUNCA recebeu `demissao` (não é uma rescisão real represada — é isso que
     *      distingue o Cenário A do Cenário B);
     *   5. destino NÃO está `ausente_na_origem = 1` (é o vínculo vigente hoje, nunca outro
     *      registro igualmente órfão) e tem `admissao` preenchida.
     *
     * `data_transferencia`: RHCONTRATOS.DATAULTTRANSFERENCIA sincronizado (2026-09,
     * `data_ultima_transferencia`) — fonte oficial da data efetiva. Precedência: o valor do
     * DESTINO (o contrato vigente reflete quando ELE passou a existir por transferência);
     * fallback para o da origem só se o destino não tiver (defensivo, nunca observado na base
     * real: 66/66 casos reais têm o valor no destino). `null` quando nenhum dos dois tem o campo
     * preenchido (ex.: sync antigo, campo ainda não populado) — a classificação do PAR continua
     * valendo, só a vigência analítica de precisão de dia (aplicarVigenciaAnalitica()) fica
     * indisponível para esse par especificamente.
     *
     * @return array{eh_transferencia_continua:bool, contrato_origem:?string, contrato_destino:?string, data_transferencia:?string}
     */
    public static function classificarTransferenciaContinua(array $origem, array $destino): array
    {
        $resultado = [
            'eh_transferencia_continua' => false,
            'contrato_origem' => self::stringOuNull($origem['identificador'] ?? null),
            'contrato_destino' => self::stringOuNull($destino['identificador'] ?? null),
            'data_transferencia' => null,
        ];

        $cpfOrigem = trim((string)($origem['cpf'] ?? ''));
        $cpfDestino = trim((string)($destino['cpf'] ?? ''));
        if ($cpfOrigem === '' || $cpfOrigem !== $cpfDestino) {
            return $resultado;
        }

        $empresaOrigem = trim((string)($origem['codigo_empresa'] ?? ''));
        $empresaDestino = trim((string)($destino['codigo_empresa'] ?? ''));
        if ($empresaOrigem === '' || $empresaDestino === '' || $empresaOrigem === $empresaDestino) {
            return $resultado;
        }

        if ((int)($origem['ausente_na_origem'] ?? 0) !== 1) {
            return $resultado;
        }
        if (self::normalizeDate($origem['demissao'] ?? null) !== null) {
            return $resultado;
        }

        if ((int)($destino['ausente_na_origem'] ?? 0) === 1) {
            return $resultado;
        }
        if (self::normalizeDate($destino['admissao'] ?? null) === null) {
            return $resultado;
        }

        $resultado['eh_transferencia_continua'] = true;
        $dataTransferencia = self::normalizeDate($destino['data_ultima_transferencia'] ?? null)
            ?? self::normalizeDate($origem['data_ultima_transferencia'] ?? null);
        $resultado['data_transferencia'] = $dataTransferencia?->format('Y-m-d');
        return $resultado;
    }

    /**
     * Percorre TODOS os contratos, agrupa por CPF e classifica pares do Cenário A (contínua, sem
     * rescisão). Exige exatamente 1 registro órfão (ausente + sem demissão) por CPF; entre os
     * demais registros do grupo, o candidato a sucessor é o que está em OUTRA empresa e tem a
     * MESMA `admissao` do órfão — a assinatura mais confiável do Cenário A é a admissão original
     * preservada ao longo da transferência (ver classificarTransferenciaContinua()), muito mais
     * precisa do que "está vigente hoje": um sucessor pode ter sido genuinamente desligado depois
     * (dias, meses) sem deixar de ter sido o destino real daquela transferência — exigir
     * `demissao IS NULL` no sucessor excluiria esse caso incorretamente. Um emprego anterior
     * qualquer (mesmo não ausente, mesmo com demissão real) nunca conta: a admissão não bate.
     * Qualquer ambiguidade real (0 ou 2+ órfãos por CPF; 0 ou 2+ candidatos com a mesma admissão
     * em empresas diferentes) fica FORA da classificação automática — nunca presumir transferência
     * sem um sucessor confiável (ver §23 da correção de 2026-09).
     *
     * @param array $contratos Cada item precisa ter, no mínimo: cpf, codigo_empresa, admissao,
     *                         demissao, ausente_na_origem, identificador.
     * @return array<int, array{eh_transferencia_continua:bool, contrato_origem:?string, contrato_destino:?string}>
     *         Só os pares classificados como transferência contínua.
     */
    public static function identificarTransferenciasContinuas(array $contratos): array
    {
        $porCpf = [];
        foreach ($contratos as $contrato) {
            $cpf = trim((string)($contrato['cpf'] ?? ''));
            if ($cpf === '') {
                continue;
            }
            $porCpf[$cpf][] = $contrato;
        }

        $classificacoes = [];
        foreach ($porCpf as $grupo) {
            if (count($grupo) < 2) {
                continue;
            }
            $orfaos = array_values(array_filter($grupo, static function (array $c): bool {
                return (int)($c['ausente_na_origem'] ?? 0) === 1 && self::normalizeDate($c['demissao'] ?? null) === null;
            }));
            if (count($orfaos) !== 1) {
                continue;
            }
            $orfao = $orfaos[0];
            $empresaOrfao = trim((string)($orfao['codigo_empresa'] ?? ''));
            $admissaoOrfao = self::normalizeDate($orfao['admissao'] ?? null);
            if ($admissaoOrfao === null) {
                continue;
            }
            $candidatos = array_values(array_filter($grupo, static function (array $c) use ($orfao, $empresaOrfao, $admissaoOrfao): bool {
                if ($c === $orfao || (int)($c['ausente_na_origem'] ?? 0) === 1) {
                    return false;
                }
                if (trim((string)($c['codigo_empresa'] ?? '')) === $empresaOrfao) {
                    return false;
                }
                $admissaoCandidato = self::normalizeDate($c['admissao'] ?? null);
                return $admissaoCandidato !== null && $admissaoCandidato->format('Y-m-d') === $admissaoOrfao->format('Y-m-d');
            }));
            if (count($candidatos) !== 1) {
                continue;
            }
            $classificacao = self::classificarTransferenciaContinua($orfao, $candidatos[0]);
            if ($classificacao['eh_transferencia_continua']) {
                $classificacoes[] = $classificacao;
            }
        }
        return $classificacoes;
    }


    /**
     * Deriva a VIGÊNCIA ANALÍTICA dos pares do Cenário A que têm `data_transferencia` conhecida
     * (ver classificarTransferenciaContinua()): devolve uma CÓPIA de `$contratos` com
     * `demissao`/`admissao` ajustadas SÓ para cálculo de população (RhIndicadoresService::
     * ativosNoPeriodo()/headcountEm()) — a origem passa a valer até o dia ANTERIOR à
     * transferência, o destino passa a valer A PARTIR do dia da transferência. NUNCA muta
     * `$contratos`, o espelho MySQL, nem os valores oficiais (regra congelada: transferência
     * interempresa nunca altera admissao/demissao reais) — a cópia existe só na memória, só para
     * o cálculo de sobreposição de período enxergar a fronteira exata entre as duas empresas.
     *
     * Pares SEM data conhecida não são tocados — continuam com admissao/demissao reais, sem
     * vigência analítica (limitação documentada: sem a data, não há corte de dia possível; quem
     * consome ainda pode optar por remover a origem da população CONSOLIDADA como salvaguarda,
     * ver PeopleAnalyticsService).
     *
     * @param array $paresContinuas Retorno de identificarTransferenciasContinuas() (ou o
     *              sub-array `continuas` de classificarMovimentacoes()).
     * @return array Cópia de `$contratos`, mesma ordem, mesmas chaves — só `admissao`/`demissao`
     *               dos contratos envolvidos podem ter mudado.
     */
    public static function aplicarVigenciaAnalitica(array $contratos, array $paresContinuas): array
    {
        $ajustes = [];
        foreach ($paresContinuas as $par) {
            if (($par['data_transferencia'] ?? null) === null) {
                continue;
            }
            $data = self::normalizeDate($par['data_transferencia']);
            if ($data === null) {
                continue;
            }
            if ($par['contrato_origem'] !== null) {
                $ajustes[$par['contrato_origem']] = ['demissao' => $data->modify('-1 day')->format('Y-m-d')];
            }
            if ($par['contrato_destino'] !== null) {
                $ajustes[$par['contrato_destino']] = ['admissao_minima' => $data->format('Y-m-d')];
            }
        }

        if ($ajustes === []) {
            return $contratos;
        }

        return array_map(static function (array $contrato) use ($ajustes): array {
            $id = (string)($contrato['identificador'] ?? '');
            if (!isset($ajustes[$id])) {
                return $contrato;
            }
            if (isset($ajustes[$id]['demissao'])) {
                $contrato['demissao'] = $ajustes[$id]['demissao'];
            }
            if (isset($ajustes[$id]['admissao_minima'])) {
                $admissaoReal = self::normalizeDate($contrato['admissao'] ?? null);
                $minima = self::normalizeDate($ajustes[$id]['admissao_minima']);
                if ($admissaoReal === null || ($minima !== null && $minima > $admissaoReal)) {
                    $contrato['admissao'] = $ajustes[$id]['admissao_minima'];
                }
            }
            return $contrato;
        }, $contratos);
    }

    /**
     * Ponto único de entrada (§8 da correção de 2026-09: "centralizar a identificação das
     * movimentações entre empresas" — nenhum classificador paralelo). Classifica as DUAS classes
     * de movimentação interempresa e devolve os conjuntos de `identificador` já prontos para
     * exclusão — quem decide COMO aplicar (população consolidada vs. eventos de Admissão/
     * Desligamento) é RhIndicadoresService/PeopleAnalyticsService, nunca esta classe.
     *
     * `origem_continua_ids`: contratos do Cenário A que devem sair da POPULAÇÃO CONSOLIDADA (o
     * sucessor já carrega a admissão original preservada — contá-los também duplicaria a pessoa).
     * `excluir_admissao_ids`/`excluir_demissao_ids`: contratos do Cenário B cujo evento de
     * admissão/demissão não é real Admissão/Desligamento — nunca deve entrar no numerador do
     * Turnover nem nos KPIs de Admissões/Desligamentos (em nenhuma segmentação, geral ou por
     * dimensão). O Cenário A nunca entra nesses dois conjuntos: por definição, a origem nunca tem
     * `demissao` preenchida (não gera evento de desligamento) e a admissão do destino é a original
     * preservada, não um evento novo na data da transferência.
     *
     * @return array{
     *   continuas: array, recontratacoes: array,
     *   origem_continua_ids: string[],
     *   excluir_admissao_ids: string[], excluir_demissao_ids: string[]
     * }
     */
    public static function classificarMovimentacoes(array $contratos): array
    {
        $continuas = self::identificarTransferenciasContinuas($contratos);
        $recontratacoes = self::identificarTransferencias($contratos);

        $origemContinuaIds = [];
        foreach ($continuas as $c) {
            if ($c['contrato_origem'] !== null) {
                $origemContinuaIds[] = $c['contrato_origem'];
            }
        }

        $excluirAdmissaoIds = [];
        $excluirDemissaoIds = [];
        foreach ($recontratacoes as $r) {
            if ($r['contrato_origem'] !== null) {
                $excluirDemissaoIds[] = $r['contrato_origem'];
            }
            if ($r['contrato_destino'] !== null) {
                $excluirAdmissaoIds[] = $r['contrato_destino'];
            }
        }

        return [
            'continuas' => $continuas,
            'recontratacoes' => $recontratacoes,
            'origem_continua_ids' => $origemContinuaIds,
            'excluir_admissao_ids' => $excluirAdmissaoIds,
            'excluir_demissao_ids' => $excluirDemissaoIds,
        ];
    }

    private static function normalizeDate($value): ?DateTimeImmutable
    {
        $value = trim((string)($value ?? ''));
        if ($value === '') {
            return null;
        }
        try {
            return new DateTimeImmutable($value);
        } catch (\Exception $e) {
            return null;
        }
    }

    private static function stringOuNull($value): ?string
    {
        $value = trim((string)($value ?? ''));
        return $value !== '' ? $value : null;
    }
}
