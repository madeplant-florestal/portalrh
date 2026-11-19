<?php

/**
 * RESOLVEDOR CENTRAL de escopo setorial (Etapa 2C.1, 2026-11). Único lugar que decide QUAIS dados de pessoas um ator pode ver por
 * setor — para listagens, consultas por ID, relatórios, exportações e operações de escrita (todos devem perguntar aqui).
 *
 * Contrato (três situações DISTINTAS, nunca confundidas):
 *   GLOBAL   — Supervisor Geral, Administrador e RH: todos os setores, quando o MÓDULO permitir.
 *   SETORES  — Gestor: exatamente os setores de `usuario_setores_gerenciados` (códigos oficiais `setores.codigo_setor`). Gestor
 *              SEM setores = conjunto VAZIO: não enxerga NENHUM dado — jamais "sem restrição".
 *   NENHUM   — ausência de autorização por escopo: perfil sem direito a dado nominal setorial (viewer na arquitetura definitiva,
 *              role desconhecida, ator inexistente). Também não enxerga nada.
 *
 * Fonte ÚNICA do conjunto de setores: `usuario_setores_gerenciados` (via UsuarioSetoresGerenciadosService). NUNCA são fonte de escopo:
 * `usuarios.gestor_usuario_id` (hierarquia 1:1), `usuario_colaboradores.is_gestor` (legado) nem `usuario_setores` (setor de atuação).
 * O flag `is_supervisor` não influencia nada aqui (só a role decide).
 *
 * TRANSIÇÃO (`VIEWER_LEGADO_TRANSITORIO`): hoje há viewers delegados que operam módulos já filtrados por Setor Gerenciado
 * (Colaboradores, Avaliação de Experiência). Para não retirar esse acesso sem migração, o viewer COM setores continua SETORES enquanto a
 * constante for true; na arquitetura definitiva (constante false) o viewer é NENHUM. Quando o viewer não tem setores, é SETORES vazio.
 * A permissão do módulo continua exigida por quem chama (PermissaoPerfilPolicy/Authorization) — o escopo NÃO substitui a permissão.
 */
final class EscopoSetorial
{
    public const GLOBAL = 'global';
    public const SETORES = 'setores';
    public const NENHUM = 'nenhum';

    public const VIEWER_LEGADO_TRANSITORIO = true;

    /** @param string[] $codigos */
    private function __construct(public readonly string $tipo, private readonly array $codigos)
    {
    }

    public static function global(): self
    {
        return new self(self::GLOBAL, []);
    }

    /** @param string[] $codigos */
    public static function restrito(array $codigos): self
    {
        return new self(self::SETORES, array_values(array_unique(array_filter(array_map('strval', $codigos), static fn(string $c): bool => $c !== ''))));
    }

    public static function nenhum(): self
    {
        return new self(self::NENHUM, []);
    }

    /**
     * @param array{id?:int|string,role?:string}|User|int|null $ator `int` = id do usuário (a role é lida do banco)
     * @param bool|null $viewerLegado null = usa VIEWER_LEGADO_TRANSITORIO (parâmetro existe para testes)
     */
    public static function resolver(mixed $ator, ?bool $viewerLegado = null): self
    {
        if (is_int($ator)) {
            $stmt = Database::conn()->prepare('SELECT id, role FROM usuarios WHERE id = ?');
            $stmt->execute([$ator]);
            $ator = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        }
        if ($ator === null) {
            return self::nenhum();
        }
        $id = is_array($ator) ? (int)($ator['id'] ?? 0) : (int)($ator->id ?? 0);
        $role = strtolower(trim((string)(is_array($ator) ? ($ator['role'] ?? '') : ($ator->role ?? ''))));
        if ($id <= 0) {
            return self::nenhum();
        }
        switch ($role) {
            case 'admin': // Supervisor Geral é admin (o flag não muda o escopo)
            case 'rh':
                return self::global();
            case 'gestor':
                return self::restrito(self::setoresGerenciadosDe($id));
            case 'viewer':
                return ($viewerLegado ?? self::VIEWER_LEGADO_TRANSITORIO) ? self::restrito(self::setoresGerenciadosDe($id)) : self::nenhum();
            default:
                return self::nenhum(); // role vazia/desconhecida: sem autorização
        }
    }

    /** ÚNICA leitura do conjunto de setores gerenciados. */
    private static function setoresGerenciadosDe(int $usuarioId): array
    {
        return (new UsuarioSetoresGerenciadosService())->codigosSetorGerenciadosPor($usuarioId);
    }

    public function ehGlobal(): bool
    {
        return $this->tipo === self::GLOBAL;
    }

    public function ehRestrito(): bool
    {
        return $this->tipo === self::SETORES;
    }

    public function semAutorizacao(): bool
    {
        return $this->tipo === self::NENHUM;
    }

    /** Há algum dado visível? (global ou restrito com ao menos 1 setor) */
    public function enxergaAlgo(): bool
    {
        return $this->ehGlobal() || ($this->ehRestrito() && $this->codigos !== []);
    }

    /** Códigos permitidos; `[]` para vazio/nenhum (nunca "tudo"). Para global use ehGlobal()/codigosOuNull(). */
    public function codigos(): array
    {
        return $this->codigos;
    }

    /** Formato legado dos repositórios: `null` = sem restrição (SÓ global); array (possivelmente vazio) = restrito; nenhum = `[]`. */
    public function codigosOuNull(): ?array
    {
        return $this->ehGlobal() ? null : $this->codigos;
    }

    /** O dado de um setor (código oficial) é acessível? Registro sem setor definido só é acessível ao escopo global. */
    public function permiteSetor(?string $codigoSetor): bool
    {
        if ($this->ehGlobal()) {
            return true;
        }
        if ($this->semAutorizacao() || $codigoSetor === null || trim($codigoSetor) === '') {
            return false;
        }
        return in_array($codigoSetor, $this->codigos, true);
    }

    /**
     * O CONTRATO (`colaboradores_metadados.id`) é acessível? Decide pelo setor ATUAL do contrato, lido agora do METADADOS (nunca um
     * snapshot gravado em feedback/avaliação/PDI): transferido = acompanha o setor novo; setor ausente/vazio = só o escopo global;
     * contrato inexistente = nunca. Cada contrato de um colaborador com múltiplos contratos é avaliado isoladamente.
     */
    public function permiteColaborador(int $metadadosId): bool
    {
        if ($metadadosId <= 0) {
            return false;
        }
        if ($this->ehGlobal()) {
            return true;
        }
        if (!$this->enxergaAlgo()) {
            return false;
        }
        $stmt = Database::conn()->prepare('SELECT codigo_setor FROM colaboradores_metadados WHERE id = ? LIMIT 1');
        $stmt->execute([$metadadosId]);
        $codigo = $stmt->fetchColumn();
        return $this->permiteSetor($codigo === false || $codigo === null ? null : (string)$codigo);
    }

    /**
     * Condição SQL parametrizada sobre uma coluna que guarda `colaboradores_metadados.id` (setor ATUAL do contrato, via EXISTS).
     * Global: `1 = 1`; restrito com setores: EXISTS(... codigo_setor IN (?, ...)); vazio/nenhum: `1 = 0`. `$colunaMetadadosId` é um
     * identificador FIXO (nunca entrada de usuário).
     *
     * @param array<int,mixed> $params recebe os parâmetros posicionais
     */
    public function sqlColaborador(string $colunaMetadadosId, array &$params): string
    {
        if ($this->ehGlobal()) {
            return '1 = 1';
        }
        if (!$this->enxergaAlgo()) {
            return '1 = 0';
        }
        foreach ($this->codigos as $c) {
            $params[] = $c;
        }
        return 'EXISTS (SELECT 1 FROM colaboradores_metadados cm_esc WHERE cm_esc.id = ' . $colunaMetadadosId
            . ' AND cm_esc.codigo_setor IN (' . implode(', ', array_fill(0, count($this->codigos), '?')) . '))';
    }

    /**
     * Condição SQL parametrizada para uma coluna de código de setor. Global: `1 = 1`; restrito com setores: `coluna IN (?, ...)`;
     * vazio/nenhum: `1 = 0` (NUNCA sem filtro). `$coluna` deve ser um identificador FIXO do código (nunca entrada de usuário).
     *
     * @param array<int,mixed> $params recebe os parâmetros posicionais
     */
    public function sql(string $coluna, array &$params): string
    {
        if ($this->ehGlobal()) {
            return '1 = 1';
        }
        if (!$this->enxergaAlgo()) {
            return '1 = 0';
        }
        foreach ($this->codigos as $c) {
            $params[] = $c;
        }
        return $coluna . ' IN (' . implode(', ', array_fill(0, count($this->codigos), '?')) . ')';
    }
}
