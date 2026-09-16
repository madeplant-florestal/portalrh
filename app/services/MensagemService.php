<?php
/**
 * Renderização de templates de mensagem (Sprint "Módulo de Mensagens" + ajuste "Catálogo e
 * inserção assistida de variáveis").
 *
 * Placeholders são texto legível entre colchetes dentro do próprio `conteudo` — ex.: `[Nome]`,
 * `[Data]`, `[Local ou Link]`. Não há coluna por variável (`mensagens` não muda de schema quando
 * um placeholder novo aparece — ver migration 2026-09-16-mensagens.sql). Nenhum código é
 * executado a partir do template: substituição é troca de texto por texto, nunca `eval`.
 *
 * O CATÁLOGO abaixo é a fonte OFICIAL de variáveis reconhecidas pelo Portal — texto arbitrário
 * entre colchetes deixou de ser aceito automaticamente como variável operacional (ver §3 da
 * sprint). Ampliar o catálogo é só adicionar uma entrada aqui — nunca exige migration/tabela nova
 * (a view lê este array via `catalogoVariaveis()`, e o JS recebe os MESMOS dados por um payload
 * JSON embutido na página — nenhuma lista duplicada/divergente).
 */
class MensagemService
{
    /** Casa `[Qualquer coisa dentro de colchetes]`, sem colchetes aninhados. */
    private const PLACEHOLDER_REGEX = '/\[([^\[\]]+)\]/u';

    /**
     * Catálogo oficial de variáveis reconhecidas. Chave = nome do placeholder (sem colchetes).
     * `nome` = rótulo amigável exibido na tela; `descricao` = texto de apoio.
     */
    private const CATALOGO_VARIAVEIS = [
        'Nome' => [
            'nome' => 'Nome do candidato',
            'descricao' => 'Nome do candidato participante do processo seletivo.',
        ],
        'Data' => [
            'nome' => 'Data do agendamento',
            'descricao' => 'Data da entrevista, exame ou compromisso relacionado à mensagem.',
        ],
        'Horário' => [
            'nome' => 'Horário do agendamento',
            'descricao' => 'Horário da entrevista, exame ou compromisso relacionado à mensagem.',
        ],
        'Responsável' => [
            'nome' => 'Responsável pela entrevista',
            'descricao' => 'Nome da pessoa responsável pela entrevista ou etapa agendada.',
        ],
        'Nome do Gestor' => [
            'nome' => 'Nome do gestor',
            'descricao' => 'Nome do gestor responsável pela entrevista ou área da vaga.',
        ],
        'Local ou Link' => [
            'nome' => 'Local ou link',
            'descricao' => 'Local presencial ou link utilizado para participação na entrevista/reunião.',
        ],
        'Nome da Clínica' => [
            'nome' => 'Nome da clínica',
            'descricao' => 'Nome da clínica responsável pelo exame admissional.',
        ],
        'Endereço' => [
            'nome' => 'Endereço',
            'descricao' => 'Endereço relacionado ao compromisso, especialmente da clínica do exame admissional.',
        ],
        'Telefone' => [
            'nome' => 'Telefone para contato',
            'descricao' => 'Telefone de contato relacionado ao atendimento ou agendamento.',
        ],
    ];

    /**
     * Catálogo oficial, no formato usado pela view/JS: lista ordenada de
     * {placeholder, nome, descricao} — `placeholder` já vem com colchetes (`[Nome]`), pronto para
     * inserção direta no conteúdo.
     */
    public static function catalogoVariaveis(): array
    {
        $lista = [];
        foreach (self::CATALOGO_VARIAVEIS as $chave => $info) {
            $lista[] = [
                'placeholder' => '[' . $chave . ']',
                'chave' => $chave,
                'nome' => $info['nome'],
                'descricao' => $info['descricao'],
            ];
        }
        return $lista;
    }

    public static function isPlaceholderReconhecido(string $nome): bool
    {
        return isset(self::CATALOGO_VARIAVEIS[$nome]);
    }

    /**
     * Placeholders distintos usados no template, na ordem em que aparecem — ex.:
     * "Olá [Nome], hoje é [Data]." -> ['Nome', 'Data']. Não distingue reconhecido/desconhecido
     * (ver `analisarConteudo()` para isso) — é só a extração bruta do texto entre colchetes.
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
     * Separa os placeholders do conteúdo em RECONHECIDOS (existem no catálogo oficial) e
     * DESCONHECIDOS (texto arbitrário entre colchetes que o Portal não sabe resolver) — são
     * situações diferentes e nunca devem ser tratadas como a mesma coisa (ver §13 da sprint).
     *
     * @return array{reconhecidas:array,desconhecidas:array}
     */
    public static function analisarConteudo(string $conteudo): array
    {
        $todos = self::detectarPlaceholders($conteudo);
        $reconhecidas = [];
        $desconhecidas = [];
        foreach ($todos as $nome) {
            if (self::isPlaceholderReconhecido($nome)) {
                $reconhecidas[] = $nome;
            } else {
                $desconhecidas[] = $nome;
            }
        }
        return ['reconhecidas' => $reconhecidas, 'desconhecidas' => $desconhecidas];
    }

    /**
     * Busca a mensagem ATIVA pelo código e renderiza com as variáveis informadas.
     *
     * @param array<string,string> $variaveis Mapa placeholder => valor, ex.: ['Nome' => 'Ana'].
     *                                        As chaves não usam colchetes.
     * @return array{ok:bool,texto:?string,placeholders_usados:array,placeholders_pendentes:array,placeholders_desconhecidos:array,error:?string}
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
                'placeholders_desconhecidos' => [],
                'error' => 'Nenhuma mensagem ativa encontrada para o código "' . $codigo . '".',
            ];
        }

        $resultado = self::renderizarConteudo((string)$mensagem['conteudo'], $variaveis);
        $resultado['ok'] = true;
        $resultado['error'] = null;
        return $resultado;
    }

    /**
     * Substitui os placeholders RECONHECIDOS de um conteúdo arbitrário (usado pela
     * pré-visualização da tela de edição, antes de a mensagem ainda estar salva). Preserva
     * quebras de linha — é substituição pura de string, `str_replace`, nunca template
     * engine/`eval`.
     *
     * NÃO silencia placeholder reconhecido sem valor: fica listado em `placeholders_pendentes` e
     * permanece escrito como `[Nome]` no texto retornado. Placeholder DESCONHECIDO (fora do
     * catálogo) nunca é substituído e é reportado separadamente em `placeholders_desconhecidos` —
     * nunca tratado como uma simples pendência.
     *
     * @param array<string,string> $variaveis
     * @return array{texto:string,placeholders_usados:array,placeholders_pendentes:array,placeholders_desconhecidos:array}
     */
    public static function renderizarConteudo(string $conteudo, array $variaveis = []): array
    {
        $analise = self::analisarConteudo($conteudo);
        $pendentes = [];
        $texto = $conteudo;

        foreach ($analise['reconhecidas'] as $nome) {
            if (!array_key_exists($nome, $variaveis) || trim((string)$variaveis[$nome]) === '') {
                $pendentes[] = $nome;
                continue;
            }
            $texto = str_replace('[' . $nome . ']', (string)$variaveis[$nome], $texto);
        }

        return [
            'texto' => $texto,
            'placeholders_usados' => $analise['reconhecidas'],
            'placeholders_pendentes' => $pendentes,
            'placeholders_desconhecidos' => $analise['desconhecidas'],
        ];
    }
}
