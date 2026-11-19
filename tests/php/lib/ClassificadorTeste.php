<?php

/**
 * Classifica o resultado de UM teste PHP standalone a partir do código de saída E do conteúdo da saída.
 *
 * Por que o código de saída sozinho não basta (causas comprovadas dos "falsos sucessos"):
 *   1. o bootstrap instala set_exception_handler(): com ele, uma exceção não tratada imprime "Erro 500" e o PHP sai com 0;
 *   2. controllers/Authorization terminam com `exit;` (403, redirect) — quando um teste os chama no PRÓPRIO processo, o teste acaba ali
 *      com código 0, sem rodar o restante das asserções nem o `finally` de limpeza;
 *   3. vários testes só imprimem "[FALHOU]" e dependem de um `exit(1)` no fim, que o item 2 impede de chegar.
 *
 * Contrato de conclusão: um teste só PASSA se terminar com código 0, sem marcas de falha/erro e com a ÚLTIMA linha de saída indicando
 * conclusão ("OK", "..._OK", "aprovado"...). Terminar de outro jeito (ex.: "Acesso negado") é INCOMPLETO — nunca sucesso.
 */
final class ClassificadorTeste
{
    public const PASSOU = 'PASSOU';
    public const FALHOU = 'FALHOU';
    public const ERRO_EXECUCAO = 'ERRO_EXECUCAO';
    public const ERRO_AMBIENTE = 'ERRO_AMBIENTE';
    public const INCOMPLETO = 'INCOMPLETO';
    public const IGNORADO = 'IGNORADO';

    private const RX_FALHA = '/^\s*\[FALHOU\]|^FALHOU\b|^FAILED\b|\bverifica[çc](?:ão|ões|ao|oes)\(ões\) falharam|^\d+ falha\(s\)\.?\s*$/mu';
    private const RX_EXECUCAO = '/^(?:PHP )?(?:Fatal error|Parse error):|Uncaught\s|^Erro 500\b|^Erro interno do servidor\./m';
    private const RX_AMBIENTE = '/SQLSTATE\[HY000\] \[2002\]|Erro ao conectar ao banco|MySQL server has gone away|Nenhuma conexão pôde ser feita|Sincronização bloqueada: o espelho colaboradores_metadados já contém registros de outra origem/u';
    private const RX_CONCLUSAO = '/\bOK\b|_OK\b|aprovad|passou|conclu[ií]d/iu';

    /**
     * @return array{status:string,motivo:string,evidencias:string[]}
     */
    public static function classificar(int $codigoSaida, string $saida, bool $estourouTempo = false): array
    {
        $linhas = array_values(array_filter(array_map('rtrim', preg_split('/\R/', $saida) ?: []), static fn(string $l): bool => trim($l) !== ''));
        $ultima = $linhas === [] ? '' : trim((string)end($linhas));
        $evid = static function (string $rx) use ($linhas): array {
            return array_slice(array_values(array_filter($linhas, static fn(string $l): bool => (bool)preg_match($rx, $l))), 0, 4);
        };

        if ($estourouTempo) {
            return ['status' => self::ERRO_EXECUCAO, 'motivo' => 'tempo limite excedido', 'evidencias' => array_slice($linhas, -3)];
        }
        if (preg_match(self::RX_AMBIENTE, $saida)) {
            return ['status' => self::ERRO_AMBIENTE, 'motivo' => 'infraestrutura/estado do ambiente (banco indisponível ou resíduo de outra origem)', 'evidencias' => $evid(self::RX_AMBIENTE)];
        }
        if (preg_match(self::RX_FALHA, $saida)) {
            return ['status' => self::FALHOU, 'motivo' => 'asserções reprovadas' . ($codigoSaida === 0 ? ' (o teste terminou com código 0 — falso sucesso evitado)' : ''), 'evidencias' => $evid(self::RX_FALHA)];
        }
        if (preg_match(self::RX_EXECUCAO, $saida)) {
            return ['status' => self::ERRO_EXECUCAO, 'motivo' => 'exceção/erro fatal não tratado' . ($codigoSaida === 0 ? ' (código de saída 0 mascarava o erro)' : ''), 'evidencias' => $evid(self::RX_EXECUCAO)];
        }
        if ($codigoSaida !== 0) {
            return ['status' => self::FALHOU, 'motivo' => "código de saída {$codigoSaida}", 'evidencias' => array_slice($linhas, -3)];
        }
        if (preg_match('/^SKIP\b/i', $ultima)) {
            return ['status' => self::IGNORADO, 'motivo' => $ultima, 'evidencias' => []];
        }
        if ($ultima !== '' && preg_match(self::RX_CONCLUSAO, $ultima)) {
            return ['status' => self::PASSOU, 'motivo' => '', 'evidencias' => []];
        }
        return ['status' => self::INCOMPLETO, 'motivo' => 'terminou com código 0 sem a linha de conclusão (encerramento prematuro, ex.: exit de controller no próprio processo)', 'evidencias' => $linhas === [] ? ['(sem saída)'] : array_slice($linhas, -2)];
    }
}
