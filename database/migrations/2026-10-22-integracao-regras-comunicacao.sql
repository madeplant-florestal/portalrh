-- Etapa 3 — Configurações → Integrações: regras de associação evento/etapa → modelo de mensagem.
--
-- Esta tabela NÃO guarda texto de mensagem nenhum — ela só associa um evento (e, quando
-- aplicável, uma etapa do pipeline) a um registro já existente em `mensagens` (módulo Mensagens
-- continua sendo a fonte oficial dos templates). `event_type` guarda o mesmo valor usado em
-- `webhook_events.event_type`/`RecruitmentEventDispatcher` (string, não FK — eventos são
-- constantes de código, não uma tabela).
--
-- Unicidade: o UNIQUE KEY abaixo (event_type, pipeline_stage_id, canal) é um cinto-e-suspensório
-- para o caso comum (etapa informada). Para eventos SEM etapa (pipeline_stage_id NULL), o MySQL
-- trata cada NULL como distinto, então o UNIQUE KEY por si só não bloqueia duplicidade nesse caso
-- — a validação definitiva (cobrindo também o caso NULL) é feita em
-- IntegracaoRegraComunicacaoRepository::existeConflito(), chamada antes de toda
-- inserção/atualização (mesmo padrão de Mensagem::create(), que também verifica duplicidade em
-- PHP antes do INSERT). Cada combinação evento+etapa+canal tem NO MÁXIMO uma linha, ativa ou não
-- — desativar uma regra é alterar o campo `ativo` da própria linha, nunca criar uma segunda linha
-- para o mesmo combo.
--
-- FKs com ON DELETE RESTRICT: nem `mensagens` nem `pipeline_stages` têm hoje uma rota de exclusão
-- no Portal (Mensagem não tem delete(); pipeline_stages só é inserida/atualizada por
-- PipelineStage::ensureRecruitmentLifecycle(), nunca apagada) — a restrição é só uma salvaguarda,
-- nunca efetivamente exercida no fluxo atual.
CREATE TABLE IF NOT EXISTS integracao_regras_comunicacao (
    id INT AUTO_INCREMENT PRIMARY KEY,
    event_type VARCHAR(80) NOT NULL,
    pipeline_stage_id INT NULL,
    mensagem_id INT NOT NULL,
    canal VARCHAR(20) NOT NULL DEFAULT 'whatsapp',
    ativo TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_integracao_regras_combo (event_type, pipeline_stage_id, canal),
    KEY idx_integracao_regras_mensagem (mensagem_id),
    KEY idx_integracao_regras_event_type (event_type),
    CONSTRAINT fk_integracao_regras_mensagem FOREIGN KEY (mensagem_id) REFERENCES mensagens(id) ON DELETE RESTRICT,
    CONSTRAINT fk_integracao_regras_stage FOREIGN KEY (pipeline_stage_id) REFERENCES pipeline_stages(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
