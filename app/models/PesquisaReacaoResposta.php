<?php

/**
 * Resposta da Pesquisa de Reação — Treinamento de Integração. Cada envio é uma linha
 * INDEPENDENTE (uma campanha aceita N respostas — nunca bloqueada por "já foi respondida",
 * diferente de `PesquisaIntegracao`). Participação anônima: `nome_opcional` pode ser NULL, nunca
 * há `colaborador_id`/CPF/e-mail/IP como identidade do participante.
 *
 * Sem DDL em runtime — a migration `2026-09-18-pesquisa-reacao-integracao.sql` é a única fonte da
 * estrutura.
 */
class PesquisaReacaoResposta
{
    /** Classificação NPS SEMPRE calculada a partir da nota, nunca persistida (evita divergência se a regra mudar). */
    public const DETRATOR = 'detrator';
    public const NEUTRO = 'neutro';
    public const PROMOTOR = 'promotor';

    public static function classificarNps(int $nota): string
    {
        if ($nota >= 9) {
            return self::PROMOTOR;
        }
        if ($nota >= 7) {
            return self::NEUTRO;
        }
        return self::DETRATOR;
    }

    /**
     * Grava uma resposta. Validação de faixa (NPS 0-10, avaliações 1-5) é responsabilidade do
     * chamador (PesquisaReacaoIntegracaoService::registrarResposta()) — esta classe é só acesso a
     * dado.
     */
    public static function create(
        int $campanhaId,
        ?string $nomeOpcional,
        int $notaNps,
        int $avaliacaoHistoriaPropositoValores,
        int $avaliacaoResponsabilidadesRotina,
        int $avaliacaoSegurancaSaude,
        int $avaliacaoRelevanciaConteudos,
        int $avaliacaoAcolhimento,
        int $avaliacaoExpectativas,
        ?string $maisGostou,
        ?string $poderiaMelhorar,
        ?string $informacaoFaltante
    ): int {
        $stmt = Database::conn()->prepare(
            'INSERT INTO respostas_pesquisa_reacao_integracao (
                campanha_id, nome_opcional, nota_nps,
                avaliacao_historia_proposito_valores, avaliacao_responsabilidades_rotina,
                avaliacao_seguranca_saude, avaliacao_relevancia_conteudos,
                avaliacao_acolhimento, avaliacao_expectativas,
                mais_gostou, poderia_melhorar, informacao_faltante
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $campanhaId, $nomeOpcional, $notaNps,
            $avaliacaoHistoriaPropositoValores, $avaliacaoResponsabilidadesRotina,
            $avaliacaoSegurancaSaude, $avaliacaoRelevanciaConteudos,
            $avaliacaoAcolhimento, $avaliacaoExpectativas,
            $maisGostou, $poderiaMelhorar, $informacaoFaltante,
        ]);
        return (int)Database::conn()->lastInsertId();
    }

    /** @return array Todas as respostas de uma campanha, mais recentes primeiro. */
    public static function listarPorCampanha(int $campanhaId): array
    {
        $stmt = Database::conn()->prepare(
            'SELECT * FROM respostas_pesquisa_reacao_integracao WHERE campanha_id = ? ORDER BY respondida_em DESC'
        );
        $stmt->execute([$campanhaId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
