<?php

/**
 * Etapa 3.2C.1 — decide COMO um usuário consulta as listagens administrativas de Vagas e de Candidaturas (somente leitura). Único ponto usado
 * pelos controllers.
 *
 * Modos:
 *   MODO_LEGADO   — Admin, RH e Supervisor Geral: comportamento global anterior, intocado (todas as vagas, inclusive rascunhos).
 *   MODO_SETORIAL — Gestor com a permissão de leitura EFETIVA (gravada + ativa + compatível com PermissaoPerfilPolicy) — o escopo vem
 *                   exclusivamente de `usuario_setores_gerenciados` (EscopoSetorial) e o setor da vaga de `vagas.setor_id`.
 *   MODO_NEGADO   — todo o resto: Viewer (perfil restrito aos dashboards autorizados — nenhuma permissão operacional legada gravada o
 *                   contorna), Gestor sem a permissão específica, perfil desconhecido, usuário inexistente.
 *
 * O perfil do Gestor é RELIDO DO BANCO (`usuarios.role`) a cada requisição — permissão e setores também —, então revogar permissão, setor
 * ou mudar o perfil vale na próxima requisição, sem depender da sessão. A role da sessão só é usada para reconhecer Admin/RH (o mesmo gate
 * `Auth::requireRole` de sempre). Falha de resolução = NEGADO, jamais global. No modo setorial o escopo nunca é nulo nem global; Gestor sem
 * setores recebe escopo vazio (listagens vazias, nenhum registro nominal consultado).
 */
final class RecrutamentoLeituraSetorialService
{
    public const MODO_LEGADO = 'legado';
    public const MODO_SETORIAL = 'setorial';
    public const MODO_NEGADO = 'negado';

    public const PERMISSAO_VAGAS = 'vagas.visualizar';
    public const PERMISSAO_CANDIDATURAS = 'candidaturas.visualizar';

    /**
     * @param string|null $roleSessao role da sessão — usada SÓ para reconhecer Admin/RH (gate legado); o Gestor nunca é decidido por ela.
     * @return array{modo:string,escopo:?EscopoSetorial}
     */
    public static function paraVagas(int $usuarioId, bool $supervisorDaSessao = false, ?string $roleSessao = null): array
    {
        return self::decidir($usuarioId, $supervisorDaSessao, $roleSessao, self::PERMISSAO_VAGAS);
    }

    /** @return array{modo:string,escopo:?EscopoSetorial} */
    public static function paraCandidaturas(int $usuarioId, bool $supervisorDaSessao = false, ?string $roleSessao = null): array
    {
        return self::decidir($usuarioId, $supervisorDaSessao, $roleSessao, self::PERMISSAO_CANDIDATURAS);
    }

    private static function decidir(int $usuarioId, bool $supervisor, ?string $roleSessao, string $permissao): array
    {
        $negado = ['modo' => self::MODO_NEGADO, 'escopo' => null];
        $roleSessao = strtolower(trim((string)$roleSessao));
        if ($supervisor || in_array($roleSessao, ['admin', 'rh'], true)) {
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
                return $negado; // inclui o Viewer, mesmo com vagas.visualizar/candidaturas.visualizar gravadas
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
