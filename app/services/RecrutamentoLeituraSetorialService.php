<?php

/**
 * Etapa 3.2C.1 — decide COMO um usuário consulta Vagas e a listagem de Candidaturas (somente leitura). Único ponto usado pelos controllers.
 *
 * Modos:
 *   MODO_LEGADO   — Admin, RH, Supervisor Geral (e, SÓ para Vagas, Viewer, como sempre foi): comportamento anterior, sem escopo, intocado.
 *   MODO_SETORIAL — Gestor com a permissão de leitura EFETIVA (gravada + ativa + compatível com PermissaoPerfilPolicy) — o escopo vem
 *                   exclusivamente de `usuario_setores_gerenciados` (EscopoSetorial) e o setor da vaga de `vagas.setor_id`.
 *   MODO_NEGADO   — todo o resto (perfil desconhecido, Gestor sem permissão, Viewer em Candidaturas, usuário inexistente).
 *
 * O PERFIL é lido do BANCO (`usuarios.role`), nunca da sessão/POST. Falha de resolução = NEGADO, jamais global. No modo setorial o escopo
 * devolvido NUNCA é nulo nem global (se, por qualquer motivo, não for restrito, o modo vira NEGADO) e Gestor sem setores recebe um escopo
 * vazio: listagens vazias, nenhum registro nominal consultado.
 */
final class RecrutamentoLeituraSetorialService
{
    public const MODO_LEGADO = 'legado';
    public const MODO_SETORIAL = 'setorial';
    public const MODO_NEGADO = 'negado';

    public const PERMISSAO_VAGAS = 'vagas.visualizar';
    public const PERMISSAO_CANDIDATURAS = 'candidaturas.visualizar';

    /**
     * @param string|null $roleSessao role da sessão — usada SÓ para preservar o gate legado de Admin/RH/Viewer (que já era decidido por ela);
     *        a decisão do Gestor nunca confia nela: o perfil é relido do banco.
     * @return array{modo:string,escopo:?EscopoSetorial} Vagas: Viewer mantém o comportamento legado (sem ampliar nem reduzir).
     */
    public static function paraVagas(int $usuarioId, bool $supervisorDaSessao = false, ?string $roleSessao = null): array
    {
        return self::decidir($usuarioId, $supervisorDaSessao, $roleSessao, self::PERMISSAO_VAGAS, true);
    }

    /** @return array{modo:string,escopo:?EscopoSetorial} Candidaturas: Viewer NEGADO (como hoje: dados nominais só Admin/RH e Gestor setorial). */
    public static function paraCandidaturas(int $usuarioId, bool $supervisorDaSessao = false, ?string $roleSessao = null): array
    {
        return self::decidir($usuarioId, $supervisorDaSessao, $roleSessao, self::PERMISSAO_CANDIDATURAS, false);
    }

    private static function decidir(int $usuarioId, bool $supervisor, ?string $roleSessao, string $permissao, bool $viewerLegado): array
    {
        $negado = ['modo' => self::MODO_NEGADO, 'escopo' => null];
        $roleSessao = strtolower(trim((string)$roleSessao));
        if ($supervisor || in_array($roleSessao, ['admin', 'rh'], true) || ($viewerLegado && $roleSessao === 'viewer')) {
            return ['modo' => self::MODO_LEGADO, 'escopo' => null];
        }
        if ($usuarioId <= 0) {
            return $negado;
        }
        try {
            $stmt = Database::conn()->prepare('SELECT role FROM usuarios WHERE id = ? LIMIT 1');
            $stmt->execute([$usuarioId]);
            $role = strtolower(trim((string)($stmt->fetchColumn() ?: '')));
            if ($role !== 'gestor' || !Authorization::usuarioTemPermissao($usuarioId, $permissao)) {
                return $negado;
            }
            $escopo = EscopoSetorial::resolver($usuarioId); // lê a role do banco; gestor => restrito (possivelmente vazio)
            if (!$escopo->ehRestrito()) {
                return $negado;
            }
            return ['modo' => self::MODO_SETORIAL, 'escopo' => $escopo];
        } catch (\Throwable $e) {
            return $negado;
        }
    }
}
