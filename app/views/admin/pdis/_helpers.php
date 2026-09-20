<?php
/**
 * Helpers de apresentação do PDI (só formatação — nenhuma regra de negócio). Incluído pelas views de /admin/pdis.
 */
if (!function_exists('pdi_data_br')) {
    function pdi_data_br(?string $data): string
    {
        return ($data !== null && $data !== '' && strtotime($data) !== false) ? date('d/m/Y', strtotime($data)) : '—';
    }

    function pdi_data_hora_br(?string $data): string
    {
        return ($data !== null && $data !== '' && strtotime($data) !== false) ? date('d/m/Y H:i', strtotime($data)) : '—';
    }

    function pdi_status_badge(string $status): string
    {
        $classes = [
            'rascunho' => 'bg-slate-100 text-slate-600',
            'nao_iniciado' => 'bg-[#F2F4EC] text-[#2E3919]',
            'em_andamento' => 'bg-blue-50 text-blue-700',
            'concluido' => 'bg-green-50 text-green-700',
            'cancelado' => 'bg-red-50 text-red-700',
        ];
        return '<span class="inline-flex rounded-full px-2.5 py-1 text-xs font-semibold ' . ($classes[$status] ?? 'bg-slate-100 text-slate-600') . '">'
            . Security::e(PdiService::STATUS[$status] ?? $status) . '</span>';
    }

    function pdi_status_acao_badge(string $status): string
    {
        $classes = ['nao_iniciada' => 'bg-slate-100 text-slate-600', 'em_andamento' => 'bg-blue-50 text-blue-700', 'concluida' => 'bg-green-50 text-green-700'];
        return '<span class="inline-flex rounded-full px-2 py-0.5 text-[11px] font-semibold ' . ($classes[$status] ?? 'bg-slate-100 text-slate-600') . '">'
            . Security::e(PdiService::STATUS_ACAO[$status] ?? $status) . '</span>';
    }

    function pdi_atraso_badge(array $prazo): string
    {
        if (empty($prazo['atrasado'])) {
            return '';
        }
        $dias = (int)$prazo['dias_atraso'];
        return '<span class="inline-flex rounded-full bg-amber-50 px-2.5 py-1 text-xs font-semibold text-amber-800">Atrasado ' . $dias . ($dias === 1 ? ' dia' : ' dias') . '</span>';
    }

    function pdi_barra_progresso(array $progresso): string
    {
        if ($progresso['percentual'] === null) {
            return '<span class="text-xs text-[#5B5F4E]">Sem ações</span>';
        }
        return '<div class="w-28" title="' . (int)$progresso['concluidas'] . ' de ' . (int)$progresso['total'] . ' ações concluídas">'
            . '<div class="h-2 rounded-full bg-slate-100"><div class="h-2 rounded-full bg-[#3B4822]" style="width: ' . (int)$progresso['percentual'] . '%"></div></div>'
            . '<p class="mt-0.5 text-[11px] text-[#5B5F4E]">' . (int)$progresso['concluidas'] . '/' . (int)$progresso['total'] . ' ações</p></div>';
    }
}
