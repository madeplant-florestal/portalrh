<?php

/**
 * Validação estrutural do lote recebido em POST /internal/metadados/colaboradores/sync — nunca
 * toca banco, nunca confia no cliente mesmo autenticado (a assinatura HMAC prova só que o
 * remetente conhece o segredo, não que o payload é bem-formado).
 *
 * Escopo desta validação: forma do envelope (chaves/tipos obrigatórios), consistência interna
 * (total declarado == quantidade recebida, sem chave lógica duplicada no lote) e limite de
 * tamanho. Validação de CONTEÚDO de negócio por registro (campo obrigatório vazio, etc.) continua
 * responsabilidade de MetadadosSyncService::validateRow() — deliberadamente mantida como está,
 * pois já isola erro por linha sem abortar o lote inteiro (ver Fase 1). Um payload que falha AQUI
 * é rejeitado por inteiro, antes de qualquer tentativa de escrita.
 */
class MetadadosSyncRequestValidator
{
    private const CAMPOS_OBRIGATORIOS_ENVELOPE = ['versao', 'origem_metadados', 'gerado_em', 'total', 'registros'];
    private const CAMPOS_CHAVE_LOGICA = ['codigo_empresa', 'codigo_unidade', 'numero_contrato'];
    private const CAMPOS_DATA = ['admissao', 'demissao', 'nascimento', 'data_inicio_cargo'];

    /**
     * @return array{ok:bool, errors:string[], origem?:string, registros?:array}
     */
    public static function validar(array $payload, int $maxBatchSize): array
    {
        $errors = [];

        foreach (self::CAMPOS_OBRIGATORIOS_ENVELOPE as $campo) {
            if (!array_key_exists($campo, $payload)) {
                $errors[] = "Campo obrigatório do envelope ausente: {$campo}.";
            }
        }
        if ($errors !== []) {
            return ['ok' => false, 'errors' => $errors];
        }

        $origem = $payload['origem_metadados'];
        if (!is_string($origem) || trim($origem) === '') {
            $errors[] = 'origem_metadados deve ser uma string não vazia.';
        }

        if (!is_int($payload['total']) && !(is_string($payload['total']) && ctype_digit($payload['total']))) {
            $errors[] = 'total deve ser um inteiro.';
        }

        if (!is_array($payload['registros'])) {
            $errors[] = 'registros deve ser uma lista.';
            return ['ok' => false, 'errors' => $errors];
        }

        $registros = array_values($payload['registros']);
        $total = (int)$payload['total'];
        if ($total !== count($registros)) {
            $errors[] = "total declarado ({$total}) diverge da quantidade recebida em registros (" . count($registros) . ').';
        }

        if (count($registros) > $maxBatchSize) {
            $errors[] = 'Lote excede o tamanho máximo permitido (' . count($registros) . ' > ' . $maxBatchSize . ').';
        }

        $chavesVistas = [];
        foreach ($registros as $indice => $registro) {
            if (!is_array($registro)) {
                $errors[] = "registros[{$indice}] não é um objeto.";
                continue;
            }

            foreach (self::CAMPOS_CHAVE_LOGICA as $campo) {
                if (!array_key_exists($campo, $registro) || trim((string)($registro[$campo] ?? '')) === '') {
                    $errors[] = "registros[{$indice}]: campo de chave lógica ausente/vazio: {$campo}.";
                }
            }

            foreach (self::CAMPOS_DATA as $campo) {
                $valor = $registro[$campo] ?? null;
                if ($valor !== null && $valor !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$valor)) {
                    $errors[] = "registros[{$indice}]: {$campo} não está no formato AAAA-MM-DD.";
                }
            }

            if (array_key_exists('ativo', $registro) && $registro['ativo'] !== null && !in_array($registro['ativo'], [0, 1, '0', '1'], true)) {
                $errors[] = "registros[{$indice}]: ativo deve ser 0, 1 ou null.";
            }

            $chave = implode('|', array_map(
                static fn (string $campo) => trim((string)($registro[$campo] ?? '')),
                self::CAMPOS_CHAVE_LOGICA
            ));
            if (isset($chavesVistas[$chave])) {
                $errors[] = "Chave lógica duplicada dentro do lote: {$chave} (registros[{$indice}]).";
            }
            $chavesVistas[$chave] = true;
        }

        if ($errors !== []) {
            return ['ok' => false, 'errors' => $errors];
        }

        return ['ok' => true, 'errors' => [], 'origem' => trim($origem), 'registros' => $registros];
    }
}
