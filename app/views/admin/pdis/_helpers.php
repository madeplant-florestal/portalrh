<?php
/**
 * Helpers de apresentação do PDI (só formatação — nenhuma regra de negócio). Incluído pelas views de /admin/pdis.
 */
require_once APP_PATH . '/views/partials/ui-shell.php';

if (!function_exists('pdi_data_br')) {
    function pdi_data_br(?string $data): string
    {
        return ($data !== null && $data !== '' && strtotime($data) !== false) ? date('d/m/Y', strtotime($data)) : '—';
    }

    function pdi_data_hora_br(?string $data): string
    {
        return ($data !== null && $data !== '' && strtotime($data) !== false) ? date('d/m/Y H:i', strtotime($data)) : '—';
    }

    // Badges V2 (ui_badge): mesmo texto/estados do sistema, só o tom semântico muda (rascunho neutro, não iniciado primary,
    // em andamento info, concluído success, cancelado danger; atraso = warning). Nada de cor sozinha: o rótulo sempre aparece.
    function pdi_status_badge(string $status): string
    {
        $tons = ['rascunho' => 'neutro', 'nao_iniciado' => 'primary', 'em_andamento' => 'info', 'concluido' => 'success', 'cancelado' => 'danger'];
        return ui_badge(PdiService::STATUS[$status] ?? $status, $tons[$status] ?? 'neutro');
    }

    function pdi_status_acao_badge(string $status): string
    {
        $tons = ['nao_iniciada' => 'neutro', 'em_andamento' => 'info', 'concluida' => 'success'];
        return ui_badge(PdiService::STATUS_ACAO[$status] ?? $status, $tons[$status] ?? 'neutro');
    }

    function pdi_atraso_badge(array $prazo): string
    {
        if (empty($prazo['atrasado'])) {
            return '';
        }
        $dias = (int)$prazo['dias_atraso'];
        return ui_badge('Atrasado ' . $dias . ($dias === 1 ? ' dia' : ' dias'), 'warning');
    }
    function pdi_barra_progresso(array $progresso): string
    {
        if ($progresso['percentual'] === null) {
            return '<span class="text-xs text-text-secondary">Sem ações</span>';
        }
        return '<div class="w-28" title="' . (int)$progresso['concluidas'] . ' de ' . (int)$progresso['total'] . ' ações concluídas">'
            . '<div class="h-2 rounded-full bg-surface-secondary"><div class="h-2 rounded-full bg-primary-700" style="width: ' . (int)$progresso['percentual'] . '%"></div></div>'
            . '<p class="mt-0.5 text-[11px] text-text-secondary">' . (int)$progresso['concluidas'] . '/' . (int)$progresso['total'] . ' ações</p></div>';
    }
}
