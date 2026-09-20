<?php

/**
 * Gestor Imediato do usuário (`usuarios.gestor_usuario_id`) — hierarquia operacional PRÓPRIA do Portal.
 *
 * Decisão arquitetural: novos relacionamentos de gestão/hierarquia NÃO apontam para estruturas legadas (`colaboradores`,
 * `usuario_colaboradores`, `lider_colaborador_id`, `is_gestor`) e NÃO são sincronizados com elas. O Gestor Imediato é
 * sempre OUTRO usuário do Portal; é conceito independente do aprovador de Solicitação de Vaga
 * (`usuarios.aprovador_usuario_id`) — nada é copiado entre os dois. Nenhum fluxo existente passa a depender dele nesta
 * etapa: é a fonte de hierarquia para módulos futuros.
 *
 * Regras: gestor existente e ATIVO (`email_verified_at IS NOT NULL`, a regra real do Portal); nunca o próprio usuário;
 * sem CICLO na cadeia (A→B→A, A→B→C→A), com proteção contra laço em dados corrompidos; usuário sem gestor é permitido;
 * gestor que ficou inativo NÃO é trocado automaticamente e o vínculo atual pode ser mantido (só mudar para OUTRO gestor
 * exige gestor ativo). Toda mudança grava `auditoria_usuarios` (`gestor_imediato_update`) na mesma transação.
 */
class UsuarioGestorService
{
    public const ACAO_AUDITORIA = 'gestor_imediato_update';
    /** Teto de passos ao subir a cadeia — proteção contra dados corrompidos. */
    public const MAX_PROFUNDIDADE = 100;

    private UsuarioGestorRepository $repository;

    public function __construct(?UsuarioGestorRepository $repository = null)
    {
        $this->repository = $repository ?? new UsuarioGestorRepository();
    }

    // ================================================================== regras puras (sobre o mapa id => gestor)

    /**
     * Cadeia de gestores acima de `$usuarioId` (gestor, gestor do gestor, ...), sem repetir e com teto de profundidade.
     *
     * @param array<int,?int> $mapa
     * @return int[]
     */
    public static function cadeiaAcima(array $mapa, int $usuarioId): array
    {
        $cadeia = [];
        $visto = [$usuarioId => true];
        $atual = $mapa[$usuarioId] ?? null;
        while ($atual !== null && count($cadeia) < self::MAX_PROFUNDIDADE) {
            if (isset($visto[$atual])) {
                break; // laço já existente nos dados: para em vez de repetir para sempre
            }
            $visto[$atual] = true;
            $cadeia[] = $atual;
            $atual = $mapa[$atual] ?? null;
        }
        return $cadeia;
    }

    /**
     * Definir `$novoGestorId` como gestor de `$usuarioId` criaria ciclo? (o usuário aparece na cadeia do novo gestor,
     * ou o novo gestor é o próprio usuário). Um laço PRÉ-EXISTENTE que não passa pelo usuário não bloqueia.
     *
     * @param array<int,?int> $mapa
     */
    public static function criaCiclo(array $mapa, int $usuarioId, int $novoGestorId): bool
    {
        if ($novoGestorId === $usuarioId) {
            return true;
        }
        return in_array($usuarioId, self::cadeiaAcima($mapa, $novoGestorId), true);
    }

    /**
     * Todos os subordinados (diretos e indiretos) de `$usuarioId`, em memória, com proteção contra laço.
     *
     * @param array<int,?int> $mapa
     * @return int[]
     */
    public static function descendentes(array $mapa, int $usuarioId): array
    {
        $filhos = [];
        foreach ($mapa as $id => $gestor) {
            if ($gestor !== null) {
                $filhos[$gestor][] = (int)$id;
            }
        }
        $resultado = [];
        $visto = [$usuarioId => true];
        $fila = [$usuarioId];
        while ($fila !== []) {
            $atual = array_shift($fila);
            foreach ($filhos[$atual] ?? [] as $filho) {
                if (!isset($visto[$filho])) {
                    $visto[$filho] = true;
                    $resultado[] = $filho;
                    $fila[] = $filho;
                }
            }
        }
        return $resultado;
    }

    // ================================================================== consultas reutilizáveis

    /** Gestor imediato do usuário (com `ativo`) ou null. */
    public function gestorDoUsuario(int $usuarioId): ?array
    {
        $g = $this->repository->gestorDe($usuarioId);
        if ($g === null) {
            return null;
        }
        $g['ativo'] = (int)$g['ativo'] === 1;
        return $g;
    }

    /** Liderados diretos. */
    public function subordinadosDiretos(int $gestorId): array
    {
        return array_map(static function (array $r): array {
            $r['ativo'] = (int)$r['ativo'] === 1;
            return $r;
        }, $this->repository->subordinadosDiretos($gestorId));
    }

    public function quantidadeSubordinadosDiretos(int $gestorId): int
    {
        return $this->repository->contarSubordinadosDiretos($gestorId);
    }

    /** Cadeia hierárquica acima do usuário (do gestor imediato para cima), como linhas de usuário. */
    public function cadeiaDoUsuario(int $usuarioId): array
    {
        $ids = self::cadeiaAcima($this->repository->mapaHierarquia(), $usuarioId);
        if ($ids === []) {
            return [];
        }
        $porId = [];
        foreach ($this->repository->ativos() as $u) {
            $porId[(int)$u['id']] = $u;
        }
        $cadeia = [];
        foreach ($ids as $id) {
            $u = $porId[$id] ?? $this->repository->usuario($id);
            if ($u !== null) {
                $cadeia[] = ['id' => (int)$id, 'nome' => (string)$u['nome']];
            }
        }
        return $cadeia;
    }

    /**
     * Opções de gestor para SELEÇÃO: só usuários ativos, nunca o próprio usuário e nunca quem já é subordinado dele
     * (escolher um subordinado criaria ciclo). Uma consulta de usuários + uma do mapa — sem N+1.
     */
    public function candidatos(?int $usuarioId): array
    {
        $excluir = [];
        if ($usuarioId !== null) {
            $excluir = array_merge([$usuarioId], self::descendentes($this->repository->mapaHierarquia(), $usuarioId));
        }
        $excluir = array_flip($excluir);
        return array_values(array_filter($this->repository->ativos(), static fn(array $u): bool => !isset($excluir[(int)$u['id']])));
    }

    /** Rótulo legível da opção: "Nome — e-mail (Cargo)". */
    public static function rotuloOpcao(array $u): string
    {
        $cargo = trim((string)($u['cargo'] ?? ''));
        return (string)$u['nome'] . ' — ' . (string)$u['email'] . ($cargo !== '' ? ' (' . $cargo . ')' : '');
    }

    // ================================================================== validação e gravação

    /** Erro de validação de um gestor para um usuário AINDA NÃO criado (sem risco de ciclo), ou null se válido/vazio. */
    public function validarGestorNovoUsuario(?int $gestorId): ?string
    {
        if ($gestorId === null) {
            return null;
        }
        $g = $this->repository->usuario($gestorId);
        if ($g === null) {
            return 'O gestor imediato informado não é um usuário válido.';
        }
        if ((int)$g['ativo'] !== 1) {
            return 'O gestor imediato informado está inativo. Ative o usuário antes de designá-lo.';
        }
        return null;
    }

    /**
     * Define, troca ou remove (`null`) o Gestor Imediato. Valida SEMPRE no servidor: usuário e gestor existentes, gestor
     * ativo (exceto manter o mesmo gestor já vinculado), sem autorreferência e sem ciclo. Audita a mudança.
     *
     * @return array{ok:bool,error?:string,alterado?:bool}
     */
    public function definirGestor(int $usuarioId, ?int $gestorId, ?int $atorUsuarioId, ?string $ip): array
    {
        $usuario = $this->repository->usuario($usuarioId);
        if ($usuario === null) {
            return ['ok' => false, 'error' => 'Usuário não encontrado.'];
        }
        $atual = $usuario['gestor_usuario_id'] !== null ? (int)$usuario['gestor_usuario_id'] : null;
        if ($gestorId === $atual) {
            return ['ok' => true, 'alterado' => false]; // nada a fazer (inclusive manter gestor que ficou inativo)
        }

        if ($gestorId !== null) {
            if ($gestorId === $usuarioId) {
                return ['ok' => false, 'error' => 'Um usuário não pode ser o próprio gestor imediato.'];
            }
            $gestor = $this->repository->usuario($gestorId);
            if ($gestor === null) {
                return ['ok' => false, 'error' => 'O gestor imediato informado não é um usuário válido.'];
            }
            if ((int)$gestor['ativo'] !== 1) {
                return ['ok' => false, 'error' => 'O gestor imediato informado está inativo. Ative o usuário antes de designá-lo.'];
            }
            if (self::criaCiclo($this->repository->mapaHierarquia(), $usuarioId, $gestorId)) {
                return ['ok' => false, 'error' => 'Hierarquia inválida: o gestor escolhido já está subordinado a este usuário (ciclo).'];
            }
        }

        $this->repository->transacao(function () use ($usuarioId, $gestorId, $atual, $atorUsuarioId, $ip): void {
            $this->repository->definir($usuarioId, $gestorId);
            AuditLog::log($atorUsuarioId, $usuarioId, self::ACAO_AUDITORIA, sprintf(
                'gestor_usuario_id_anterior=%s gestor_usuario_id_novo=%s',
                $atual === null ? 'NULL' : (string)$atual,
                $gestorId === null ? 'NULL' : (string)$gestorId
            ), $ip);
        });
        return ['ok' => true, 'alterado' => true];
    }

    // ================================================================== sugestão (PDI)

    /**
     * Gestor imediato do USUÁRIO do Portal associado ao contrato (`usuarios.colaborador_metadados_id = metadados_id`),
     * para SUGERIR/pré-selecionar no formulário de PDI. Só a relação nova; só se o gestor estiver ativo; nunca persiste
     * nada e nunca é obrigatório.
     *
     * @return array{id:int,nome:string,usuario_nome:string}|null
     */
    public function gestorSugeridoParaContrato(int $metadadosId): ?array
    {
        $g = $this->repository->gestorDoUsuarioDoContrato($metadadosId);
        if ($g === null || (int)$g['ativo'] !== 1) {
            return null;
        }
        return ['id' => (int)$g['id'], 'nome' => (string)$g['nome'], 'usuario_nome' => (string)$g['usuario_nome']];
    }
}
