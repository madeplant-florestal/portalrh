<?php

/**
 * Política CENTRAL de autoridade entre contas do Portal (Etapa 2A-bis, 2026-11) — única fonte de decisão sobre quem pode
 * gerenciar quem, quem pode atribuir qual perfil e quem é o Supervisor Geral. Nenhum controller, model ou view deve decidir
 * isso por conta própria (nada de `role === 'admin' || is_supervisor`); a UI só ESCONDE o que esta classe já nega.
 *
 * Hierarquia oficial:
 *   1. Supervisor Geral  = role `admin` E `is_supervisor = 1`  (as DUAS condições; o flag sozinho não concede nada)
 *   2. Administrador     = role `admin`, `is_supervisor = 0`
 *   3. RH                = role `rh`
 *   4. Gestor            = (perfil futuro — hoje cai em "viewer"/desconhecido; não é tratado como privilegiado)
 *   5. Visualizador      = role `viewer`
 * Um `is_supervisor = 1` em conta que NÃO é admin é CONFIGURAÇÃO INCONSISTENTE: a conta vale pela própria role, sem
 * privilégio algum, e é apenas sinalizada (`privilegioInconsistente()`); nunca é corrigida automaticamente.
 *
 * Regras de gerência (`podeGerenciar`, sobre a CONTA: perfil, status, senha, exclusão, permissões):
 *   - Supervisor Geral: todas as contas (inclusive outros Supervisores Gerais).
 *   - Administrador: todas, EXCETO Supervisor Geral.
 *   - RH: apenas contas rh/viewer (nunca admin nem Supervisor Geral).
 *   - Gestor/Viewer: nenhuma.
 * Perfil (`podeAtribuirRole`): ninguém altera o próprio perfil; Supervisor Geral e Administrador atribuem admin/rh/viewer;
 * RH atribui somente rh/viewer, e só a contas rh/viewer (nunca promove a admin, nunca rebaixa um admin).
 * O FLAG `is_supervisor` só muda por operação explícita e auditada de um Supervisor Geral (`podeAlterarSupervisorGeral`).
 *
 * Os parâmetros aceitam um `User`, um array com `id`/`role`/`is_supervisor` ou null.
 */
final class Autoridade
{
    public const SUPERVISOR_GERAL = 'supervisor_geral';
    public const ADMIN = 'admin';
    public const RH = 'rh';
    public const GESTOR = 'gestor';
    public const VIEWER = 'viewer';
    public const DESCONHECIDO = 'desconhecido';

    /** Perfis que o formulário pode pedir (whitelist de `role`). */
    public const ROLES_ATRIBUIVEIS = ['admin', 'rh', 'viewer'];

    /** @return array{id:int,role:string,flag:int}|null */
    private static function normalizar(mixed $u): ?array
    {
        if ($u === null) {
            return null;
        }
        if (is_array($u)) {
            return ['id' => (int)($u['id'] ?? 0), 'role' => strtolower(trim((string)($u['role'] ?? ''))), 'flag' => (int)($u['is_supervisor'] ?? 0)];
        }
        if (is_object($u)) {
            return ['id' => (int)($u->id ?? 0), 'role' => strtolower(trim((string)($u->role ?? ''))), 'flag' => (int)($u->is_supervisor ?? 0)];
        }
        return null;
    }

    public static function ehSupervisorGeral(mixed $u): bool
    {
        $n = self::normalizar($u);
        return $n !== null && $n['role'] === 'admin' && $n['flag'] === 1;
    }

    /** is_supervisor = 1 em conta que não é admin: não concede nada; só é reportada. */
    public static function privilegioInconsistente(mixed $u): bool
    {
        $n = self::normalizar($u);
        return $n !== null && $n['flag'] === 1 && $n['role'] !== 'admin';
    }

    public static function nivel(mixed $u): string
    {
        $n = self::normalizar($u);
        if ($n === null) {
            return self::DESCONHECIDO;
        }
        if (self::ehSupervisorGeral($u)) {
            return self::SUPERVISOR_GERAL;
        }
        return match ($n['role']) {
            'admin' => self::ADMIN,
            'rh' => self::RH,
            'gestor' => self::GESTOR,
            'viewer' => self::VIEWER,
            default => self::DESCONHECIDO,
        };
    }

    /** Valor efetivo do flag para leitores legados: 1 somente para o Supervisor Geral de fato. */
    public static function flagEfetivo(mixed $u): int
    {
        return self::ehSupervisorGeral($u) ? 1 : 0;
    }

    /** O ator é Administrador ou Supervisor Geral (pode usar as ações administrativas de conta). */
    public static function ehAdministrativo(mixed $u): bool
    {
        return in_array(self::nivel($u), [self::SUPERVISOR_GERAL, self::ADMIN], true);
    }

    /** Pode gerenciar a CONTA do alvo (perfil, status, senha, exclusão, permissões, vínculos). Inclui o próprio ator. */
    public static function podeGerenciar(mixed $ator, mixed $alvo): bool
    {
        if (self::normalizar($ator) === null || self::normalizar($alvo) === null) {
            return false;
        }
        $nivelAlvo = self::nivel($alvo);
        return match (self::nivel($ator)) {
            self::SUPERVISOR_GERAL => true,
            self::ADMIN => $nivelAlvo !== self::SUPERVISOR_GERAL,
            self::RH => in_array($nivelAlvo, [self::RH, self::VIEWER, self::GESTOR], true),
            default => false,
        };
    }

    /**
     * Pode editar o CONTEXTO ORGANIZACIONAL do alvo (cargo, setores, vínculo METADADOS, setores gerenciados) — as telas
     * que o RH já operava. Regra anterior preservada: qualquer admin/RH, EXCETO contas de Supervisor Geral (só outro
     * Supervisor Geral as edita).
     */
    public static function podeGerenciarContexto(mixed $ator, mixed $alvo): bool
    {
        if (self::normalizar($ator) === null || self::normalizar($alvo) === null) {
            return false;
        }
        if (!in_array(self::nivel($ator), [self::SUPERVISOR_GERAL, self::ADMIN, self::RH], true)) {
            return false;
        }
        return !self::ehSupervisorGeral($alvo) || self::ehSupervisorGeral($ator);
    }

    public static function podeAtribuirRole(mixed $ator, mixed $alvo, string $novaRole): bool
    {
        $a = self::normalizar($ator);
        $t = self::normalizar($alvo);
        $novaRole = strtolower(trim($novaRole));
        if ($a === null || $t === null || !in_array($novaRole, self::ROLES_ATRIBUIVEIS, true)) {
            return false;
        }
        if ($a['id'] > 0 && $a['id'] === $t['id']) {
            return false; // ninguém altera o PRÓPRIO perfil (anti-autoelevação e anti-autorrebaixamento)
        }
        if (!self::podeGerenciar($ator, $alvo)) {
            return false;
        }
        if (self::ehSupervisorGeral($alvo)) {
            return false; // o perfil de um Supervisor Geral só muda DEPOIS de revogar o flag (operação explícita) — nunca de forma indireta
        }
        return match (self::nivel($ator)) {
            self::SUPERVISOR_GERAL, self::ADMIN => true,
            self::RH => in_array($novaRole, ['rh', 'viewer'], true) && in_array($t['role'], ['rh', 'viewer'], true),
            default => false,
        };
    }

    /** Somente um Supervisor Geral concede ou remove o flag (operação explícita e auditada). */
    public static function podeAlterarSupervisorGeral(mixed $ator): bool
    {
        return self::ehSupervisorGeral($ator);
    }

    /** Ator da requisição, lido do BANCO a cada chamada (nunca da sessão, que pode estar defasada). */
    public static function atorDaSessao(): ?User
    {
        $id = (int)($_SESSION['user_id'] ?? 0);
        return $id > 0 ? User::findById($id) : null;
    }
}
