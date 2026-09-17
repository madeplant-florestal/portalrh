<?php

/**
 * Materialização sob demanda da extensão local (`colaboradores`) de um contrato oficial
 * (`colaboradores_metadados`) — corrige o bloqueio "Sem extensão local" que impedia o uso de
 * contratos oficiais em funcionalidades do Portal que ainda dependem de `colaboradores.id`
 * (Acesso/Liderança, Dados RH, Avaliações, Integração, Pesquisa de Integração).
 *
 * Regras:
 *   - NUNCA usa CPF para localizar/reconciliar o vínculo — só `colaboradores.metadados_id`
 *     (UNIQUE), o mesmo vínculo estrutural já usado por toda a listagem oficial
 *     (ColaboradorMetadadosConsultaRepository).
 *   - Idempotente: se já existe uma linha com este metadados_id, retorna ela — nunca duplica.
 *   - NUNCA escreve em `colaboradores_metadados` (READ-ONLY aqui).
 *   - Cargo/Empresa/Setor locais (cargo_id/empresa_id/setor_id) são resolvidos pelos códigos
 *     oficiais (codigo_cargo/codigo_empresa/codigo_setor) via os catálogos já oficiais
 *     (CatalogoMetadadosRepository / EmpresaMetadadosRepository) — nunca inventados. `cargo_id`
 *     é NOT NULL no schema local: se o cargo oficial não resolver a um cargo local, a
 *     materialização falha com erro explícito em vez de inventar um cargo.
 *   - `empresa_id`/`setor_id` locais ficam NULL quando o código oficial não resolver — mesma
 *     regra de "não inferir Setor" já aplicada na correção dos contadores de Empresas/Setores.
 *   - Os campos oficiais (nome, cpf, salário, datas, cargo/empresa/setor "de exibição") não são
 *     a fonte de verdade a partir daqui: `Colaborador::find()` sempre sobrepõe esses campos com
 *     o espelho quando `metadados_id` está presente (ver Colaborador::mesclarComEspelhoOficial()).
 *     Os valores gravados aqui na criação são só o mínimo estrutural exigido pelo schema local.
 */
class ColaboradorExtensaoLocalService
{
    /**
     * @return array{ok:bool,error?:string,id?:int,ja_existia?:bool}
     */
    public static function obterOuCriar(int $metadadosId): array
    {
        if ($metadadosId <= 0) {
            return ['ok' => false, 'error' => 'Contrato oficial inválido.'];
        }

        $existenteId = self::buscarIdPorMetadadosId($metadadosId);
        if ($existenteId !== null) {
            return ['ok' => true, 'id' => $existenteId, 'ja_existia' => true];
        }

        $pdo = Database::conn();
        $stmt = $pdo->prepare('SELECT * FROM colaboradores_metadados WHERE id = ? LIMIT 1');
        $stmt->execute([$metadadosId]);
        $contrato = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($contrato === false) {
            return ['ok' => false, 'error' => 'Contrato oficial não encontrado no espelho do METADADOS.'];
        }

        $cargoId = null;
        if (!empty($contrato['codigo_cargo'])) {
            $cargoOficial = (new CatalogoMetadadosRepository('cargos'))->findByCodigo((string)$contrato['codigo_cargo']);
            $cargoId = $cargoOficial['id'] ?? null;
        }
        if ($cargoId === null) {
            return [
                'ok' => false,
                'error' => 'Não foi possível localizar o cargo oficial deste contrato no catálogo local (codigo_cargo não resolvido). Sincronize/cadastre o cargo antes de habilitar as ações locais.',
            ];
        }

        $empresaId = null;
        if (!empty($contrato['codigo_empresa'])) {
            $empresaId = (new EmpresaMetadadosRepository())->findIdByCodigo((string)$contrato['codigo_empresa']);
        }

        $setorId = null;
        if (!empty($contrato['codigo_setor'])) {
            $setorOficial = (new CatalogoMetadadosRepository('setores'))->findByCodigo((string)$contrato['codigo_setor']);
            $setorId = $setorOficial['id'] ?? null;
        }

        $nome = trim((string)($contrato['nome'] ?? '')) !== '' ? (string)$contrato['nome'] : ('Colaborador #' . $metadadosId);
        $slug = self::gerarSlugUnico($nome, (string)($contrato['numero_contrato'] ?? $metadadosId));

        try {
            $insert = $pdo->prepare(
                'INSERT INTO colaboradores (nome, slug, cargo_id, empresa_id, setor_id, metadados_id, ativo)
                 VALUES (?, ?, ?, ?, ?, ?, ?)'
            );
            $insert->execute([
                $nome,
                $slug,
                $cargoId,
                $empresaId,
                $setorId,
                $metadadosId,
                (int)($contrato['ativo'] ?? 1) === 1 ? 1 : 0,
            ]);
            $novoId = (int)$pdo->lastInsertId();
        } catch (Throwable $e) {
            // Corrida: outra requisição materializou entre a checagem e o INSERT (UNIQUE em
            // metadados_id barra o duplicado) — comportamento idempotente, não propaga o erro.
            $existenteId = self::buscarIdPorMetadadosId($metadadosId);
            if ($existenteId !== null) {
                return ['ok' => true, 'id' => $existenteId, 'ja_existia' => true];
            }
            throw $e;
        }

        return ['ok' => true, 'id' => $novoId, 'ja_existia' => false];
    }

    private static function buscarIdPorMetadadosId(int $metadadosId): ?int
    {
        $stmt = Database::conn()->prepare('SELECT id FROM colaboradores WHERE metadados_id = ? LIMIT 1');
        $stmt->execute([$metadadosId]);
        $id = $stmt->fetchColumn();
        return $id !== false ? (int)$id : null;
    }

    private static function gerarSlugUnico(string $nome, string $referencia): string
    {
        $base = self::slugify($nome . '-' . $referencia);
        if ($base === '') {
            $base = 'colaborador';
        }
        $slug = $base;
        $sufixo = 1;
        $stmt = Database::conn()->prepare('SELECT COUNT(*) FROM colaboradores WHERE slug = ?');
        while (true) {
            $stmt->execute([$slug]);
            if ((int)$stmt->fetchColumn() === 0) {
                return $slug;
            }
            $sufixo++;
            $slug = $base . '-' . $sufixo;
        }
    }

    private static function slugify(string $valor): string
    {
        $valor = strtolower(trim($valor));
        $transliterado = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $valor);
        if (is_string($transliterado) && $transliterado !== '') {
            $valor = $transliterado;
        }
        $valor = preg_replace('/[^a-z0-9]+/', '-', $valor) ?? '';
        return trim($valor, '-');
    }
}
