<?php

/**
 * Porta de entrada comum dos endpoints internos de sincronização do METADADOS
 * (POST /internal/metadados/{colaboradores|empresas|unidades}/sync).
 *
 * Faz só o que é idêntico às três dimensões: verifica a assinatura HMAC + janela de replay
 * (MetadadosSyncSignature) e decodifica o corpo JSON. A validação de FORMA do lote (campos do
 * envelope, chave lógica por registro, limite de tamanho) fica nos validators específicos de cada
 * dimensão, e a persistência nos serviços de sincronização já existentes — nada disso é duplicado
 * aqui.
 *
 * Nunca confia no cliente mesmo autenticado: a assinatura prova só que o remetente conhece o
 * segredo, não que o payload é bem-formado.
 */
class MetadadosSyncEnvelope
{
    /**
     * @param array<string,string> $headers Nomes de cabeçalho case-insensitive.
     * @return array{ok:bool, http_status?:int, body?:array, payload?:array}
     *         ok=true  -> ['ok'=>true, 'payload'=>array decodificado]
     *         ok=false -> ['ok'=>false, 'http_status'=>int, 'body'=>array] pronto para responder
     */
    public static function abrir(string $corpoBruto, array $headers, string $segredo, int $janelaSegundos): array
    {
        $timestamp = self::header($headers, MetadadosSyncSignature::HEADER_TIMESTAMP);
        $assinatura = self::header($headers, MetadadosSyncSignature::HEADER_SIGNATURE);

        $verificacao = MetadadosSyncSignature::verificar($timestamp, $assinatura, $corpoBruto, $segredo, $janelaSegundos);
        if (!$verificacao['ok']) {
            Logger::warning('Sincronização METADADOS recusada: falha de autenticação', ['motivo' => $verificacao['motivo']]);
            return ['ok' => false, 'http_status' => 401, 'body' => ['ok' => false, 'error' => 'Autenticação inválida.']];
        }

        $payload = json_decode($corpoBruto, true);
        if (!is_array($payload) || json_last_error() !== JSON_ERROR_NONE) {
            return ['ok' => false, 'http_status' => 400, 'body' => ['ok' => false, 'error' => 'JSON inválido.']];
        }

        return ['ok' => true, 'payload' => $payload];
    }

    /** @param array<string,string> $headers */
    private static function header(array $headers, string $name): ?string
    {
        foreach ($headers as $key => $value) {
            if (strcasecmp((string)$key, $name) === 0) {
                return (string)$value;
            }
        }
        return null;
    }
}
