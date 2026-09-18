<?php

/**
 * Orquestra o fluxo COLETIVO (QR Code) da Pesquisa de Integração: identificação por CPF + Data de
 * Nascimento contra o espelho oficial, contexto temporário na sessão anônima do navegador,
 * seleção segura de contrato e registro da resposta associada a (contrato oficial + data da
 * sessão de integração ativa).
 *
 * Privacidade: CPF e nascimento existem só como argumento de `identificar()` — nunca são gravados
 * em sessão, banco, cookie, URL ou log. O contexto guarda apenas ids oficiais de contrato
 * (`colaboradores_metadados.id`), o id da sessão de integração e uma expiração curta; Nome/Cargo/
 * Empresa são relidos do espelho a cada exibição.
 *
 * Nunca escreve em `colaboradores` nem no METADADOS.
 */
class PesquisaIntegracaoQrService
{
    public const SESSION_KEY = 'integracao_qr';
    /** Validade do contexto temporário de identificação (segundos). */
    public const CONTEXTO_TTL = 900;

    private const RATE_SCOPE_IP = 'integracao_qr_ident_ip';
    private const RATE_SCOPE_GLOBAL = 'integracao_qr_ident_global';
    private const RATE_JANELA = 600;
    private const RATE_BLOQUEIO = 600;
    /**
     * Só FALHAS de identificação contam (acerto nunca zera o contador — quem conhece um CPF+nascimento
     * válido não pode "resetar" o limite para continuar tentando). O limite por IP é folgado porque
     * dezenas de celulares de uma turma compartilham o mesmo Wi-Fi/IP público; o limite GLOBAL é
     * o freio contra enumeração distribuída/IP forjado (Security::clientIp() confia em
     * X-Forwarded-For) e também fica muito acima do uso legítimo de uma turma.
     */
    private const RATE_MAX_IP = 40;
    private const RATE_MAX_GLOBAL = 300;

    public const MSG_FALHA_IDENTIFICACAO = 'Não foi possível localizar um vínculo ativo com os dados informados. Confira os dados ou procure o RH.';

    /** Instrumento atual da Pesquisa de Integração — mesmas perguntas de pesquisa_integracao/show.php (não redesenhado). */
    public static function criteriosSatisfacao(): array
    {
        return [
            'nota_clareza' => 'Clareza das informações apresentadas durante a integração',
            'nota_acolhimento' => 'Qualidade da recepção e acolhimento',
            'nota_normas' => 'Compreensão das normas, processos e orientações da empresa',
            'nota_utilidade' => 'Utilidade das informações recebidas para iniciar suas atividades',
            'nota_satisfacao_geral' => 'Satisfação geral com o processo de integração',
        ];
    }

    // ---- Identificação -------------------------------------------------------------------------

    public static function normalizarCpf(string $valor): string
    {
        return preg_replace('/\D/', '', $valor) ?? '';
    }

    /** Aceita Y-m-d (input date) ou d/m/Y; devolve Y-m-d de uma data real, passada e plausível. */
    public static function normalizarNascimento(string $valor): ?string
    {
        $valor = trim($valor);
        foreach (['!Y-m-d', '!d/m/Y'] as $formato) {
            $data = DateTimeImmutable::createFromFormat($formato, $valor);
            $erros = DateTimeImmutable::getLastErrors();
            if ($data === false || ($erros !== false && ($erros['warning_count'] > 0 || $erros['error_count'] > 0))) {
                continue;
            }
            if ($data > new DateTimeImmutable('today') || (int)$data->format('Y') < 1900) {
                return null;
            }
            return $data->format('Y-m-d');
        }
        return null;
    }

    /**
     * @return array{ok:bool,motivo?:string,contratos?:array}
     *   motivo = 'formato' (CPF/nascimento malformados — sem consulta ao banco) ou
     *   'nao_encontrado' (nenhum contrato ativo com CPF E nascimento coincidentes — mensagem
     *   genérica, nunca revela qual dos dois falhou nem se o CPF existe/está desligado).
     */
    public static function identificar(string $cpf, string $nascimento): array
    {
        $cpfDigitos = self::normalizarCpf($cpf);
        $nascimentoYmd = self::normalizarNascimento($nascimento);
        if (!Security::isValidCpf($cpfDigitos) || $nascimentoYmd === null) {
            return ['ok' => false, 'motivo' => 'formato'];
        }

        $contratos = PesquisaIntegracaoQr::contratosAtivosPorCpfENascimento($cpfDigitos, $nascimentoYmd);
        if ($contratos === []) {
            return ['ok' => false, 'motivo' => 'nao_encontrado'];
        }
        return ['ok' => true, 'contratos' => $contratos];
    }

    // ---- Rate limit ----------------------------------------------------------------------------

    /** @return int Segundos até poder tentar de novo; 0 = liberado. */
    public static function segundosBloqueado(): int
    {
        $ip = self::chaveIp();
        $porIp = Security::rateLimitCheck(self::RATE_SCOPE_IP, $ip, self::RATE_MAX_IP, self::RATE_JANELA, self::RATE_BLOQUEIO);
        $global = Security::rateLimitCheck(self::RATE_SCOPE_GLOBAL, 'global', self::RATE_MAX_GLOBAL, self::RATE_JANELA, self::RATE_BLOQUEIO);
        return max((int)$porIp['retry_after'], (int)$global['retry_after']);
    }

    public static function registrarFalhaIdentificacao(): void
    {
        $ip = self::chaveIp();
        $porIp = Security::rateLimitCheck(self::RATE_SCOPE_IP, $ip, self::RATE_MAX_IP, self::RATE_JANELA, self::RATE_BLOQUEIO);
        Security::rateLimitHit($porIp['file'], $porIp['data'], false, self::RATE_BLOQUEIO, self::RATE_MAX_IP, self::RATE_JANELA);
        $global = Security::rateLimitCheck(self::RATE_SCOPE_GLOBAL, 'global', self::RATE_MAX_GLOBAL, self::RATE_JANELA, self::RATE_BLOQUEIO);
        Security::rateLimitHit($global['file'], $global['data'], false, self::RATE_BLOQUEIO, self::RATE_MAX_GLOBAL, self::RATE_JANELA);
    }

    public static function limparRateLimit(): void
    {
        Security::rateLimitReset(self::RATE_SCOPE_IP, self::chaveIp());
        Security::rateLimitReset(self::RATE_SCOPE_GLOBAL, 'global');
    }

    private static function chaveIp(): string
    {
        return hash('sha256', Security::clientIp());
    }

    // ---- Contexto temporário (sessão anônima do navegador) --------------------------------------

    /** Guarda só ids oficiais + id da sessão de integração + expiração. Nunca CPF/nascimento. */
    public static function iniciarContexto(array $contratos, int $sessaoId): void
    {
        $ids = array_map(static fn(array $c): int => (int)$c['id'], $contratos);
        $_SESSION[self::SESSION_KEY] = [
            'candidatos' => $ids,
            'selecionado' => count($ids) === 1 ? $ids[0] : null,
            'sessao_id' => $sessaoId,
            'expira' => time() + self::CONTEXTO_TTL,
        ];
    }

    public static function limparContexto(): void
    {
        unset($_SESSION[self::SESSION_KEY]);
    }

    /** Contexto válido (existe, não expirou, sessão de integração ainda aberta e a mesma), ou null. */
    public static function contextoAtivo(): ?array
    {
        $ctx = $_SESSION[self::SESSION_KEY] ?? null;
        if (!is_array($ctx) || (int)($ctx['expira'] ?? 0) < time()) {
            self::limparContexto();
            return null;
        }
        $sessao = SessaoIntegracao::abertaAtual();
        if ($sessao === null || (int)$sessao['id'] !== (int)($ctx['sessao_id'] ?? 0)) {
            self::limparContexto();
            return null;
        }
        $ctx['sessao'] = $sessao;
        return $ctx;
    }

    /** O backend só aceita um contrato que pertença ao conjunto previamente validado neste contexto. */
    public static function selecionarContrato(int $metadadosId): bool
    {
        $ctx = self::contextoAtivo();
        if ($ctx === null || !in_array($metadadosId, $ctx['candidatos'], true)) {
            return false;
        }
        if (PesquisaIntegracaoQr::contratoAtivoPorId($metadadosId) === null) {
            return false;
        }
        $_SESSION[self::SESSION_KEY]['selecionado'] = $metadadosId;
        return true;
    }

    /** Contratos do contexto que continuam ativos (relidos do espelho — nada de PII em sessão). */
    public static function contratosDoContexto(array $ctx): array
    {
        $contratos = [];
        foreach ($ctx['candidatos'] as $id) {
            $contrato = PesquisaIntegracaoQr::contratoAtivoPorId((int)$id);
            if ($contrato !== null) {
                $contratos[] = $contrato;
            }
        }
        return $contratos;
    }

    // ---- Resposta ------------------------------------------------------------------------------

    /**
     * Valida e registra a resposta do contrato selecionado no contexto. Data do evento = data da
     * SESSÃO de integração aberta (nunca a data da resposta). Duplicidade por (contrato oficial +
     * data), inclusive contra pesquisas do fluxo individual antigo do mesmo contrato/evento.
     *
     * @param array $post nota_nps, nota_clareza, nota_acolhimento, nota_normas, nota_utilidade,
     *                    nota_satisfacao_geral, comentarios
     * @return array{ok:bool,codigo?:string,error?:string}
     *   codigo: 'sem_contexto' | 'invalido' | 'duplicada'
     */
    public static function registrarResposta(array $post): array
    {
        $ctx = self::contextoAtivo();
        if ($ctx === null) {
            return ['ok' => false, 'codigo' => 'sem_contexto', 'error' => 'Sua identificação expirou ou a integração foi encerrada. Comece novamente.'];
        }
        $metadadosId = (int)($ctx['selecionado'] ?? 0);
        if ($metadadosId <= 0 || !in_array($metadadosId, $ctx['candidatos'], true)) {
            return ['ok' => false, 'codigo' => 'sem_contexto', 'error' => 'Confirme seu vínculo antes de responder a pesquisa.'];
        }
        if (PesquisaIntegracaoQr::contratoAtivoPorId($metadadosId) === null) {
            return ['ok' => false, 'codigo' => 'sem_contexto', 'error' => self::MSG_FALHA_IDENTIFICACAO];
        }

        $notas = [
            'nota_nps' => self::parseNota($post['nota_nps'] ?? null, 0, 10),
            'nota_clareza' => self::parseNota($post['nota_clareza'] ?? null, 1, 5),
            'nota_acolhimento' => self::parseNota($post['nota_acolhimento'] ?? null, 1, 5),
            'nota_normas' => self::parseNota($post['nota_normas'] ?? null, 1, 5),
            'nota_utilidade' => self::parseNota($post['nota_utilidade'] ?? null, 1, 5),
            'nota_satisfacao_geral' => self::parseNota($post['nota_satisfacao_geral'] ?? null, 1, 5),
        ];
        if (in_array(null, $notas, true)) {
            return ['ok' => false, 'codigo' => 'invalido', 'error' => 'Responda a nota de recomendação (0 a 10) e todas as perguntas de satisfação (1 a 5) antes de enviar.'];
        }

        $comentarios = Security::sanitizeString($post['comentarios'] ?? '');
        if (mb_strlen($comentarios) > 2000) {
            return ['ok' => false, 'codigo' => 'invalido', 'error' => 'Comentário muito longo (máximo 2000 caracteres).'];
        }
        $comentarios = $comentarios !== '' ? $comentarios : null;

        $dataIntegracao = (string)$ctx['sessao']['data_integracao'];
        $duplicada = ['ok' => false, 'codigo' => 'duplicada', 'error' => 'Já recebemos uma resposta para este vínculo nesta integração. Obrigado!'];

        $existente = PesquisaIntegracaoQr::buscarPesquisaDoEvento($metadadosId, $dataIntegracao);
        if ($existente !== null) {
            if (!empty($existente['respondida_em'])) {
                self::limparContexto();
                return $duplicada;
            }
            // Pesquisa individual gerada pelo RH para o mesmo contrato/evento e ainda sem resposta:
            // completa ESSA linha (o link individual antigo passa a constar como respondido).
            if (!PesquisaIntegracaoQr::completarPesquisaPendente((int)$existente['id'], $metadadosId, $notas, $comentarios)) {
                self::limparContexto();
                return $duplicada;
            }
            self::limparContexto();
            return ['ok' => true];
        }

        try {
            PesquisaIntegracaoQr::inserirResposta($metadadosId, $dataIntegracao, $notas, $comentarios);
        } catch (PDOException $e) {
            if ((int)($e->errorInfo[1] ?? 0) === 1062) {
                self::limparContexto();
                return $duplicada;
            }
            throw $e;
        }

        self::limparContexto();
        return ['ok' => true];
    }

    /** Nota inteira em [$min, $max]; qualquer outra coisa (vazio, fora da faixa, texto) é rejeitada. */
    private static function parseNota(mixed $valor, int $min, int $max): ?int
    {
        if (!is_scalar($valor) || !ctype_digit((string)$valor)) {
            return null;
        }
        $n = (int)$valor;
        return ($n >= $min && $n <= $max) ? $n : null;
    }
}
