<?php

/**
 * Orquestra a geração idempotente da Pesquisa de Integração — 1 pesquisa por EVENTO de
 * integração (colaborador + data da integração no momento da geração), nunca por candidatura,
 * CPF ou e-mail.
 */
class PesquisaIntegracaoService
{
    /**
     * Exige `colaboradores.integracao_status = 'realizada'` (definição oficial de integração
     * concluída — dado operacional do Portal, NÃO exige `metadados_id`). Se já existir uma
     * pesquisa para este colaborador + esta data de integração, retorna a existente sem duplicar
     * (idempotente, reforçado pelo UNIQUE KEY em (colaborador_id, integracao_data_relacionada)).
     * Se a integração for corrigida para outra data no futuro, isso conta como um novo evento —
     * a pesquisa antiga (e sua resposta, se houver) permanece intacta.
     *
     * @return array{ok:bool,error?:string,ja_existia?:bool,pesquisa?:array,token?:?string}
     */
    public static function criarParaIntegracao(int $colaboradorId): array
    {
        $colaborador = Colaborador::find($colaboradorId);
        if ($colaborador === null) {
            return ['ok' => false, 'error' => 'Colaborador não encontrado.'];
        }

        $status = strtolower(trim((string)($colaborador['integracao_status'] ?? 'pendente')));
        if ($status !== 'realizada') {
            return ['ok' => false, 'error' => 'A integração deste colaborador ainda não está registrada como realizada.'];
        }

        $integracaoData = (string)($colaborador['integracao_data'] ?? '');
        if ($integracaoData === '') {
            return ['ok' => false, 'error' => 'A integração deste colaborador não possui data registrada.'];
        }

        $existente = PesquisaIntegracao::findByColaboradorEData($colaboradorId, $integracaoData);
        if ($existente !== null) {
            return ['ok' => true, 'ja_existia' => true, 'pesquisa' => $existente, 'token' => null];
        }

        // Mesmo contrato oficial + mesma integração já cobertos pelo fluxo coletivo (QR): é o mesmo
        // EVENTO — não gera uma segunda pesquisa (nunca por CPF; só pelo vínculo oficial).
        $metadadosId = !empty($colaborador['metadados_id']) ? (int)$colaborador['metadados_id'] : null;
        if ($metadadosId !== null) {
            $doEvento = PesquisaIntegracaoQr::buscarPesquisaDoEvento($metadadosId, $integracaoData);
            if ($doEvento !== null) {
                return ['ok' => true, 'ja_existia' => true, 'pesquisa' => PesquisaIntegracao::find((int)$doEvento['id']), 'token' => null];
            }
        }

        $rawToken = bin2hex(random_bytes(32));
        try {
            $id = PesquisaIntegracao::create($colaboradorId, $integracaoData, hash('sha256', $rawToken), $metadadosId);
        } catch (Throwable $e) {
            // Corrida: outra chamada criou a pesquisa entre o findByColaboradorEData() e o INSERT
            // acima (UNIQUE KEY barra o duplicado) — comportamento idempotente equivalente:
            // devolve a que já existe, sem duplicar nem propagar o erro.
            $existente = PesquisaIntegracao::findByColaboradorEData($colaboradorId, $integracaoData);
            if ($existente === null && $metadadosId !== null) {
                $doEvento = PesquisaIntegracaoQr::buscarPesquisaDoEvento($metadadosId, $integracaoData);
                $existente = $doEvento !== null ? PesquisaIntegracao::find((int)$doEvento['id']) : null;
            }
            if ($existente !== null) {
                return ['ok' => true, 'ja_existia' => true, 'pesquisa' => $existente, 'token' => null];
            }
            throw $e;
        }

        return ['ok' => true, 'ja_existia' => false, 'pesquisa' => PesquisaIntegracao::find($id), 'token' => $rawToken];
    }
}
