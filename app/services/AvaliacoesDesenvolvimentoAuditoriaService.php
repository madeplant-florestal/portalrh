<?php

/**
 * Auditoria e Ciência COMPARTILHADAS entre Avaliação de Experiência e Feedback (Etapa 4, 2026-09) —
 * mesmo componente de dados usado pelos dois domínios (§45), documento identificado por
 * `documento_tipo` + `documento_id` (SEM FK, mesma convenção de `pdis.origem_ref_id`).
 *
 * `avaliacoes_desenvolvimento_eventos` é APPEND-ONLY (só INSERT, nunca UPDATE/DELETE, mesmo padrão de
 * `pdi_eventos`). Ciência (`avaliacoes_desenvolvimento_ciencia`) tem no máximo 1 linha por
 * (documento, papel) — registrar de novo substitui a anterior (ex.: reabertura + nova conclusão pede
 * nova ciência do colaborador).
 *
 * Preparação para assinatura eletrônica futura (§46/§47): os campos `status_assinatura`/
 * `provedor_assinatura`/etc. existem no banco mas NUNCA são preenchidos nesta rodada — nenhum
 * provedor está integrado. `registrarCiencia()` sempre grava `status_assinatura = 'nao_solicitada'`.
 */
class AvaliacoesDesenvolvimentoAuditoriaService
{
    public const TIPOS_DOCUMENTO = ['avaliacao_experiencia', 'feedback'];
    public const PAPEIS_CIENCIA = ['gestor', 'rh', 'colaborador'];
    public const PAPEIS_EVENTO = ['gestor', 'rh', 'admin'];

    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Database::conn();
    }

    /** Papel do ator para a trilha de eventos (nunca "colaborador" aqui — quem opera o formulário é sempre um usuário do Portal). */
    public static function papelDoAtor(array $ator): string
    {
        $role = (string)($ator['role'] ?? '');
        if ($role === 'admin') {
            return 'admin';
        }
        return $role === 'rh' ? 'rh' : 'gestor';
    }

    public function registrarEvento(
        string $documentoTipo,
        int $documentoId,
        string $tipoEvento,
        ?string $campo,
        ?string $valorAnterior,
        ?string $valorNovo,
        array $ator,
        ?string $ip,
        string $agora
    ): void {
        $this->pdo->prepare(
            'INSERT INTO avaliacoes_desenvolvimento_eventos
                (documento_tipo, documento_id, tipo_evento, campo, valor_anterior, valor_novo, ator_usuario_id, ator_papel, ip, criado_em)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $documentoTipo, $documentoId, $tipoEvento, $campo, $valorAnterior, $valorNovo,
            (int)$ator['id'], self::papelDoAtor($ator), $ip, $agora,
        ]);
    }

    /** @return array<int,array<string,mixed>> mais recente primeiro */
    public function listarEventos(string $documentoTipo, int $documentoId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT tipo_evento, campo, valor_anterior, valor_novo, ator_usuario_id, ator_papel, criado_em
             FROM avaliacoes_desenvolvimento_eventos WHERE documento_tipo = ? AND documento_id = ? ORDER BY criado_em DESC, id DESC'
        );
        $stmt->execute([$documentoTipo, $documentoId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Registra (ou substitui) a ciência de um papel sobre o documento. Ciência é APENAS interna nesta
     * fase (§44/§48): não é assinatura eletrônica/jurídica — por isso `status_assinatura` sempre nasce
     * `nao_solicitada`.
     */
    public function registrarCiencia(
        string $documentoTipo,
        int $documentoId,
        string $papel,
        ?int $usuarioId,
        string $nomeSnapshot,
        string $agora
    ): void {
        $this->pdo->prepare(
            'INSERT INTO avaliacoes_desenvolvimento_ciencia
                (documento_tipo, documento_id, papel, usuario_id, nome_snapshot, registrado_em, status_assinatura)
             VALUES (?, ?, ?, ?, ?, ?, \'nao_solicitada\')
             ON DUPLICATE KEY UPDATE usuario_id = VALUES(usuario_id), nome_snapshot = VALUES(nome_snapshot), registrado_em = VALUES(registrado_em)'
        )->execute([$documentoTipo, $documentoId, $papel, $usuarioId, $nomeSnapshot, $agora]);
    }

    /** @return array<string,array<string,mixed>> indexado por papel */
    public function listarCiencias(string $documentoTipo, int $documentoId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT papel, usuario_id, nome_snapshot, registrado_em, status_assinatura
             FROM avaliacoes_desenvolvimento_ciencia WHERE documento_tipo = ? AND documento_id = ?'
        );
        $stmt->execute([$documentoTipo, $documentoId]);
        $porPapel = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $porPapel[(string)$row['papel']] = $row;
        }
        return $porPapel;
    }

    public function temCiencia(string $documentoTipo, int $documentoId, string $papel): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT 1 FROM avaliacoes_desenvolvimento_ciencia WHERE documento_tipo = ? AND documento_id = ? AND papel = ? LIMIT 1'
        );
        $stmt->execute([$documentoTipo, $documentoId, $papel]);
        return $stmt->fetchColumn() !== false;
    }
}
