<?php

/**
 * Contexto organizacional do usuário (Sprint "Contexto Organizacional dos Usuários" — Etapa 1).
 *
 * Duas responsabilidades:
 *   1. HERANÇA — quando o usuário tem vínculo `usuarios.colaborador_metadados_id`, o contrato
 *      oficial determina o Cargo principal (`colaboradores_metadados.codigo_cargo` -> `cargos.codigo_cargo`)
 *      e o Setor principal (`colaboradores_metadados.codigo_setor` -> `setores.codigo_setor`,
 *      quando preenchido). Sem `codigo_setor` NÃO há inferência — RH/Admin escolhe manualmente.
 *   2. EDIÇÃO MANUAL — para usuário sem vínculo (ou vinculado a contrato sem `codigo_setor`),
 *      RH/Admin define Cargo e Setores usando SOMENTE registros oficiais do catálogo
 *      (`codigo_* IS NOT NULL`); os 22 cargos / 4 setores legados nunca são aceitos.
 *
 * Regras de modelagem:
 *   - `usuario_setores.principal` — no máximo 1 por usuário, garantido AQUI dentro da transação
 *     de gravação (índice único parcial tem suporte divergente entre MySQL 8.4 e MariaDB 11.8).
 *   - `usuario_setores.origem` — 'METADADOS' (principal herdado do contrato) x 'MANUAL' (qualquer
 *     setor concedido por RH/Admin). Uma futura re-sincronização do contexto pode atualizar só as
 *     linhas 'METADADOS' sem destruir as 'MANUAL'.
 *
 * Geração nova (Repository/Service), como CatalogoMetadadosSyncService: construtor `?PDO`,
 * 100% prepared statements, sem dependência do SQL Server (lê só o espelho MySQL).
 *
 * Débito de collation (Fase 5.2): `cargos.codigo_cargo` / `setores.codigo_setor` são
 * `utf8mb4_general_ci`; `colaboradores_metadados.codigo_*` é `utf8mb4_0900_ai_ci` (dev) /
 * `utf8mb4_uca1400_ai_ci` (prod). A resolução aqui compara SEMPRE um valor JÁ lido do espelho
 * contra o catálogo via parâmetro vinculado (coercível — adota a collation da coluna do
 * catálogo), então não dispara #1267. Nenhum JOIN coluna-a-coluna entre as duas famílias.
 */
class UsuarioContextoOrganizacionalService
{
    public const ORIGEM_METADADOS = 'METADADOS';
    public const ORIGEM_MANUAL = 'MANUAL';

    private PDO $pdo;
    private CatalogoMetadadosRepository $cargos;
    private CatalogoMetadadosRepository $setores;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Database::conn();
        $this->cargos = new CatalogoMetadadosRepository('cargos', $this->pdo);
        $this->setores = new CatalogoMetadadosRepository('setores', $this->pdo);
    }

    /**
     * Resolve Cargo e Setor principal OFICIAIS a partir de um contrato do espelho.
     *
     * @return array{
     *   colaborador_metadados_id:int, codigo_cargo:?string, codigo_setor:?string,
     *   cargo_id:?int, cargo_rotulo:?string, cargo_aviso:?string,
     *   setor_principal_id:?int, setor_principal_rotulo:?string, setor_aviso:?string
     * }
     */
    public function resolverContextoOficial(int $colaboradorMetadadosId): array
    {
        $out = [
            'colaborador_metadados_id' => $colaboradorMetadadosId,
            'codigo_cargo' => null,
            'codigo_setor' => null,
            'cargo_id' => null,
            'cargo_rotulo' => null,
            'cargo_aviso' => null,
            'setor_principal_id' => null,
            'setor_principal_rotulo' => null,
            'setor_aviso' => null,
        ];

        $stmt = $this->pdo->prepare(
            'SELECT codigo_cargo, codigo_setor FROM colaboradores_metadados WHERE id = ? LIMIT 1'
        );
        $stmt->execute([$colaboradorMetadadosId]);
        $contrato = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$contrato) {
            $out['cargo_aviso'] = 'Contrato oficial não encontrado no espelho do METADADOS.';
            $out['setor_aviso'] = 'Contrato oficial não encontrado no espelho do METADADOS.';
            return $out;
        }

        $codigoCargo = trim((string)($contrato['codigo_cargo'] ?? ''));
        $out['codigo_cargo'] = $codigoCargo !== '' ? $codigoCargo : null;
        if ($codigoCargo === '') {
            $out['cargo_aviso'] = 'Contrato oficial sem código de cargo no METADADOS.';
        } else {
            $cargo = $this->cargos->findByCodigo($codigoCargo);
            if ($cargo) {
                $out['cargo_id'] = (int)$cargo['id'];
                $out['cargo_rotulo'] = $this->rotulo($cargo);
            } else {
                $out['cargo_aviso'] = sprintf(
                    'Cargo do contrato (código %s) ainda não existe no catálogo oficial.',
                    $codigoCargo
                );
            }
        }

        $codigoSetor = trim((string)($contrato['codigo_setor'] ?? ''));
        $out['codigo_setor'] = $codigoSetor !== '' ? $codigoSetor : null;
        if ($codigoSetor === '') {
            $out['setor_aviso'] = 'Setor não informado no METADADOS';
        } else {
            $setor = $this->setores->findByCodigo($codigoSetor);
            if ($setor) {
                $out['setor_principal_id'] = (int)$setor['id'];
                $out['setor_principal_rotulo'] = $this->rotulo($setor);
            } else {
                $out['setor_aviso'] = sprintf(
                    'Setor do contrato (código %s) ainda não existe no catálogo oficial.',
                    $codigoSetor
                );
            }
        }

        return $out;
    }

    /**
     * Aplica o contexto herdado do contrato ao usuário: seta `usuarios.cargo_id` e sincroniza a
     * linha principal `origem = METADADOS` em `usuario_setores`. NUNCA destrói linhas
     * `origem = MANUAL`. Idempotente.
     */
    public function aplicarContextoDoVinculo(int $usuarioId, int $colaboradorMetadadosId, ?User $actor = null, ?string $ip = null): void
    {
        $ctx = $this->resolverContextoOficial($colaboradorMetadadosId);
        $ownTx = !$this->pdo->inTransaction();
        if ($ownTx) {
            $this->pdo->beginTransaction();
        }
        try {
            $this->pdo->prepare('UPDATE usuarios SET cargo_id = ? WHERE id = ?')
                ->execute([$ctx['cargo_id'], $usuarioId]);

            $setorPrincipalId = $ctx['setor_principal_id'];
            if ($setorPrincipalId !== null) {
                // Remove qualquer principal METADADOS antigo que não seja o setor atual do contrato.
                $this->pdo->prepare(
                    "DELETE FROM usuario_setores
                     WHERE usuario_id = ? AND origem = 'METADADOS' AND setor_id <> ?"
                )->execute([$usuarioId, $setorPrincipalId]);

                // Rebaixa qualquer outro principal (manual) do usuário.
                $this->pdo->prepare(
                    'UPDATE usuario_setores SET principal = 0 WHERE usuario_id = ? AND setor_id <> ?'
                )->execute([$usuarioId, $setorPrincipalId]);

                // Upsert do principal oficial (promove uma linha manual pré-existente, se houver).
                $this->pdo->prepare(
                    "INSERT INTO usuario_setores (usuario_id, setor_id, principal, origem)
                     VALUES (?, ?, 1, 'METADADOS')
                     ON DUPLICATE KEY UPDATE principal = 1, origem = 'METADADOS', updated_at = CURRENT_TIMESTAMP"
                )->execute([$usuarioId, $setorPrincipalId]);
            } else {
                // Contrato sem setor oficial: nenhuma linha METADADOS deve sobrar.
                $this->pdo->prepare(
                    "DELETE FROM usuario_setores WHERE usuario_id = ? AND origem = 'METADADOS'"
                )->execute([$usuarioId]);
            }

            if ($ownTx) {
                $this->pdo->commit();
            }
        } catch (\Throwable $e) {
            if ($ownTx && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        AuditLog::log($actor?->id, $usuarioId, 'usuario_contexto_metadados', sprintf(
            'cargo_id=%s setor_principal_id=%s',
            $ctx['cargo_id'] === null ? 'NULL' : (string)$ctx['cargo_id'],
            $setorPrincipalId === null ? 'NULL' : (string)$setorPrincipalId
        ), $ip);
    }

    /**
     * Ao desvincular o contrato: preserva TODAS as associações já gravadas, apenas reetiqueta as
     * linhas `METADADOS` como `MANUAL` (não há mais contrato as sustentando) e mantém
     * `usuarios.cargo_id`. Nada é apagado; a edição volta a ser liberada na tela.
     */
    public function aoDesvincular(int $usuarioId): void
    {
        $this->pdo->prepare(
            "UPDATE usuario_setores SET origem = 'MANUAL' WHERE usuario_id = ? AND origem = 'METADADOS'"
        )->execute([$usuarioId]);
    }

    /**
     * Edição manual do contexto por RH/Admin. Aceita apenas ids OFICIAIS. Respeita a trava do
     * vínculo: com contrato METADADOS o Cargo é imutável e, havendo Setor oficial no contrato, o
     * Setor principal também. Setores adicionais são sempre editáveis.
     *
     * @param int[] $setoresAdicionais ids oficiais
     * @return array{ok:bool, error?:string}
     */
    public function definirContextoManual(
        int $usuarioId,
        ?int $cargoId,
        ?int $setorPrincipalId,
        array $setoresAdicionais,
        ?User $actor = null,
        ?string $ip = null
    ): array {
        $usuario = User::findById($usuarioId);
        if (!$usuario) {
            return ['ok' => false, 'error' => 'Usuário não encontrado.'];
        }

        $vinculado = $usuario->colaborador_metadados_id !== null;
        $ctx = $vinculado ? $this->resolverContextoOficial((int)$usuario->colaborador_metadados_id) : null;

        // ----- Cargo -----
        if ($vinculado && $ctx['cargo_id'] !== null) {
            if ($cargoId !== null && $cargoId !== $ctx['cargo_id']) {
                return ['ok' => false, 'error' => 'O Cargo é herdado do contrato METADADOS e não pode ser alterado manualmente enquanto o vínculo existir.'];
            }
            $cargoId = $ctx['cargo_id'];
        } elseif ($cargoId !== null) {
            if ($this->cargos->oficialPorId($cargoId) === null) {
                return ['ok' => false, 'error' => 'Cargo inválido: selecione um cargo do catálogo oficial.'];
            }
        }

        // ----- Setor principal -----
        $principalTravadoMetadados = $vinculado && $ctx['setor_principal_id'] !== null;
        if ($principalTravadoMetadados) {
            if ($setorPrincipalId !== null && $setorPrincipalId !== $ctx['setor_principal_id']) {
                return ['ok' => false, 'error' => 'O Setor principal é herdado do contrato METADADOS e não pode ser substituído.'];
            }
            $setorPrincipalManualId = null; // o principal já é gerido como linha METADADOS
        } else {
            if ($setorPrincipalId !== null && $this->setores->oficialPorId($setorPrincipalId) === null) {
                return ['ok' => false, 'error' => 'Setor principal inválido: selecione um setor do catálogo oficial.'];
            }
            $setorPrincipalManualId = $setorPrincipalId;
        }

        // ----- Setores adicionais -----
        $setorOficialDoContrato = $principalTravadoMetadados ? (int)$ctx['setor_principal_id'] : null;
        $adicionais = [];
        foreach (array_unique(array_map('intval', $setoresAdicionais)) as $sid) {
            if ($sid <= 0) {
                continue;
            }
            if ($sid === $setorPrincipalManualId || $sid === $setorOficialDoContrato) {
                continue; // já é o principal
            }
            if ($this->setores->oficialPorId($sid) === null) {
                return ['ok' => false, 'error' => 'Setor adicional inválido: apenas setores do catálogo oficial são aceitos.'];
            }
            $adicionais[] = $sid;
        }

        $ownTx = !$this->pdo->inTransaction();
        if ($ownTx) {
            $this->pdo->beginTransaction();
        }
        try {
            $this->pdo->prepare('UPDATE usuarios SET cargo_id = ? WHERE id = ?')
                ->execute([$cargoId, $usuarioId]);

            // A edição manual gere SOMENTE as linhas MANUAL; as linhas METADADOS pertencem ao vínculo.
            $this->pdo->prepare(
                "DELETE FROM usuario_setores WHERE usuario_id = ? AND origem = 'MANUAL'"
            )->execute([$usuarioId]);

            if ($setorPrincipalManualId !== null) {
                // Garante 1 principal: rebaixa um eventual principal METADADOS remanescente.
                $this->pdo->prepare(
                    'UPDATE usuario_setores SET principal = 0 WHERE usuario_id = ?'
                )->execute([$usuarioId]);
                $this->inserirManual($usuarioId, $setorPrincipalManualId, true);
            }

            foreach ($adicionais as $sid) {
                $this->inserirManual($usuarioId, $sid, false);
            }

            if ($ownTx) {
                $this->pdo->commit();
            }
        } catch (\Throwable $e) {
            if ($ownTx && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            return ['ok' => false, 'error' => 'Falha ao gravar o contexto organizacional: ' . $e->getMessage()];
        }

        AuditLog::log($actor?->id, $usuarioId, 'usuario_contexto_manual', sprintf(
            'cargo_id=%s setor_principal=%s adicionais=[%s]',
            $cargoId === null ? 'NULL' : (string)$cargoId,
            $setorPrincipalManualId === null ? ($setorOficialDoContrato === null ? 'NULL' : $setorOficialDoContrato . ' (METADADOS)') : (string)$setorPrincipalManualId,
            implode(',', $adicionais)
        ), $ip);

        return ['ok' => true];
    }

    /**
     * Snapshot do contexto para a tela de detalhe do usuário.
     *
     * @return array{
     *   vinculado:bool, cargo_id:?int, cargo_rotulo:?string, cargo_travado:bool, cargo_aviso:?string,
     *   setor_principal:?array{setor_id:int,rotulo:string,origem:string},
     *   setor_principal_travado:bool, setor_aviso:?string,
     *   setores_adicionais:array<int, array{setor_id:int,rotulo:string,origem:string}>
     * }
     */
    public function contextoDoUsuario(int $usuarioId): array
    {
        $usuario = User::findById($usuarioId);
        $vinculado = $usuario !== null && $usuario->colaborador_metadados_id !== null;
        $ctx = $vinculado ? $this->resolverContextoOficial((int)$usuario->colaborador_metadados_id) : null;

        $cargoId = $usuario?->cargo_id;
        $cargoRotulo = null;
        if ($cargoId !== null) {
            $stmt = $this->pdo->prepare('SELECT id, nome, descricao_oficial FROM cargos WHERE id = ? LIMIT 1');
            $stmt->execute([$cargoId]);
            $cargo = $stmt->fetch(PDO::FETCH_ASSOC);
            $cargoRotulo = $cargo ? $this->rotulo($cargo) : null;
        }

        $setores = $this->setoresDoUsuario($usuarioId);
        $principal = null;
        $adicionais = [];
        foreach ($setores as $linha) {
            $item = [
                'setor_id' => (int)$linha['setor_id'],
                'rotulo' => $this->rotulo($linha),
                'origem' => (string)$linha['origem'],
            ];
            if ((int)$linha['principal'] === 1 && $principal === null) {
                $principal = $item;
            } else {
                $adicionais[] = $item;
            }
        }

        return [
            'vinculado' => $vinculado,
            'cargo_id' => $cargoId,
            'cargo_rotulo' => $cargoRotulo,
            'cargo_travado' => $vinculado && $ctx['cargo_id'] !== null,
            'cargo_aviso' => $vinculado ? $ctx['cargo_aviso'] : null,
            'setor_principal' => $principal,
            'setor_principal_travado' => $vinculado && $ctx['setor_principal_id'] !== null,
            'setor_aviso' => $vinculado ? $ctx['setor_aviso'] : null,
            'setores_adicionais' => $adicionais,
        ];
    }

    /** @return array<int, array<string,mixed>> linhas de `usuario_setores` + nome/descrição do setor */
    public function setoresDoUsuario(int $usuarioId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT us.setor_id, us.principal, us.origem, s.nome, s.descricao_oficial
             FROM usuario_setores us
             INNER JOIN setores s ON s.id = us.setor_id
             WHERE us.usuario_id = ?
             ORDER BY us.principal DESC, s.nome ASC'
        );
        $stmt->execute([$usuarioId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function inserirManual(int $usuarioId, int $setorId, bool $principal): void
    {
        // ON DUPLICATE: se já houver linha METADADOS para o mesmo setor, não a rebaixa a MANUAL —
        // apenas ignora (o principal oficial vence). UNIQUE(usuario_id, setor_id) garante 0..1.
        $this->pdo->prepare(
            "INSERT INTO usuario_setores (usuario_id, setor_id, principal, origem)
             VALUES (?, ?, ?, 'MANUAL')
             ON DUPLICATE KEY UPDATE updated_at = CURRENT_TIMESTAMP"
        )->execute([$usuarioId, $setorId, $principal ? 1 : 0]);
    }

    /** descricao_oficial (identidade do METADADOS) quando houver, senão o nome local. */
    private function rotulo(array $row): string
    {
        $oficial = trim((string)($row['descricao_oficial'] ?? ''));
        return $oficial !== '' ? $oficial : trim((string)($row['nome'] ?? ''));
    }
}
