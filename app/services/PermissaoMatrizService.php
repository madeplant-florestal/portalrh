<?php

/**
 * Matriz horizontal de permissões (Etapa 3) — monta, a partir do catálogo REAL (`permissoes`) e da `PermissaoPerfilPolicy`, a
 * estrutura que as telas de cadastro/edição de usuários exibem: linhas = módulos, colunas = ações, células = permissões.
 *
 * Esta classe é só APRESENTAÇÃO + montagem de dados. Ela NÃO decide autorização: tudo que é "aplicável", "recomendado" ou
 * "incompatível" vem da política central; o backend (`validarSelecao`, `Authorization`) continua sendo a única barreira real.
 *
 * Identidade: a linha é o PREFIXO REAL do código (`avaliacao_experiencia` em `avaliacao_experiencia.avaliar`), a coluna é a AÇÃO
 * real (sufixo do código) e a célula carrega `id` + `codigo`. A coluna `permissoes.modulo` NÃO é usada como identidade (vários códigos
 * de prefixos diferentes compartilham `avaliacoes_desenvolvimento`; `dashboard_integracao_agregado.*` está em `integracao_colaborador`).
 * Rótulos e áreas abaixo são texto de interface — nunca identificador de segurança; um prefixo sem área cai em "Outros módulos"
 * (nenhuma permissão do catálogo some da matriz).
 */
final class PermissaoMatrizService
{
    /** Áreas de exibição (ordem fixa) → módulos (prefixo do código) na ordem em que aparecem. */
    private const AREAS = [
        'indicadores' => ['Indicadores e Dashboards', ['dashboard', 'indicadores_rh', 'dashboard_turnover', 'dashboard_recrutamento', 'dashboard_entrevista_desligamento', 'dashboard_integracao_agregado']],
        'pessoas' => ['Gestão de Pessoas', ['colaboradores', 'movimentacao_pessoal', 'beneficios', 'integracao_colaborador', 'pesquisa_experiencia', 'pesquisa_reacao_integracao', 'entrevista_desligamento', 'mensagens', 'comunicacoes']],
        'avaliacoes' => ['Avaliações e Desenvolvimento', ['avaliacao_experiencia', 'avaliacao_desempenho', 'feedback', 'pdi', 'avaliacoes']],
        'recrutamento' => ['Recrutamento e Seleção', ['solicitacao_vaga', 'kanban_vagas', 'vagas', 'candidaturas', 'pipeline', 'indicacoes', 'recruitment_webhooks']],
        'administracao' => ['Administração e Configurações', ['empresas', 'setores', 'cargos', 'manual']],
    ];

    private const ROTULO_MODULO = [
        'dashboard' => 'People Analytics', 'indicadores_rh' => 'Indicadores de RH', 'dashboard_turnover' => 'Turnover',
        'dashboard_recrutamento' => 'Dashboard de Recrutamento', 'dashboard_entrevista_desligamento' => 'Dashboard de Entrevista de Desligamento',
        'dashboard_integracao_agregado' => 'Dashboard agregado de Integração',
        'colaboradores' => 'Colaboradores', 'movimentacao_pessoal' => 'Movimentação de Pessoal', 'beneficios' => 'Benefícios',
        'integracao_colaborador' => 'Integração do Colaborador', 'pesquisa_experiencia' => 'Pesquisa de Experiência',
        'pesquisa_reacao_integracao' => 'Pesquisa de Reação (Integração)', 'entrevista_desligamento' => 'Entrevista de Desligamento',
        'mensagens' => 'Mensagens', 'comunicacoes' => 'Histórico de Comunicação',
        'avaliacao_experiencia' => 'Avaliação de Experiência', 'avaliacao_desempenho' => 'Avaliação de Desempenho', 'feedback' => 'Feedback',
        'pdi' => 'PDI', 'avaliacoes' => 'Avaliações (cadastro)',
        'solicitacao_vaga' => 'Solicitação de Vagas', 'kanban_vagas' => 'Kanban de Vagas', 'vagas' => 'Vagas', 'candidaturas' => 'Candidaturas',
        'pipeline' => 'Pipeline', 'indicacoes' => 'Indicações', 'recruitment_webhooks' => 'Webhooks de Recrutamento',
        'empresas' => 'Empresas', 'setores' => 'Setores', 'cargos' => 'Cargos', 'manual' => 'Manual',
    ];

    /** Ordem preferida das colunas; ações desconhecidas entram depois, na ordem do catálogo. */
    private const ORDEM_ACOES = ['visualizar', 'detalhes', 'criar', 'editar', 'excluir', 'publicar', 'avaliar', 'acompanhar', 'gerenciar', 'movimentar', 'aprovar_lider', 'assinar', 'resultados', 'gerenciar_pagamento', 'cancelar'];

    private const ROTULO_ACAO = [
        'visualizar' => 'Visualizar', 'detalhes' => 'Detalhes', 'criar' => 'Criar', 'editar' => 'Editar', 'excluir' => 'Excluir',
        'publicar' => 'Publicar', 'avaliar' => 'Avaliar', 'acompanhar' => 'Acompanhar', 'gerenciar' => 'Gerenciar',
        'movimentar' => 'Movimentar', 'aprovar_lider' => 'Aprovar líder', 'assinar' => 'Assinar', 'resultados' => 'Resultados',
        'gerenciar_pagamento' => 'Gerenciar pagamento', 'cancelar' => 'Cancelar',
    ];

    public const AREA_OUTROS = 'outros';

    /** Prefixo e ação REAIS de um código `modulo.acao` (a ação é tudo depois do primeiro ponto). */
    public static function separarCodigo(string $codigo): array
    {
        $pos = strpos($codigo, '.');
        return $pos === false ? [$codigo, ''] : [substr($codigo, 0, $pos), substr($codigo, $pos + 1)];
    }

    /**
     * Estrutura da matriz para o perfil `$role`.
     *
     * @param int[] $idsGravados  ids hoje gravados em `usuario_permissoes` (vazio no cadastro)
     * @param bool  $edicao       true na edição: o estado inicial é o GRAVADO (nunca o modelo)
     * @param int[]|null $selecaoExplicita  re-renderização do cadastro após erro: ids que o usuário tinha marcado (preserva a seleção)
     * @return array{
     *   perfil:string, global:bool, edicao:bool, colunas:array<string,string>, areas:array, perfis:array,
     *   total:int, selecionadas:int, legado:array
     * }
     */
    public static function construir(string $role, array $idsGravados = [], bool $edicao = false, ?array $rolesDisponiveis = null, ?array $selecaoExplicita = null): array
    {
        $role = PermissaoPerfilPolicy::normalizarRole($role);
        $catalogo = self::catalogoAtivo();
        $gravados = array_flip(array_map('intval', $idsGravados));
        $modeloAtual = array_flip(PermissaoPerfilPolicy::modeloRecomendado($role));

        $porModulo = [];
        $acoesVistas = [];
        foreach ($catalogo as $p) {
            [$prefixo, $acao] = self::separarCodigo((string)$p['codigo']);
            $porModulo[$prefixo][] = $p + ['acao' => $acao];
            $acoesVistas[$acao] = true;
        }
        $colunas = [];
        foreach (self::ORDEM_ACOES as $a) {
            if (isset($acoesVistas[$a])) {
                $colunas[$a] = self::ROTULO_ACAO[$a];
            }
        }
        foreach (array_keys($acoesVistas) as $a) {
            $colunas[$a] ??= ucfirst(str_replace('_', ' ', $a));
        }

        $legado = [];
        $areas = [];
        $usados = [];
        $explicita = $selecaoExplicita === null ? null : array_flip(array_map('intval', $selecaoExplicita));
        $montarLinha = static function (string $prefixo) use (&$porModulo, $role, $gravados, $modeloAtual, $edicao, $explicita, &$legado): array {
            $celulas = [];
            $modulosColuna = [];
            foreach ($porModulo[$prefixo] as $p) {
                $codigo = (string)$p['codigo'];
                $id = (int)$p['id'];
                $classe = PermissaoPerfilPolicy::classificar($role, $codigo);
                $gravada = isset($gravados[$id]);
                $aplicavel = PermissaoPerfilPolicy::podeReceberPermissao($role, $codigo, $gravada);
                $marcada = $explicita !== null ? isset($explicita[$id]) : ($edicao ? $gravada : isset($modeloAtual[$codigo]));
                if ($gravada && $classe === PermissaoPerfilPolicy::INCOMPATIVEL) {
                    $legado[] = $codigo;
                }
                $modulosColuna[(string)$p['modulo']] = true;
                $celulas[$p['acao']] = [
                    'id' => $id, 'codigo' => $codigo, 'nome' => (string)$p['nome'], 'acao' => $p['acao'],
                    'classe' => $classe, 'aplicavel' => $aplicavel, 'gravada' => $gravada, 'marcada' => $marcada && ($aplicavel || $gravada),
                    // incompatível mas gravada: aparece marcada ("armazenada, sem efeito") para o administrador poder DESMARCAR de forma explícita.
                    'legado_incompativel' => $gravada && $classe === PermissaoPerfilPolicy::INCOMPATIVEL,
                    'recomendada' => isset($modeloAtual[$codigo]),
                ];
            }
            return ['modulo' => $prefixo, 'modulo_catalogo' => array_keys($modulosColuna), 'celulas' => $celulas];
        };

        foreach (self::AREAS as $chave => [$rotuloArea, $modulos]) {
            $linhas = [];
            foreach ($modulos as $prefixo) {
                if (isset($porModulo[$prefixo])) {
                    $linha = $montarLinha($prefixo);
                    $linha['rotulo'] = self::ROTULO_MODULO[$prefixo] ?? ucfirst(str_replace('_', ' ', $prefixo));
                    $linhas[] = $linha;
                    $usados[$prefixo] = true;
                }
            }
            if ($linhas !== []) {
                $areas[] = ['chave' => $chave, 'rotulo' => $rotuloArea, 'linhas' => $linhas];
            }
        }
        $sobras = [];
        foreach (array_keys($porModulo) as $prefixo) {
            if (!isset($usados[$prefixo])) {
                $linha = $montarLinha($prefixo);
                $linha['rotulo'] = self::ROTULO_MODULO[$prefixo] ?? ucfirst(str_replace('_', ' ', $prefixo));
                $sobras[] = $linha;
            }
        }
        if ($sobras !== []) {
            $areas[] = ['chave' => self::AREA_OUTROS, 'rotulo' => 'Outros módulos', 'linhas' => $sobras];
        }

        $total = 0;
        $selecionadas = 0;
        foreach ($areas as $a) {
            foreach ($a['linhas'] as $l) {
                foreach ($l['celulas'] as $c) {
                    $total++;
                    $selecionadas += $c['marcada'] ? 1 : 0;
                }
            }
        }

        return [
            'perfil' => $role,
            'global' => PermissaoPerfilPolicy::perfilGlobal($role),
            'edicao' => $edicao,
            'colunas' => $colunas,
            'areas' => $areas,
            'perfis' => self::perfisParaJs($catalogo, $rolesDisponiveis),
            'total' => $total,
            'selecionadas' => $selecionadas,
            'legado' => array_values(array_unique($legado)),
        ];
    }

    /**
     * Mapa por perfil que o JavaScript usa para reaplicar modelo e limites SEM replicar regra: tudo calculado AQUI pela política.
     *   aplicaveis — ids que o perfil pode receber (checkbox habilitada);
     *   lote       — ids que "Marcar todas/grupo/linha" pode marcar (só permissões PLENAS: as de transição do viewer exigem clique
     *                individual; perfis globais não usam permissões individuais);
     *   transicao  — ids de permissões de transição do viewer (marcador visual);
     *   modelo     — ids do modelo recomendado.
     *
     * @param array<int,array<string,mixed>> $catalogo
     * @return array<string,array{global:bool,rotulo:string,aplicaveis:int[],lote:int[],transicao:int[],modelo:int[]}>
     */
    private static function perfisParaJs(array $catalogo, ?array $rolesDisponiveis): array
    {
        $rotulos = ['admin' => 'Admin', 'rh' => 'RH', 'gestor' => 'Gestor', 'viewer' => 'Leitor'];
        $saida = [];
        foreach ($rolesDisponiveis ?? PermissaoPerfilPolicy::perfisAtivos() as $role) {
            $global = PermissaoPerfilPolicy::perfilGlobal($role);
            $modelo = array_flip(PermissaoPerfilPolicy::modeloRecomendado($role));
            $aplicaveis = [];
            $lote = [];
            $idsModelo = [];
            $transicao = [];
            foreach ($catalogo as $p) {
                $codigo = (string)$p['codigo'];
                $id = (int)$p['id'];
                if (PermissaoPerfilPolicy::podeReceberPermissao($role, $codigo, false)) {
                    $aplicaveis[] = $id;
                    $classe = PermissaoPerfilPolicy::classificar($role, $codigo);
                    if (!$global && $classe === PermissaoPerfilPolicy::PERMITIDA) {
                        $lote[] = $id;
                    }
                    if (!$global && $classe === PermissaoPerfilPolicy::TRANSITORIA_GESTOR) {
                        $transicao[] = $id;
                    }
                }
                if (isset($modelo[$codigo])) {
                    $idsModelo[] = $id;
                }
            }
            $saida[$role] = [
                'global' => $global,
                'rotulo' => $rotulos[$role] ?? strtoupper($role),
                'aplicaveis' => $global ? [] : $aplicaveis, // global: o acesso vem do perfil; a matriz não oferece seleção
                'lote' => $lote,
                'transicao' => $transicao,
                'modelo' => $idsModelo,
            ];
        }
        return $saida;
    }

    /** @return array<int,array{id:int|string,codigo:string,modulo:string,nome:string}> */
    private static function catalogoAtivo(): array
    {
        return Database::conn()->query('SELECT id, codigo, modulo, nome FROM permissoes WHERE ativo = 1 ORDER BY ordem ASC, id ASC')->fetchAll(PDO::FETCH_ASSOC);
    }
}
