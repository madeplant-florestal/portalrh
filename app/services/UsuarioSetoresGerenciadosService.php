<?php

/**
 * "Setores gerenciados" (Bloco 7, 2026-10, pedido do RH) — relação explícita, declarada pelo
 * RH/Admin, entre um usuário do Portal e os Setores oficiais (catálogo local `setores`, espelhado
 * do METADADOS) pelos quais ele tem responsabilidade GERENCIAL. É a base do filtro por Gestor:
 *
 *   Usuário gestor -> Setores gerenciados -> colaboradores_metadados.codigo_setor
 *
 * Decisão de negócio (ver migration 2026-10-02-usuario-setores-gerenciados.sql): não existe vínculo
 * individual colaborador -> gestor confiável no METADADOS. A responsabilidade gerencial é
 * declarada por Setor, nunca inferida — e é responsabilidade COMPARTILHADA por desenho: mais de um
 * usuário pode gerenciar o mesmo Setor (não existe exclusividade 1 Setor = 1 gestor). Filtrar por
 * qualquer um desses gestores mostra o Setor inteiro — nunca tenta deduzir qual colaborador
 * "pertence" a qual gestor individualmente.
 *
 * Propositalmente SEPARADA de `usuario_setores` (Setor principal/adicionais de atuação — escopo do
 * PRÓPRIO usuário) e de `usuarios.gestor_usuario_id` (Gestor Imediato — hierarquia 1:1 entre
 * usuários). Nenhuma das duas é lida nem escrita aqui.
 */
class UsuarioSetoresGerenciadosService
{
    private PDO $pdo;
    private CatalogoMetadadosRepository $setoresCatalogo;

    public function __construct(?PDO $pdo = null, ?CatalogoMetadadosRepository $setoresCatalogo = null)
    {
        $this->pdo = $pdo ?? Database::conn();
        $this->setoresCatalogo = $setoresCatalogo ?? new CatalogoMetadadosRepository('setores', $this->pdo);
    }

    /**
     * Setores gerenciados pelo usuário, só do catálogo oficial (mesmo filtro de listarOficiais()).
     *
     * @return array<int,array{id:int,codigo:string,nome:string,descricao_oficial:?string}>
     */
    public function setoresDoUsuario(int $usuarioId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT s.id, s.codigo_setor AS codigo, s.nome, s.descricao_oficial
             FROM usuario_setores_gerenciados usg
             INNER JOIN setores s ON s.id = usg.setor_id
             WHERE usg.usuario_id = ? AND s.codigo_setor IS NOT NULL AND s.codigo_setor <> '' AND s.ativo = 1
             ORDER BY COALESCE(NULLIF(s.descricao_oficial, ''), s.nome) ASC"
        );
        $stmt->execute([$usuarioId]);
        return array_map(static function (array $row): array {
            return [
                'id' => (int)$row['id'],
                'codigo' => (string)$row['codigo'],
                'nome' => (string)$row['nome'],
                'descricao_oficial' => $row['descricao_oficial'] !== null ? (string)$row['descricao_oficial'] : null,
            ];
        }, $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * Códigos oficiais de Setor (`codigo_setor`, a chave que casa com
     * `colaboradores_metadados.codigo_setor`) gerenciados por este usuário — a tradução usada pelo
     * filtro por Gestor. Usuário sem nenhum setor gerenciado (ou inexistente/inativo) devolve [].
     *
     * @return string[]
     */
    public function codigosSetorGerenciadosPor(int $usuarioId): array
    {
        return array_column($this->setoresDoUsuario($usuarioId), 'codigo');
    }

    /**
     * Candidatos a Gestor para o filtro: só usuários ATIVOS (email_verified_at IS NOT NULL, mesma
     * convenção de User::paginateForAdmin()/setActiveStatus()) com pelo menos 1 Setor gerenciado.
     * Usuário sem nenhum setor marcado nunca aparece aqui — "nenhum setor marcado = usuário não
     * participa dos filtros por Gestor".
     *
     * @return array<int,array{id:int,nome:string}>
     */
    public function gestoresElegiveis(): array
    {
        $stmt = $this->pdo->query(
            "SELECT DISTINCT u.id, u.nome
             FROM usuario_setores_gerenciados usg
             INNER JOIN usuarios u ON u.id = usg.usuario_id
             INNER JOIN setores s ON s.id = usg.setor_id
             WHERE u.email_verified_at IS NOT NULL
               AND s.codigo_setor IS NOT NULL AND s.codigo_setor <> '' AND s.ativo = 1
             ORDER BY u.nome ASC"
        );
        return array_map(static fn(array $row): array => ['id' => (int)$row['id'], 'nome' => (string)$row['nome']], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * Substitui o conjunto de Setores gerenciados do usuário pelos ids recebidos (replace
     * completo, mesmo padrão de UsuarioContextoOrganizacionalService::definirContextoManual() para
     * os setores adicionais). Só aceita ids do catálogo OFICIAL (CatalogoMetadadosRepository::
     * oficialPorId()) — nunca um id de setor legado sem código.
     *
     * @param int[] $setorIds
     * @return array{ok:bool,error?:string}
     */
    public function definirSetoresGerenciados(int $usuarioId, array $setorIds): array
    {
        if (User::findById($usuarioId) === null) {
            return ['ok' => false, 'error' => 'Usuário não encontrado.'];
        }

        $ids = [];
        foreach (array_unique(array_map('intval', $setorIds)) as $sid) {
            if ($sid <= 0) {
                continue;
            }
            if ($this->setoresCatalogo->oficialPorId($sid) === null) {
                return ['ok' => false, 'error' => 'Setor inválido: apenas setores do catálogo oficial são aceitos.'];
            }
            $ids[] = $sid;
        }

        $ownTx = !$this->pdo->inTransaction();
        if ($ownTx) {
            $this->pdo->beginTransaction();
        }
        try {
            $this->pdo->prepare('DELETE FROM usuario_setores_gerenciados WHERE usuario_id = ?')->execute([$usuarioId]);
            if ($ids !== []) {
                $stmt = $this->pdo->prepare('INSERT INTO usuario_setores_gerenciados (usuario_id, setor_id) VALUES (?, ?)');
                foreach ($ids as $sid) {
                    $stmt->execute([$usuarioId, $sid]);
                }
            }
            if ($ownTx) {
                $this->pdo->commit();
            }
        } catch (\Throwable $e) {
            if ($ownTx && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            return ['ok' => false, 'error' => 'Falha ao salvar os setores gerenciados.'];
        }

        return ['ok' => true];
    }
}
