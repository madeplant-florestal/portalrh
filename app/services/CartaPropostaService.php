<?php

/**
 * Regras de negócio da Carta Proposta (Fase 1 da formalização da contratação). Camada nova
 * (Repository/Service), reaproveitando integralmente:
 *   - Vaga::beneficiosPublicos() (vaga_beneficios, nunca cargo_beneficios — pedido do RH) para o
 *     pré-preenchimento, sempre revisável pelo RH antes de emitir;
 *   - ComunicacaoService::prepararDeTemplate()/Comunicacao (histórico de comunicação já existente)
 *     para "mensagem preparada" — nunca confirma envio por si só (ver migration);
 *   - Candidatura::updateStage() -> RecruitmentPipelineService (mesma validação/histórico/
 *     idempotência do Kanban) para mover a candidatura para "Admissão" após o aceite;
 *   - CartaPropostaPdfService (dompdf) para o PDF, salvo uma única vez por versão (nunca
 *     sobrescrito — uma correção depois de emitida sempre vira versão nova).
 */
class CartaPropostaService
{
    public const PRAZO_DIAS_TOKEN = 15;
    public const CODIGO_MENSAGEM_ENVIO = 'carta_proposta_enviada';

    private CartaPropostaRepository $repository;

    public function __construct(?CartaPropostaRepository $repository = null)
    {
        $this->repository = $repository ?? new CartaPropostaRepository();
    }

    /**
     * Devolve o rascunho em edição (sem duplicar versão) ou cria uma nova versão — marcando a
     * anterior como substituída quando ela já tiver sido emitida/enviada (nunca apaga, nunca
     * reaproveita o aceite de uma versão para outra).
     *
     * @return array{ok:bool,id?:int,nova_versao?:bool,error?:string}
     */
    public function criarOuReaproveitarRascunho(int $candidaturaId, int $usuarioId): array
    {
        $candidatura = Candidatura::find($candidaturaId);
        if ($candidatura === null) {
            return ['ok' => false, 'error' => 'Candidatura não encontrada.'];
        }

        $ultima = $this->repository->buscarUltimaPorCandidatura($candidaturaId);
        if ($ultima !== null && $ultima['status'] === 'rascunho') {
            return ['ok' => true, 'id' => (int)$ultima['id'], 'nova_versao' => false];
        }
        if ($ultima !== null && in_array($ultima['status'], ['gerada', 'enviada'], true)) {
            $this->repository->marcarSubstituida((int)$ultima['id']);
        }

        $versao = $this->repository->proximaVersao($candidaturaId);
        $id = $this->repository->criar($this->prefillDados($candidatura) + [
            'candidatura_id' => $candidaturaId,
            'versao' => $versao,
            'gerada_por_usuario_id' => $usuarioId,
        ]);

        return ['ok' => true, 'id' => $id, 'nova_versao' => $ultima !== null];
    }

    private function prefillDados(array $candidatura): array
    {
        $vagaId = (int)($candidatura['vaga_id'] ?? 0);
        $vaga = $vagaId > 0 ? Vaga::find($vagaId) : null;

        $salario = null;
        $jornada = '';
        $setor = null;
        $escala = null;
        if ($vaga !== null && !empty($vaga['solicitacao_vaga_id'])) {
            $stmt = Database::conn()->prepare(
                'SELECT sv.salario_previsto, sv.jornada_trabalho, sv.escala_encrypted, s.nome AS setor_nome,
                        sv.gestor_solicitante_colaborador_id, sv.colaborador_substituido_id,
                        sv.lider_imediato_colaborador_id, sv.nome_contratado_colaborador_id
                 FROM solicitacoes_vaga sv
                 LEFT JOIN setores s ON s.id = sv.setor_id
                 WHERE sv.id = ?'
            );
            $stmt->execute([(int)$vaga['solicitacao_vaga_id']]);
            $sol = $stmt->fetch(PDO::FETCH_ASSOC);
            // Exclusão analítica GLOBAL: não se pré-preenche carta a partir de solicitação ligada a colaborador de contrato excluído.
            if ($sol !== false) {
                $sol = ColaboradorMetadadosElegibilidadeService::filtrarProcessoIndividual($sol, SolicitacaoVaga::CAMPOS_COLABORADOR_PROCESSO) ?? false;
            }
            if ($sol !== false) {
                $salario = $sol['salario_previsto'];
                $jornada = (string)$sol['jornada_trabalho'];
                $setor = $sol['setor_nome'];
                $escala = Cipher::decrypt($sol['escala_encrypted'] ?? null);
            }
        } elseif ($vaga !== null && trim((string)($vaga['area'] ?? '')) !== '') {
            // Vaga legada/manual (sem Solicitação de Vaga vinculada — cadastro de exceção, ver
            // AdminVagasController): "Área" é texto livre preenchido pelo RH no cadastro manual da
            // vaga. Não é o catálogo oficial de Setores, mas é a melhor aproximação disponível;
            // o RH sempre revisa antes de emitir.
            $setor = trim((string)$vaga['area']);
        }

        // Benefícios por VAGA (vaga_beneficios) — nunca cargo_beneficios. Só o pré-preenchimento:
        // o RH revisa/edita o texto livremente antes de emitir.
        $beneficiosTexto = null;
        if ($vagaId > 0) {
            $beneficios = Vaga::beneficiosPublicos($vagaId);
            if ($beneficios !== []) {
                $beneficiosTexto = implode("\n", array_map(static fn(array $b): string => '- ' . $b['nome'], $beneficios));
            }
        }

        $localTrabalho = $vaga !== null ? trim((string)($vaga['local'] ?? '')) : '';
        $escala = trim((string)($escala ?? ''));

        return [
            'nome_candidato' => (string)($candidatura['nome'] ?? ''),
            'cargo' => (string)($candidatura['cargo_pretendido'] ?: ($candidatura['vaga_titulo'] ?? '')),
            'setor' => $setor,
            'empresa_contratante' => (string)($candidatura['vaga_empresa_nome'] ?? ''),
            'salario_proposto' => $salario ?? 0,
            'jornada_trabalho' => $jornada,
            'escala_horario' => $escala !== '' ? $escala : null,
            'beneficios_texto' => $beneficiosTexto,
            'local_trabalho' => $localTrabalho !== '' ? $localTrabalho : null,
            'previsao_inicio' => null,
            'validade_proposta' => (new DateTimeImmutable('today'))->modify('+7 days')->format('Y-m-d'),
            'observacoes' => null,
        ];
    }

    /** Edição livre do rascunho — sem validação de completude (só emitir() exige tudo preenchido). */
    public function atualizarRascunho(int $id, array $dados): array
    {
        $gravou = $this->repository->atualizarRascunho($id, $dados);
        if (!$gravou) {
            return ['ok' => false, 'error' => 'Não foi possível salvar — a versão já pode ter sido emitida.'];
        }
        return ['ok' => true];
    }

    /** @return string[] */
    public function validarParaEmissao(array $carta): array
    {
        $erros = [];
        if (trim((string)($carta['nome_candidato'] ?? '')) === '') { $erros[] = 'Nome do candidato é obrigatório.'; }
        if (trim((string)($carta['cargo'] ?? '')) === '') { $erros[] = 'Cargo é obrigatório.'; }
        if (trim((string)($carta['empresa_contratante'] ?? '')) === '') { $erros[] = 'Empresa contratante é obrigatória.'; }
        if ((float)($carta['salario_proposto'] ?? 0) <= 0) { $erros[] = 'Salário proposto deve ser maior que zero.'; }
        if (trim((string)($carta['jornada_trabalho'] ?? '')) === '') { $erros[] = 'Jornada de trabalho é obrigatória.'; }
        $validade = (string)($carta['validade_proposta'] ?? '');
        if ($validade === '' || strtotime($validade) === false) {
            $erros[] = 'Validade da proposta (data) é obrigatória.';
        }
        return $erros;
    }

    /** rascunho -> gerada: congela o snapshot, gera o PDF. */
    public function emitir(int $id): array
    {
        $carta = $this->repository->buscarPorId($id);
        if ($carta === null) { return ['ok' => false, 'error' => 'Carta Proposta não encontrada.']; }
        if ($carta['status'] !== 'rascunho') { return ['ok' => false, 'error' => 'Esta versão já foi emitida.']; }

        $erros = $this->validarParaEmissao($carta);
        if ($erros !== []) {
            return ['ok' => false, 'error' => implode(' ', $erros)];
        }

        $nomeArmazenado = CartaPropostaPdfService::gerar($carta);
        $agora = (new DateTimeImmutable('now'))->format('Y-m-d H:i:s');
        $gravou = $this->repository->marcarGerada($id, $nomeArmazenado, $agora);
        if (!$gravou) {
            return ['ok' => false, 'error' => 'Falha ao registrar a emissão (talvez já tenha sido emitida por outra aba/sessão).'];
        }
        return ['ok' => true];
    }

    /**
     * "Preparar mensagem" — gera o token agora (o texto copiável precisa do link real) e cria/
     * vincula a Comunicacao, mas NUNCA marca como enviada (ver migration). Idempotente: reabrir a
     * tela antes de confirmar não gera um segundo token/segunda comunicação.
     *
     * @return array{ok:bool,texto?:string,error?:string}
     */
    public function prepararEnvio(int $id, int $usuarioId): array
    {
        $carta = $this->repository->buscarPorId($id);
        if ($carta === null) { return ['ok' => false, 'error' => 'Carta Proposta não encontrada.']; }
        if ($carta['status'] !== 'gerada') {
            return ['ok' => false, 'error' => 'A proposta precisa estar emitida (PDF gerado) antes de preparar o envio.'];
        }

        if (empty($carta['token_hash'])) {
            $tokenBruto = bin2hex(random_bytes(32));
            $tokenHash = hash('sha256', $tokenBruto);
            $expira = (new DateTimeImmutable('now'))->modify('+' . self::PRAZO_DIAS_TOKEN . ' days')->format('Y-m-d H:i:s');
            $link = rtrim((string)(Config::app()['base_url'] ?? ''), '/') . '/carta-proposta/' . $tokenBruto;

            $render = ComunicacaoService::prepararDeTemplate(
                self::CODIGO_MENSAGEM_ENVIO,
                (int)$carta['candidatura_id'],
                ['Nome' => self::primeiroNome((string)$carta['nome_candidato']), 'Link da Proposta' => $link],
                'MANUAL',
                $usuarioId
            );
            if (!($render['ok'] ?? false)) {
                return ['ok' => false, 'error' => (string)($render['error'] ?? 'Falha ao preparar a mensagem. Verifique se o template "' . self::CODIGO_MENSAGEM_ENVIO . '" existe e está ativo em Configurações → Mensagens.')];
            }
            $this->repository->definirTokenEComunicacao($id, $tokenHash, $expira, (int)$render['id']);
            $carta = $this->repository->buscarPorId($id);
        }

        $comunicacao = $carta['comunicacao_id'] ? Comunicacao::find((int)$carta['comunicacao_id']) : null;
        return ['ok' => true, 'texto' => (string)($comunicacao['conteudo'] ?? '')];
    }

    /** Ação explícita e separada do RH — nunca automática ao mover para Aprovado nem ao preparar a mensagem. */
    public function confirmarEnvio(int $id, int $usuarioId): array
    {
        $carta = $this->repository->buscarPorId($id);
        if ($carta === null) { return ['ok' => false, 'error' => 'Carta Proposta não encontrada.']; }
        if ($carta['status'] !== 'gerada') {
            return ['ok' => false, 'error' => 'Esta proposta não está no estado correto para confirmar o envio (status atual: ' . $carta['status'] . ').'];
        }
        if (empty($carta['token_hash'])) {
            return ['ok' => false, 'error' => 'Prepare a mensagem antes de confirmar o envio.'];
        }

        $agora = (new DateTimeImmutable('now'))->format('Y-m-d H:i:s');
        $gravou = $this->repository->confirmarEnvio($id, $usuarioId, $agora);
        if (!$gravou) {
            return ['ok' => false, 'error' => 'Falha ao confirmar o envio.'];
        }
        if (!empty($carta['comunicacao_id'])) {
            Comunicacao::marcarEnviada((int)$carta['comunicacao_id']);
        }
        return ['ok' => true];
    }

    /**
     * Único ponto de verificação de acesso por token — usado pela página pública E pelo PDF
     * público (CartaPropostaController::pdf()), nunca duplicado (correção de homologação de
     * 2026-11: antes cada rota tinha sua própria checagem, e a do PDF nem verificava
     * validade_proposta). A validade de NEGÓCIO da proposta (prazo de aceite, definido pelo RH)
     * tem prioridade sobre tudo, inclusive sobre uma decisão já registrada — passada a validade,
     * o link para de expor qualquer dado, mesmo que a proposta já tenha sido aceita; a via aceita
     * continua preservada e recuperável por fora deste link (mecanismo do RH), são operações
     * diferentes. Mesmo vocabulário da Entrevista de Desligamento (invalido | indisponivel |
     * formulario), mais `proposta_vencida` e `concluida`.
     *
     * `carta` só vem preenchida no estado 'formulario' — todos os demais estados (inclusive
     * 'concluida') nunca expõem a linha inteira: o PDF só pode ser servido enquanto a decisão
     * ainda não foi tomada e a proposta está dentro da validade.
     *
     * @return array{estado:string,carta:?array,decisao:?string}
     */
    public function resolverAcessoPorToken(string $tokenBruto, DateTimeImmutable $agora): array
    {
        if (!preg_match('/^[0-9a-f]{64}$/', $tokenBruto)) {
            return ['estado' => 'invalido', 'carta' => null, 'decisao' => null];
        }
        $carta = $this->repository->buscarPorTokenHash(hash('sha256', $tokenBruto));
        if ($carta === null || !in_array($carta['status'], ['enviada', 'aceita', 'recusada'], true)) {
            // 'rascunho'/'gerada' (sem confirmação de envio) ou 'substituida' — tratado igual a
            // token inexistente, nunca revela estado interno a quem não deveria ver.
            return ['estado' => 'invalido', 'carta' => null, 'decisao' => null];
        }

        if ($carta['validade_proposta'] !== null) {
            // Fuso de NEGÓCIO explícito (Campo Grande/MS) — independente do timezone padrão do
            // servidor PHP (ver nota em CLAUDE.md/riscos-conhecidos.md: o timezone padrão deste
            // ambiente não é o do Brasil; aqui fixamos explicitamente para não deslocar o corte
            // das 23:59:59 em relação ao horário real do candidato).
            $limite = new DateTimeImmutable($carta['validade_proposta'] . ' 23:59:59', new DateTimeZone('America/Campo_Grande'));
            if ($limite < $agora) {
                return ['estado' => 'proposta_vencida', 'carta' => null, 'decisao' => null];
            }
        }

        if (in_array($carta['status'], ['aceita', 'recusada'], true)) {
            return ['estado' => 'concluida', 'carta' => null, 'decisao' => $carta['status']];
        }

        // status === 'enviada', ainda não decidida: só falta o prazo TÉCNICO do token (independente
        // da validade de negócio, já checada acima).
        if ($carta['token_expira_em'] !== null && new DateTimeImmutable($carta['token_expira_em']) < $agora) {
            return ['estado' => 'indisponivel', 'carta' => null, 'decisao' => null];
        }
        return ['estado' => 'formulario', 'carta' => $carta, 'decisao' => null];
    }

    /**
     * Adapta resolverAcessoPorToken() para o vocabulário/formato já usado pela página pública —
     * nunca expõe a carta inteira fora do estado 'formulario' (privacidade: nem para 'concluida'
     * nem para 'proposta_vencida' o contexto traz dados pessoais).
     *
     * @return array{estado:string,contexto:?array}
     */
    public function resolverParaPagina(string $tokenBruto, DateTimeImmutable $agora): array
    {
        $acesso = $this->resolverAcessoPorToken($tokenBruto, $agora);
        if ($acesso['estado'] === 'formulario') {
            return ['estado' => 'formulario', 'contexto' => $acesso['carta']];
        }
        if ($acesso['estado'] === 'concluida') {
            return ['estado' => 'concluida', 'contexto' => ['decisao' => $acesso['decisao']]];
        }
        return ['estado' => $acesso['estado'], 'contexto' => null];
    }

    /**
     * @return array{ok:bool,estado?:string,decisao?:string,erro?:string}
     */
    public function registrarDecisao(string $tokenBruto, string $decisao, ?string $motivoRecusa, ?string $ip, ?string $userAgent, DateTimeImmutable $agora): array
    {
        $resolvido = $this->resolverParaPagina($tokenBruto, $agora);
        if ($resolvido['estado'] !== 'formulario') {
            return ['ok' => false, 'estado' => $resolvido['estado']];
        }
        if (!in_array($decisao, ['aceita', 'recusada'], true)) {
            return ['ok' => false, 'estado' => 'formulario', 'erro' => 'Decisão inválida.'];
        }
        $carta = $resolvido['contexto'];

        $gravou = $this->repository->registrarDecisao(
            (int)$carta['id'],
            $decisao,
            $agora->format('Y-m-d H:i:s'),
            $ip,
            $userAgent !== null ? substr($userAgent, 0, 255) : null,
            $decisao === 'recusada' ? $motivoRecusa : null
        );
        if (!$gravou) {
            // Perdeu a corrida: já decidida/substituída entre a leitura e a gravação acima.
            return ['ok' => false, 'estado' => $this->resolverParaPagina($tokenBruto, $agora)['estado']];
        }

        if ($decisao === 'aceita') {
            $this->moverParaAdmissaoSeNecessario((int)$carta['candidatura_id'], $carta);
            $this->gerarComprovanteAceite((int)$carta['id']);
        }

        return ['ok' => true, 'decisao' => $decisao];
    }

    /**
     * Gera a via com comprovante de aceite (PDF separado do original) depois que o aceite já foi
     * gravado no banco — nunca bloqueia/desfaz o aceite em si: uma falha aqui fica só registrada
     * em log (o aceite real já está garantido; a via pode ser gerada manualmente depois, se
     * necessário). Idempotente: nunca gera uma segunda via para a mesma versão.
     */
    private function gerarComprovanteAceite(int $cartaId): void
    {
        try {
            $cartaAtual = $this->repository->buscarPorId($cartaId);
            if ($cartaAtual === null || $cartaAtual['status'] !== 'aceita' || !empty($cartaAtual['comprovante_pdf_nome_armazenado'])) {
                return;
            }
            $identificador = strtoupper(bin2hex(random_bytes(6)));
            $nomeArmazenado = CartaPropostaPdfService::gerarComprovante($cartaAtual, $identificador);
            $agora = (new DateTimeImmutable('now'))->format('Y-m-d H:i:s');
            $this->repository->registrarComprovante($cartaId, $nomeArmazenado, $identificador, $agora);
        } catch (\Throwable $e) {
            Logger::error('Falha ao gerar a via com comprovante de aceite da Carta Proposta', [
                'carta_id' => $cartaId,
                'erro' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Reaproveita INTEGRALMENTE RecruitmentPipelineService (via Candidatura::updateStage()) —
     * mesma validação/histórico/idempotência do Kanban, nunca um fluxo paralelo. A etapa é
     * identificada pelo SLUG canônico 'admissao' (PipelineStage::ensureRecruitmentLifecycle(),
     * mesmo identificador usado por RecruitmentStageMetadataValidator/RecruitmentEventDispatcher
     * — nunca por texto do nome, que o RH pode editar). Essa etapa EXIGE `admission_date`/
     * `admission_notes` (RecruitmentStageMetadataValidator::missingFields('admissao', ...)) —
     * preenchidos aqui a partir da própria proposta aceita, nunca deixados em branco.
     *
     * `$usuarioId = null`: o ator real é o CANDIDATO (via aceite), não um usuário do Portal —
     * mesma convenção de Comunicacao::create() com origem AUTOMATICA. Nunca move para trás
     * (candidatura já em Admissão ou além fica como está) e nunca duplica a transição
     * (idempotente). Falha aqui nunca desfaz o registro do aceite — só fica registrada em log
     * para o RH mover manualmente.
     */
    private function moverParaAdmissaoSeNecessario(int $candidaturaId, array $carta): void
    {
        $candidatura = Candidatura::find($candidaturaId);
        if ($candidatura === null) {
            return;
        }
        $etapaAdmissao = $this->buscarEtapaPorSlug('admissao');
        if ($etapaAdmissao === null) {
            Logger::warning('Carta Proposta aceita, mas a etapa "admissao" não existe no Kanban — movimentação manual necessária.', ['candidatura_id' => $candidaturaId]);
            return;
        }
        $etapaAtualId = (int)($candidatura['stage_id'] ?? 0);
        $ordemAtual = $this->ordemDaEtapa($etapaAtualId);
        if ($etapaAtualId === (int)$etapaAdmissao['id'] || ($ordemAtual !== null && $ordemAtual >= (int)$etapaAdmissao['ordem'])) {
            return; // já está em Admissão ou além — idempotente, nunca move para trás
        }

        $dataAdmissao = !empty($carta['previsao_inicio']) ? (string)$carta['previsao_inicio'] : (new DateTimeImmutable('today'))->format('Y-m-d');
        $resultado = Candidatura::updateStage($candidaturaId, (int)$etapaAdmissao['id'], null, [
            'admission_date' => $dataAdmissao,
            'admission_notes' => 'Admissão confirmada automaticamente após aceite da Carta Proposta (versão ' . (int)($carta['versao'] ?? 0) . ').',
            'observacoes' => 'Movido automaticamente para Admissão após aceite da Carta Proposta.',
        ]);
        if (!($resultado['ok'] ?? false)) {
            Logger::warning('Carta Proposta aceita, mas a movimentação automática para Admissão falhou — tratar manualmente.', [
                'candidatura_id' => $candidaturaId,
                'error' => $resultado['message'] ?? $resultado['error'] ?? 'desconhecido',
            ]);
        }
    }

    private function buscarEtapaPorSlug(string $slug): ?array
    {
        foreach (PipelineStage::all() as $etapa) {
            if ((string)($etapa['slug'] ?? '') === $slug) {
                return $etapa;
            }
        }
        return null;
    }

    private function ordemDaEtapa(int $etapaId): ?int
    {
        foreach (PipelineStage::all() as $etapa) {
            if ((int)$etapa['id'] === $etapaId) {
                return (int)$etapa['ordem'];
            }
        }
        return null;
    }

    private static function primeiroNome(string $nomeCompleto): string
    {
        $partes = explode(' ', trim($nomeCompleto));
        return $partes[0] ?? '';
    }
}
