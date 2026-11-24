<?php

/**
 * Etapa 3.2B.2 — autorização POR CANDIDATURA e POR ARQUIVO pelo setor responsável da vaga (`vagas.setor_id`), para detalhe e downloads.
 *
 * Cadeia: ator → `usuario_setores_gerenciados` (EscopoSetorial) → `vagas.setor_id` → candidatura (via `candidaturas.vaga_id`) → arquivo vinculado
 * a ESSA candidatura. Nada é deduzido de setor do candidato, nome/e-mail, `vagas.area` ou parâmetro do navegador.
 *
 * ESTE SERVIÇO NÃO É CHAMADO POR NENHUM CONTROLLER nesta etapa (os endpoints seguem Admin/RH): é a camada pronta para a Etapa 3.2C. Quem o
 * chamar DEVE obter o escopo por `escopoNominal()`, que (a) nunca devolve "global" por falha de resolução — ator inexistente/sem id/role
 * desconhecida = NENHUM — e (b) exclui o Viewer (acesso nominal a candidatos não é do Viewer, mesmo com setores gerenciados legados).
 * Todos os métodos exigem um EscopoSetorial NÃO nulo (o tipo impede "sem escopo = global"); escopo vazio/nenhum = nada.
 * Resultado `null` = inexistente OU fora do escopo (indistinguíveis de propósito).
 */
final class CandidaturaAcessoSetorialService
{
    public const ARQUIVO_CURRICULO = 'curriculo';
    public const ARQUIVO_DOCUMENTO = 'documento';
    public const ARQUIVO_CARTA_PDF = 'carta_pdf';
    public const ARQUIVO_CARTA_COMPROVANTE = 'carta_comprovante';

    /** Escopo para ACESSO NOMINAL a candidatos: viewer = nenhum (sem o legado transitório); falha de resolução = nenhum, jamais global. */
    public static function escopoNominal(mixed $ator): EscopoSetorial
    {
        try {
            return EscopoSetorial::resolver($ator, false);
        } catch (\Throwable $e) {
            return EscopoSetorial::nenhum();
        }
    }

    /**
     * Contexto mínimo de autorização da candidatura (id, vaga, setor da vaga) — a decisão é feita NO SQL (escopo na própria consulta),
     * sem carregar a candidatura inteira para filtrar depois.
     *
     * @return array{id:int,vaga_id:int,setor_id:?int}|null
     */
    public function autorizar(EscopoSetorial $escopo, int $candidaturaId): ?array
    {
        if ($candidaturaId <= 0 || (!$escopo->ehGlobal() && !$escopo->enxergaAlgo())) {
            return null;
        }
        $params = [$candidaturaId];
        if ($escopo->ehGlobal()) {
            $where = '1 = 1';
            $colSetor = VagaSetorService::schemaSuportaSetor() ? 'v.setor_id' : 'NULL';
        } else {
            if (!VagaSetorService::schemaSuportaSetor()) {
                return null;
            }
            $where = $escopo->sqlSetorId('v.setor_id', $params);
            $colSetor = 'v.setor_id';
        }
        $stmt = Database::conn()->prepare(
            "SELECT c.id, c.vaga_id, {$colSetor} AS setor_id FROM candidaturas c INNER JOIN vagas v ON v.id = c.vaga_id WHERE c.id = ? AND {$where} LIMIT 1"
        );
        $stmt->execute($params);
        $r = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$r) {
            return null;
        }
        return ['id' => (int)$r['id'], 'vaga_id' => (int)$r['vaga_id'], 'setor_id' => $r['setor_id'] === null ? null : (int)$r['setor_id']];
    }

    /**
     * Detalhe SEGURO da candidatura para o acompanhamento setorial: projeção da listagem (nome, vaga, setor da vaga, etapa, status, data) +
     * histórico de ETAPAS (de → para, data). Fora da projeção, por decisão de privacidade (ver docs): CPF, e-mail, telefone, currículo,
     * cargo pretendido/experiência (texto do candidato), indicação, observações e usuário do histórico, metadados de etapa, comunicações,
     * Carta Proposta, Exame Admissional e documentos. Não existe ficha global do candidato: só ESTA candidatura.
     */
    public function detalhe(EscopoSetorial $escopo, int $candidaturaId): ?array
    {
        if ($this->autorizar($escopo, $candidaturaId) === null) {
            return null;
        }
        $linha = Candidatura::acharEscopo($escopo, $candidaturaId);
        if ($linha === null && $escopo->ehGlobal()) {
            // Global sem schema de setor: usa a mesma projeção mínima sem depender de vagas.setor_id.
            $stmt = Database::conn()->prepare('SELECT c.id, c.vaga_id, c.nome, c.status, c.stage_id, c.created_at, v.titulo AS vaga_titulo FROM candidaturas c INNER JOIN vagas v ON v.id = c.vaga_id WHERE c.id = ? LIMIT 1');
            $stmt->execute([$candidaturaId]);
            $linha = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        }
        if ($linha === null) {
            return null;
        }
        // Autorização já decidida acima (mesma candidatura): leitura restrita a colunas de etapa, sem observações nem usuário.
        $h = Database::conn()->prepare('SELECT status_anterior, status_novo, created_at FROM candidatura_historico WHERE candidatura_id = ? ORDER BY created_at DESC, id DESC');
        $h->execute([$candidaturaId]);
        $linha['historico_etapas'] = $h->fetchAll(PDO::FETCH_ASSOC);
        return $linha;
    }

    /**
     * Resolve UM arquivo da candidatura para entrega protegida. Ordem: (1) candidatura no escopo (SQL); (2) o arquivo pertence A ESSA
     * candidatura (id de documento/carta de outra candidatura = null); (3) caminho físico resolvido por basename + realpath dentro do
     * diretório esperado. Nunca usa nome/extensão/caminho recebidos do navegador. Retorna os dados para o controller entregar; NÃO envia nada.
     *
     * @return array{caminho:string,mime:string,nome_download:string,tipo:string}|null
     */
    public function arquivo(EscopoSetorial $escopo, int $candidaturaId, string $tipo, int $arquivoId = 0): ?array
    {
        if ($this->autorizar($escopo, $candidaturaId) === null) {
            return null;
        }
        $pdo = Database::conn();
        switch ($tipo) {
            case self::ARQUIVO_CURRICULO:
                $stmt = $pdo->prepare('SELECT pdf_path FROM candidaturas WHERE id = ? LIMIT 1');
                $stmt->execute([$candidaturaId]);
                $nome = (string)($stmt->fetchColumn() ?: '');
                if (strtolower(pathinfo($nome, PATHINFO_EXTENSION)) !== 'pdf') {
                    return null;
                }
                return $this->resolverCaminho('resumes', $nome, 'application/pdf', 'curriculo-' . $candidaturaId . '.pdf', $tipo);
            case self::ARQUIVO_DOCUMENTO:
                if ($arquivoId <= 0) {
                    return null;
                }
                $stmt = $pdo->prepare('SELECT nome_armazenado, mime, nome_original FROM candidatura_documentos WHERE id = ? AND candidatura_id = ? LIMIT 1');
                $stmt->execute([$arquivoId, $candidaturaId]);
                $d = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$d) {
                    return null;
                }
                return $this->resolverCaminho('candidatura_documentos', (string)$d['nome_armazenado'], (string)$d['mime'], 'documento-' . $arquivoId . '.' . (pathinfo((string)$d['nome_armazenado'], PATHINFO_EXTENSION) ?: 'bin'), $tipo);
            case self::ARQUIVO_CARTA_PDF:
            case self::ARQUIVO_CARTA_COMPROVANTE:
                if ($arquivoId <= 0) {
                    return null;
                }
                $col = $tipo === self::ARQUIVO_CARTA_PDF ? 'pdf_nome_armazenado' : 'comprovante_pdf_nome_armazenado';
                $stmt = $pdo->prepare("SELECT {$col} AS arq, versao FROM cartas_propostas WHERE id = ? AND candidatura_id = ? LIMIT 1");
                $stmt->execute([$arquivoId, $candidaturaId]);
                $c = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$c || (string)$c['arq'] === '') {
                    return null;
                }
                return $this->resolverCaminho('cartas_propostas', (string)$c['arq'], 'application/pdf', 'carta-proposta-v' . (int)$c['versao'] . '.pdf', $tipo);
        }
        return null;
    }

    private function resolverCaminho(string $subdir, string $nomeArmazenado, string $mime, string $nomeDownload, string $tipo): ?array
    {
        $base = basename($nomeArmazenado);
        if ($base === '' || $base === '.' || $base === '..') {
            return null;
        }
        $dir = STORAGE_PATH . DIRECTORY_SEPARATOR . $subdir;
        $real = realpath($dir . DIRECTORY_SEPARATOR . $base);
        $realDir = realpath($dir);
        if ($real === false || $realDir === false || strpos($real, $realDir . DIRECTORY_SEPARATOR) !== 0 || !is_file($real)) {
            return null;
        }
        return ['caminho' => $real, 'mime' => $mime, 'nome_download' => $nomeDownload, 'tipo' => $tipo];
    }
}
