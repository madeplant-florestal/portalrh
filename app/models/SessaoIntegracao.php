<?php

/**
 * Sessão de Integração — a MENOR estrutura para saber qual integração está aberta para receber
 * respostas pelo QR Code coletivo da Pesquisa de Integração (migration
 * 2026-09-19-pesquisa-integracao-qr.sql). Só data da integração + aberta/encerrada; não é módulo de
 * turmas/agenda/presença.
 *
 * `aberta` vale 1 (aberta) ou NULL (encerrada) — `UNIQUE KEY (aberta)` garante no banco que exista
 * NO MÁXIMO UMA sessão aberta. A data desta sessão é a que vira `integracao_data_relacionada` das
 * respostas do fluxo QR.
 *
 * Sem DDL em runtime — a migration é a única fonte da estrutura.
 */
class SessaoIntegracao
{
    public static function abertaAtual(): ?array
    {
        $stmt = Database::conn()->query('SELECT * FROM sessoes_integracao WHERE aberta = 1 LIMIT 1');
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public static function find(int $id): ?array
    {
        $stmt = Database::conn()->prepare('SELECT * FROM sessoes_integracao WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /** @return array Sessões mais recentes primeiro, com a contagem de respostas do fluxo QR na data. */
    public static function listarRecentes(int $limite = 10): array
    {
        $stmt = Database::conn()->prepare(
            'SELECT s.*,
                    (SELECT COUNT(*) FROM pesquisas_integracao p
                      WHERE p.metadados_id IS NOT NULL
                        AND p.respondida_em IS NOT NULL
                        AND p.integracao_data_relacionada = s.data_integracao) AS respostas_qr
             FROM sessoes_integracao s
             ORDER BY s.created_at DESC, s.id DESC
             LIMIT ' . max(1, $limite)
        );
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Abre uma sessão para a data informada (Y-m-d, já validada pelo chamador). Se já existir uma
     * sessão aberta, o UNIQUE em `aberta` barra a segunda — nunca há duas abertas ao mesmo tempo.
     *
     * @return array{ok:bool,error?:string,id?:int}
     */
    public static function abrir(string $dataIntegracao, int $criadoPorUsuarioId): array
    {
        try {
            $stmt = Database::conn()->prepare(
                'INSERT INTO sessoes_integracao (data_integracao, aberta, criado_por_usuario_id) VALUES (?, 1, ?)'
            );
            $stmt->execute([$dataIntegracao, $criadoPorUsuarioId]);
        } catch (PDOException $e) {
            if ((int)($e->errorInfo[1] ?? 0) === 1062) {
                return ['ok' => false, 'error' => 'Já existe uma integração aberta. Encerre-a antes de abrir outra.'];
            }
            throw $e;
        }
        return ['ok' => true, 'id' => (int)Database::conn()->lastInsertId()];
    }

    public static function encerrar(int $id): bool
    {
        $stmt = Database::conn()->prepare(
            'UPDATE sessoes_integracao SET aberta = NULL, encerrada_em = NOW() WHERE id = ? AND aberta = 1'
        );
        $stmt->execute([$id]);
        return $stmt->rowCount() > 0;
    }
}
