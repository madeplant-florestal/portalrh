-- Exclusão analítica de contratos específicos (2026-10) — mecanismo central e reaproveitável
-- para "este contrato nunca deve entrar em nenhuma estatística/listagem analítica do Portal",
-- sem apagar o registro original em `colaboradores_metadados` e sem heurística genérica (ex.:
-- "sem setor" foi avaliado e rejeitado — há muitos contratos legítimos sem setor).
--
-- Cada linha é uma decisão administrativa explícita e auditável: motivo + quem excluiu + quando.
-- Ausência de linha para um `metadados_id` = o contrato participa normalmente de tudo. Ver
-- app/services/ColaboradorMetadadosElegibilidadeService.php.
CREATE TABLE IF NOT EXISTS colaboradores_metadados_exclusoes_analiticas (
    id INT AUTO_INCREMENT PRIMARY KEY,
    metadados_id INT NOT NULL,
    motivo TEXT NOT NULL,
    excluido_por_usuario_id INT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_cmea_metadados (metadados_id),
    KEY idx_cmea_usuario (excluido_por_usuario_id),
    CONSTRAINT fk_cmea_metadados FOREIGN KEY (metadados_id) REFERENCES colaboradores_metadados(id) ON DELETE RESTRICT,
    CONSTRAINT fk_cmea_usuario FOREIGN KEY (excluido_por_usuario_id) REFERENCES usuarios(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
