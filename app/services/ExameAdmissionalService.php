<?php

/**
 * Agendamento do Exame Admissional — liberado só depois do aceite da Carta Proposta
 * (CartaPropostaRepository::existeVersaoAceita(), checado aqui no backend, nunca só pela
 * visibilidade do botão na tela). Reaproveita integralmente ComunicacaoService/MensagemService
 * (mesma distinção "preparar ≠ enviar" da Carta Proposta) — nenhum mecanismo de envio paralelo.
 *
 * Diferente da Carta Proposta: não há PDF nem aceite do candidato, então o agendamento é UMA
 * linha editável por candidatura (reagendar = editar), com o histórico de alterações registrado
 * em ExameAdmissionalRepository::registrarEvento(). Editar qualquer um dos 5 campos de
 * agendamento depois de já preparada/enviada a mensagem invalida a preparação anterior (volta
 * para rascunho) — a mensagem antiga nunca fica associada a dados que já mudaram.
 */
class ExameAdmissionalService
{
    public const CODIGO_MENSAGEM = 'exame_admissional_agendado';
    private const CAMPOS_AGENDAMENTO = ['clinica', 'endereco', 'data_exame', 'horario', 'telefone_contato'];
    private const ROTULOS_CAMPOS = [
        'clinica' => 'Clínica', 'endereco' => 'Endereço', 'data_exame' => 'Data do exame',
        'horario' => 'Horário', 'telefone_contato' => 'Telefone de contato',
    ];

    private ExameAdmissionalRepository $repository;

    public function __construct(?ExameAdmissionalRepository $repository = null)
    {
        $this->repository = $repository ?? new ExameAdmissionalRepository();
    }

    public function podeAgendar(int $candidaturaId): bool
    {
        return (new CartaPropostaRepository())->existeVersaoAceita($candidaturaId);
    }

    /** @return array{ok:bool,id?:int,error?:string} */
    public function obterOuCriar(int $candidaturaId, int $usuarioId): array
    {
        $candidatura = Candidatura::find($candidaturaId);
        if ($candidatura === null) {
            return ['ok' => false, 'error' => 'Candidatura não encontrada.'];
        }
        if (!$this->podeAgendar($candidaturaId)) {
            return ['ok' => false, 'error' => 'O agendamento do exame admissional só é liberado depois do aceite da Carta Proposta.'];
        }

        $existente = $this->repository->buscarPorCandidatura($candidaturaId);
        if ($existente !== null) {
            return ['ok' => true, 'id' => (int)$existente['id']];
        }

        $id = $this->repository->criar($candidaturaId, $usuarioId);
        $this->repository->registrarEvento($id, 'criado', null, null, 'Agendamento do exame admissional criado.', $usuarioId);
        return ['ok' => true, 'id' => $id];
    }

    /**
     * Edição livre dos 5 campos (rascunho incompleto é permitido) — só validarParaPreparo() exige
     * tudo preenchido. Data/horário são validados no backend (formato/existência, não faixa: data
     * futura é o uso normal do exame, nunca rejeitada por estar "no futuro").
     *
     * @param array{clinica:?string,endereco:?string,data_exame:?string,horario:?string,telefone_contato:?string} $dados
     * @return array{ok:bool,error?:string}
     */
    public function atualizarDados(int $id, array $dados, int $usuarioId): array
    {
        $atual = $this->repository->buscarPorId($id);
        if ($atual === null) {
            return ['ok' => false, 'error' => 'Agendamento não encontrado.'];
        }

        $dataExame = $this->validarData($dados['data_exame'] ?? null);
        if ($dataExame === false) {
            return ['ok' => false, 'error' => 'Data do exame inválida. Use o formato AAAA-MM-DD.'];
        }
        $horario = $this->validarHorario($dados['horario'] ?? null);
        if ($horario === false) {
            return ['ok' => false, 'error' => 'Horário inválido. Use o formato HH:MM.'];
        }

        $novo = [
            'clinica' => trim((string)($dados['clinica'] ?? '')) ?: null,
            'endereco' => trim((string)($dados['endereco'] ?? '')) ?: null,
            'data_exame' => $dataExame,
            'horario' => $horario,
            'telefone_contato' => trim((string)($dados['telefone_contato'] ?? '')) ?: null,
        ];

        $anterior = [];
        $mudou = false;
        foreach (self::CAMPOS_AGENDAMENTO as $campo) {
            $anterior[$campo] = $atual[$campo];
            if ((string)($atual[$campo] ?? '') !== (string)($novo[$campo] ?? '')) {
                $mudou = true;
            }
        }
        if (!$mudou) {
            return ['ok' => true];
        }

        $precisaInvalidarPreparo = in_array($atual['status'], ['mensagem_preparada', 'enviado'], true);
        $gravou = $this->repository->salvarDados($id, $novo, $precisaInvalidarPreparo);
        if (!$gravou) {
            return ['ok' => false, 'error' => 'Falha ao salvar os dados do agendamento.'];
        }

        $this->repository->registrarEvento(
            $id,
            $precisaInvalidarPreparo ? 'reagendado' : 'atualizado',
            $anterior,
            $novo,
            $precisaInvalidarPreparo
                ? 'Dados do agendamento alterados — a mensagem preparada anteriormente foi invalidada (volta para rascunho).'
                : 'Dados do agendamento atualizados.',
            $usuarioId
        );
        return ['ok' => true];
    }

    /** @return string[] rótulos (PT-BR) dos campos obrigatórios ainda não preenchidos. */
    public function camposFaltantes(array $exame): array
    {
        $faltantes = [];
        foreach (self::CAMPOS_AGENDAMENTO as $campo) {
            if (trim((string)($exame[$campo] ?? '')) === '') {
                $faltantes[] = self::ROTULOS_CAMPOS[$campo];
            }
        }
        return $faltantes;
    }

    /**
     * "Preparar mensagem" — exige os 5 campos preenchidos E que a renderização final não deixe
     * NENHUM placeholder (incluindo [Nome]) sem substituição; nunca disponibiliza um texto com
     * placeholder literal para o RH copiar. Idempotente: reabrir sem alterar dados reaproveita a
     * mesma Comunicacao, nunca duplica.
     *
     * @return array{ok:bool,texto?:string,error?:string}
     */
    public function prepararMensagem(int $id, int $usuarioId): array
    {
        $exame = $this->repository->buscarPorId($id);
        if ($exame === null) {
            return ['ok' => false, 'error' => 'Agendamento não encontrado.'];
        }
        $faltantes = $this->camposFaltantes($exame);
        if ($faltantes !== []) {
            return ['ok' => false, 'error' => 'Preencha os dados obrigatórios antes de preparar a mensagem: ' . implode(', ', $faltantes) . '.'];
        }

        if ($exame['status'] === 'mensagem_preparada' && !empty($exame['comunicacao_id'])) {
            $comunicacao = Comunicacao::find((int)$exame['comunicacao_id']);
            return ['ok' => true, 'texto' => (string)($comunicacao['conteudo'] ?? '')];
        }

        $candidatura = Candidatura::find((int)$exame['candidatura_id']);
        if ($candidatura === null) {
            return ['ok' => false, 'error' => 'Candidatura não encontrada.'];
        }

        $vars = [
            'Nome' => self::primeiroNome((string)($candidatura['nome'] ?? '')),
            'Data' => date('d/m/Y', strtotime((string)$exame['data_exame'])),
            'Horário' => substr((string)$exame['horario'], 0, 5),
            'Nome da Clínica' => (string)$exame['clinica'],
            'Endereço' => (string)$exame['endereco'],
            'Telefone' => (string)$exame['telefone_contato'],
        ];

        $render = MensagemService::renderizar(self::CODIGO_MENSAGEM, $vars);
        if (!($render['ok'] ?? false)) {
            return ['ok' => false, 'error' => (string)($render['error'] ?? 'Falha ao renderizar o template.') . ' Cadastre-o em Configurações → Mensagens com o código técnico "' . self::CODIGO_MENSAGEM . '".'];
        }
        $semSubstituir = array_merge($render['placeholders_pendentes'] ?? [], $render['placeholders_desconhecidos'] ?? []);
        if ($semSubstituir !== []) {
            $lista = implode(', ', array_map(static fn(string $n): string => '[' . $n . ']', $semSubstituir));
            return ['ok' => false, 'error' => 'A mensagem não pode ser preparada: ' . $lista . ' não foi(ram) substituído(s). Confira os dados do agendamento.'];
        }

        $comResult = ComunicacaoService::prepararDeTemplate(self::CODIGO_MENSAGEM, (int)$exame['candidatura_id'], $vars, 'MANUAL', $usuarioId);
        if (!($comResult['ok'] ?? false)) {
            return ['ok' => false, 'error' => (string)($comResult['error'] ?? 'Falha ao preparar a mensagem.')];
        }

        $agora = (new DateTimeImmutable('now'))->format('Y-m-d H:i:s');
        $this->repository->marcarMensagemPreparada($id, (int)$comResult['id'], $agora);
        $this->repository->registrarEvento($id, 'mensagem_preparada', null, $vars, 'Mensagem preparada para envio ao candidato.', $usuarioId);

        return ['ok' => true, 'texto' => (string)$render['texto']];
    }

    /** Ação explícita do RH — declara que enviou manualmente; nunca é confirmação de entrega real (sem integração automática de envio). */
    public function confirmarEnvio(int $id, int $usuarioId): array
    {
        $exame = $this->repository->buscarPorId($id);
        if ($exame === null) {
            return ['ok' => false, 'error' => 'Agendamento não encontrado.'];
        }
        if ($exame['status'] !== 'mensagem_preparada') {
            return ['ok' => false, 'error' => 'Prepare a mensagem antes de confirmar o envio.'];
        }

        $agora = (new DateTimeImmutable('now'))->format('Y-m-d H:i:s');
        $gravou = $this->repository->confirmarEnvio($id, $usuarioId, $agora);
        if (!$gravou) {
            return ['ok' => false, 'error' => 'Falha ao confirmar o envio.'];
        }
        if (!empty($exame['comunicacao_id'])) {
            Comunicacao::marcarEnviada((int)$exame['comunicacao_id']);
        }
        $this->repository->registrarEvento($id, 'envio_confirmado', null, null, 'Envio confirmado manualmente pelo RH.', $usuarioId);
        return ['ok' => true];
    }

    public function historicoPorCandidatura(int $candidaturaId): array
    {
        $exame = $this->repository->buscarPorCandidatura($candidaturaId);
        return $exame !== null ? $this->repository->historico((int)$exame['id']) : [];
    }

    /** @return string|null|false null = vazio (permitido em rascunho); false = formato inválido. */
    private function validarData(?string $valor)
    {
        $valor = trim((string)$valor);
        if ($valor === '') {
            return null;
        }
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $valor, $m) || !checkdate((int)$m[2], (int)$m[3], (int)$m[1])) {
            return false;
        }
        return $valor;
    }

    /** @return string|null|false null = vazio (permitido em rascunho); false = formato inválido. */
    private function validarHorario(?string $valor)
    {
        $valor = trim((string)$valor);
        if ($valor === '') {
            return null;
        }
        if (!preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $valor)) {
            return false;
        }
        return $valor . ':00';
    }

    private static function primeiroNome(string $nomeCompleto): string
    {
        $partes = explode(' ', trim($nomeCompleto));
        return $partes[0] ?? '';
    }
}
