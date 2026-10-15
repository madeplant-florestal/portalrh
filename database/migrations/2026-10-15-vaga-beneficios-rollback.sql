-- Rollback: 2026-10-15-vaga-beneficios.sql
--
--   Remove a tabela `vaga_beneficios`. Não afeta `vagas` nem `beneficios` (FK é só no sentido
--   vaga_beneficios -> vagas/beneficios, nunca o contrário). A página pública volta a não ter
--   nenhuma seleção por vaga — reverte para o comportamento anterior a esta migration
--   (HomeController precisa ser revertido manualmente se este rollback for aplicado isoladamente).

DROP TABLE IF EXISTS vaga_beneficios;
