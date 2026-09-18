<?php

/**
 * Resultados ADMINISTRATIVOS da Pesquisa de Integração respondida pelo fluxo coletivo (QR Code).
 * Só consulta/agrega dados já gravados em `pesquisas_integracao` — não escreve nada, não mistura
 * com a Pesquisa de Reação (outro instrumento, outras tabelas) e não altera o People Analytics.
 *
 * Origem QR = PesquisaIntegracaoQr::FILTRO_ORIGEM_QR (colaborador_id e token_hash NULL). Agrupamento
 * pela data real da integração (`integracao_data_relacionada`). NPS = %Promotores - %Detratores
 * (0-6 Detrator, 7-8 Neutro, 9-10 Promotor — mesma regra do NPS de Integração do People Analytics),
 * nunca a média das notas. Nome/Cargo/Empresa vêm do espelho oficial pelo contrato (metadados_id);
 * CPF/nascimento nunca são lidos.
 */
class PesquisaIntegracaoResultadosService
{
    public static function classificarNps(int $nota): string
    {
        if ($nota >= 9) {
            return 'promotor';
        }
        return $nota >= 7 ? 'neutro' : 'detrator';
    }

    /** Y-m-d de uma data real, ou null. */
    public static function dataValida(string $valor): ?string
    {
        $data = DateTimeImmutable::createFromFormat('!Y-m-d', $valor);
        $erros = DateTimeImmutable::getLastErrors();
        if ($data === false || ($erros !== false && ($erros['warning_count'] > 0 || $erros['error_count'] > 0))) {
            return null;
        }
        return $data->format('Y-m-d');
    }

    /**
     * @param int[] $notasNps
     * @return array{total:int,promotores:int,neutros:int,detratores:int,nps:?float}
     */
    public static function calcularNps(array $notasNps): array
    {
        $total = count($notasNps);
        $contagem = ['promotor' => 0, 'neutro' => 0, 'detrator' => 0];
        foreach ($notasNps as $nota) {
            $contagem[self::classificarNps((int)$nota)]++;
        }
        return [
            'total' => $total,
            'promotores' => $contagem['promotor'],
            'neutros' => $contagem['neutro'],
            'detratores' => $contagem['detrator'],
            'nps' => $total === 0 ? null : round((($contagem['promotor'] - $contagem['detrator']) / $total) * 100, 1),
        ];
    }

    /**
     * Uma linha por data de integração que tenha respostas via QR, mais recente primeiro.
     *
     * @return array<int,array{data_integracao:string,total:int,promotores:int,neutros:int,detratores:int,nps:?float}>
     */
    public static function resumoPorIntegracao(): array
    {
        $porData = [];
        foreach (PesquisaIntegracaoQr::respostasQrParaResumo() as $linha) {
            $porData[(string)$linha['integracao_data_relacionada']][] = (int)$linha['nota_nps'];
        }
        krsort($porData);

        $resumo = [];
        foreach ($porData as $data => $notas) {
            $resumo[] = ['data_integracao' => (string)$data] + self::calcularNps($notas);
        }
        return $resumo;
    }

    /**
     * Resultados detalhados de UMA integração (data). Sem respostas: `total = 0` e `nps = null`
     * (nunca um 0 inventado).
     *
     * @return array{data_integracao:string,total:int,nps:?float,promotores:int,neutros:int,detratores:int,perguntas:array,comentarios:array}
     */
    public static function resultadosDaIntegracao(string $dataYmd): array
    {
        $respostas = PesquisaIntegracaoQr::respostasQrDaIntegracao($dataYmd);
        $resultado = ['data_integracao' => $dataYmd] + self::calcularNps(array_map(static fn(array $r): int => (int)$r['nota_nps'], $respostas));

        // Perguntas REAIS do instrumento atual (não as da Pesquisa de Reação): média + distribuição 1-5.
        $perguntas = [];
        foreach (PesquisaIntegracaoQrService::criteriosSatisfacao() as $campo => $rotulo) {
            $distribuicao = [1 => 0, 2 => 0, 3 => 0, 4 => 0, 5 => 0];
            $soma = 0;
            foreach ($respostas as $r) {
                $nota = (int)$r[$campo];
                $soma += $nota;
                if (isset($distribuicao[$nota])) {
                    $distribuicao[$nota]++;
                }
            }
            $perguntas[] = [
                'rotulo' => $rotulo,
                'media' => $respostas === [] ? null : round($soma / count($respostas), 1),
                'distribuicao' => $distribuicao,
            ];
        }
        $resultado['perguntas'] = $perguntas;

        // Comentários (só os preenchidos), com Nome/Cargo/Empresa oficiais do contrato — sem CPF.
        $comComentario = array_values(array_filter($respostas, static fn(array $r): bool => trim((string)($r['comentarios'] ?? '')) !== ''));
        $contratos = PesquisaIntegracaoQr::contratosPorIds(array_map(static fn(array $r): int => (int)$r['metadados_id'], $comComentario));
        $comentarios = [];
        foreach ($comComentario as $r) {
            $contrato = $contratos[(int)$r['metadados_id']] ?? null;
            $comentarios[] = [
                'comentario' => trim((string)$r['comentarios']),
                'respondida_em' => (string)$r['respondida_em'],
                'nome' => $contrato['nome'] ?? null,
                'cargo' => $contrato['cargo'] ?? null,
                'empresa' => $contrato['empresa'] ?? null,
            ];
        }
        $resultado['comentarios'] = $comentarios;

        return $resultado;
    }
}
