<?php

/**
 * Módulos da CENTRAL DO PORTAL RH (Nova UI, Fase 3A) — camada de NAVEGAÇÃO, nunca de autorização.
 *
 * Cada módulo é uma área funcional de alto nível (não um item de menu) e reúne destinos REAIS já existentes. O card
 * aparece se pelo menos UM destino for visível ao usuário e aponta para o PRIMEIRO destino visível (a entrada lógica da área).
 * Esconder/mostrar card é só representação: o backend de cada rota continua sendo a autoridade final (403 direto).
 *
 * FONTE ÚNICA DE NAVEGAÇÃO (publicação consolidada): a antiga sidebar (`layouts/sidebar.php`) foi removida — este serviço é
 * agora a ÚNICA fonte da navegação administrativa, consumido pela Central (`modulos()`) e pelas abas de cada módulo já
 * migrado (`abas()`). As regras usam as mesmas fontes de autorização do backend (`Authorization::temPermissao`, role,
 * supervisor), nunca uma matriz paralela por perfil. `integration_portal_central.php` prova, contra os gates reais dos
 * controllers, que nenhum card visível leva a um destino que devolveria 403.
 *
 * Regras (chaves de `regra`):
 *   - `aberto`            sempre visível a qualquer usuário autenticado (a sidebar não gateia por permissão);
 *   - `perm:<codigo>`     `Authorization::temPermissao(<codigo>)` (Admin pelo bypass central desse resolver);
 *   - `staff_ou:<codigo>` admin/supervisor/rh OU a permissão (`$veAdminRh` da sidebar);
 *   - `pedidos_vaga`      staff OU solicitacao_vaga.visualizar/criar OU kanban_vagas.visualizar (`$vePedidosDeVaga`);
 *   - `admin_supervisor`  só admin ou supervisor (`$isAdminOuSupervisor`);
 *   - `perm_qualquer:<a>,<b>` qualquer uma das permissões individuais (a Central de Pesquisas de Integração abre com a de Reação OU
 *                         a da Integração — ver AdminPesquisaReacaoIntegracaoController::index);
 *   - `staff`             admin/supervisor/rh, SEM permissão individual: espelha um gate de backend deliberadamente restrito por
 *                         regra funcional documentada (ex.: a listagem de Colaboradores expõe salário individual — ver
 *                         AdminColaboradoresController::index, que proíbe `viewer` mesmo com `colaboradores.visualizar`).
 *
 * ABAS DE MÓDULO (`definicaoAbas`/`abas`): navegação interna de um módulo migrado. Regra de ouro: uma aba só aparece se o
 * usuário REALMENTE consegue abrir o destino (nunca uma aba que leva a 403). As regras das abas são as MESMAS da sidebar e da
 * Central porque os controllers de entrada (Pipeline, Indicações, Webhooks) passaram a usar
 * `Authorization::requireRoleOuPermissao()` — role admin/rh OU a permissão individual `<módulo>.visualizar` —, exatamente o que
 * `staff_ou:<código>` expressa. `integration_portal_modulo_recrutamento.php` lê o gate de cada destino e falha se divergirem.
 */
class PortalNavegacaoService
{
    /** @var callable(string):bool */
    private $temPermissao;
    private ?string $role;
    private bool $supervisor;

    /**
     * Sem argumentos usa a SESSÃO atual (o normal). Os parâmetros existem para testar sem depender de `$_SESSION`.
     *
     * @param (callable(string):bool)|null $temPermissao
     */
    public function __construct(?callable $temPermissao = null, ?string $role = null, ?bool $supervisor = null)
    {
        $this->temPermissao = $temPermissao ?? static fn(string $codigo): bool => Authorization::temPermissao($codigo);
        $this->role = $role ?? Auth::role();
        $this->supervisor = $supervisor ?? !empty($_SESSION['user_is_supervisor']);
    }

    /**
     * Definição dos módulos: ordem = ordem de exibição; `itens` em ordem de preferência de entrada. Só destinos reais.
     *
     * @return array<int,array{chave:string,titulo:string,descricao:string,icone:string,itens:array<int,array{href:string,regra:string}>}>
     */
    public static function definicao(): array
    {
        return [
            ['chave' => 'indicadores', 'titulo' => 'Indicadores de RH', 'icone' => 'indicadores',
                'descricao' => 'Dashboard de People Analytics e indicadores gerenciais de pessoas.',
                'itens' => [
                    ['href' => '/admin/dashboard', 'regra' => 'perm:dashboard.visualizar'], // People Analytics (antigo /admin)
                    ['href' => '/admin/indicadores-rh', 'regra' => 'aberto'],
                ]],
            ['chave' => 'recrutamento', 'titulo' => 'Recrutamento e Seleção', 'icone' => 'recrutamento',
                'descricao' => 'Dashboard, candidaturas, pipeline, vagas, webhooks e indicações.',
                'itens' => [
                    ['href' => '/admin/dashboard-recrutamento', 'regra' => 'perm:dashboard_recrutamento.visualizar'],
                    ['href' => '/admin/candidaturas', 'regra' => 'aberto'],
                    ['href' => '/admin/pipeline', 'regra' => 'staff_ou:pipeline.visualizar'],
                    ['href' => '/admin/vagas', 'regra' => 'aberto'],
                    ['href' => '/admin/recruitment-webhooks', 'regra' => 'staff_ou:recruitment_webhooks.visualizar'],
                    ['href' => '/admin/indicacoes', 'regra' => 'staff_ou:indicacoes.visualizar'],
                ]],
            ['chave' => 'solicitacoes-vaga', 'titulo' => 'Solicitações de Vaga', 'icone' => 'vagas',
                'descricao' => 'Abra e acompanhe solicitações de vaga.',
                'itens' => [
                    ['href' => '/admin/solicitacoes-vaga', 'regra' => 'pedidos_vaga'],
                ]],
            ['chave' => 'colaboradores', 'titulo' => 'Colaboradores', 'icone' => 'colaboradores',
                'descricao' => 'Cadastro de colaboradores e movimentações de pessoal.',
                'itens' => [
                    ['href' => '/admin/colaboradores', 'regra' => 'staff'], // backend admin/rh por regra documentada (salário) — a sidebar antiga é mais permissiva
                    ['href' => '/admin/movimentacoes-pessoal', 'regra' => 'aberto'],
                ]],
            ['chave' => 'pdi', 'titulo' => 'PDI', 'icone' => 'pdi',
                'descricao' => 'Planos de Desenvolvimento Individual e seu acompanhamento.',
                'itens' => [
                    ['href' => '/admin/pdis', 'regra' => 'perm:pdi.visualizar'],
                ]],
            ['chave' => 'integracao', 'titulo' => 'Integração', 'icone' => 'integracao',
                'descricao' => 'Pesquisas de reação e de integração de novos colaboradores.',
                'itens' => [
                    ['href' => '/admin/pesquisas-reacao-integracao', 'regra' => 'perm:pesquisa_reacao_integracao.visualizar'],
                    ['href' => '/admin/pesquisa-integracao-qr', 'regra' => 'perm:integracao_colaborador.visualizar'],
                ]],
            ['chave' => 'desligamento', 'titulo' => 'Turnover e Desligamento', 'icone' => 'desligamento',
                'descricao' => 'Dashboards de turnover e entrevistas de desligamento.',
                'itens' => [
                    ['href' => '/admin/dashboard-turnover', 'regra' => 'perm:dashboard_turnover.visualizar'],
                    ['href' => '/admin/dashboard-entrevista-desligamento', 'regra' => 'perm:dashboard_entrevista_desligamento.visualizar'],
                    ['href' => '/admin/entrevistas-desligamento', 'regra' => 'perm:entrevista_desligamento.visualizar'],
                ]],
            ['chave' => 'mensagens', 'titulo' => 'Mensagens', 'icone' => 'mensagens',
                'descricao' => 'Modelos de mensagem do processo seletivo.',
                'itens' => [
                    ['href' => '/admin/mensagens', 'regra' => 'perm:mensagens.visualizar'],
                ]],
            ['chave' => 'cadastros', 'titulo' => 'Cadastros', 'icone' => 'cadastros',
                'descricao' => 'Empresas, setores, cargos, benefícios e avaliações.',
                'itens' => [
                    ['href' => '/admin/empresas', 'regra' => 'aberto'],
                    ['href' => '/admin/setores', 'regra' => 'aberto'],
                    ['href' => '/admin/cargos', 'regra' => 'aberto'],
                    ['href' => '/admin/beneficios', 'regra' => 'aberto'],
                    ['href' => '/admin/avaliacoes', 'regra' => 'aberto'],
                ]],
            ['chave' => 'usuarios', 'titulo' => 'Usuários e Acessos', 'icone' => 'usuarios',
                'descricao' => 'Usuários do Portal, perfis e permissões.',
                'itens' => [
                    ['href' => '/admin/usuarios', 'regra' => 'admin_supervisor'],
                ]],
        ];
    }

    /**
     * Abas dos módulos já migrados para o AppShell V2 (por chave de módulo). Só destinos reais. `regra` como em `visivel()`.
     *
     * @return array<string,array{titulo:string,abas:array<int,array{chave:string,label:string,href:string,regra:string}>}>
     */
    public static function definicaoAbas(): array
    {
        return [
            // Bloco C — Pessoas. `trilha_modulo` false: o breadcrumb é Portal RH › <Aba> (sem nível de módulo intermediário).
            'pessoas' => ['titulo' => 'Pessoas', 'trilha_modulo' => false, 'abas' => [
                ['chave' => 'colaboradores', 'label' => 'Colaboradores', 'href' => '/admin/colaboradores', 'regra' => 'staff'],
                ['chave' => 'usuarios', 'label' => 'Usuários e Acessos', 'href' => '/admin/usuarios', 'regra' => 'admin_supervisor'],
            ]],
            // Bloco F — Indicadores de RH: duas telas irmãs reais e independentes (services próprios), o mesmo agrupamento do card da Central.
            // People Analytics é o antigo Dashboard principal (/admin/dashboard, gate dashboard.visualizar); Indicadores de RH é aberto a admin/rh/viewer.
            'indicadores' => ['titulo' => 'Indicadores de RH', 'trilha_modulo' => false, 'abas' => [
                ['chave' => 'people-analytics', 'label' => 'People Analytics', 'href' => '/admin/dashboard', 'regra' => 'perm:dashboard.visualizar'],
                ['chave' => 'indicadores-rh', 'label' => 'Indicadores de RH', 'href' => '/admin/indicadores-rh', 'regra' => 'aberto'],
            ]],
            // Bloco G — Cadastros: cinco áreas irmãs reais (mesmo agrupamento do card da Central). Todas abertas às roles admin/rh/viewer no backend
            // (a listagem de cada uma só exige `Auth::requireRole`), como a sidebar antiga e a Central. Escrita continua gateada por controller.
            'cadastros' => ['titulo' => 'Cadastros', 'abas' => [
                ['chave' => 'empresas', 'label' => 'Empresas', 'href' => '/admin/empresas', 'regra' => 'aberto'],
                ['chave' => 'setores', 'label' => 'Setores', 'href' => '/admin/setores', 'regra' => 'aberto'],
                ['chave' => 'cargos', 'label' => 'Cargos', 'href' => '/admin/cargos', 'regra' => 'aberto'],
                ['chave' => 'beneficios', 'label' => 'Benefícios', 'href' => '/admin/beneficios', 'regra' => 'aberto'],
                ['chave' => 'avaliacoes', 'label' => 'Avaliações', 'href' => '/admin/avaliacoes', 'regra' => 'aberto'],
            ]],
            // Bloco E — Integração e Turnover/Desligamento: dois agrupamentos pequenos e reais (os mesmos cards da Central). Sem "megabarra":
            // as pesquisas públicas e as telas de detalhe (resultados) ficam sob a aba de origem.
            'integracao' => ['titulo' => 'Integração', 'abas' => [
                ['chave' => 'pesquisas', 'label' => 'Pesquisas e resultados', 'href' => '/admin/pesquisas-reacao-integracao', 'regra' => 'perm_qualquer:pesquisa_reacao_integracao.visualizar,integracao_colaborador.visualizar'],
                ['chave' => 'qr', 'label' => 'QR Code da Integração', 'href' => '/admin/pesquisa-integracao-qr', 'regra' => 'perm:integracao_colaborador.visualizar'],
            ]],
            'desligamento' => ['titulo' => 'Turnover e Desligamento', 'abas' => [
                ['chave' => 'turnover', 'label' => 'Turnover', 'href' => '/admin/dashboard-turnover', 'regra' => 'perm:dashboard_turnover.visualizar'],
                ['chave' => 'dashboard-entrevista', 'label' => 'Dashboard da Entrevista', 'href' => '/admin/dashboard-entrevista-desligamento', 'regra' => 'perm:dashboard_entrevista_desligamento.visualizar'],
                ['chave' => 'entrevistas', 'label' => 'Entrevistas de Desligamento', 'href' => '/admin/entrevistas-desligamento', 'regra' => 'perm:entrevista_desligamento.visualizar'],
            ]],
            'recrutamento' => ['titulo' => 'Recrutamento e Seleção', 'abas' => [
                ['chave' => 'dashboard', 'label' => 'Dashboard', 'href' => '/admin/dashboard-recrutamento', 'regra' => 'perm:dashboard_recrutamento.visualizar'],
                ['chave' => 'solicitacoes', 'label' => 'Solicitações de Vaga', 'href' => '/admin/solicitacoes-vaga', 'regra' => 'pedidos_vaga'],
                ['chave' => 'vagas', 'label' => 'Vagas', 'href' => '/admin/vagas', 'regra' => 'aberto'],
                ['chave' => 'candidaturas', 'label' => 'Candidaturas', 'href' => '/admin/candidaturas', 'regra' => 'aberto'],
                ['chave' => 'pipeline', 'label' => 'Pipeline Kanban', 'href' => '/admin/pipeline', 'regra' => 'staff_ou:pipeline.visualizar'],
                ['chave' => 'indicacoes', 'label' => 'Indicações', 'href' => '/admin/indicacoes', 'regra' => 'staff_ou:indicacoes.visualizar'],
                ['chave' => 'webhooks', 'label' => 'Webhooks', 'href' => '/admin/recruitment-webhooks', 'regra' => 'staff_ou:recruitment_webhooks.visualizar'],
            ]],
        ];
    }

    /**
     * Abas visíveis do módulo para o usuário atual (caminhos relativos). A aba da página em que o usuário JÁ está (`$ativa`)
     * sempre aparece — ele chegou nela, então o backend a permitiu — e vem marcada como `ativo`.
     *
     * `acessivel` = a regra da aba libera o usuário (a aba ATIVA pode aparecer sem ser acessível — ex.: RH no detalhe de um
     * usuário, que a role admin/rh abre mas cuja lista é só admin; nesse caso o topo a mostra sem link).
     *
     * @return array<int,array{chave:string,label:string,href:string,ativo:bool,acessivel:bool}>
     */
    public function abas(string $modulo, ?string $ativa = null): array
    {
        $saida = [];
        foreach ((self::definicaoAbas()[$modulo]['abas'] ?? []) as $aba) {
            $acessivel = $this->visivel($aba['regra']);
            if ($aba['chave'] === $ativa || $acessivel) {
                $saida[] = ['chave' => $aba['chave'], 'label' => $aba['label'], 'href' => $aba['href'], 'ativo' => $aba['chave'] === $ativa, 'acessivel' => $acessivel];
            }
        }
        return $saida;
    }

    /** Título do módulo e primeira aba visível (entrada lógica), para o breadcrumb. */
    public function entradaDoModulo(string $modulo): ?string
    {
        foreach ($this->abas($modulo) as $aba) {
            return $aba['href'];
        }
        return null;
    }

    /**
     * Cards do usuário atual: só módulos com pelo menos um destino visível, apontando para o primeiro visível.
     * Caminhos relativos (`/admin/...`): quem renderiza aplica o `$base`.
     *
     * @return array<int,array{chave:string,titulo:string,descricao:string,icone:string,href:string}>
     */
    public function modulos(): array
    {
        $cards = [];
        foreach (self::definicao() as $modulo) {
            foreach ($modulo['itens'] as $item) {
                if ($this->visivel($item['regra'])) {
                    $cards[] = [
                        'chave' => $modulo['chave'],
                        'titulo' => $modulo['titulo'],
                        'descricao' => $modulo['descricao'],
                        'icone' => $modulo['icone'],
                        'href' => $item['href'],
                    ];
                    break;
                }
            }
        }
        return $cards;
    }

    /** Destino visível ao usuário? (mesma lógica da sidebar — ver o cabeçalho da classe) */
    public function visivel(string $regra): bool
    {
        $adminOuSupervisor = $this->role === 'admin' || $this->supervisor;
        $staff = $adminOuSupervisor || $this->role === 'rh';
        $perm = fn(string $codigo): bool => (bool)($this->temPermissao)($codigo);

        if ($regra === 'aberto') {
            return true;
        }
        if ($regra === 'admin_supervisor') {
            return $adminOuSupervisor;
        }
        if ($regra === 'staff') {
            return $staff;
        }
        if ($regra === 'pedidos_vaga') {
            return $staff || $perm('solicitacao_vaga.visualizar') || $perm('solicitacao_vaga.criar') || $perm('kanban_vagas.visualizar');
        }
        if (str_starts_with($regra, 'perm:')) {
            return $perm(substr($regra, 5));
        }
        if (str_starts_with($regra, 'perm_qualquer:')) {
            foreach (explode(',', substr($regra, 14)) as $codigo) {
                if ($perm(trim($codigo))) {
                    return true;
                }
            }
            return false;
        }
        if (str_starts_with($regra, 'staff_ou:')) {
            return $staff || $perm(substr($regra, 9));
        }
        return false; // regra desconhecida nunca libera nada
    }
}
