<?php
/**
 * Classificação central de MOVIMENTAÇÃO entre contratos do espelho METADADOS — diagnóstico
 * "Transferências + Turnover por Sexo/Gênero". Só IDENTIFICA; NÃO decide nem aplica nada sobre
 * Turnover/Admissões/Desligamentos — essa é uma decisão de negócio ainda pendente do RH (excluir
 * transferência do Turnover Geral, ou só dos numeradores Voluntário/Involuntário). Enquanto isso
 * não for decidido, `RhIndicadoresService`/`PeopleAnalyticsService` continuam exatamente como
 * estavam: esta classe não é chamada por nenhum dos dois ainda.
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
