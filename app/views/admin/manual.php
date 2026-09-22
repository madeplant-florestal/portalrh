<?php
/**
 * Manual de Uso — documentação operacional do Portal RH (Wiki + FAQ), derivada do comportamento REAL do sistema (rotas,
 * controllers, services, permissões e campos — nunca do que "deveria" existir). Cada funcionalidade é um `<details>` com
 * âncora estável (`id`) para permitir link direto no futuro (`/admin/manual#pdi`, por exemplo), sem exigir botões de ajuda
 * nas telas agora. A busca é local (assets/manual.js): filtra os `<details>` pelo texto agregado de cada um
 * (`data-manual-texto`), sem backend nem indexador. Nenhuma regra de negócio é documentada por suposição — o que não está
 * claro no código fica de fora, nunca inventado.
 */
require_once APP_PATH . '/views/partials/ui-shell.php';
ui_titulo_pagina('Manual de Uso');
ui_script_pagina('manual.js');
$app = Config::app();
$versao = trim((string)($app['version'] ?? ''));
$releaseDate = trim((string)($app['release_date'] ?? ''));

/** Converte `` `código` `` em <code>, escapando o resto. Único ponto de HTML confiável nos textos abaixo. */
function manual_texto(string $t): string
{
    $t = Security::e($t);
    return preg_replace('/`([^`]+)`/', '<code class="rounded bg-surface-secondary px-1.5 py-0.5 text-[13px] font-mono text-text-primary">$1</code>', $t);
}

/** Só para o índice de busca (sem tags, sem acento — a comparação em JS já normaliza, mas simplifica o atributo). */
function manual_texto_puro(string $t): string
{
    return preg_replace('/`([^`]*)`/', '$1', $t);
}

function manual_bloco(string $rotulo, $conteudo): string
{
    if ($conteudo === null || $conteudo === '' || $conteudo === []) {
        return '';
    }
    $corpo = '';
    if (is_string($conteudo)) {
        $corpo = '<p>' . manual_texto($conteudo) . '</p>';
    } elseif (isset($conteudo[0]) && is_string($conteudo[0])) {
        // lista simples de passos/observações
        $corpo = '<ul class="list-disc space-y-1 pl-5">';
        foreach ($conteudo as $linha) {
            $corpo .= '<li>' . manual_texto($linha) . '</li>';
        }
        $corpo .= '</ul>';
    } elseif (isset($conteudo[0]['p'])) {
        // FAQ: pergunta/resposta
        $corpo = '<dl class="space-y-3">';
        foreach ($conteudo as $qa) {
            $corpo .= '<div><dt class="font-semibold text-text-primary">' . manual_texto($qa['p']) . '</dt><dd class="mt-0.5">' . manual_texto($qa['r']) . '</dd></div>';
        }
        $corpo .= '</dl>';
    } elseif (isset($conteudo[0]['campo'])) {
        // tabela de campos do formulário
        $corpo = '<div class="overflow-x-auto"><table class="min-w-full text-left text-sm"><thead><tr class="border-b border-border text-text-secondary">'
            . '<th class="py-1.5 pr-3 font-semibold">Campo</th><th class="py-1.5 pr-3 font-semibold">O que informar</th><th class="py-1.5 pr-3 font-semibold">Obrigatório</th><th class="py-1.5 font-semibold">Observações</th></tr></thead><tbody>';
        foreach ($conteudo as $c) {
            $corpo .= '<tr class="border-b border-border align-top"><td class="py-1.5 pr-3 font-medium text-text-primary">' . manual_texto($c['campo']) . '</td>'
                . '<td class="py-1.5 pr-3">' . manual_texto($c['informar']) . '</td>'
                . '<td class="py-1.5 pr-3">' . (!empty($c['obrigatorio']) ? '<span class="font-semibold text-danger">Sim</span>' : 'Não') . '</td>'
                . '<td class="py-1.5">' . manual_texto($c['obs'] ?? '') . '</td></tr>';
        }
        $corpo .= '</tbody></table></div>';
    } elseif (isset($conteudo[0]['nome'])) {
        // ações/status: nome em destaque + explicação
        $corpo = '<ul class="space-y-2">';
        foreach ($conteudo as $x) {
            $corpo .= '<li><span class="font-semibold text-text-primary">' . manual_texto($x['nome']) . '</span> — ' . manual_texto($x['explicacao']) . '</li>';
        }
        $corpo .= '</ul>';
    }
    return '<div><h4 class="text-ds-caption font-bold uppercase tracking-wide text-text-muted">' . Security::e($rotulo) . '</h4><div class="mt-1.5 text-text-secondary">' . $corpo . '</div></div>';
}

/**
 * @param array{id:string,titulo:string,oQue?:string,paraQue?:string,quem?:string,acesso?:string,comoUsar?:array|string,
 *   campos?:array,acoes?:array,depois?:string,status?:array,atencoes?:array,faq?:array} $it
 */
function manual_item(array $it): string
{
    $blocos = [
        'O que é' => $it['oQue'] ?? null,
        'Para que serve' => $it['paraQue'] ?? null,
        'Quem pode utilizar' => $it['quem'] ?? null,
        'Como acessar' => $it['acesso'] ?? null,
        'Como utilizar' => $it['comoUsar'] ?? null,
        'Campos' => $it['campos'] ?? null,
        'Ações' => $it['acoes'] ?? null,
        'O que acontece depois' => $it['depois'] ?? null,
        'Status' => $it['status'] ?? null,
        'Atenções' => $it['atencoes'] ?? null,
        'Perguntas frequentes' => $it['faq'] ?? null,
    ];
    $textoBusca = $it['titulo'];
    foreach ($blocos as $c) {
        if (is_string($c)) {
            $textoBusca .= ' ' . $c;
        } elseif (is_array($c)) {
            $textoBusca .= ' ' . implode(' ', array_map(static fn($x): string => is_array($x) ? implode(' ', $x) : (string)$x, $c));
        }
    }
    $corpo = '';
    foreach ($blocos as $rotulo => $conteudo) {
        $corpo .= manual_bloco($rotulo, $conteudo);
    }
    return '<details id="' . Security::e($it['id']) . '" data-manual-item data-manual-texto="' . Security::e(mb_strtolower(manual_texto_puro($textoBusca))) . '" class="rounded-ds-lg border border-border bg-surface">'
        . '<summary class="flex cursor-pointer list-none items-center justify-between gap-3 px-4 py-3 text-ds-label font-semibold text-text-primary marker:content-none [&::-webkit-details-marker]:hidden">'
        . '<span>' . Security::e($it['titulo']) . '</span>'
        . '<svg class="h-4 w-4 shrink-0 text-text-muted transition-transform" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M9 18l6-6-6-6"/></svg>'
        . '</summary>'
        . '<div class="space-y-4 border-t border-border px-4 py-4 text-sm">' . $corpo . '</div>'
        . '</details>';
}

/** @param array{id:string,titulo:string,descricao:string,itens:array} $secao */
function manual_secao(array $secao): string
{
    $html = '<section id="' . Security::e($secao['id']) . '" data-manual-secao class="scroll-mt-24 space-y-3">'
        . '<div><h2 class="text-ds-h2 font-extrabold text-text-primary">' . Security::e($secao['titulo']) . '</h2>'
        . '<p class="mt-0.5 text-sm text-text-secondary">' . manual_texto($secao['descricao']) . '</p></div>'
        . '<div class="space-y-2">';
    foreach ($secao['itens'] as $it) {
        $html .= manual_item($it);
    }
    return $html . '</div></section>';
}

// ============================================================================================================================
// CONTEÚDO — derivado do código real (rotas/controllers/services/views). Nada aqui é aspiracional; o que não está claro no
// sistema (ex.: regras internas de sincronização do METADADOS) fica fora, em vez de ser suposto.
// ============================================================================================================================
$secoes = [
    [
        'id' => 'central', 'titulo' => 'Central do Portal', 'descricao' => 'A tela inicial depois do login — o ponto de partida para todos os módulos.',
        'itens' => [
            [
                'id' => 'central-portal', 'titulo' => 'Central do Portal RH',
                'oQue' => 'A página inicial do Portal (`/admin`), com um card por área do sistema.',
                'paraQue' => 'Ponto único de entrada: a partir dela você chega a qualquer módulo que tiver acesso, sem precisar decorar endereços.',
                'quem' => 'Qualquer pessoa autenticada. Os cards que aparecem dependem só do que cada usuário pode acessar — ninguém vê um card que levaria a uma tela bloqueada.',
                'acesso' => 'É a tela que abre automaticamente após o login, e o logotipo no topo de qualquer tela sempre volta para ela.',
                'comoUsar' => ['Clique em um card para entrar no módulo.', 'Se nenhum card aparecer, você está autenticado mas sem nenhum acesso liberado — fale com um administrador.'],
                'atencoes' => ['Um card sempre leva ao primeiro destino que você realmente pode abrir dentro daquela área; ele nunca aponta para uma tela que devolveria acesso negado.'],
                'faq' => [
                    ['p' => 'Por que não vejo determinado módulo na Central?', 'r' => 'Porque nenhuma das telas daquele módulo está liberada para o seu usuário. Peça a um administrador para revisar seu perfil e suas permissões em `Usuários e Acessos`.'],
                    ['p' => 'Como eu volto para a Central de qualquer tela?', 'r' => 'Clique no logotipo no topo da página, ou no primeiro item do caminho mostrado logo abaixo dele (`Portal RH`).'],
                ],
            ],
        ],
    ],
    [
        'id' => 'indicadores', 'titulo' => 'Indicadores e Dashboards', 'descricao' => 'Duas telas gerenciais independentes, com fontes de dados e escopos diferentes.',
        'itens' => [
            [
                'id' => 'people-analytics', 'titulo' => 'People Analytics',
                'oQue' => 'O dashboard gerencial principal do Portal (`/admin/dashboard`), com headcount, turnover, admissões, desligamentos e indicadores de recrutamento num único painel.',
                'paraQue' => 'Visão executiva rápida da força de trabalho e do funil de contratação, sem precisar abrir cada módulo separadamente.',
                'quem' => 'Quem tiver a permissão de visualizar o Dashboard. Nem todo perfil administrativo tem essa permissão por padrão.',
                'acesso' => '`Central do Portal → Indicadores de RH → People Analytics`.',
                'comoUsar' => 'Escolha o Período (últimos 12/6 meses, ano atual ou ano anterior) e, opcionalmente, filtre por Empresa e Setor. Os filtros usam os códigos oficiais do METADADOS.',
                'campos' => [
                    ['campo' => 'Período', 'informar' => 'Uma das quatro janelas de tempo fixas', 'obrigatorio' => true, 'obs' => 'Define o intervalo de admissões/desligamentos e o cálculo de turnover.'],
                    ['campo' => 'Empresa', 'informar' => 'Empresa oficial (opcional)', 'obrigatorio' => false, 'obs' => 'Vagas Abertas/Fechadas não respeitam este filtro — o módulo de Recrutamento ainda não tem essa dimensão.'],
                    ['campo' => 'Setor', 'informar' => 'Setor oficial (opcional)', 'obrigatorio' => false, 'obs' => ''],
                ],
                'depois' => 'O painel é montado por CONTRATO, não por pessoa — um colaborador readmitido conta como um novo contrato, então os totais podem não bater com "quantidade de pessoas".',
                'atencoes' => ['"Sem base" ou "Dados insuficientes" significam que não há dados suficientes para calcular aquele indicador — nunca interprete como zero.', 'Turnover Geral usa o headcount médio do período (não o headcount do dia).'],
                'faq' => [['p' => 'Por que um colaborador aparece duas vezes nos números?', 'r' => 'Porque a análise conta contratos, não pessoas. Uma readmissão gera um segundo contrato.']],
            ],
            [
                'id' => 'indicadores-rh', 'titulo' => 'Indicadores de RH',
                'oQue' => 'Um dashboard analítico próprio (`/admin/indicadores-rh`), alimentado só pelo espelho local do METADADOS, com evolução mensal de turnover, turnover precoce, distribuição do quadro por dimensão e motivos de rescisão.',
                'paraQue' => 'Análises mais profundas de turnover e composição do quadro do que o People Analytics oferece — é um painel diferente, não uma versão resumida do outro.',
                'quem' => 'Aberto a qualquer usuário autenticado com perfil administrativo — não exige nenhuma permissão individual própria.',
                'acesso' => '`Central do Portal → Indicadores de RH → Indicadores de RH`.',
                'comoUsar' => 'Escolha Período, Empresa, Unidade, Cargo, Setor e Centro de Custo. No bloco "Quadro atual e turnover por dimensão", escolha a dimensão de agrupamento (Empresa, Unidade, Cargo, Setor ou Centro de custo).',
                'campos' => [
                    ['campo' => 'Dimensão', 'informar' => 'Como agrupar o quadro/turnover no bloco de distribuição', 'obrigatorio' => false, 'obs' => 'Não altera os outros gráficos da tela, só esse bloco.'],
                ],
                'acoes' => [['nome' => 'Atualizar dados', 'explicacao' => 'Dispara uma sincronização sob demanda com o METADADOS. Só aparece para quem tem perfil para sincronizar, e fica desabilitado se o ambiente não tiver a sincronização configurada.']],
                'depois' => 'Depois de clicar em Atualizar dados, o botão mostra "Atualizando…" e consulta o andamento a cada poucos segundos; ao concluir, a página recarrega sozinha com os dados atualizados.',
                'atencoes' => ['"Última atualização" vem do histórico oficial de sincronizações — nunca da data de edição de um colaborador.', 'Este painel é independente do People Analytics: mesma fonte de dados, cálculos e propósitos diferentes.'],
                'faq' => [['p' => 'Qual a diferença entre People Analytics e Indicadores de RH?', 'r' => 'People Analytics é a visão executiva rápida (headcount, turnover geral, recrutamento). Indicadores de RH é a análise mais detalhada de turnover e composição do quadro, com o botão de sincronizar os dados do METADADOS. São duas telas com serviços de cálculo próprios, não a mesma coisa em lugares diferentes.'],
                    ['p' => 'Como sei se os dados estão atualizados?', 'r' => 'Veja "Última atualização" no topo da tela — é a data da última sincronização bem-sucedida com o METADADOS.']],
            ],
        ],
    ],
    [
        'id' => 'recrutamento', 'titulo' => 'Recrutamento e Seleção', 'descricao' => 'Publicação de vagas, recebimento e acompanhamento de candidaturas até a contratação.',
        'itens' => [
            [
                'id' => 'dashboard-recrutamento', 'titulo' => 'Dashboard de Recrutamento',
                'oQue' => 'Painel com volume, velocidade, conversão e qualidade do processo seletivo.',
                'quem' => 'Quem tiver a permissão de visualizar este dashboard.',
                'acesso' => '`Central do Portal → Recrutamento e Seleção → Dashboard`.',
                'comoUsar' => 'Filtre por período, empresa e vaga. Indicadores de Efetivação/Turnover/Desligamento usam o quadro oficial do METADADOS e não respeitam o filtro de Empresa/Vaga.',
            ],
            [
                'id' => 'vagas', 'titulo' => 'Vagas',
                'oQue' => 'Cadastro das vagas exibidas na página pública de vagas.',
                'paraQue' => 'Publicar (ou manter em rascunho) as oportunidades que os candidatos externos veem e se candidatam.',
                'quem' => 'Qualquer perfil administrativo pode visualizar; a criação/edição segue o gate da tela.',
                'acesso' => '`Central do Portal → Recrutamento e Seleção → Vagas`.',
                'depois' => 'Uma vaga marcada como ativa passa a aparecer na página pública `/vagas` e recebe candidaturas.',
            ],
            [
                'id' => 'candidaturas', 'titulo' => 'Candidaturas',
                'oQue' => 'Lista de todos os candidatos que se inscreveram em alguma vaga pública, com filtros e o detalhe de cada um.',
                'paraQue' => 'Acompanhar quem se candidatou, baixar currículo e abrir o detalhe (etapa atual, indicação, Pesquisa de Experiência quando respondida).',
                'quem' => 'Aberto a qualquer perfil administrativo.',
                'acesso' => '`Central do Portal → Recrutamento e Seleção → Candidaturas`.',
                'atencoes' => ['A Pesquisa de Experiência do candidato (satisfação com o processo seletivo) aparece dentro do detalhe da candidatura só para quem tem a permissão de visualizá-la — é uma avaliação preenchida pelo próprio candidato, por link público, não pelo RH.'],
            ],
            [
                'id' => 'pipeline', 'titulo' => 'Pipeline Kanban',
                'oQue' => 'Quadro Kanban com as candidaturas de uma vaga, organizadas por etapa do processo seletivo: Nova Inscrição, Triagem RH, Entrevista RH, Entrevista Gestor, Testes, Aprovado, Admissão, Banco de Talentos e Reprovado.',
                'paraQue' => 'Mover um candidato de etapa arrastando o card, sem precisar abrir o detalhe da candidatura.',
                'quem' => 'Perfis administrativos (admin/RH/supervisor) ou quem tiver a permissão individual do Pipeline.',
                'acesso' => '`Central do Portal → Recrutamento e Seleção → Pipeline Kanban` (escolha a vaga).',
                'comoUsar' => 'Arraste o card do candidato para a coluna da nova etapa. A mudança é salva na hora.',
                'atencoes' => ['O Pipeline (etapas do candidato) e o Kanban de Solicitações de Vaga (situação operacional da vaga) são quadros completamente separados, com tabelas e regras próprias — mover um não afeta o outro.'],
                'faq' => [['p' => 'Por que não consigo arrastar um card?', 'r' => 'Você pode estar sem a permissão de gerenciar o Pipeline, ou a etapa de destino pode exigir um campo que ainda falta preencher no candidato.']],
            ],
            [
                'id' => 'indicacoes', 'titulo' => 'Indicações',
                'oQue' => 'Controle de candidatos indicados por colaboradores, incluindo o registro e pagamento de bonificação por indicação.',
                'quem' => 'Perfis administrativos ou quem tiver a permissão individual de Indicações.',
                'acesso' => '`Central do Portal → Recrutamento e Seleção → Indicações`.',
                'status' => [['nome' => 'Pendente', 'explicacao' => 'Indicação registrada, pagamento ainda não marcado.'], ['nome' => 'Pago', 'explicacao' => 'Pagamento registrado com data e método.']],
            ],
            [
                'id' => 'webhooks', 'titulo' => 'Webhooks de Recrutamento',
                'oQue' => 'Configuração e reprocessamento de notificações automáticas disparadas por eventos do recrutamento.',
                'quem' => 'Perfis administrativos ou quem tiver a permissão individual de Webhooks — é uma tela técnica, não operacional do dia a dia.',
                'acesso' => '`Central do Portal → Recrutamento e Seleção → Webhooks`.',
            ],
        ],
    ],
    [
        'id' => 'solicitacoes-vaga', 'titulo' => 'Solicitações de Vaga', 'descricao' => 'O pedido formal de abertura de vaga, desde a solicitação do gestor até a aprovação do RH.',
        'itens' => [
            [
                'id' => 'solicitacao-vaga-visao-geral', 'titulo' => 'Solicitações de Vaga — visão geral',
                'oQue' => 'O formulário que um gestor preenche para pedir a abertura de uma vaga, com aprovação em duas etapas (líder imediato e RH) antes de a vaga poder ser publicada.',
                'paraQue' => 'Formalizar e documentar o motivo, o perfil e as condições de uma contratação antes de ela existir como vaga pública.',
                'quem' => 'Quem tem a permissão `pode_solicitar_vaga` no cadastro do usuário cria solicitações; perfis administrativos (admin/RH/supervisor) veem e aprovam todas.',
                'acesso' => '`Central do Portal → Solicitações de Vaga`.',
                'status' => [
                    ['nome' => 'Pendente de líder imediato', 'explicacao' => 'Aguardando a aprovação de quem está indicado como aprovador da solicitação.'],
                    ['nome' => 'Pendente de RH', 'explicacao' => 'Aprovada pelo líder, aguardando a aprovação do RH.'],
                    ['nome' => 'Reprovada pelo líder', 'explicacao' => 'O aprovador recusou o pedido.'],
                    ['nome' => 'Reprovada pelo RH', 'explicacao' => 'O RH recusou o pedido mesmo após a aprovação do líder.'],
                    ['nome' => 'Aprovada', 'explicacao' => 'As duas aprovações foram concedidas — o RH já pode gerar a vaga pública.'],
                    ['nome' => 'Concluída', 'explicacao' => 'O ciclo da solicitação foi encerrado (por exemplo, depois de a contratação se efetivar).'],
                ],
                'atencoes' => ['O aprovador da solicitação (Aprovador de Solicitação de Vaga) é um campo próprio do cadastro do usuário, diferente do Gestor Imediato — veja a seção Usuários e Acessos.'],
            ],
            [
                'id' => 'solicitacao-vaga-criacao', 'titulo' => 'Criar uma Solicitação de Vaga',
                'paraQue' => 'Registrar o pedido de vaga com todas as informações que o RH precisa para abrir o processo seletivo.',
                'acesso' => '`Central do Portal → Solicitações de Vaga → Nova solicitação`.',
                'comoUsar' => 'O formulário tem sete seções. Preencha da 1 à 5; as seções 6 e 7 são preenchidas pelo sistema/RH conforme o fluxo avança.',
                'campos' => [
                    ['campo' => '1. Identificação da vaga', 'informar' => 'Solicitante, Área/Setor, Quantidade de vagas, Cargo, Gestor solicitante', 'obrigatorio' => true, 'obs' => 'Para vagas de Operador de Máquinas Florestais, há um campo específico para a máquina que a pessoa vai operar.'],
                    ['campo' => 'Tipo de vaga', 'informar' => 'Nova posição, Substituição, Aumento de quadro ou Projeto temporário', 'obrigatorio' => true, 'obs' => 'Em Substituição, informe o nome de quem saiu, a data e o motivo da saída.'],
                    ['campo' => '2. Informações contratuais', 'informar' => 'Tipo de contratação, Salário previsto, Centro de custo, se está previsto no orçamento anual, benefícios aplicáveis', 'obrigatorio' => true, 'obs' => 'Se não estiver previsto no orçamento, é preciso justificar.'],
                    ['campo' => '3. Jornada e escala', 'informar' => 'Jornada de trabalho, escala (se houver) e turno', 'obrigatorio' => true, 'obs' => ''],
                    ['campo' => '4. Perfil da vaga', 'informar' => 'Escolaridade mínima, formação, experiência necessária, entregas esperadas da função, competências técnicas e comportamentais, nível de responsabilidade', 'obrigatorio' => true, 'obs' => ''],
                    ['campo' => '5. Prazos e prioridade', 'informar' => 'Data prevista para início, data limite desejada, urgência', 'obrigatorio' => true, 'obs' => ''],
                ],
                'acoes' => [['nome' => 'Enviar solicitação', 'explicacao' => 'Grava o pedido e inicia o fluxo de aprovação pelo líder imediato.']],
                'depois' => 'A solicitação entra como "Pendente de líder imediato" e aparece para o aprovador definido no cadastro do solicitante.',
            ],
            [
                'id' => 'solicitacao-vaga-aprovacao', 'titulo' => 'Aprovações e Controle Interno RH',
                'oQue' => 'As seções 6 e 7 do detalhe de uma solicitação: o registro das duas aprovações (líder imediato e RH) e, depois de aprovada, o acompanhamento da contratação pelo RH.',
                'quem' => 'A aprovação de líder é feita por quem está definido como aprovador do solicitante; a aprovação de RH e o Controle Interno RH são só para perfil de RH.',
                'comoUsar' => 'Na seção 6, o aprovador da vez vê os botões Aprovar/Reprovar com um campo de observação opcional. Depois das duas aprovações, a seção 7 fica disponível ao RH.',
                'campos' => [
                    ['campo' => 'Nome do contratado', 'informar' => 'Colaborador que ocupou a vaga (preenchido pelo RH depois da admissão)', 'obrigatorio' => false, 'obs' => ''],
                    ['campo' => 'Data de admissão', 'informar' => 'Data em que a pessoa foi admitida', 'obrigatorio' => false, 'obs' => ''],
                    ['campo' => 'Avaliação após 90 dias', 'informar' => 'Atendeu plenamente, atendeu parcialmente ou não atendeu', 'obrigatorio' => false, 'obs' => 'É o acompanhamento do RH sobre o período inicial do contratado — não é um formulário próprio, é este campo, preenchido aqui mesmo, no Controle Interno RH.'],
                    ['campo' => 'Observações', 'informar' => 'Anotações internas do RH sobre a contratação', 'obrigatorio' => false, 'obs' => ''],
                ],
                'depois' => 'O "Tempo para fechamento da vaga" e as contagens de "Avaliação após 90 dias" alimentam o People Analytics (bloco Experiência).',
                'atencoes' => ['Só usuários com perfil de RH editam a seção 7, mesmo depois das duas aprovações.'],
            ],
            [
                'id' => 'solicitacao-vaga-kanban', 'titulo' => 'Kanban de Solicitações de Vaga',
                'oQue' => 'Um quadro visual com a situação operacional de cada solicitação — separado do status de aprovação (pendente/aprovada/reprovada).',
                'paraQue' => 'Acompanhar o andamento prático da vaga (por exemplo, em divulgação, em entrevistas) independentemente de já estar aprovada ou não.',
                'acesso' => '`Central do Portal → Solicitações de Vaga → Kanban`.',
                'atencoes' => ['Este Kanban é uma máquina de estados própria (tabela e histórico próprios) — mover um cartão aqui não altera o status de aprovação da solicitação, e vice-versa.'],
            ],
            [
                'id' => 'solicitacao-vaga-vinculo-vaga', 'titulo' => 'Gerar a vaga a partir da solicitação',
                'oQue' => 'Depois que uma solicitação é aprovada, o RH pode gerar a vaga pública correspondente a partir dela.',
                'quem' => 'Perfil de RH, e só quando a solicitação está aprovada e ainda não tem vaga vinculada.',
                'acoes' => [['nome' => 'Gerar vaga', 'explicacao' => 'Cria a vaga em rascunho a partir dos dados da solicitação; o RH ainda precisa publicá-la para que apareça no site.']],
                'depois' => 'O detalhe da solicitação passa a mostrar um link para a vaga gerada, com a situação dela (rascunho ou já publicada).',
            ],
        ],
    ],
    [
        'id' => 'colaboradores', 'titulo' => 'Colaboradores', 'descricao' => 'Consulta dos contratos oficiais sincronizados do METADADOS e as ações de RH sobre cada um.',
        'itens' => [
            [
                'id' => 'colaboradores-lista', 'titulo' => 'Lista de Colaboradores',
                'oQue' => 'A lista de contratos oficiais sincronizados do METADADOS, com filtros por Empresa, Unidade, Setor e Cargo.',
                'paraQue' => 'Localizar um colaborador para editar seus Dados RH, ajustar Acesso e liderança ou ver suas Avaliações de Desempenho.',
                'quem' => 'Restrito a admin, RH e supervisor — mesmo quem tem a permissão individual de visualizar colaboradores não abre esta lista se não tiver um desses perfis, porque ela expõe salário.',
                'acesso' => '`Central do Portal → Colaboradores → Colaboradores`.',
                'atencoes' => ['Cada linha da lista é um CONTRATO, não uma pessoa: um colaborador readmitido aparece como um novo registro. Não assuma que cada linha é única por pessoa.', 'Os dados vêm do METADADOS (só leitura) — não é possível editar nome, cargo oficial ou datas de admissão/desligamento por aqui.'],
                'faq' => [['p' => 'Por que o mesmo colaborador aparece mais de uma vez?', 'r' => 'Porque cada linha representa um contrato oficial, e uma pessoa pode ter mais de um contrato ao longo do tempo (por exemplo, numa readmissão).']],
            ],
            [
                'id' => 'colaboradores-dados-rh', 'titulo' => 'Dados RH',
                'oQue' => 'Tela de edição das informações internas de RH associadas a um contrato — o que não vem do METADADOS.',
                'quem' => 'admin e RH.',
                'acesso' => '`Colaboradores → (escolha um colaborador) → Editar dados RH`.',
            ],
            [
                'id' => 'colaboradores-acesso', 'titulo' => 'Acesso e liderança',
                'oQue' => 'Tela com o vínculo do colaborador a um usuário do sistema e sua posição na hierarquia (gestor/liderados).',
                'quem' => 'admin e RH.',
                'acesso' => '`Colaboradores → (escolha um colaborador) → Acesso e liderança`.',
            ],
        ],
    ],
    [
        'id' => 'usuarios', 'titulo' => 'Usuários e Acessos', 'descricao' => 'Contas de acesso ao Portal, seus perfis, permissões e vínculos.',
        'itens' => [
            [
                'id' => 'usuarios-lista', 'titulo' => 'Usuários e Acessos',
                'oQue' => 'A lista e o detalhe das contas administrativas do Portal.',
                'quem' => 'admin e supervisor abrem a lista; o detalhe de um usuário também é visível ao RH.',
                'acesso' => '`Central do Portal → Usuários e Acessos`.',
                'campos' => [
                    ['campo' => 'Perfil (role)', 'informar' => 'admin, rh ou viewer', 'obrigatorio' => true, 'obs' => 'Define o conjunto de telas que o gate de role libera antes de qualquer permissão individual.'],
                    ['campo' => 'Status', 'informar' => 'Ativo ou inativo', 'obrigatorio' => true, 'obs' => 'Usuário inativo não consegue entrar no Portal.'],
                    ['campo' => 'Vínculo com o METADADOS', 'informar' => 'Contrato oficial associado a este usuário (opcional)', 'obrigatorio' => false, 'obs' => 'Localizado por busca; nunca por CPF exibido na tela.'],
                    ['campo' => 'Contexto organizacional', 'informar' => 'Cargo e setor(es) do usuário, usados para herdar dados em outras telas', 'obrigatorio' => false, 'obs' => ''],
                    ['campo' => 'Permissões individuais', 'informar' => 'Marcações específicas por funcionalidade (ex.: `pdi.visualizar`, `mensagens.criar`)', 'obrigatorio' => false, 'obs' => 'Somam-se ao que o perfil (role) já libera; nunca retiram acesso.'],
                    ['campo' => 'Pode solicitar vaga', 'informar' => 'Habilita o usuário a criar Solicitações de Vaga', 'obrigatorio' => false, 'obs' => 'Controlado junto com o campo Aprovador de Solicitação de Vaga.'],
                    ['campo' => 'Gestor Imediato', 'informar' => 'Outro usuário que é o superior hierárquico deste', 'obrigatorio' => false, 'obs' => 'Ver a diferença para "Aprovador de Solicitação de Vaga" logo abaixo.'],
                ],
                'acoes' => [
                    ['nome' => 'Alterar senha', 'explicacao' => 'Abre um modal para definir uma nova senha para o usuário (o administrador não vê a senha atual).'],
                    ['nome' => 'Vincular ao METADADOS', 'explicacao' => 'Busca um contrato oficial pelo nome e associa a este usuário.'],
                ],
                'atencoes' => ['`is_supervisor` sempre libera as telas que dependem de role, mesmo que o perfil (role) do usuário seja `viewer` — nunca use isso para tentar restringir um supervisor.'],
                'faq' => [
                    ['p' => 'Qual a diferença entre Gestor Imediato e Aprovador de Solicitação de Vaga?', 'r' => 'São dois campos diferentes no cadastro do usuário. O Gestor Imediato é uma relação hierárquica geral, usada como sugestão em telas como o PDI. O Aprovador de Solicitação de Vaga é quem especificamente aprova os pedidos de vaga daquele usuário. Um não substitui o outro, e alterar um não muda o outro.'],
                    ['p' => 'Qual a diferença entre usuário e colaborador?', 'r' => 'Usuário é uma conta de acesso ao Portal. Colaborador é um contrato oficial sincronizado do METADADOS. Um usuário pode estar vinculado a um colaborador (para saber quem ele é na estrutura oficial), mas nem todo colaborador tem uma conta de usuário, e a edição de cada um fica em telas diferentes.'],
                    ['p' => 'O que a permissão individual controla?', 'r' => 'Cada permissão libera uma ação específica (por exemplo, criar uma mensagem, ou visualizar um dashboard). Ela nunca substitui o perfil (role) do usuário — as duas coisas são checadas juntas pelo sistema.'],
                    ['p' => 'Como redefinir uma senha?', 'r' => 'Um administrador ou RH pode alterá-la pelo detalhe do usuário. O próprio usuário também pode usar "Esqueci minha senha" na tela de login.'],
                ],
            ],
        ],
    ],
    [
        'id' => 'pdi', 'titulo' => 'PDI — Plano de Desenvolvimento Individual', 'descricao' => 'Acompanhamento do desenvolvimento de um colaborador, conduzido pelo RH e pelo gestor.',
        'itens' => [
            [
                'id' => 'pdi-visao-geral', 'titulo' => 'PDI',
                'oQue' => 'Um plano de desenvolvimento aberto para um contrato (colaborador), com competências a desenvolver, ações concretas, acompanhamentos ao longo do tempo e um histórico auditável de tudo o que aconteceu no plano.',
                'paraQue' => 'Registrar formalmente o processo de desenvolvimento de uma pessoa: o que ela precisa melhorar, o plano de ação e o acompanhamento desse plano.',
                'quem' => 'Quem tem a permissão de gerenciar cria e edita; quem tem a de acompanhar registra acompanhamentos e movimenta o status; a de visualizar só permite ler. Um gestor sem essas permissões individuais não vê nenhum PDI, nem o de sua própria equipe.',
                'acesso' => '`Central do Portal → PDI`.',
                'comoUsar' => 'Escolha o colaborador (contrato), defina o gestor responsável, a origem do plano, as competências a desenvolver e até 3 ações concretas. Depois de criado, o plano fica em Rascunho até ser liberado.',
                'campos' => [
                    ['campo' => 'Colaborador', 'informar' => 'O contrato oficial para quem o plano é aberto', 'obrigatorio' => true, 'obs' => ''],
                    ['campo' => 'Gestor responsável', 'informar' => 'Quem conduz o plano', 'obrigatorio' => true, 'obs' => 'O Gestor Imediato do colaborador aparece como sugestão no formulário, mas não define sozinho quem pode acessar o PDI — quem acessa é definido pelas permissões.'],
                    ['campo' => 'Ações', 'informar' => 'Passos concretos do plano, com responsável e prazo', 'obrigatorio' => false, 'obs' => 'No máximo 3 ações por plano; é preciso pelo menos 1 ação para poder iniciar o PDI.'],
                ],
                'acoes' => [
                    ['nome' => 'Liberar', 'explicacao' => 'Move o plano de Rascunho para Não iniciado.'],
                    ['nome' => 'Iniciar PDI', 'explicacao' => 'Move para Em andamento — exige ao menos 1 ação cadastrada.'],
                    ['nome' => 'Concluir PDI', 'explicacao' => 'Exige uma avaliação final. Depois de concluído, a edição comum do plano fica bloqueada.'],
                    ['nome' => 'Cancelar PDI', 'explicacao' => 'Encerra o plano sem conclusão, com motivo obrigatório.'],
                    ['nome' => 'Reabrir', 'explicacao' => 'Só RH/admin, com motivo obrigatório — a reabertura fica registrada no histórico, de forma auditável.'],
                ],
                'status' => [
                    ['nome' => 'Rascunho', 'explicacao' => 'Plano criado, ainda não liberado para acompanhamento.'],
                    ['nome' => 'Não iniciado', 'explicacao' => 'Liberado, mas as ações ainda não começaram.'],
                    ['nome' => 'Em andamento', 'explicacao' => 'Plano ativo, recebendo acompanhamentos.'],
                    ['nome' => 'Concluído', 'explicacao' => 'Encerrado com avaliação final; edição comum bloqueada.'],
                    ['nome' => 'Cancelado', 'explicacao' => 'Encerrado sem conclusão.'],
                ],
                'depois' => 'O histórico do PDI nunca é apagado: cada mudança de status, acompanhamento e edição relevante fica registrada, mesmo depois de concluído ou cancelado.',
                'atencoes' => ['Depois de concluído, o plano fica bloqueado para edição comum — só a reabertura (auditada, RH/admin) permite mexer de novo.', 'O espaço do colaborador (relato em primeira pessoa) é sempre registrado por RH/Gestor em nome dele, nunca preenchido diretamente pelo colaborador no sistema.'],
                'faq' => [
                    ['p' => 'Como funciona o PDI?', 'r' => 'Um plano é aberto para um colaborador, com um gestor responsável, competências e até 3 ações. Ele passa por Rascunho → Não iniciado → Em andamento → Concluído (ou Cancelado), recebendo acompanhamentos ao longo do caminho.'],
                    ['p' => 'Por que não consigo iniciar um PDI?', 'r' => 'É preciso ter pelo menos 1 ação cadastrada no plano antes de movê-lo para Em andamento.'],
                ],
            ],
        ],
    ],
    [
        'id' => 'pesquisas', 'titulo' => 'Pesquisas', 'descricao' => 'Duas pesquisas independentes, com objetivos e públicos diferentes — não confundir uma com a outra.',
        'itens' => [
            [
                'id' => 'pesquisa-integracao', 'titulo' => 'Pesquisa de Integração',
                'oQue' => 'Pesquisa respondida pelo colaborador recém-integrado, sobre o processo de integração/onboarding.',
                'paraQue' => 'Medir a experiência de quem acabou de entrar na empresa.',
                'quem' => 'O colaborador responde pelo link público, sem login; a administração é de quem tem a permissão de Integração.',
                'acesso' => 'Administração em `Central do Portal → Integração → QR Code da Integração`. Resultados em `Central do Portal → Integração → Pesquisas e resultados`.',
                'comoUsar' => 'O RH abre uma integração (por data), gerando um QR Code fixo e reutilizável. O colaborador escaneia, se identifica por CPF e data de nascimento, e responde à pesquisa.',
                'depois' => 'As respostas ficam agrupadas por data de integração, com NPS calculado a partir das notas.',
                'atencoes' => ['O QR Code contém só a URL pública fixa — nenhum dado de colaborador é codificado nele.'],
            ],
            [
                'id' => 'pesquisa-reacao', 'titulo' => 'Pesquisa de Reação',
                'oQue' => 'Pesquisa de reação a um treinamento de integração, aplicada por campanha.',
                'paraQue' => 'Avaliar um treinamento específico, diferente da Pesquisa de Integração (que é sobre o processo de integração como um todo).',
                'quem' => 'Quem responde é convidado pelo link da campanha, sem login; a administração é de quem tem a permissão de Pesquisa de Reação.',
                'acesso' => '`Central do Portal → Integração → Pesquisas e resultados`.',
                'comoUsar' => 'Crie uma campanha informando Empresa, Setor, data da integração e validade do link. O link gerado é enviado aos participantes.',
                'depois' => 'Cada resposta é registrada na campanha; os resultados (NPS e médias) ficam disponíveis assim que houver respostas.',
                'atencoes' => ['A Pesquisa de Reação e a Pesquisa de Integração são instrumentos, tabelas e fluxos totalmente separados — não são a mesma pesquisa em lugares diferentes.'],
                'faq' => [['p' => 'Onde encontro os resultados das pesquisas?', 'r' => 'Em `Central do Portal → Integração → Pesquisas e resultados`, tanto para a Pesquisa de Integração (por data) quanto para a Pesquisa de Reação (por campanha).']],
            ],
        ],
    ],
    [
        'id' => 'turnover-desligamento', 'titulo' => 'Turnover e Desligamento', 'descricao' => 'Entrevista de saída e os dois dashboards que analisam desligamentos.',
        'itens' => [
            [
                'id' => 'entrevista-desligamento', 'titulo' => 'Entrevista de Desligamento',
                'oQue' => 'Um link público de entrevista enviado a um ex-colaborador depois de um desligamento efetivado.',
                'paraQue' => 'Entender, na visão do ex-colaborador, os motivos e a experiência ao redor da saída.',
                'quem' => 'A geração e o acompanhamento operacional exigem a permissão de gerenciar; ver os resultados individuais exige a permissão de resultados (mais sensível, porque identifica quem respondeu).',
                'acesso' => '`Central do Portal → Turnover e Desligamento → Entrevistas de Desligamento`.',
                'comoUsar' => 'Na aba Elegíveis, escolha um desligamento e clique em Gerar entrevista. O link aparece uma única vez na tela — copie e envie ao ex-colaborador pelo canal que preferir.',
                'acoes' => [
                    ['nome' => 'Gerar entrevista', 'explicacao' => 'Cria o link para um contrato desligado elegível.'],
                    ['nome' => 'Regenerar link', 'explicacao' => 'Invalida o link anterior e cria um novo, para quem perdeu o link original.'],
                    ['nome' => 'Cancelar', 'explicacao' => 'Encerra o link antes de ser respondido.'],
                ],
                'status' => [
                    ['nome' => 'Pendente', 'explicacao' => 'Link gerado, ainda não respondido.'],
                    ['nome' => 'Respondida', 'explicacao' => 'O ex-colaborador respondeu.'],
                    ['nome' => 'Expirada', 'explicacao' => 'O prazo do link (30 dias) passou sem resposta.'],
                    ['nome' => 'Cancelada', 'explicacao' => 'Encerrada manualmente antes da resposta.'],
                ],
                'atencoes' => [
                    'O motivo oficial `020 — Falecimento` nunca gera entrevista — esse contrato não entra na lista de elegíveis.',
                    'A entrevista é ligada ao contrato oficial (`metadados_id`), com no máximo uma entrevista por contrato.',
                    'A pesquisa não tem pergunta de Voluntário/Involuntário nem campos de Gestor ou Área — não existe essa classificação nesta funcionalidade.',
                ],
                'faq' => [['p' => 'Por que determinada entrevista não pode ser gerada?', 'r' => 'Ou o contrato ainda não foi desligado oficialmente no METADADOS, ou o motivo do desligamento é Falecimento (020), que nunca gera entrevista, ou já existe uma entrevista para esse contrato.']],
            ],
            [
                'id' => 'dashboard-entrevista-desligamento', 'titulo' => 'Dashboard da Entrevista de Desligamento',
                'oQue' => 'Painel agregado com cobertura, motivos declarados e a experiência relatada pelos ex-colaboradores que responderam à entrevista.',
                'quem' => 'Quem tiver a permissão própria deste dashboard.',
                'acesso' => '`Central do Portal → Turnover e Desligamento → Dashboard da Entrevista`.',
                'comoUsar' => 'Filtre por período, unidade e cargo. A competência usada é a data de desligamento, não a data em que a entrevista foi respondida.',
                'atencoes' => ['Cobertura mostra quantos desligamentos elegíveis viraram entrevista gerada e depois respondida — os indicadores de opinião (motivos, satisfação, eNPS) usam só as respondidas.', 'Não há, nesta versão, análise por tipo de desligamento, Área ou Gestor.'],
            ],
            [
                'id' => 'dashboard-turnover', 'titulo' => 'Dashboard de Turnover',
                'oQue' => 'Seis análises sobre os contratos oficiais do METADADOS: evolução mensal, admissões x desligamentos, motivos, tempo de empresa, turnover por cargo e por empresa.',
                'quem' => 'Quem tiver a permissão própria deste dashboard.',
                'acesso' => '`Central do Portal → Turnover e Desligamento → Turnover`.',
                'comoUsar' => 'Filtre por Ano, Empresa e Cargo.',
                'faq' => [['p' => 'Como o indicador de turnover é calculado?', 'r' => 'É o número de desligamentos no período dividido pela média entre o headcount no início e no fim do período, em percentual. Meses sem base suficiente aparecem como "Sem base", nunca como zero.']],
            ],
        ],
    ],
    [
        'id' => 'avaliacoes', 'titulo' => 'Avaliações', 'descricao' => 'Ponto central para as avaliações que já existem no Portal hoje — nem toda "avaliação" do sistema mora tecnicamente na mesma tela, mas todas estão descritas aqui.',
        'itens' => [
            [
                'id' => 'avaliacoes-desempenho', 'titulo' => 'Avaliações de Desempenho',
                'oQue' => 'Um cadastro simples de avaliações de desempenho por colaborador: título, período de referência, nota e um resumo.',
                'paraQue' => 'Registrar formalmente uma avaliação de desempenho, para consulta posterior e para ser referenciada em uma Movimentação de Pessoal.',
                'quem' => 'admin, RH e viewer visualizam a lista; criar/editar exige perfil admin ou RH; excluir é restrito a admin.',
                'acesso' => '`Central do Portal → Cadastros → Avaliações` (também acessível a partir de `Colaboradores`, pelo atalho "Ver avaliações" de cada contrato).',
                'comoUsar' => 'Escolha o colaborador, informe o título e o período de referência, e, se quiser, uma nota (0 a 10) e um resumo em texto livre.',
                'campos' => [
                    ['campo' => 'Colaborador', 'informar' => 'A quem esta avaliação se refere', 'obrigatorio' => true, 'obs' => ''],
                    ['campo' => 'Título da avaliação', 'informar' => 'Um nome curto para identificar a avaliação', 'obrigatorio' => true, 'obs' => ''],
                    ['campo' => 'Período de referência', 'informar' => 'Texto livre (ex.: "1º semestre 2026")', 'obrigatorio' => true, 'obs' => ''],
                    ['campo' => 'Nota', 'informar' => 'Um valor de 0 a 10', 'obrigatorio' => false, 'obs' => ''],
                    ['campo' => 'Resumo', 'informar' => 'Observações da avaliação', 'obrigatorio' => false, 'obs' => ''],
                ],
                'depois' => 'A avaliação criada fica disponível para ser referenciada ao abrir uma Movimentação de Pessoal do mesmo colaborador.',
                'atencoes' => ['Esta é a única tela de cadastro de avaliação de desempenho do Portal — as outras duas "avaliações" do sistema (abaixo) vivem dentro de outros módulos, por serem partes de outros fluxos, não porque estejam perdidas.'],
            ],
            [
                'id' => 'avaliacao-90-dias', 'titulo' => 'Avaliação após 90 dias (dentro de Solicitações de Vaga)',
                'oQue' => 'Não é uma tela própria: é um campo (Atendeu plenamente / parcialmente / não atendeu) dentro da seção "7. Controle interno RH" do detalhe de uma Solicitação de Vaga já aprovada e com contratação registrada.',
                'quem' => 'Só perfil de RH edita.',
                'acesso' => '`Solicitações de Vaga → (abra a solicitação aprovada) → seção 7. Controle interno RH`.',
                'depois' => 'Alimenta o bloco "Experiência" do People Analytics (Avaliações realizadas/pendentes).',
                'atencoes' => ['Não confundir com "Avaliações de Desempenho": esta é uma avaliação da contratação em si (ficou bem 90 dias depois?), feita pelo RH dentro do fluxo de Solicitação de Vaga, não um cadastro próprio de desempenho do colaborador.'],
            ],
            [
                'id' => 'pesquisa-experiencia-candidato', 'titulo' => 'Pesquisa de Experiência do Candidato (dentro de Candidaturas)',
                'oQue' => 'Não é uma tela própria: é a avaliação que o CANDIDATO faz sobre a experiência dele no processo seletivo, respondida por link público (sem login), e visível dentro do detalhe da candidatura no admin.',
                'quem' => 'Quem responde é o candidato, sem login. No admin, só quem tem a permissão de visualizar a Pesquisa de Experiência vê o resultado.',
                'acesso' => '`Recrutamento e Seleção → Candidaturas → (abra a candidatura)`, bloco "Pesquisa de Experiência" (só aparece se o candidato já respondeu e você tiver a permissão).',
                'atencoes' => ['É uma avaliação do CANDIDATO sobre o processo seletivo, não uma avaliação de desempenho de um colaborador — são conceitos diferentes, apesar do nome parecido.'],
            ],
        ],
    ],
    [
        'id' => 'mensagens', 'titulo' => 'Mensagens', 'descricao' => 'Modelos de texto usados no processo seletivo, com variáveis preenchidas automaticamente.',
        'itens' => [
            [
                'id' => 'mensagens-modelos', 'titulo' => 'Mensagens',
                'oQue' => 'Modelos de mensagem reutilizáveis (convocação para entrevista, agendamento de exame, etc.), com o Portal como fonte oficial desses textos.',
                'paraQue' => 'Padronizar comunicações do processo seletivo, com dados do candidato/agendamento preenchidos automaticamente no envio.',
                'quem' => 'Visualizar, criar e editar são permissões individuais separadas — ter uma não garante as outras.',
                'acesso' => '`Central do Portal → Mensagens`.',
                'comoUsar' => 'Crie uma mensagem com um código técnico único (minúsculas, números e underscore, não editável depois), título, descrição interna e o conteúdo com as variáveis que quiser usar.',
                'campos' => [
                    ['campo' => 'Código técnico', 'informar' => 'Identificador único da mensagem (ex.: `convocacao_entrevista_rh`)', 'obrigatorio' => true, 'obs' => 'Só na criação — não pode ser alterado depois.'],
                    ['campo' => 'Título', 'informar' => 'Nome de exibição da mensagem', 'obrigatorio' => true, 'obs' => ''],
                    ['campo' => 'Descrição', 'informar' => 'Anotação interna sobre quando usar', 'obrigatorio' => false, 'obs' => 'Não é enviada ao candidato.'],
                    ['campo' => 'Conteúdo', 'informar' => 'O texto da mensagem, com variáveis entre colchetes', 'obrigatorio' => true, 'obs' => 'Quebras de linha e emojis são preservados.'],
                    ['campo' => 'Ativa', 'informar' => 'Se a mensagem está disponível para uso', 'obrigatorio' => false, 'obs' => ''],
                ],
                'comoUsar' => ['Clique em uma das "Variáveis automáticas" para inserir o placeholder no ponto do cursor.', 'As variáveis disponíveis hoje são: Nome do candidato, Data do agendamento, Horário, Responsável, Nome do Gestor, Local ou Link, Nome da Clínica, Endereço e Telefone.', 'A pré-visualização mostra o texto final se você preencher valores de exemplo — nada digitado ali é salvo.'],
                'atencoes' => ['Um placeholder fora do catálogo oficial (por exemplo, `[Campo Inventado]`) é sinalizado como "não reconhecido" e não será substituído automaticamente no envio.'],
            ],
        ],
    ],
    [
        'id' => 'movimentacao', 'titulo' => 'Movimentação de Pessoal', 'descricao' => 'Mérito, promoção, transferência e alteração de função, com aprovação em rascunho, assinatura do gestor e do RH.',
        'itens' => [
            [
                'id' => 'movimentacao-visao-geral', 'titulo' => 'Movimentação de Pessoal',
                'oQue' => 'Um formulário para formalizar uma mudança na condição de um colaborador: mérito (aumento salarial), promoção, transferência ou alteração de função.',
                'paraQue' => 'Documentar formalmente a mudança, com justificativa, impacto financeiro e assinatura de quem solicita e de quem aprova.',
                'quem' => 'admin, RH e viewer acessam a listagem e criam; a assinatura de RH é restrita a admin/RH.',
                'acesso' => '`Central do Portal → Movimentação de Pessoal`.',
                'comoUsar' => 'Ao escolher o Tipo de movimentação, o formulário mostra ou esconde campos conforme o que aquele tipo realmente precisa — por exemplo, uma alteração de função tem campos diferentes de uma promoção. Preencha identificação, dados do colaborador, dados da movimentação proposta, justificativa, evidências e impacto financeiro.',
                'campos' => [
                    ['campo' => 'Tipo de movimentação', 'informar' => 'Mérito, Promoção, Transferência ou Alteração de função', 'obrigatorio' => true, 'obs' => 'Determina quais outros campos do formulário aparecem.'],
                    ['campo' => 'Gestor solicitante', 'informar' => 'Quem está pedindo a movimentação', 'obrigatorio' => true, 'obs' => ''],
                    ['campo' => 'Colaborador', 'informar' => 'Quem sofre a movimentação', 'obrigatorio' => true, 'obs' => ''],
                    ['campo' => 'Avaliação de desempenho mais recente', 'informar' => 'Uma Avaliação de Desempenho já cadastrada para o colaborador', 'obrigatorio' => true, 'obs' => 'Precisa existir ao menos uma avaliação cadastrada em `Avaliações` para esse colaborador antes de abrir a movimentação.'],
                    ['campo' => 'Justificativa, entregas e resultados', 'informar' => 'Texto livre explicando o motivo da movimentação', 'obrigatorio' => true, 'obs' => ''],
                    ['campo' => 'Existe orçamento aprovado', 'informar' => 'Sim, Não ou Em validação', 'obrigatorio' => true, 'obs' => ''],
                ],
                'acoes' => [
                    ['nome' => 'Salvar rascunho', 'explicacao' => 'Grava o formulário sem enviar para assinatura.'],
                    ['nome' => 'Assinar (gestor)', 'explicacao' => 'O gestor solicitante confirma o pedido, movendo para pendente de RH.'],
                    ['nome' => 'Assinar (RH)', 'explicacao' => 'O RH aprova formalmente a movimentação.'],
                ],
                'status' => [
                    ['nome' => 'Rascunho', 'explicacao' => 'Em preenchimento, ainda sem assinatura do gestor.'],
                    ['nome' => 'Pendente RH', 'explicacao' => 'Assinada pelo gestor, aguardando o RH.'],
                    ['nome' => 'Aprovada', 'explicacao' => 'Assinada por gestor e RH — o processo está concluído.'],
                ],
                'depois' => 'Cada mudança de status fica registrada na auditoria do registro, visível no próprio detalhe da movimentação.',
            ],
        ],
    ],
    [
        'id' => 'cadastros', 'titulo' => 'Cadastros', 'descricao' => 'As tabelas de apoio usadas pelo restante do sistema.',
        'itens' => [
            [
                'id' => 'cadastro-empresas', 'titulo' => 'Empresas',
                'oQue' => 'Cadastro das empresas do grupo, usado como referência (filtro, vínculo) em praticamente todo o resto do sistema.',
                'quem' => 'admin, RH e viewer visualizam; a edição segue o gate da tela.',
                'acesso' => '`Central do Portal → Cadastros → Empresas`.',
            ],
            [
                'id' => 'cadastro-setores', 'titulo' => 'Setores',
                'oQue' => 'Cadastro de setores, com vínculo obrigatório a uma Empresa e exportação em CSV, Excel e PDF.',
                'acesso' => '`Central do Portal → Cadastros → Setores`.',
                'atencoes' => ['Setores legados sem empresa vinculada podem existir por herança de dados antigos; o cadastro segue funcionando, mas novos registros e edições exigem uma empresa válida.'],
            ],
            [
                'id' => 'cadastro-cargos', 'titulo' => 'Cargos',
                'oQue' => 'Cadastro de cargos, com associação a setores.',
                'acesso' => '`Central do Portal → Cadastros → Cargos`.',
            ],
            [
                'id' => 'cadastro-beneficios', 'titulo' => 'Benefícios',
                'oQue' => 'Cadastro de benefícios/parcerias exibidos na página pública de vagas, com logo, nome, parceiro e se estão ativos.',
                'acesso' => '`Central do Portal → Cadastros → Benefícios`.',
            ],
        ],
    ],
    [
        'id' => 'seguranca', 'titulo' => 'Segurança e Acessos', 'descricao' => 'Como o Portal decide o que cada pessoa pode ver e fazer.',
        'itens' => [
            [
                'id' => 'seguranca-perfis-permissoes', 'titulo' => 'Perfis e permissões',
                'oQue' => 'Cada usuário tem um perfil (admin, RH ou viewer) e, além dele, pode ter permissões individuais específicas (por exemplo, `pdi.gerenciar` ou `mensagens.criar`).',
                'paraQue' => 'O perfil libera o básico de cada área; as permissões individuais liberam ações específicas dentro dela, sem alterar o perfil.',
                'quem' => 'Só um administrador concede permissões, em `Usuários e Acessos`.',
                'atencoes' => ['`is_supervisor` sempre libera o que o perfil admin/RH libera, mesmo que o perfil cadastrado seja `viewer` — nunca é usado para restringir alguém.', 'A Central e os menus só mostram o que cada usuário já pode abrir; nenhum card leva a uma tela bloqueada.'],
                'faq' => [
                    ['p' => 'Por que não consigo editar um registro mesmo vendo a tela?', 'r' => 'Visualizar e editar costumam ser permissões separadas. É possível ver uma lista sem ter permissão para criar ou alterar os registros dela.'],
                    ['p' => 'Por que determinado botão não aparece para mim?', 'r' => 'Botões de ação só aparecem para quem tem a permissão correspondente — não é um problema visual, é o mesmo controle de acesso que protege a ação no servidor.'],
                ],
            ],
        ],
    ],
];

$faqGlobal = [
    ['p' => 'Por que não vejo determinado módulo?', 'r' => 'Porque nenhuma das telas daquele módulo está liberada para o seu perfil ou suas permissões individuais. Fale com um administrador.'],
    ['p' => 'Por que não consigo editar um registro?', 'r' => 'Visualizar e editar costumam exigir permissões diferentes — você pode ter uma sem ter a outra.'],
    ['p' => 'Por que o mesmo colaborador aparece mais de uma vez?', 'r' => 'Porque as listas de colaboradores representam contratos, não pessoas. Uma readmissão gera um novo contrato.'],
    ['p' => 'Qual a diferença entre usuário e colaborador?', 'r' => 'Usuário é uma conta de acesso ao Portal; colaborador é um contrato oficial do METADADOS. Um usuário pode estar vinculado a um colaborador, mas são cadastros diferentes.'],
    ['p' => 'Qual a diferença entre Gestor Imediato e Aprovador de Solicitação de Vaga?', 'r' => 'Gestor Imediato é a hierarquia geral do usuário (usada, por exemplo, como sugestão no PDI). Aprovador de Solicitação de Vaga é especificamente quem aprova os pedidos de vaga daquele usuário. São dois campos independentes.'],
    ['p' => 'Como sei se os dados estão atualizados?', 'r' => 'Os painéis que dependem do METADADOS mostram "Última atualização" com a data da sincronização mais recente.'],
    ['p' => 'O que significa "Sem base" em um gráfico?', 'r' => 'Significa que não havia dados suficientes para calcular aquele indicador no período — é diferente de um resultado real igual a zero, que é mostrado normalmente.'],
    ['p' => 'Por que determinado botão não aparece?', 'r' => 'Os botões de ação só aparecem para quem tem a permissão correspondente àquela ação.'],
    ['p' => 'Como voltar para a Central?', 'r' => 'Clique no logotipo no topo da tela, ou no primeiro item do caminho (`Portal RH`) logo abaixo dele.'],
    ['p' => 'Como redefinir uma senha?', 'r' => 'Um administrador ou RH altera pelo detalhe do usuário, em `Usuários e Acessos`. O próprio usuário também pode usar "Esqueci minha senha" na tela de login.'],
    ['p' => 'Como funciona o PDI?', 'r' => 'Um plano de desenvolvimento aberto para um colaborador, com competências, até 3 ações e acompanhamento, passando por Rascunho → Não iniciado → Em andamento → Concluído ou Cancelado.'],
    ['p' => 'Por que determinada entrevista de desligamento não pode ser gerada?', 'r' => 'O contrato ainda não foi desligado oficialmente, o motivo é Falecimento (020, que nunca gera entrevista), ou já existe uma entrevista para esse contrato.'],
    ['p' => 'Onde encontro os resultados das pesquisas?', 'r' => 'Em `Central do Portal → Integração → Pesquisas e resultados`, tanto para a Pesquisa de Integração quanto para a Pesquisa de Reação.'],
];
?>
<div class="mx-auto max-w-5xl space-y-6">
  <?= ui_breadcrumb([['label' => 'Portal RH', 'href' => $base . '/admin'], ['label' => 'Manual de Uso']]) ?>
  <?= ui_page_header([
      'titulo' => 'Manual de Uso do Portal RH',
      'descricao' => 'Documentação operacional dos módulos reais do sistema: o que cada tela faz, quem pode usá-la, como preencher e o que esperar depois.',
  ]) ?>

  <section class="rounded-ds-lg border border-border bg-surface p-4 shadow-resting">
    <form data-manual-busca-form role="search" class="flex flex-wrap items-center gap-2">
      <label for="manual-busca" class="sr-only">Buscar no Manual</label>
      <input id="manual-busca" type="search" data-manual-busca placeholder="Buscar: gestor imediato, PDI, entrevista, permissão, senha…" class="min-w-0 flex-1 rounded-ds-md border border-border bg-surface px-3 py-2 text-sm text-text-primary outline-none focus:border-focus focus:ring-2 focus:ring-primary-100">
      <button type="button" data-manual-busca-limpar class="<?= ui_btn('secundario') ?>">Limpar</button>
    </form>
    <p data-manual-busca-contador class="mt-2 text-ds-caption text-text-secondary" role="status" aria-live="polite"></p>
  </section>

  <nav aria-label="Índice do Manual" class="rounded-ds-lg border border-border bg-surface p-4 shadow-resting">
    <h2 class="text-ds-caption font-bold uppercase tracking-wide text-text-muted">Índice</h2>
    <ul class="mt-2 grid grid-cols-2 gap-x-4 gap-y-1.5 text-sm sm:grid-cols-3">
      <?php foreach ($secoes as $s): ?>
        <li><a href="#<?= Security::e($s['id']) ?>" class="text-primary-700 hover:underline"><?= Security::e($s['titulo']) ?></a></li>
      <?php endforeach; ?>
      <li><a href="#faq" class="text-primary-700 hover:underline">Perguntas Frequentes</a></li>
    </ul>
  </nav>

  <?php foreach ($secoes as $s): ?>
    <?= manual_secao($s) ?>
  <?php endforeach; ?>

  <section id="faq" data-manual-secao class="scroll-mt-24 space-y-3">
    <div><h2 class="text-ds-h2 font-extrabold text-text-primary">Perguntas Frequentes</h2><p class="mt-0.5 text-sm text-text-secondary">Dúvidas práticas sobre o uso do Portal, reunidas de todos os módulos.</p></div>
    <div class="rounded-ds-lg border border-border bg-surface p-4 shadow-resting" data-manual-item data-manual-texto="<?= Security::e(mb_strtolower(manual_texto_puro('faq geral ' . implode(' ', array_map(static fn(array $qa): string => $qa['p'] . ' ' . $qa['r'], $faqGlobal))))) ?>">
      <?= manual_bloco('', $faqGlobal) ?>
    </div>
  </section>

  <section class="rounded-ds-lg border border-border bg-surface p-4 shadow-resting">
    <h2 class="text-ds-caption font-bold uppercase tracking-wide text-text-muted">Versão e suporte</h2>
    <div class="mt-2 grid grid-cols-1 gap-4 text-sm sm:grid-cols-3">
      <div><p class="text-text-muted">Versão</p><p class="font-semibold text-text-primary"><?= Security::e($versao !== '' ? $versao : 'não informada na configuração') ?></p></div>
      <div><p class="text-text-muted">Data de release</p><p class="font-semibold text-text-primary"><?= Security::e($releaseDate !== '' ? $releaseDate : 'não informada na configuração') ?></p></div>
      <div><p class="text-text-muted">Canal de suporte</p><a class="font-semibold text-primary-700 hover:underline" href="https://wa.me/5567993256260" target="_blank" rel="noopener noreferrer">WhatsApp (67) 99325-6260</a></div>
    </div>
  </section>
</div>
