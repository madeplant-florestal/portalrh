<?php

/**
 * Orquestra a Pesquisa de Reação — Treinamento de Integração: criação de campanha (resolve
 * validade/nomes-snapshot de Empresa/Setor), validação e registro de resposta, e cálculo de
 * resultados administrativos (NPS, Promotores/Neutros/Detratores, médias das 6 avaliações).
 *
 * Empresa/Setor/Data da Integração pertencem à CAMPANHA (contexto definido pelo RH na criação) —
 * o formulário público nunca os recebe como entrada confiável; `registrarResposta()` só lê esses
 * campos do array `$campanha` já resolvido pelo token, nunca de `$_POST`.
 */
class PesquisaReacaoIntegracaoService
{
    /** Mesma ordem das 6 afirmações informadas pelo RH — nunca reaproveita as perguntas de PesquisaIntegracao. */
    private const CAMPOS_AVALIACAO = [
        'avaliacao_historia_proposito_valores',
        'avaliacao_responsabilidades_rotina',
        'avaliacao_seguranca_saude',
        'avaliacao_relevancia_conteudos',
        'avaliacao_acolhimento',
        'avaliacao_expectativas',
    ];

    /**
     * @param array $dados Chaves aceitas: codigo_empresa, codigo_setor, data_integracao,
     *                      validade_dias (uma de PesquisaReacaoCampanha::VALIDADES_RAPIDAS) OU
     *                      expira_em_customizada ("Y-m-d\TH:i" de um <input type="datetime-local">).
     * @return array{ok:bool,error?:string,id?:int,token?:string}
     */
    public static function criarCampanha(array $dados, int $criadoPorUsuarioId): array
    {
        $codigoEmpresa = Security::sanitizeString($dados['codigo_empresa'] ?? '') ?: null;
        $codigoSetor = Security::sanitizeString($dados['codigo_setor'] ?? '') ?: null;
        $dataIntegracaoBruta = Security::sanitizeString($dados['data_integracao'] ?? '');
        $dataIntegracao = null;
        if ($dataIntegracaoBruta !== '') {
            $dataIntegracao = self::parseData($dataIntegracaoBruta);
            if ($dataIntegracao === null) {
                return ['ok' => false, 'error' => 'Data da Integração inválida.'];
            }
        }

        $expiraEm = self::resolverExpiracao($dados);
        if ($expiraEm === null) {
            return ['ok' => false, 'error' => 'Selecione uma validade rápida ou informe uma data/hora de expiração válida.'];
        }
        if ($expiraEm <= new DateTimeImmutable('now')) {
            return ['ok' => false, 'error' => 'A expiração do link precisa ser no futuro.'];
        }

        $opcoes = PesquisaReacaoCampanha::opcoesEmpresaSetor();
        $empresaNomeSnapshot = self::resolverNome($opcoes['empresas'], 'codigo_empresa', 'empresa', $codigoEmpresa);
        $setorNomeSnapshot = self::resolverNome($opcoes['setores'], 'codigo_setor', 'nome', $codigoSetor);

        $criado = PesquisaReacaoCampanha::create(
            $codigoEmpresa,
            $empresaNomeSnapshot,
            $codigoSetor,
            $setorNomeSnapshot,
            $dataIntegracao?->format('Y-m-d'),
            $expiraEm,
            $criadoPorUsuarioId
        );

        return ['ok' => true, 'id' => $criado['id'], 'token' => $criado['token']];
    }

    /**
     * Valida e registra uma resposta. NPS 0-10 e as 6 avaliações 1-5 são obrigatórios; nome e as 3
     * perguntas abertas são opcionais (nunca bloqueiam o envio). `$campanha` já deve ter sido
     * resolvida e validada (existe/ativa/não expirada) pelo chamador — esta função não repete essa
     * checagem, só grava.
     *
     * @return array{ok:bool,error?:string,id?:int}
     */
    public static function registrarResposta(array $campanha, array $post): array
    {
        $notaNps = self::parseNota($post['nota_nps'] ?? null, 0, 10);
        if ($notaNps === null) {
            return ['ok' => false, 'error' => 'Responda a Pergunta NPS (de 0 a 10) antes de enviar.'];
        }

        $avaliacoes = [];
        foreach (self::CAMPOS_AVALIACAO as $campo) {
            $nota = self::parseNota($post[$campo] ?? null, 1, 5);
            if ($nota === null) {
                return ['ok' => false, 'error' => 'Responda todas as afirmações da Avaliação da Integração (de 1 a 5) antes de enviar.'];
            }
            $avaliacoes[$campo] = $nota;
        }

        $nome = Security::sanitizeString($post['nome'] ?? '');
        $maisGostou = Security::sanitizeString($post['mais_gostou'] ?? '');
        $poderiaMelhorar = Security::sanitizeString($post['poderia_melhorar'] ?? '');
        $informacaoFaltante = Security::sanitizeString($post['informacao_faltante'] ?? '');

        $id = PesquisaReacaoResposta::create(
            (int)$campanha['id'],
            $nome !== '' ? $nome : null,
            $notaNps,
            $avaliacoes['avaliacao_historia_proposito_valores'],
            $avaliacoes['avaliacao_responsabilidades_rotina'],
            $avaliacoes['avaliacao_seguranca_saude'],
            $avaliacoes['avaliacao_relevancia_conteudos'],
            $avaliacoes['avaliacao_acolhimento'],
            $avaliacoes['avaliacao_expectativas'],
            $maisGostou !== '' ? $maisGostou : null,
            $poderiaMelhorar !== '' ? $poderiaMelhorar : null,
            $informacaoFaltante !== '' ? $informacaoFaltante : null
        );

        return ['ok' => true, 'id' => $id];
    }

    /**
     * NPS = %Promotores - %Detratores (nunca a média das notas 0-10). Sem respostas, `nps` é
     * `null` (nunca 0 falso) e a view mostra "Nenhuma resposta recebida até o momento.".
     */
    public static function calcularResultados(int $campanhaId): array
    {
        $respostas = PesquisaReacaoResposta::listarPorCampanha($campanhaId);
        $total = count($respostas);
        if ($total === 0) {
            return [
                'total' => 0, 'nps' => null, 'promotores' => 0, 'neutros' => 0, 'detratores' => 0,
                'medias' => [], 'abertas' => [],
            ];
        }

        $promotores = 0;
        $neutros = 0;
        $detratores = 0;
        $somas = array_fill_keys(self::CAMPOS_AVALIACAO, 0);
        $abertas = [];

        foreach ($respostas as $resposta) {
            switch (PesquisaReacaoResposta::classificarNps((int)$resposta['nota_nps'])) {
                case PesquisaReacaoResposta::PROMOTOR:
                    $promotores++;
                    break;
                case PesquisaReacaoResposta::NEUTRO:
                    $neutros++;
                    break;
                default:
                    $detratores++;
            }
            foreach (self::CAMPOS_AVALIACAO as $campo) {
                $somas[$campo] += (int)$resposta[$campo];
            }
            $abertas[] = [
                'nome' => $resposta['nome_opcional'],
                'mais_gostou' => $resposta['mais_gostou'],
                'poderia_melhorar' => $resposta['poderia_melhorar'],
                'informacao_faltante' => $resposta['informacao_faltante'],
                'respondida_em' => $resposta['respondida_em'],
            ];
        }

        $medias = [];
        foreach (self::CAMPOS_AVALIACAO as $campo) {
            $medias[$campo] = round($somas[$campo] / $total, 1);
        }

        $percentualPromotores = ($promotores / $total) * 100;
        $percentualDetratores = ($detratores / $total) * 100;

        return [
            'total' => $total,
            'nps' => round($percentualPromotores - $percentualDetratores, 1),
            'promotores' => $promotores,
            'neutros' => $neutros,
            'detratores' => $detratores,
            'medias' => $medias,
            'abertas' => $abertas,
        ];
    }


    /**
     * Texto das 6 afirmações, na ordem oficial informada pelo RH — fonte única para o formulário
     * público e para a tela de resultados administrativa (nunca duplicado literalmente nos dois).
     *
     * @return array<string,string> campo => texto da afirmação
     */
    public static function rotulosAvaliacoes(): array
    {
        return [
            'avaliacao_historia_proposito_valores' => 'O treinamento me ajudou a compreender a história, propósito e valores da empresa.',
            'avaliacao_responsabilidades_rotina' => 'Recebi informações claras sobre minhas responsabilidades e rotina de trabalho.',
            'avaliacao_seguranca_saude' => 'As informações relacionadas à segurança e saúde no trabalho foram apresentadas de forma clara.',
            'avaliacao_relevancia_conteudos' => 'Os conteúdos abordados foram relevantes para meu início na empresa.',
            'avaliacao_acolhimento' => 'Senti-me acolhido(a) durante o processo de integração.',
            'avaliacao_expectativas' => 'O treinamento atendeu minhas expectativas.',
        ];
    }

    private static function resolverExpiracao(array $dados): ?DateTimeImmutable
    {
        // Data/hora customizada tem PRIORIDADE sobre a validade rápida quando preenchida (mesmo
        // que o formulário sempre envie um valor de validade_dias, por causa do <select> ter uma
        // opção padrão selecionada) — combina com o rótulo exibido no formulário administrativo.
        $customizada = Security::sanitizeString($dados['expira_em_customizada'] ?? '');
        if ($customizada !== '') {
            return self::parseData($customizada);
        }

        $validadeDias = $dados['validade_dias'] ?? '';
        if ($validadeDias === '' || $validadeDias === null) {
            return null;
        }
        if (!ctype_digit((string)$validadeDias) || !in_array((int)$validadeDias, PesquisaReacaoCampanha::VALIDADES_RAPIDAS, true)) {
            return null;
        }
        return (new DateTimeImmutable('now'))->modify('+' . (int)$validadeDias . ' days');
    }

    /** @param array<int,array<string,mixed>> $opcoes */
    private static function resolverNome(array $opcoes, string $chaveCodigo, string $chaveNome, ?string $codigo): ?string
    {
        if ($codigo === null) {
            return null;
        }
        foreach ($opcoes as $opcao) {
            if ((string)($opcao[$chaveCodigo] ?? '') === $codigo) {
                $nome = $opcao[$chaveNome] ?? null;
                return $nome !== null ? (string)$nome : null;
            }
        }
        return null;
    }

    private static function parseNota(mixed $valor, int $min, int $max): ?int
    {
        if (!is_scalar($valor) || !ctype_digit((string)$valor)) {
            return null;
        }
        $n = (int)$valor;
        return ($n >= $min && $n <= $max) ? $n : null;
    }

    private static function parseData(string $valor): ?DateTimeImmutable
    {
        if ($valor === '') {
            return null;
        }
        try {
            return new DateTimeImmutable($valor);
        } catch (Throwable) {
            return null;
        }
    }
}
