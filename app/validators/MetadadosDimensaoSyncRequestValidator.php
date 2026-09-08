<?php

/**
 * Validação estrutural do lote recebido em POST /internal/metadados/{empresas|unidades}/sync —
 * Fase 5.1A. Mesma filosofia de MetadadosSyncRequestValidator (a de colaboradores, mantida
 * intacta): valida só a FORMA do envelope, a chave lógica de cada registro, ausência de
 * duplicidade no lote e o limite de tamanho. Um payload que falha aqui é rejeitado por inteiro,
 * antes de qualquer escrita. Validação de conteúdo de negócio por registro fica nos serviços de
 * sincronização (EmpresaMetadadosSyncService / UnidadeMetadadosSyncService), que já isolam erro
 * por linha.
 *
 * O envelope é idêntico ao de colaboradores (versao/origem_metadados/gerado_em/total/registros +
 * correlacao_id opcional). O que muda por dimensão é só a chave lógica e o campo de descrição:
 *   - empresas:  chave (codigo_empresa)                 + razao_social não vazio
 *   - unidades:  chave (codigo_empresa, codigo_unidade) + descricao não vazio
 */
class MetadadosDimensaoSyncRequestValidator
{
    private const CAMPOS_OBRIGATORIOS_ENVELOPE = ['versao', 'origem_metadados', 'gerado_em', 'total', 'registros'];

    private const DIMENSOES = [
        'empresas' => ['chave' => ['codigo_empresa'], 'descricao' => 'razao_social'],
        'unidades' => ['chave' => ['codigo_empresa', 'codigo_unidade'], 'descricao' => 'descricao'],
    ];

    /**
     * @return array{ok:bool, errors:string[], origem?:string, registros?:array, correlacao_id?:?string}
     */
    public static function validar(array $payload, int $maxBatchSize, string $dimensao): array
    {
        if (!isset(self::DIMENSOES[$dimensao])) {
            return ['ok' => false, 'errors' => ["Dimensão não suportada: {$dimensao}."]];
        }
        $chaveLogica = self::DIMENSOES[$dimensao]['chave'];
        $campoDescricao = self::DIMENSOES[$dimensao]['descricao'];

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

            foreach ($chaveLogica as $campo) {
                if (!array_key_exists($campo, $registro) || trim((string)($registro[$campo] ?? '')) === '') {
                    $errors[] = "registros[{$indice}]: campo de chave lógica ausente/vazio: {$campo}.";
                }
            }
            if (!array_key_exists($campoDescricao, $registro) || trim((string)($registro[$campoDescricao] ?? '')) === '') {
                $errors[] = "registros[{$indice}]: campo obrigatório ausente/vazio: {$campoDescricao}.";
            }

            $chave = implode('|', array_map(
                static fn (string $campo) => trim((string)($registro[$campo] ?? '')),
                $chaveLogica
            ));
            if (isset($chavesVistas[$chave])) {
                $errors[] = "Chave lógica duplicada dentro do lote: {$chave} (registros[{$indice}]).";
            }
            $chavesVistas[$chave] = true;
        }

        $correlacaoId = null;
        if (array_key_exists('correlacao_id', $payload) && $payload['correlacao_id'] !== null && $payload['correlacao_id'] !== '') {
            if (!is_string($payload['correlacao_id']) || !preg_match('/^[0-9a-fA-F-]{36}$/', $payload['correlacao_id'])) {
                $errors[] = 'correlacao_id, quando presente, deve ser um UUID.';
            } else {
                $correlacaoId = strtolower($payload['correlacao_id']);
            }
        }

        if ($errors !== []) {
            return ['ok' => false, 'errors' => $errors];
        }

        return ['ok' => true, 'errors' => [], 'origem' => trim($origem), 'registros' => $registros, 'correlacao_id' => $correlacaoId];
    }
}
