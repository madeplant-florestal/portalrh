<?php

/**
 * Política CENTRAL de permissões por perfil (Etapa 2B, 2026-11) — fonte única de: quais permissões (`permissoes.codigo`) cada
 * `role` pode receber, quais concedem acesso EFETIVO, quais são incompatíveis, qual o modelo recomendado e como tratar
 * atribuições legadas. A hierarquia entre contas continua sendo de `Autoridade` (esta classe NÃO decide quem gerencia quem e
 * nunca anula `Autoridade`: restrições hierárquicas prevalecem sobre qualquer permissão genérica).
 *
 * A matriz horizontal da Etapa 3 e o backend (`Authorization`, `updatePermissoes`) consomem ESTA classe — nenhuma regra de
 * perfil × permissão deve ser repetida em controller, view ou JavaScript. Códigos são os REAIS da tabela `permissoes`; nada é
 * inferido pelo sufixo `.visualizar` nem presume CRUD.
 *
 * NÍVEIS de uma permissão para um perfil (`classificar`):
 *   permitida          — pode ser recebida e concede acesso efetivo.
 *   transitoria_gestor — uso de fato do "viewer gestor" de hoje. Continua válida e atribuível ATÉ existir o perfil Gestor, quando
 *                        migra para ele. Não entra no modelo recomendado. ATENÇÃO: o escopo REAL varia por módulo (ver
 *                        docs/claude/roadmap-tecnico.md, "Preparação do perfil Gestor"): só Colaboradores e Avaliação de Experiência
 *                        usam `usuario_setores_gerenciados`; Avaliação de Desempenho, Feedback e PDI filtram pelo gestor
 *                        IMEDIATO/avaliador (`gestor_usuario_id`); Solicitação de Vaga e Kanban de Vagas pelo solicitante/aprovador;
 *                        Integração do Colaborador NÃO filtra por ator (resultados/dashboard globais) — precisa de decisão (B).
 *   pendente_decisao   — mecanismo de transição para uma permissão cuja decisão depende de evidência: a atribuição JÁ GRAVADA segue
 *                        efetiva, NOVAS são recusadas. Hoje vazio (as antigas pendentes foram decididas como incompatíveis).
 *   incompativel       — nominal/financeira/administrativa/de escrita sem escopo: nunca concede acesso a esse perfil, mesmo
 *                        gravada em `usuario_permissoes`; bloqueia o salvamento de seleções que a contenham.
 * Código fora das listas = `incompativel` (negação por padrão); `unit_permissao_perfil_cobertura.php` falha se o catálogo ganhar
 * um código sem classificação explícita.
 *
 * Perfis: admin e RH — global, qualquer permissão é "permitida" (o acesso vem do perfil, não de linhas em usuario_permissoes).
 * Viewer — apenas dashboards agregados autorizados (as de transição só se mantêm onde já gravadas; sem novas concessões — Etapa 3.1). Gestor — FUTURO: inativo (`PERFIL_GESTOR_ATIVO`)
 * e sem ENUM/conta; a política já existe para ser ativada sem refazer a matriz.
 */
final class PermissaoPerfilPolicy
{
    public const PERMITIDA = 'permitida';
    public const TRANSITORIA_GESTOR = 'transitoria_gestor';
    public const PENDENTE_DECISAO = 'pendente_decisao';
    public const INCOMPATIVEL = 'incompativel';

    /**
     * O código reconhece o perfil `gestor` (Etapa 2C.1). Ele só fica ATIVO quando o ENUM `usuarios.role` do banco também o suporta
     * (`Autoridade::gestorDisponivel()`): código publicado antes da migration segue só com admin/rh/viewer.
     */
    public const PERFIL_GESTOR_HABILITADO = true;

    public static function gestorAtivo(): bool
    {
        return self::PERFIL_GESTOR_HABILITADO && Autoridade::gestorDisponivel();
    }

    /**
     * GESTOR: SOMENTE permissões de módulos com escopo setorial COMPROVADO (`usuario_setores_gerenciados`, via EscopoSetorial) e fluxo revisado.
     *  - Etapa 2C.1: colaboradores.visualizar, avaliacao_experiencia.visualizar (`.avaliar` entrou na 2C.2B).
     *  - Etapa 2C.2A: Avaliação de Desempenho, Feedback e PDI — leitura e escrita com escopo por LINHA = setor ATUAL do contrato
     *    (listagem, detalhe por ID, criação com colaborador do POST, edição, ciência, conclusão, cancelamento, acompanhamento, transições).
     *    Reabrir concluído segue só Admin/RH. Integração do Colaborador segue fechada (resultados globais, sem amostra mínima).
     *  - Etapa 2C.2B: Solicitação de Vaga (visualizar/criar) e Kanban de Vagas (visualizar/detalhes/movimentar) com escopo por setor da
     *    solicitação. Ficam FORA por não terem operação implementada/protegida: solicitacao_vaga.editar/cancelar, kanban_vagas.editar. A
     *    aprovação de líder pelo Gestor NÃO é liberada (não há código de permissão nem política).
     */
    private const GESTOR_PERMITIDAS = [
        'colaboradores.visualizar',
        'avaliacao_experiencia.visualizar',
        'avaliacao_desempenho.visualizar', 'avaliacao_desempenho.avaliar',
        'feedback.visualizar', 'feedback.avaliar',
        'pdi.visualizar', 'pdi.gerenciar', 'pdi.acompanhar',
        // Etapa 2C.2B: avaliar Experiência (escopo/CSRF/elegibilidade/reabertura comprovados) e Solicitação de Vaga/Kanban com escopo por setor da solicitação.
        'avaliacao_experiencia.avaliar',
        'solicitacao_vaga.visualizar', 'solicitacao_vaga.criar',
        'kanban_vagas.visualizar', 'kanban_vagas.detalhes', 'kanban_vagas.movimentar',
        // Etapa 2C.2C: aprovar a etapa de LÍDER (só com designação formal, setor gerenciado e etapa correta — impostos no backend) e o dashboard
        // AGREGADO de Integração (k>=5, sem comentários/respostas individuais). Aprovação de RH e a Integração operacional seguem fechadas ao Gestor.
        'solicitacao_vaga.aprovar_lider', 'dashboard_integracao_agregado.visualizar',
        // Etapa 3.2C.1: SOMENTE leitura de Vagas e da LISTAGEM de Candidaturas, com escopo por `vagas.setor_id` ∈ Setores Gerenciados (controllers
        // integrados: AdminVagasController::index e AdminCandidaturasController::index, projeção sem dados pessoais). Detalhe, currículo, documentos,
        // Carta/Exame, Pipeline (consulta e movimentação), indicações, comunicações e toda escrita seguem bloqueados (3.2C.2).
        'vagas.visualizar', 'candidaturas.visualizar',
    ];

    /**
     * Modelo RECOMENDADO do Gestor (Etapa 3): as permissões OPERACIONAIS setoriais de GESTOR_PERMITIDAS. Ficam FORA do modelo (continuam
     * permitidas, mas só por concessão explícita) a aprovação de líder e o dashboard agregado de Integração da 2C.2C: a primeira
     * depende de designação formal e a segunda expõe indicador consolidado — não devem nascer marcadas.
     */
    private const GESTOR_FORA_DO_MODELO = ['solicitacao_vaga.aprovar_lider', 'dashboard_integracao_agregado.visualizar', 'vagas.visualizar', 'candidaturas.visualizar'];

    // ---- GESTOR: permissões NÃO concedíveis (Etapa 3.1) — categoria + motivo, ancorados na auditoria de 2026-11 ------------------------
    public const BLOQUEIO_SEM_CONSUMIDOR = 'sem_consumidor';
    public const BLOQUEIO_REQUER_ESCOPO = 'requer_escopo';
    public const BLOQUEIO_ADMINISTRATIVA = 'administrativa';
    public const BLOQUEIO_GLOBAL_NOMINAL = 'global_nominal';

    /**
     * Por MÓDULO (prefixo do código): todo código do catálogo que não está em GESTOR_PERMITIDAS precisa de uma categoria e de um motivo —
     * `unit_permissao_perfil_cobertura.php` falha se o catálogo ganhar um código sem decisão. Conceder uma permissão daqui a um Gestor NÃO
     * teria efeito (o endpoint exige role Admin/RH) e liberar exigiria escopo setorial no backend: não é um simples "marcar" na matriz.
     * Nada aqui é decidido por ID ou nome de usuário.
     */
    private const GESTOR_BLOQUEIOS_POR_MODULO = [
        'dashboard' => [self::BLOQUEIO_GLOBAL_NOMINAL, 'Dashboard People Analytics: filtros sem tamanho mínimo de amostra (reidentificação) e sem escopo setorial.'],
        'indicadores_rh' => [self::BLOQUEIO_GLOBAL_NOMINAL, 'Indicadores de RH: dados consolidados da empresa, sem escopo setorial e com risco de reidentificação.'],
        'dashboard_turnover' => [self::BLOQUEIO_GLOBAL_NOMINAL, 'Turnover: visão global da empresa, sem escopo setorial.'],
        'dashboard_recrutamento' => [self::BLOQUEIO_REQUER_ESCOPO, 'Dashboard de Recrutamento: consultas globais (todas as vagas); requer escopo por setor da solicitação.'],
        'dashboard_entrevista_desligamento' => [self::BLOQUEIO_GLOBAL_NOMINAL, 'Entrevista de Desligamento: respostas individuais/agregadas sem amostra mínima e sem escopo setorial.'],
        'entrevista_desligamento' => [self::BLOQUEIO_GLOBAL_NOMINAL, 'Entrevista de Desligamento: dado nominal e sensível, sem escopo setorial.'],
        'integracao_colaborador' => [self::BLOQUEIO_GLOBAL_NOMINAL, 'Integração do Colaborador: resultados globais com comentários, sem amostra mínima (o agregado k>=5 é a permissão própria).'],
        'pesquisa_reacao_integracao' => [self::BLOQUEIO_ADMINISTRATIVA, 'Pesquisa de Reação: configuração e resultados corporativos (Admin/RH).'],
        'pesquisa_experiencia' => [self::BLOQUEIO_REQUER_ESCOPO, 'Pesquisa de Experiência (candidatura): sem escopo setorial; só é lida dentro do fluxo de Candidaturas (Admin/RH).'],
        'comunicacoes' => [self::BLOQUEIO_REQUER_ESCOPO, 'Histórico de Comunicação com candidatos: sem escopo setorial; só é lido dentro de Candidaturas (Admin/RH).'],
        'candidaturas' => [self::BLOQUEIO_REQUER_ESCOPO, 'Candidaturas: só a LISTAGEM setorial (candidaturas.visualizar) é concedível. Edição, detalhe, currículo, documentos, indicação e Carta/Exame seguem Admin/RH (dados pessoais; exigem a Etapa 3.2C.2).'],
        'pipeline' => [self::BLOQUEIO_REQUER_ESCOPO, 'Pipeline de candidatos: o quadro ainda usa o setor da solicitação e expõe metadados de entrevista; a movimentação setorial existe (3.2B.3) mas não está integrada. Segue Admin/RH até a 3.2C.2.'],
        'vagas' => [self::BLOQUEIO_REQUER_ESCOPO, 'Vagas: só a CONSULTA setorial (vagas.visualizar) é concedível. Criar, editar, excluir e publicar seguem Admin/RH (escrita não liberada ao Gestor).'],
        'indicacoes' => [self::BLOQUEIO_GLOBAL_NOMINAL, 'Indicações: dados nominais e financeiros (pagamento de comissão).'],
        'recruitment_webhooks' => [self::BLOQUEIO_ADMINISTRATIVA, 'Webhooks de recrutamento: configuração crítica de integração (Admin/RH).'],
        'movimentacao_pessoal' => [self::BLOQUEIO_REQUER_ESCOPO, 'Movimentação de Pessoal: documento nominal com dados salariais e assinatura de RH, sem escopo setorial.'],
        'mensagens' => [self::BLOQUEIO_ADMINISTRATIVA, 'Mensagens: configuração corporativa de modelos (Admin/RH).'],
        'beneficios' => [self::BLOQUEIO_ADMINISTRATIVA, 'Benefícios: catálogo corporativo (Admin/RH).'],
        'avaliacoes' => [self::BLOQUEIO_ADMINISTRATIVA, 'Cadastro de Avaliações (modelos): configuração corporativa (Admin/RH).'],
        'empresas' => [self::BLOQUEIO_ADMINISTRATIVA, 'Cadastro de Empresas: dado mestre corporativo (Admin/RH).'],
        'setores' => [self::BLOQUEIO_ADMINISTRATIVA, 'Cadastro de Setores: dado mestre corporativo (Admin/RH).'],
        'cargos' => [self::BLOQUEIO_ADMINISTRATIVA, 'Cadastro de Cargos: dado mestre corporativo (Admin/RH).'],
        'manual' => [self::BLOQUEIO_ADMINISTRATIVA, 'Manual do Portal: tela restrita a Admin/RH/Leitor, sem uso para Gestor.'],
    ];

    /** Exceções por CÓDIGO: o módulo é parcialmente concedível, mas esta ação não tem operação protegida/implementada. */
    private const GESTOR_BLOQUEIOS_POR_CODIGO = [
        'colaboradores.editar' => [self::BLOQUEIO_SEM_CONSUMIDOR, 'Edição do cadastro do colaborador: a operação é exclusiva de Admin/RH por role; nenhuma ação do Gestor usa esta permissão.'],
        'solicitacao_vaga.editar' => [self::BLOQUEIO_SEM_CONSUMIDOR, 'Editar solicitação: nenhuma operação do Gestor consome esta permissão (a edição/aprovação de RH é por role).'],
        'solicitacao_vaga.cancelar' => [self::BLOQUEIO_SEM_CONSUMIDOR, 'Cancelar solicitação: nenhuma operação do Gestor consome esta permissão.'],
        'kanban_vagas.editar' => [self::BLOQUEIO_SEM_CONSUMIDOR, 'Editar card do Kanban: nenhuma operação consome esta permissão (movimentar e anotar usam kanban_vagas.movimentar).'],
    ];

    /** Perfis com acesso global pelo próprio papel (Authorization já os libera sem linhas em usuario_permissoes). */
    private const PERFIS_GLOBAIS = ['admin', 'rh'];

    // ---- VIEWER: dashboards/indicadores AGREGADOS auditados (Etapa 2B) -------------------------------------------------------
    private const VIEWER_PERMITIDAS = [
        'dashboard.visualizar',                       // People Analytics (agregado desde a Etapa 2A)
        'indicadores_rh.visualizar',                  // Indicadores de RH
        'dashboard_turnover.visualizar',              // Turnover
        'dashboard_recrutamento.visualizar',          // Recrutamento
        'dashboard_entrevista_desligamento.visualizar', // Entrevista de Desligamento (agregado)
    ];

    /** Subconjunto aplicado AUTOMATICAMENTE no cadastro: só dashboard sem risco de reidentificação (ver auditoriaDashboards()). */
    private const VIEWER_RECOMENDADAS = ['dashboard_recrutamento.visualizar'];

    // ---- Gestor-de-fato de hoje (backend já filtra por Setor Gerenciado para ator não admin/RH) ---------------------------------
    private const TRANSITORIAS_GESTOR = [
        'colaboradores.visualizar',
        'avaliacao_experiencia.visualizar', 'avaliacao_experiencia.avaliar',
        'avaliacao_desempenho.visualizar', 'avaliacao_desempenho.avaliar',
        'feedback.visualizar', 'feedback.avaliar',
        'pdi.visualizar', 'pdi.gerenciar', 'pdi.acompanhar',
        'solicitacao_vaga.visualizar', 'solicitacao_vaga.criar', 'solicitacao_vaga.editar', 'solicitacao_vaga.cancelar',
        'kanban_vagas.visualizar', 'kanban_vagas.detalhes', 'kanban_vagas.editar', 'kanban_vagas.movimentar',
    ];

    /**
     * Vazia por decisão de segurança (Etapa 2B complementar): Entrevista de Desligamento, Pesquisa de Reação e Mensagens eram "pendentes"
     * e foram DECIDIDAS como incompatíveis com o viewer (dado global/nominal ou configuração corporativa, sem escopo setorial). O nível
     * continua existindo para futuras permissões cuja decisão ainda dependa de evidência (grandfathering, ver cabeçalho).
     */
    private const PENDENTES_DECISAO = [];

    /** Explícitas (e não só "o resto") para o teste de cobertura detectar códigos novos sem classificação. */
    private const VIEWER_INCOMPATIVEIS = [
        // Etapa 2C.2B: resultados/dashboard/QR da Integração são GLOBAIS e trazem respostas e comentários sem amostra mínima (reidentificação) — fora do viewer.
        'integracao_colaborador.visualizar', 'integracao_colaborador.editar',
        // Etapa 2C.2C: o Viewer segue SEM aprovação por permissão (a aprovação de viewer designado é a compatibilidade temporária por designação) e sem o dashboard agregado.
        'solicitacao_vaga.aprovar_lider', 'dashboard_integracao_agregado.visualizar',
        'candidaturas.visualizar', 'candidaturas.editar',
        'pipeline.visualizar', 'pipeline.movimentar',
        'indicacoes.visualizar', 'indicacoes.gerenciar_pagamento',
        'recruitment_webhooks.visualizar', 'recruitment_webhooks.gerenciar',
        'empresas.visualizar', 'empresas.criar', 'empresas.editar', 'empresas.excluir',
        'setores.visualizar', 'setores.criar', 'setores.editar', 'setores.excluir',
        'cargos.visualizar', 'cargos.criar', 'cargos.editar', 'cargos.excluir',
        'beneficios.visualizar', 'beneficios.criar', 'beneficios.editar', 'beneficios.excluir',
        'avaliacoes.visualizar', 'avaliacoes.criar', 'avaliacoes.editar', 'avaliacoes.excluir',
        'vagas.visualizar', 'vagas.criar', 'vagas.editar', 'vagas.excluir', 'vagas.publicar',
        'movimentacao_pessoal.visualizar', 'movimentacao_pessoal.criar', 'movimentacao_pessoal.editar', 'movimentacao_pessoal.assinar',
        'manual.visualizar', 'comunicacoes.visualizar', 'pesquisa_experiencia.visualizar',
        // Etapa 2B complementar — decididos como NÃO-viewer: entrevistas individuais, respostas identificáveis, gerenciamento
        // operacional, pesquisas de reação e configuração corporativa de mensagens são de Admin/RH (e, no futuro, de Gestor com escopo).
        'entrevista_desligamento.visualizar', 'entrevista_desligamento.gerenciar', 'entrevista_desligamento.resultados',
        'pesquisa_reacao_integracao.visualizar', 'pesquisa_reacao_integracao.gerenciar',
        'mensagens.visualizar', 'mensagens.criar', 'mensagens.editar',
        'colaboradores.editar', // edição do cadastro (editRh) é admin/RH por role; sem uso para viewer
    ];

    /**
     * Resultado da auditoria dos dashboards do Viewer (Etapa 2B) — documentado AQUI para a matriz e os testes usarem a mesma
     * fonte. `reidentificacao`: alto/medio/baixo (combinações de filtros sem tamanho mínimo de amostra); só `baixo` entra no
     * modelo recomendado. Nenhum dashboard devolve nome/CPF/ID a viewer; nenhum tem exportação para viewer (o CSV nominal é admin/RH).
     */
    private const AUDITORIA_DASHBOARDS = [
        'dashboard.visualizar' => ['rota' => '/admin/dashboard (+ /dados)', 'reidentificacao' => 'alto', 'motivo' => 'filtros empresa × setor × sexo × motivo × mês sem tamanho mínimo de amostra isolam 1 pessoa; a lista de opções expõe nomes de usuários gestores'],
        'indicadores_rh.visualizar' => ['rota' => '/admin/indicadores-rh', 'reidentificacao' => 'alto', 'motivo' => 'filtros empresa × unidade × cargo × setor × centro de custo sem tamanho mínimo; cargo/setor de 1 pessoa'],
        'dashboard_turnover.visualizar' => ['rota' => '/admin/dashboard-turnover', 'reidentificacao' => 'medio', 'motivo' => 'turnover por cargo/empresa: cargo com 1 ocupante expõe o desligamento individual'],
        'dashboard_entrevista_desligamento.visualizar' => ['rota' => '/admin/dashboard-entrevista-desligamento', 'reidentificacao' => 'alto', 'motivo' => 'satisfação/eNPS por cargo/unidade: grupo com 1 respondente expõe a resposta de uma pessoa'],
        'dashboard_recrutamento.visualizar' => ['rota' => '/admin/dashboard-recrutamento', 'reidentificacao' => 'baixo', 'motivo' => 'contagens e médias por etapa/vaga, sem atributo pessoal; ressalva: filtro por vaga com amostra 1'],
    ];

    // ---------------------------------------------------------------------------------------------------------------------------

    public static function normalizarRole(mixed $role): string
    {
        return strtolower(trim((string)$role));
    }

    public static function perfilGlobal(string $role): bool
    {
        return in_array(self::normalizarRole($role), self::PERFIS_GLOBAIS, true);
    }

    /** Perfis que a política conhece e que hoje existem de fato. */
    public static function perfisAtivos(): array
    {
        return self::gestorAtivo() ? ['admin', 'rh', 'gestor', 'viewer'] : ['admin', 'rh', 'viewer'];
    }

    public static function classificar(string $role, string $codigo): string
    {
        $role = self::normalizarRole($role);
        if (in_array($role, self::PERFIS_GLOBAIS, true)) {
            return self::PERMITIDA;
        }
        if ($role === 'gestor') {
            // Negação por padrão: só as permissões de módulos com escopo setorial comprovado, e só com o perfil ativo.
            return self::gestorAtivo() && in_array($codigo, self::GESTOR_PERMITIDAS, true) ? self::PERMITIDA : self::INCOMPATIVEL;
        }
        if ($role !== 'viewer') {
            return self::INCOMPATIVEL; // role desconhecida: negação por padrão
        }
        if (in_array($codigo, self::VIEWER_PERMITIDAS, true)) {
            return self::PERMITIDA;
        }
        if (in_array($codigo, self::TRANSITORIAS_GESTOR, true)) {
            return self::TRANSITORIA_GESTOR;
        }
        if (in_array($codigo, self::PENDENTES_DECISAO, true)) {
            return self::PENDENTE_DECISAO;
        }
        return self::INCOMPATIVEL;
    }

    /**
     * Permissões que o perfil PODE receber/ter válidas. Perfis globais: todas as do catálogo informado (ou [] se não informado);
     * viewer: dashboards permitidos + de transição (as pendentes só se já gravadas — ver podeReceberPermissao).
     *
     * @param string[]|null $catalogo códigos ativos do catálogo (para perfis globais)
     * @return string[]
     */
    public static function permissoesPermitidas(string $role, ?array $catalogo = null): array
    {
        $role = self::normalizarRole($role);
        if (in_array($role, self::PERFIS_GLOBAIS, true)) {
            return array_values($catalogo ?? []);
        }
        if ($role === 'viewer') {
            return self::VIEWER_PERMITIDAS; // as de transição só são MANTIDAS quando já gravadas (podeReceberPermissao/permissaoEfetiva)
        }
        if ($role === 'gestor' && self::gestorAtivo()) {
            return self::GESTOR_PERMITIDAS;
        }
        return [];
    }

    /**
     * Modelo sugerido pela matriz de permissões (cadastro e "Restaurar modelo"). Admin/RH: vazio (o acesso vem do perfil). Viewer: só
     * o dashboard sem risco de reidentificação. Gestor (Etapa 3): permissões operacionais setoriais (ver GESTOR_FORA_DO_MODELO); só com
     * o perfil ativo. Sempre ⊆ permissoesPermitidas().
     *
     * @return string[]
     */
    public static function modeloRecomendado(string $role): array
    {
        return match (self::normalizarRole($role)) {
            'viewer' => self::VIEWER_RECOMENDADAS,
            'gestor' => self::gestorAtivo() ? array_values(array_diff(self::GESTOR_PERMITIDAS, self::GESTOR_FORA_DO_MODELO)) : [],
            default => [],
        };
    }

    /**
     * Permissões CONCEDÍVEIS ao perfil (Etapa 3.1): o conjunto que o administrador pode atribuir individualmente. É distinto do modelo
     * recomendado (sugestão inicial, subconjunto): o modelo nunca limita o que é concedível, e algo concedível não entra no modelo
     * sozinho.
     *
     * @return string[]
     */
    public static function concediveis(string $role): array
    {
        return self::permissoesPermitidas($role);
    }

    /**
     * Motivo (texto para o administrador) pelo qual `$codigo` não pode ser concedida ao perfil, ou null se pode. Para o Gestor vem da
     * auditoria (categorias BLOQUEIO_*); para o Viewer, novas concessões operacionais de transição estão fechadas.
     */
    public static function motivoBloqueio(string $role, string $codigo): ?string
    {
        $role = self::normalizarRole($role);
        if (self::classificar($role, $codigo) === self::PERMITIDA) {
            return null;
        }
        if ($role === 'gestor') {
            return (self::bloqueioGestor($codigo) ?? [self::BLOQUEIO_REQUER_ESCOPO, 'Sem decisão de escopo setorial para este código: negado por padrão.'])[1];
        }
        if ($role === 'viewer') {
            return self::classificar($role, $codigo) === self::TRANSITORIA_GESTOR
                ? 'Permissão operacional de transição: só permanece onde já estava gravada; novas concessões ao Leitor não são permitidas (use o perfil Gestor).'
                : 'Incompatível com o perfil Leitor (dado nominal, global ou administrativo).';
        }
        return 'Incompatível com o perfil.';
    }

    /** Categoria BLOQUEIO_* do código para o Gestor, ou null se é concedível ao Gestor. */
    public static function categoriaBloqueioGestor(string $codigo): ?string
    {
        return in_array($codigo, self::GESTOR_PERMITIDAS, true) ? null : (self::bloqueioGestor($codigo)[0] ?? self::BLOQUEIO_REQUER_ESCOPO);
    }

    /** @return array{0:string,1:string}|null */
    private static function bloqueioGestor(string $codigo): ?array
    {
        if (in_array($codigo, self::GESTOR_PERMITIDAS, true)) {
            return null;
        }
        $prefixo = explode('.', $codigo, 2)[0];
        return self::GESTOR_BLOQUEIOS_POR_CODIGO[$codigo] ?? self::GESTOR_BLOQUEIOS_POR_MODULO[$prefixo] ?? null;
    }

    /** Há decisão explícita de bloqueio (módulo ou código) para o Gestor? Usado pelo teste de cobertura do catálogo. */
    public static function gestorTemDecisao(string $codigo): bool
    {
        return in_array($codigo, self::GESTOR_PERMITIDAS, true) || self::bloqueioGestor($codigo) !== null;
    }

    /**
     * Pode ser ATRIBUÍDA numa seleção. `$jaAtribuida`: a permissão pendente de decisão só é mantida se já estava gravada
     * (nova atribuição recusada).
     */
    public static function podeReceberPermissao(string $role, string $codigo, bool $jaAtribuida = false): bool
    {
        // Etapa 3.1: a transição do Viewer deixou de ser porta de NOVAS concessões operacionais — só se mantém o que já está gravado
        // (continua efetivo, ver permissaoEfetiva). Operacional novo = perfil Gestor.
        return match (self::classificar($role, $codigo)) {
            self::PERMITIDA => true,
            self::TRANSITORIA_GESTOR, self::PENDENTE_DECISAO => $jaAtribuida,
            default => false,
        };
    }

    /**
     * Concede ACESSO EFETIVO no backend (é o que `Authorization::usuarioTemPermissao` consulta). Inclui as pendentes JÁ gravadas
     * (ninguém perde acesso sem evidência de que não depende dele); a gravação em si (`usuario_permissoes`) é verificada por quem chama.
     */
    public static function permissaoEfetiva(string $role, string $codigo): bool
    {
        return self::classificar($role, $codigo) !== self::INCOMPATIVEL;
    }

    /**
     * Códigos da seleção que o perfil NÃO pode ter. `$jaAtribuidos`: códigos gravados antes (para tolerar as pendentes).
     *
     * @param string[] $codigos
     * @param string[] $jaAtribuidos
     * @return string[]
     */
    public static function incompativeis(string $role, array $codigos, array $jaAtribuidos = []): array
    {
        $saida = [];
        foreach (array_values(array_unique($codigos)) as $codigo) {
            if (!self::podeReceberPermissao($role, (string)$codigo, in_array($codigo, $jaAtribuidos, true))) {
                $saida[] = (string)$codigo;
            }
        }
        return $saida;
    }

    /**
     * Códigos que o usuário TEM efetivamente: global → todo o catálogo ativo; demais → o que está gravado E é efetivo no perfil.
     *
     * @param User|array|null $usuario
     * @return string[]
     */
    public static function permissoesEfetivas(mixed $usuario): array
    {
        $id = is_array($usuario) ? (int)($usuario['id'] ?? 0) : (int)($usuario->id ?? 0);
        $role = self::normalizarRole(is_array($usuario) ? ($usuario['role'] ?? '') : ($usuario->role ?? ''));
        if ($id <= 0) {
            return [];
        }
        if (self::perfilGlobal($role)) {
            return array_values(array_map(static fn(array $p): string => (string)$p['codigo'], self::catalogo(true)));
        }
        return array_values(array_filter(
            Authorization::codigosGravados($id),
            static fn(string $codigo): bool => self::permissaoEfetiva($role, $codigo)
        ));
    }

    /**
     * Permissões gravadas que NÃO concedem acesso ao perfil (incompatíveis) e as mantidas só por transição/pendência — para
     * informar o administrador, sem alterar nada.
     *
     * @return array{incompativeis:string[],pendentes:string[],transitorias:string[]}
     */
    public static function diagnosticoLegado(string $role, array $codigosGravados): array
    {
        $r = ['incompativeis' => [], 'pendentes' => [], 'transitorias' => []];
        foreach ($codigosGravados as $codigo) {
            match (self::classificar($role, (string)$codigo)) {
                self::INCOMPATIVEL => $r['incompativeis'][] = (string)$codigo,
                self::PENDENTE_DECISAO => $r['pendentes'][] = (string)$codigo,
                self::TRANSITORIA_GESTOR => $r['transitorias'][] = (string)$codigo,
                default => null,
            };
        }
        return $r;
    }

    /**
     * Valida a SELEÇÃO FINAL de ids de permissão para um perfil (usada por `updatePermissoes`): cada id deve existir e estar ativo
     * e ser compatível. Nada é descartado em silêncio e nada é gravado aqui.
     *
     * @param int[] $idsRecebidos
     * @param int[] $idsAtuais    ids hoje gravados (tolerância das pendentes)
     * @return array{ok:bool,mensagem:string,inexistentes:array,inativas:string[],incompativeis:string[],codigos:string[]}
     */
    public static function validarSelecao(string $role, array $idsRecebidos, array $idsAtuais = []): array
    {
        $catalogo = [];
        foreach (self::catalogo(false) as $p) {
            $catalogo[(int)$p['id']] = $p;
        }
        $inexistentes = [];
        $inativas = [];
        $codigos = [];
        foreach (array_values(array_unique(array_map('intval', $idsRecebidos))) as $id) {
            if (!isset($catalogo[$id])) {
                $inexistentes[] = $id;
            } elseif ((int)$catalogo[$id]['ativo'] !== 1) {
                $inativas[] = (string)$catalogo[$id]['codigo'];
            } else {
                $codigos[] = (string)$catalogo[$id]['codigo'];
            }
        }
        $codigosAtuais = [];
        foreach (array_unique(array_map('intval', $idsAtuais)) as $id) {
            if (isset($catalogo[$id])) {
                $codigosAtuais[] = (string)$catalogo[$id]['codigo'];
            }
        }
        $incompativeis = self::incompativeis($role, $codigos, $codigosAtuais);

        $mensagens = [];
        if ($inexistentes !== []) {
            $mensagens[] = 'Permissão inexistente (id ' . implode(', ', $inexistentes) . ').';
        }
        if ($inativas !== []) {
            $mensagens[] = 'Permissão inativa: ' . implode(', ', $inativas) . '.';
        }
        if ($incompativeis !== []) {
            $rotulos = array_map(static function (string $c) use ($catalogo): string {
                foreach ($catalogo as $p) {
                    if ($p['codigo'] === $c) {
                        return $c . ' (' . $p['modulo'] . ' — ' . $p['nome'] . ')';
                    }
                }
                return $c;
            }, $incompativeis);
            $mensagens[] = 'Incompatível com o perfil ' . strtoupper(self::normalizarRole($role)) . ': ' . implode('; ', $rotulos) . '. Desmarque para salvar.';
        }
        return [
            'ok' => $mensagens === [],
            'mensagem' => implode(' ', $mensagens),
            'inexistentes' => $inexistentes,
            'inativas' => $inativas,
            'incompativeis' => $incompativeis,
            'codigos' => $codigos,
        ];
    }

    /** Auditoria dos dashboards do viewer (rota, risco de reidentificação, motivo) e se entram no modelo automático. */
    public static function auditoriaDashboards(): array
    {
        $saida = [];
        foreach (self::AUDITORIA_DASHBOARDS as $codigo => $info) {
            $saida[$codigo] = $info + ['recomendado' => in_array($codigo, self::VIEWER_RECOMENDADAS, true)];
        }
        return $saida;
    }

    /** Todos os códigos classificados explicitamente (para o teste de cobertura do catálogo). */
    public static function codigosClassificadosViewer(): array
    {
        return array_merge(self::VIEWER_PERMITIDAS, self::TRANSITORIAS_GESTOR, self::PENDENTES_DECISAO, self::VIEWER_INCOMPATIVEIS);
    }

    /** @return array<int,array{id:int|string,codigo:string,modulo:string,nome:string,ativo:int|string}> */
    private static function catalogo(bool $apenasAtivas): array
    {
        $sql = 'SELECT id, codigo, modulo, nome, ativo FROM permissoes' . ($apenasAtivas ? ' WHERE ativo = 1' : '') . ' ORDER BY ordem ASC, id ASC';
        return Database::conn()->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    }
}
