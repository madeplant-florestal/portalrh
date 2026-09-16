<?php
/**
 * Renderização de templates de mensagem (Sprint "Módulo de Mensagens").
 *
 * Placeholders são texto legível entre colchetes dentro do próprio `conteudo` — ex.: `[Nome]`,
 * `[Data]`, `[Local ou Link]`. Não há coluna por variável (`mensagens` não muda de schema quando
 * um placeholder novo aparece — ver migration 2026-09-16-mensagens.sql). Nenhum código é
 * executado a partir do template: substituição é troca de texto por texto, nunca `eval`.
 */
class MensagemService
{
    /** Casa `[Qualquer coisa dentro de colchetes]`, sem colchetes aninhados. */
    private const PLACEHOLDER_REGEX = '/\[([^\[\]]+)\]/u';

    /**
     * Placeholders distintos usados no template, na ordem em que aparecem — ex.:
     * "Olá [Nome], hoje é [Data]." -> ['Nome', 'Data'].
     */
    public static function detectarPlaceholders(string $conteudo): array
    {
        if (!preg_match_all(self::PLACEHOLDER_REGEX, $conteudo, $matches)) {
            return [];
        }
        $vistos = [];
        $ordenados = [];
        foreach ($matches[1] as $nome) {
            $nome = trim($nome);
            if ($nome === '' || isset($vistos[$nome])) {
                continue;
            }
            $vistos[$nome] = true;
            $ordenados[] = $nome;
        }
        return $ordenados;
    }

    /**
     * Busca a mensagem ATIVA pelo código e renderiza com as variáveis informadas.
     *
     * @param array<string,string> $variaveis Mapa placeholder => valor, ex.: ['Nome' => 'Ana'].
     *                                        As chaves não usam colchetes.
     * @return array{ok:bool,texto:?string,placeholders_usados:array,placeholders_pendentes:array,error:?string}
     */
    public static function renderizar(string $codigo, array $variaveis = []): array
    {
        $mensagem = Mensagem::findAtivaByCodigo($codigo);
        if ($mensagem === null) {
            return [
                'ok' => false,
                'texto' => null,
                'placeholders_usados' => [],
                'placeholders_pendentes' => [],
                'error' => 'Nenhuma mensagem ativa encontrada para o código "' . $codigo . '".',
            ];
        }

        $resultado = self::renderizarConteudo((string)$mensagem['conteudo'], $variaveis);
        $resultado['ok'] = true;
        $resultado['error'] = null;
        return $resultado;
    }

    /**
     * Substitui os placeholders conhecidos de um conteúdo arbitrário (usado pela pré-visualização
     * da tela de edição, antes de a mensagem ainda estar salva). Preserva quebras de linha —
     * é substituição pura de string, `str_replace`, nunca template engine/`eval`.
     *
     * NÃO silencia placeholder sem valor: quem faltou fica listado em `placeholders_pendentes` e
     * permanece escrito como `[Nome]` no texto retornado (nunca é apagado silenciosamente).
     *
     * @param array<string,string> $variaveis
     * @return array{texto:string,placeholders_usados:array,placeholders_pendentes:array}
     */
    public static function renderizarConteudo(string $conteudo, array $variaveis = []): array
    {
        $placeholders = self::detectarPlaceholders($conteudo);
        $pendentes = [];
        $texto = $conteudo;

        foreach ($placeholders as $nome) {
            if (!array_key_exists($nome, $variaveis) || trim((string)$variaveis[$nome]) === '') {
                $pendentes[] = $nome;
                continue;
            }
            $texto = str_replace('[' . $nome . ']', (string)$variaveis[$nome], $texto);
        }

        return [
            'texto' => $texto,
            'placeholders_usados' => $placeholders,
            'placeholders_pendentes' => $pendentes,
        ];
    }
}
