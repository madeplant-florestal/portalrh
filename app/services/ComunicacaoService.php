<?php
/**
 * Camada reutilizável para preparar uma comunicação a partir de um template oficial de
 * `mensagens` (Sprint "Histórico de Comunicação"). Não dispara nada (sem n8n/Evolution API nesta
 * sprint) — só resolve o template com `MensagemService::renderizar()` e grava o snapshot
 * IMUTÁVEL em `comunicacoes`, pronto para o fluxo futuro:
 * Kanban -> dados da movimentação -> este service -> webhook -> n8n -> Evolution.
 */
class ComunicacaoService
{
    /**
     * @param array<string,string> $variaveis
     * @return array{ok:bool,id?:int,error?:string}
     */
    public static function prepararDeTemplate(
        string $codigoMensagem,
        int $candidaturaId,
        array $variaveis,
        string $origem = 'MANUAL',
        ?int $usuarioId = null,
        string $canal = 'whatsapp'
    ): array {
        $render = MensagemService::renderizar($codigoMensagem, $variaveis);
        if (!($render['ok'] ?? false)) {
            return ['ok' => false, 'error' => (string)($render['error'] ?? 'Falha ao renderizar o template.')];
        }

        $mensagem = Mensagem::findAtivaByCodigo($codigoMensagem);

        return Comunicacao::create([
            'candidatura_id' => $candidaturaId,
            'mensagem_id' => $mensagem['id'] ?? null,
            'conteudo' => (string)$render['texto'],
            'canal' => $canal,
            'situacao' => 'preparada',
            'origem' => $origem,
            'usuario_id' => $usuarioId,
        ]);
    }
}
