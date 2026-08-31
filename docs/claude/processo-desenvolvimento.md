# Processo de Desenvolvimento — RH Madeplant

> Consultar para qualquer funcionalidade nova, bug ou refatoração relevante. O processo mínimo
> universal (8 passos) está em `CLAUDE.md`; aqui está o detalhamento por tipo de tarefa.

## Filosofia de desenvolvimento

- **Simplicidade acima de sofisticação.** Este é um sistema hand-rolled por escolha, não por
  limitação — não "corrija" isso introduzindo um framework.
- **Reutilização antes de criação.** Sempre procure um controller/model/service análogo antes de
  escrever um novo. Duas gerações arquiteturais coexistem — imite a geração do módulo vizinho, não
  a que você prefere.
- **Compatibilidade retroativa é inegociável.** Sistema em produção com usuários diários; uma
  mudança que "melhora" a arquitetura mas quebra uma rota, view ou fluxo existente não é aceitável
  sem aprovação explícita.
- **Estabilidade sobre velocidade.** O usuário já declarou preferir "uma entrega bem planejada em
  dois dias do que uma entrega apressada em duas horas". Não corte etapas do fluxo de trabalho
  para parecer mais rápido.
- **Baixo acoplamento onde já existe; não o introduza à força onde não existe.** Não refatore
  models legados estáticos para o padrão Repository/Service como efeito colateral de uma tarefa
  não relacionada.
- **Alta legibilidade.** Nomes de domínio em português, estrutura previsível, sem "cleverness"
  desnecessária.
- **Segurança é parte do design, não um passo posterior.**

## Fluxo de trabalho completo (10 passos)

Versão detalhada do processo mínimo do `CLAUDE.md`, para qualquer pedido de funcionalidade ou
mudança de código (não para perguntas puramente informativas):

1. **Compreender a solicitação** — se algo for ambíguo, perguntar antes de presumir.
2. **Pesquisar todo o projeto** — não só o arquivo óbvio; buscar padrões análogos, lógica
   relacionada, precedentes (priorize Serena, ver `CLAUDE.md`).
3. **Localizar os arquivos relacionados** especificamente.
4. **Explicar a estratégia de implementação** em termos simples.
5. **Informar exatamente quais arquivos serão alterados** (e quais serão criados).
6. **Informar os impactos**: regras de negócio afetadas, migrations necessárias, breaking
   changes, implicações de segurança/autenticação.
7. **Aguardar aprovação explícita** — não escrever/editar código antes disso, mesmo para mudanças
   aparentemente pequenas.
8. **Implementar** somente após aprovação.
9. **Revisar criticamente o próprio diff** — procurar bugs, não só confirmar que "rodou".
10. **Validar possíveis regressões** — checar efeitos colaterais em qualquer módulo que
    compartilhe tabela, model, service ou helper com a mudança feita.

Exceção: perguntas puramente informativas ou continuação de um plano já aprovado não precisam
repetir o gate inteiro. Se o usuário disser explicitamente para pular uma etapa, isso vale só para
aquele pedido, não como mudança permanente deste fluxo.

## Novas funcionalidades

1. Siga o fluxo acima até a aprovação.
2. Escolha a geração arquitetural (legado vs. Repository/Service) com base no módulo mais próximo
   já existente, não por preferência pessoal.
3. Escreva migration + rollback antes ou junto do código que depende do schema novo.
4. Reaproveite componentes de UI existentes (`ct-btn`, `ct-badge`, layouts admin) — não crie
   estilos ad hoc.
5. Adicione teste de integração PHP (`tests/php/integration_*.php`) cobrindo o caminho feliz e
   pelo menos uma regra de negócio de rejeição, seguindo o padrão dos testes existentes (script
   standalone, `require bootstrap.php`, `exit(1)` em falha).
6. Se a funcionalidade tiver superfície visual nova ou alterada, valide manualmente no navegador
   (golden path + edge cases) antes de reportar como concluída — testes automatizados verificam
   correção de código, não UX real.

## Refatoração

**Quando é apropriada:** quando uma mudança de negócio exige tocar um trecho já frágil/duplicado
*e* a refatoração é o menor caminho seguro para entregar essa mudança — nunca como tarefa isolada
não solicitada.

**Quando NÃO deve ocorrer:**
- Como efeito colateral de uma correção de bug pontual.
- Para migrar um model legado para Repository/Service "por consistência", sem pedido explícito.
- Para renomear arquivos/classes/rotas por preferência estética ou "modernização".
- Para trocar um componente que já funciona por uma alternativa mais "moderna" sem necessidade
  concreta.

Se identificar uma oportunidade de refatoração durante outra tarefa, **documente a sugestão para
discussão futura** em vez de expandir o escopo da entrega atual.

## Correção de bugs

1. Reproduza o problema mentalmente ou via teste antes de propor a causa raiz — não assuma.
2. Localize a causa raiz real; corrigir sintoma sem entender a causa tende a reintroduzir o bug em
   outro fluxo.
3. Siga o gate de aprovação normalmente — um bug fix ainda precisa de explicação e aprovação antes
   do código, especialmente se tocar autenticação, sessão, upload ou construção de SQL (ver
   `checklists.md`, Segurança).
4. Corrija apenas o necessário — não aproveite para "limpar" código ao redor.
5. Adicione um teste que teria pego o bug, quando viável.
6. Verifique se o mesmo bug existe em código irmão (outro módulo com a mesma lógica copiada) — é
   comum neste código base ter padrões repetidos entre `Admin*Controller`s.
