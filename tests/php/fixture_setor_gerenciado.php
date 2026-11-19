<?php

/**
 * Helpers de teste (Etapa 2C.2A) — NÃO é um teste (prefixo fixture_ é ignorado pela suíte).
 * Cria setores oficiais de teste e liga usuários a eles via `usuario_setores_gerenciados` (a ÚNICA fonte de escopo setorial do
 * Gestor). Substitui o antigo arranjo de teste "gestor dono via usuarios.gestor_usuario_id", que deixou de dar acesso.
 */

if (!function_exists('zzSetorTeste')) {
    /** Cria um setor oficial (ativo, com código) e devolve o id. */
    function zzSetorTeste(PDO $pdo, string $codigo, string $rotulo = 'ZZ Setor'): int
    {
        $pdo->prepare("INSERT INTO setores (codigo_setor, nome, descricao_oficial, slug, ativo, situacao_metadados, origem_metadados) VALUES (?, ?, ?, ?, 1, 'A', 'zz-teste')")
            ->execute([$codigo, $rotulo . ' ' . $codigo, $rotulo . ' ' . $codigo, 'zz-' . strtolower($codigo) . '-' . uniqid()]);
        return (int)$pdo->lastInsertId();
    }

    /** O usuário passa a gerenciar o setor (acrescenta; não remove os demais). */
    function zzGerenciar(PDO $pdo, int $usuarioId, int $setorId): void
    {
        $pdo->prepare('INSERT IGNORE INTO usuario_setores_gerenciados (usuario_id, setor_id) VALUES (?, ?)')->execute([$usuarioId, $setorId]);
    }

    /** O usuário deixa de gerenciar o setor (histórico de registros permanece). */
    function zzDeixarDeGerenciar(PDO $pdo, int $usuarioId, int $setorId): void
    {
        $pdo->prepare('DELETE FROM usuario_setores_gerenciados WHERE usuario_id = ? AND setor_id = ?')->execute([$usuarioId, $setorId]);
    }

    /** @param int[] $setorIds */
    function zzLimparSetores(PDO $pdo, array $setorIds): void
    {
        if ($setorIds === []) {
            return;
        }
        $in = implode(',', array_map('intval', $setorIds));
        $pdo->exec("DELETE FROM usuario_setores_gerenciados WHERE setor_id IN ({$in})");
        $pdo->exec("DELETE FROM setores WHERE id IN ({$in})");
    }
}
