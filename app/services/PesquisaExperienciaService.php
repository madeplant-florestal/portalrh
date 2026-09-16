<?php
/**
 * Criação da Pesquisa de Experiência para uma participação no processo seletivo (Sprint
 * "Experiência do Candidato"). Reutilizável pelo futuro gatilho automático ao encerrar o processo
 * (NÃO implementado nesta sprint) — hoje só oferece a estrutura + a página pública funcionando.
 */
class PesquisaExperienciaService
{
    /**
     * Garante uma única pesquisa por participação: se já existir, retorna a existente
     * (idempotente, nunca duplica — reforçado pelo UNIQUE KEY em `candidatura_id`). Se criar
     * agora, gera um token seguro (aleatório, 32 bytes / 64 hex, não derivado de CPF/ID/telefone)
     * e devolve o token BRUTO só nesta chamada — o banco guarda apenas o hash (mesmo padrão de
     * `PasswordReset`), então chamadas futuras nunca mais conseguem recuperá-lo em texto puro.
     *
     * @return array{ja_existia:bool,pesquisa:array,token:?string}
     */
    public static function criarParaParticipacao(int $candidaturaId): array
    {
        $existente = PesquisaExperiencia::findByCandidatura($candidaturaId);
        if ($existente !== null) {
            return ['ja_existia' => true, 'pesquisa' => $existente, 'token' => null];
        }

        $rawToken = bin2hex(random_bytes(32));
        try {
            $id = PesquisaExperiencia::create($candidaturaId, hash('sha256', $rawToken));
        } catch (Throwable $e) {
            // Corrida: outra chamada criou a pesquisa entre o findByCandidatura() e o INSERT
            // acima (UNIQUE KEY em candidatura_id barra o duplicado) — comportamento idempotente
            // equivalente: devolve a que já existe, sem duplicar nem propagar o erro.
            $existente = PesquisaExperiencia::findByCandidatura($candidaturaId);
            if ($existente !== null) {
                return ['ja_existia' => true, 'pesquisa' => $existente, 'token' => null];
            }
            throw $e;
        }

        return ['ja_existia' => false, 'pesquisa' => PesquisaExperiencia::find($id), 'token' => $rawToken];
    }
}
