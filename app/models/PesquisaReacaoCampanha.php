<?php

/**
 * Campanha da Pesquisa de Reação — Treinamento de Integração. 1 Campanha -> N Respostas
 * (`PesquisaReacaoResposta`). Instrumento DIFERENTE de `PesquisaIntegracao` (5 perguntas, 1
 * resposta por evento) — não reaproveita sua tabela nem suas perguntas.
 *
 * Diferente de `PesquisaIntegracao`, esta classe NUNCA executa DDL em runtime: a migration
 * `2026-09-18-pesquisa-reacao-integracao.sql` é a única fonte da estrutura (decisão explícita do
 * Fabio para este módulo novo — ver relatório da sprint). Se a tabela não existir, os métodos
 * abaixo falham com o erro real do PDO, nunca criam a tabela silenciosamente.
 *
 * Identidade organizacional: `codigo_empresa`/`codigo_setor` são os CÓDIGOS OFICIAIS do METADADOS
 * (mesma fonte/convenção de PeopleAnalyticsRepository::opcoesFiltro()/RhIndicadoresRepository::
 * opcoesFiltro() — nunca os ids locais de `empresas`/`setores`, domínio de Recrutamento/vagas).
 * `*_nome_snapshot` é só a descrição textual capturada no momento da criação, para preservação
 * histórica — nunca usada como identidade/filtro, só exibição.
 *
 * Token público: só o HASH (sha256 de `random_bytes(32)`) é armazenado — mesmo padrão de
 * segurança de `PesquisaIntegracao`/`PasswordReset`. Token bruto nunca persistido.
 */
class PesquisaReacaoCampanha
{
    /** Opções rápidas de validade oferecidas ao RH na geração do link. */
    public const VALIDADES_RAPIDAS = [1, 3, 5, 7, 15, 30];

    public static function find(int $id): ?array
    {
        $stmt = Database::conn()->prepare('SELECT * FROM campanhas_pesquisa_reacao_integracao WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /** Usado pela página pública — nunca guarda/recebe o token bruto, só o hash para comparar. */
    public static function findByRawToken(string $rawToken): ?array
    {
        $stmt = Database::conn()->prepare('SELECT * FROM campanhas_pesquisa_reacao_integracao WHERE token_hash = ? LIMIT 1');
        $stmt->execute([hash('sha256', $rawToken)]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /** @return array Todas as campanhas, mais recentes primeiro, com a contagem de respostas (sempre derivada, nunca um contador redundante). */
    public static function listarComContagem(): array
    {
        $stmt = Database::conn()->query(
            'SELECT c.*, COUNT(r.id) AS total_respostas
             FROM campanhas_pesquisa_reacao_integracao c
             LEFT JOIN respostas_pesquisa_reacao_integracao r ON r.campanha_id = c.id
             GROUP BY c.id
             ORDER BY c.created_at DESC'
        );
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Cria a campanha. `$expiraEm` já deve ser um DateTimeImmutable estritamente futuro — validado
     * pelo chamador (PesquisaReacaoIntegracaoService::criarCampanha()), nunca aqui (esta classe é
     * só acesso a dado, sem regra de negócio).
     *
     * @return array{id:int,token:string} Token BRUTO — só existe neste retorno, nunca mais é
     *         recuperável depois (mesmo padrão de PesquisaIntegracaoService::criarParaIntegracao()).
     */
    public static function create(
        ?string $codigoEmpresa,
        ?string $empresaNomeSnapshot,
        ?string $codigoSetor,
        ?string $setorNomeSnapshot,
        ?string $dataIntegracao,
        DateTimeImmutable $expiraEm,
        int $criadoPorUsuarioId
    ): array {
        $rawToken = bin2hex(random_bytes(32));
        $stmt = Database::conn()->prepare(
            'INSERT INTO campanhas_pesquisa_reacao_integracao (
                token_hash, codigo_empresa, empresa_nome_snapshot, codigo_setor, setor_nome_snapshot,
                data_integracao, expira_em, ativa, criado_por_usuario_id
            ) VALUES (?, ?, ?, ?, ?, ?, ?, 1, ?)'
        );
        $stmt->execute([
            hash('sha256', $rawToken), $codigoEmpresa, $empresaNomeSnapshot, $codigoSetor, $setorNomeSnapshot,
            $dataIntegracao, $expiraEm->format('Y-m-d H:i:s'), $criadoPorUsuarioId,
        ]);
        return ['id' => (int)Database::conn()->lastInsertId(), 'token' => $rawToken];
    }

    /** Desativa uma campanha — nunca mais aceita novas respostas, independente de `expira_em`. */
    public static function desativar(int $id): bool
    {
        $stmt = Database::conn()->prepare('UPDATE campanhas_pesquisa_reacao_integracao SET ativa = 0 WHERE id = ? AND ativa = 1');
        $stmt->execute([$id]);
        return $stmt->rowCount() > 0;
    }

    /** Aceita novas respostas: existe, está ativa E ainda não expirou (comparação em UTC/horário do banco). */
    public static function aceitaRespostas(array $campanha): bool
    {
        if ((int)($campanha['ativa'] ?? 0) !== 1) {
            return false;
        }
        $expiraEm = self::parseData((string)($campanha['expira_em'] ?? ''));
        return $expiraEm !== null && $expiraEm > new DateTimeImmutable('now');
    }

    /**
     * Empresas/Setores oficiais para o formulário de geração de campanha — mesma
     * fonte/convenção já validada em PeopleAnalyticsRepository::opcoesFiltro()/
     * RhIndicadoresRepository::opcoesFiltro() (colaboradores_metadados, nunca a tabela local
     * `empresas`/`setores` do Recrutamento).
     */
    public static function opcoesEmpresaSetor(): array
    {
        $pdo = Database::conn();

        $empresas = $pdo->query(
            "SELECT codigo_empresa, MAX(empresa) AS empresa
             FROM colaboradores_metadados
             WHERE codigo_empresa IS NOT NULL AND codigo_empresa <> ''
             GROUP BY codigo_empresa
             ORDER BY empresa"
        )->fetchAll(PDO::FETCH_ASSOC);

        $setores = $pdo->query(
            "SELECT cm.codigo_setor, COALESCE(s.descricao_oficial, s.nome, MAX(cm.setor)) AS nome
             FROM colaboradores_metadados cm
             LEFT JOIN setores s
               ON s.codigo_setor COLLATE utf8mb4_general_ci = cm.codigo_setor COLLATE utf8mb4_general_ci
             WHERE cm.codigo_setor IS NOT NULL AND cm.codigo_setor <> ''
             GROUP BY cm.codigo_setor, s.descricao_oficial, s.nome
             ORDER BY nome"
        )->fetchAll(PDO::FETCH_ASSOC);

        return ['empresas' => $empresas, 'setores' => $setores];
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
