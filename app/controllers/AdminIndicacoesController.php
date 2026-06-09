<?php
class AdminIndicacoesController extends Controller
{
    public function index(): void
    {
        Auth::requireRole(['admin', 'rh']);
        $filters = [
            'q' => Security::sanitizeString($_GET['q'] ?? ''),
            'pagamento' => Security::sanitizeString($_GET['pagamento'] ?? ''),
            'experiencia' => Security::sanitizeString($_GET['experiencia'] ?? ''),
            'data_de' => Security::sanitizeString($_GET['data_de'] ?? ''),
            'data_ate' => Security::sanitizeString($_GET['data_ate'] ?? ''),
            'indicador' => Security::sanitizeString($_GET['indicador'] ?? '')
        ];
        $filters = $this->applyScopeFilters($filters);
        $page = max(1, (int)($_GET['page'] ?? 1));
        $result = Candidatura::paginateIndicacoes($filters, $page, 15);
        $items = [];
        foreach ($result['items'] as $row) {
            $row['payment_signal'] = Candidatura::paymentSignal($row);
            $items[] = $row;
        }
        $this->view->render('admin/indicacoes/index', [
            'items' => $items,
            'total' => $result['total'],
            'page' => $result['page'],
            'pages' => $result['pages'],
            'filters' => $filters,
            'csrf' => Security::csrfToken(),
            'flashError' => Security::sanitizeString($_GET['erro'] ?? ''),
            'flashSuccess' => Security::sanitizeString($_GET['ok'] ?? '')
        ], 'layouts/admin');
    }

    public function markPago(string $id): void
    {
        Auth::requireRole(['admin', 'rh']);
        if (!Security::csrfCheck($_POST['csrf'] ?? '')) {
            http_response_code(400);
            echo 'Falha na verificação de segurança (CSRF).';
            return;
        }
        $cand = Candidatura::find((int)$id);
        if (!$cand || (int)($cand['indicacao_colaborador'] ?? 0) !== 1) {
            http_response_code(404);
            echo 'Candidatura indicada não encontrada.';
            return;
        }
        $paymentDate = Security::sanitizeString($_POST['payment_date'] ?? '');
        $paymentMethod = Security::sanitizeString($_POST['payment_method'] ?? '');
        $actorId = (int)($_SESSION['user_id'] ?? 0);
        $result = Candidatura::markIndicacaoPagamento((int)$id, $paymentDate, $actorId, $paymentMethod);
        if (!($result['ok'] ?? false)) {
            redirect('/admin/indicacoes?erro=' . urlencode((string)($result['error'] ?? 'Falha ao registrar pagamento.')));
        }
        $nome = (string)($cand['nome'] ?? 'Candidato');
        $vaga = (string)($cand['vaga_titulo'] ?? '-');
        $ator = (string)($_SESSION['user_name'] ?? 'Usuário');
        Mailer::notifyHR('Pagamento de indicação registrado', "Candidato: {$nome}\nVaga: {$vaga}\nData pagamento: {$paymentDate}\nRegistrado por: {$ator}");
        redirect('/admin/indicacoes?ok=' . urlencode('Pagamento registrado com sucesso.'));
    }

    public function updatePaymentDate(string $id): void
    {
        Auth::requireRole(['admin', 'rh']);
        if (!Security::csrfCheck($_POST['csrf'] ?? '')) {
            http_response_code(400);
            echo 'Falha na verificação de segurança (CSRF).';
            return;
        }
        $newDate = Security::sanitizeString($_POST['payment_date_edit'] ?? '');
        $reason = Security::sanitizeString($_POST['payment_edit_reason'] ?? '');
        $actorId = (int)($_SESSION['user_id'] ?? 0);
        $cand = Candidatura::find((int)$id);
        if (!$cand || (int)($cand['indicacao_colaborador'] ?? 0) !== 1) {
            http_response_code(404);
            echo 'Candidatura indicada não encontrada.';
            return;
        }
        $result = Candidatura::updateIndicacaoPaymentDate((int)$id, $newDate, $reason, $actorId);
        if (!($result['ok'] ?? false)) {
            redirect('/admin/indicacoes?erro=' . urlencode((string)($result['error'] ?? 'Falha ao editar data de pagamento.')));
        }
        $nome = (string)($cand['nome'] ?? 'Candidato');
        $vaga = (string)($cand['vaga_titulo'] ?? '-');
        $ator = (string)($_SESSION['user_name'] ?? 'Usuário');
        $old = (string)($result['old_date'] ?? '');
        $new = (string)($result['new_date'] ?? '');
        Mailer::notifyHR('Data de pagamento alterada', "Candidato: {$nome}\nVaga: {$vaga}\nData anterior: {$old}\nData nova: {$new}\nMotivo: {$reason}\nAlterado por: {$ator}");
        redirect('/admin/indicacoes?ok=' . urlencode('Data de pagamento alterada com sucesso.'));
    }

    public function statusApi(string $id): void
    {
        Auth::requireRole(['admin', 'rh', 'viewer']);
        $cand = Candidatura::find((int)$id);
        if (!$cand || (int)($cand['indicacao_colaborador'] ?? 0) !== 1) {
            http_response_code(404);
            header('Content-Type: application/json; charset=UTF-8');
            echo json_encode(['ok' => false, 'error' => 'Indicação não encontrada'], JSON_UNESCAPED_UNICODE);
            return;
        }
        $signal = Candidatura::paymentSignal($cand);
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode([
            'ok' => true,
            'id' => (int)$cand['id'],
            'pagamento_realizado' => (int)($cand['indicacao_pagamento_realizado'] ?? 0),
            'data_pagamento' => $cand['indicacao_data_pagamento'] ?? null,
            'data_contratacao' => $cand['indicacao_data_contratacao'] ?? null,
            'data_fim_experiencia' => $cand['indicacao_data_fim_experiencia'] ?? null,
            'signal' => $signal
        ], JSON_UNESCAPED_UNICODE);
    }

    public function contasReceberApi(): void
    {
        Auth::requireRole(['admin', 'rh', 'viewer']);
        header('Content-Type: application/json; charset=UTF-8');
        $rows = Candidatura::financialIndicacoesDataset(['pagamento' => Security::sanitizeString($_GET['pagamento'] ?? '')]);
        echo json_encode(['ok' => true, 'module' => 'contas_receber', 'items' => $rows], JSON_UNESCAPED_UNICODE);
    }

    public function conciliacaoApi(): void
    {
        Auth::requireRole(['admin', 'rh', 'viewer']);
        header('Content-Type: application/json; charset=UTF-8');
        $rows = Candidatura::financialIndicacoesDataset(['pagamento' => Security::sanitizeString($_GET['pagamento'] ?? '')]);
        echo json_encode(['ok' => true, 'module' => 'conciliacao_bancaria', 'items' => $rows], JSON_UNESCAPED_UNICODE);
    }

    public function relatoriosFinanceirosApi(): void
    {
        Auth::requireRole(['admin', 'rh', 'viewer']);
        header('Content-Type: application/json; charset=UTF-8');
        $rows = Candidatura::financialIndicacoesDataset(['pagamento' => Security::sanitizeString($_GET['pagamento'] ?? '')]);
        $totais = [
            'qtd_total' => count($rows),
            'qtd_pagos' => count(array_filter($rows, static fn($r) => (int)($r['indicacao_pagamento_realizado'] ?? 0) === 1)),
            'qtd_pendentes' => count(array_filter($rows, static fn($r) => (int)($r['indicacao_pagamento_realizado'] ?? 0) === 0))
        ];
        echo json_encode(['ok' => true, 'module' => 'relatorios_financeiros', 'totais' => $totais, 'items' => $rows], JSON_UNESCAPED_UNICODE);
    }

    public function export(): void
    {
        Auth::requireRole(['admin', 'rh']);
        $format = strtolower(Security::sanitizeString($_GET['format'] ?? 'excel'));
        $filters = [
            'q' => Security::sanitizeString($_GET['q'] ?? ''),
            'pagamento' => Security::sanitizeString($_GET['pagamento'] ?? ''),
            'experiencia' => Security::sanitizeString($_GET['experiencia'] ?? ''),
            'data_de' => Security::sanitizeString($_GET['data_de'] ?? ''),
            'data_ate' => Security::sanitizeString($_GET['data_ate'] ?? ''),
            'indicador' => Security::sanitizeString($_GET['indicador'] ?? '')
        ];
        $filters = $this->applyScopeFilters($filters);
        if ($format !== 'excel') {
            redirect('/admin/indicacoes?erro=' . urlencode('A exportação em PDF foi desativada. Utilize Excel.'));
        }
        $rows = Candidatura::reportIndicacoes($filters);
        if (empty($rows)) {
            redirect('/admin/indicacoes?erro=' . urlencode('Nenhum dado encontrado para exportação com os filtros atuais.'));
        }
        $totais = Candidatura::reportTotals($rows);
        $this->exportExcel($rows, $filters, $totais);
    }

    private function exportPdf(array $rows, array $filters, array $totais): void
    {
        $periodo = trim(($filters['data_de'] ?? '') . ' a ' . ($filters['data_ate'] ?? ''));
        if ($periodo === 'a') {
            $periodo = 'Todos';
        }
        $header = [
            'RH Madeplant - Programa de Indicacoes',
            'Período: ' . $periodo,
            'Gerado em: ' . date('d/m/Y H:i')
        ];
        $columns = ['Candidato', 'Indicador', 'Pagamento', 'Data Indicação', 'Comissão', 'Data Pgto', 'Método', 'Contratação', 'Cargo', 'CPF', 'Telefone', 'E-mail'];
        $lines = [];
        if (empty($rows)) {
            $lines[] = 'Nenhum registro encontrado para os filtros informados.';
        }
        $lines[] = implode(' | ', $columns);
        foreach ($rows as $row) {
            $statusPg = $this->paymentStatusLabel($row);
            $comissao = isset($row['indicacao_valor_comissao']) && $row['indicacao_valor_comissao'] !== null ? 'R$ ' . number_format((float)$row['indicacao_valor_comissao'], 2, ',', '.') : '-';
            $line = [
                $this->truncateText((string)($row['nome'] ?? '-'), 22),
                $this->truncateText((string)($row['indicacao_colaborador_nome'] ?? '-'), 18),
                $statusPg,
                !empty($row['created_at']) ? date('d/m/Y', strtotime((string)$row['created_at'])) : '-',
                $comissao,
                !empty($row['indicacao_data_pagamento']) ? date('d/m/Y', strtotime((string)$row['indicacao_data_pagamento'])) : '-',
                $this->truncateText((string)($row['indicacao_metodo_pagamento'] ?? '-'), 12),
                $this->truncateText((string)($row['stage_nome'] ?? '-'), 12),
                $this->truncateText((string)($row['cargo_pretendido'] ?? '-'), 16),
                (string)($row['cpf'] ?? '-'),
                $this->truncateText(Phone::format((string)($row['telefone'] ?? '')), 15),
                $this->truncateText((string)($row['email'] ?? '-'), 24)
            ];
            $lines[] = implode(' | ', $line);
        }
        $footer = [
            'Totais gerais: ' . (int)($totais['total'] ?? 0),
            'Por status: Pendente=' . (int)(($totais['status']['pendente'] ?? 0)) . ', Pago=' . (int)(($totais['status']['pago'] ?? 0)) . ', Cancelado=' . (int)(($totais['status']['cancelado'] ?? 0)) . ', Em processo=' . (int)(($totais['status']['em processo'] ?? 0)),
            'Conversão: ' . number_format((float)($totais['conversao_percentual'] ?? 0), 2, ',', '.') . '%'
        ];
        $pdf = $this->buildSimplePdfLandscape(array_merge($header, [''], $lines, [''], $footer));
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="programa-indicacoes-' . date('Ymd-His') . '.pdf"');
        header('Content-Length: ' . strlen($pdf));
        echo $pdf;
    }

    private function exportExcel(array $rows, array $filters, array $totais): void
    {
        $periodo = trim(($filters['data_de'] ?? '') . ' a ' . ($filters['data_ate'] ?? ''));
        if ($periodo === 'a') {
            $periodo = 'Todos';
        }
        $grouped = [
            'pendente' => [],
            'pago' => [],
            'cancelado' => [],
            'em processo' => []
        ];
        foreach ($rows as $row) {
            $status = $this->paymentStatusLabel($row);
            if (!isset($grouped[$status])) {
                $grouped[$status] = [];
            }
            $grouped[$status][] = $row;
        }
        $xml = '<?xml version="1.0" encoding="UTF-8"?>';
        $xml .= '<?mso-application progid="Excel.Sheet"?>';
        $xml .= '<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet" xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet">';
        $xml .= '<Styles><Style ss:ID="h"><Font ss:Bold="1"/><Interior ss:Color="#DCEEFF" ss:Pattern="Solid"/></Style><Style ss:ID="warn"><Interior ss:Color="#FFE5E5" ss:Pattern="Solid"/></Style><Style ss:ID="foot"><Font ss:Bold="1"/></Style></Styles>';
        foreach ($grouped as $status => $items) {
            $sheetName = $status === 'em processo' ? 'Em processo' : ucfirst($status);
            $xml .= '<Worksheet ss:Name="' . $this->xmlEsc($sheetName) . '"><Table>';
            $xml .= $this->excelRow([['Relatório Programa de Indicações', 'h']]);
            $xml .= $this->excelRow([['Período: ' . $periodo, null]]);
            $xml .= $this->excelRow([['Gerado em: ' . date('d/m/Y H:i'), null]]);
            $xml .= $this->excelRow([]);
            $xml .= $this->excelRow([
                ['Candidato', 'h'], ['Indicador', 'h'], ['Status pagamento', 'h'], ['Data indicação', 'h'], ['Valor comissão', 'h'],
                ['Data pagamento', 'h'], ['Método pagamento', 'h'], ['Status contratação', 'h'], ['Departamento/Cargo', 'h'],
                ['CPF', 'h'], ['Telefone', 'h'], ['E-mail', 'h']
            ]);
            $startDataRow = 6;
            $rowIndex = $startDataRow;
            foreach ($items as $row) {
                $diasPendente = !empty($row['created_at']) ? (int)floor((time() - strtotime((string)$row['created_at'])) / 86400) : 0;
                $isWarn = $status === 'pendente' && $diasPendente > 30;
                $style = $isWarn ? 'warn' : null;
                $comissao = isset($row['indicacao_valor_comissao']) && $row['indicacao_valor_comissao'] !== null ? number_format((float)$row['indicacao_valor_comissao'], 2, ',', '.') : '-';
                $xml .= $this->excelRow([
                    [(string)($row['nome'] ?? '-'), $style],
                    [(string)($row['indicacao_colaborador_nome'] ?? '-'), $style],
                    [$this->paymentStatusLabel($row), $style],
                    [!empty($row['created_at']) ? date('d/m/Y', strtotime((string)$row['created_at'])) : '-', $style],
                    [$comissao, $style],
                    [!empty($row['indicacao_data_pagamento']) ? date('d/m/Y', strtotime((string)$row['indicacao_data_pagamento'])) : '-', $style],
                    [(string)($row['indicacao_metodo_pagamento'] ?? '-'), $style],
                    [(string)($row['stage_nome'] ?? '-'), $style],
                    [(string)($row['cargo_pretendido'] ?? '-'), $style],
                    [(string)($row['cpf'] ?? '-'), $style],
                    [Phone::format((string)($row['telefone'] ?? '')), $style],
                    [(string)($row['email'] ?? '-'), $style]
                ]);
                $rowIndex++;
            }
            $sumFrom = $startDataRow;
            $sumTo = max($sumFrom, $rowIndex - 1);
            $xml .= '<Row>';
            $xml .= '<Cell ss:StyleID="foot"><Data ss:Type="String">Totais da aba</Data></Cell>';
            $xml .= '<Cell/><Cell/><Cell/>';
            $xml .= '<Cell ss:StyleID="foot" ss:Formula="=COUNT(A' . $sumFrom . ':A' . $sumTo . ')"><Data ss:Type="Number">0</Data></Cell>';
            $xml .= '<Cell/><Cell/><Cell/><Cell/><Cell/><Cell/><Cell/>';
            $xml .= '</Row>';
            $xml .= $this->excelRow([]);
            $xml .= $this->excelRow([['Totais gerais: ' . (int)($totais['total'] ?? 0), 'foot']]);
            $xml .= $this->excelRow([['Conversão: ' . number_format((float)($totais['conversao_percentual'] ?? 0), 2, ',', '.') . '%', 'foot']]);
            $xml .= '</Table></Worksheet>';
        }
        $xml .= '</Workbook>';
        header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
        header('Content-Disposition: attachment; filename="programa-indicacoes-' . date('Ymd-His') . '.xls"');
        echo $xml;
    }

    private function paymentStatusLabel(array $row): string
    {
        $hasPaymentDate = !empty($row['indicacao_data_pagamento']) && strtotime((string)$row['indicacao_data_pagamento']) !== false;
        if ($hasPaymentDate) {
            return 'pago';
        }
        $status = trim(mb_strtolower((string)($row['indicacao_pagamento_status'] ?? ''), 'UTF-8'));
        if ($status === 'pago' && !$hasPaymentDate) {
            return 'pendente';
        }
        if ($status === '') {
            return 'pendente';
        }
        return $status;
    }

    private function truncateText(string $value, int $limit): string
    {
        $text = trim($value);
        if (mb_strlen($text, 'UTF-8') <= $limit) {
            return $text;
        }
        return mb_substr($text, 0, $limit - 1, 'UTF-8') . '…';
    }

    private function buildSimplePdfLandscape(array $lines): string
    {
        $fontSize = 8;
        $lineHeight = 11;
        $marginLeft = 24;
        $marginTop = 560;
        $maxLinesPerPage = 45;
        $chunks = array_chunk($lines, $maxLinesPerPage);
        $objects = [];
        $objects[] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>';
        $pageObjectIndexes = [];
        foreach ($chunks as $chunk) {
            $content = "BT\n/F1 " . $fontSize . " Tf\n";
            $y = $marginTop;
            foreach ($chunk as $line) {
                $line = iconv('UTF-8', 'Windows-1252//TRANSLIT//IGNORE', $line) ?: $line;
                $safe = str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $line);
                $content .= "1 0 0 1 {$marginLeft} {$y} Tm\n(" . $safe . ") Tj\n";
                $y -= $lineHeight;
            }
            $content .= "ET";
            $objects[] = '<< /Length ' . strlen($content) . " >>\nstream\n" . $content . "\nendstream";
            $contentObjIndex = count($objects);
            $objects[] = '<< /Type /Page /Parent 0 0 R /MediaBox [0 0 842 595] /Resources << /Font << /F1 1 0 R >> >> /Contents ' . $contentObjIndex . ' 0 R >>';
            $pageObjectIndexes[] = count($objects);
        }
        $kids = implode(' ', array_map(static fn($i) => $i . ' 0 R', $pageObjectIndexes));
        $objects[] = '<< /Type /Pages /Kids [' . $kids . '] /Count ' . count($pageObjectIndexes) . ' >>';
        $pagesObjIndex = count($objects);
        foreach ($pageObjectIndexes as $idx) {
            $objects[$idx - 1] = str_replace('0 0 R', $pagesObjIndex . ' 0 R', $objects[$idx - 1]);
        }
        $objects[] = '<< /Type /Catalog /Pages ' . $pagesObjIndex . ' 0 R >>';
        $catalogObjIndex = count($objects);
        $pdf = "%PDF-1.4\n";
        $offsets = [0];
        foreach ($objects as $i => $obj) {
            $offsets[] = strlen($pdf);
            $pdf .= ($i + 1) . " 0 obj\n" . $obj . "\nendobj\n";
        }
        $xrefPos = strlen($pdf);
        $pdf .= "xref\n0 " . (count($objects) + 1) . "\n";
        $pdf .= "0000000000 65535 f \n";
        for ($i = 1; $i <= count($objects); $i++) {
            $pdf .= sprintf('%010d 00000 n ', $offsets[$i]) . "\n";
        }
        $pdf .= "trailer\n<< /Size " . (count($objects) + 1) . " /Root " . $catalogObjIndex . " 0 R >>\nstartxref\n" . $xrefPos . "\n%%EOF";
        return $pdf;
    }

    private function excelRow(array $cells): string
    {
        if (empty($cells)) {
            return '<Row></Row>';
        }
        $row = '<Row>';
        foreach ($cells as $cell) {
            $value = (string)($cell[0] ?? '');
            $style = $cell[1] ?? null;
            $row .= '<Cell' . ($style ? ' ss:StyleID="' . $style . '"' : '') . '><Data ss:Type="String">' . $this->xmlEsc($value) . '</Data></Cell>';
        }
        $row .= '</Row>';
        return $row;
    }

    private function xmlEsc(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private function applyScopeFilters(array $filters): array
    {
        $role = Auth::role();
        if ($role === 'rh') {
            $owner = trim((string)($_SESSION['user_name'] ?? ''));
            if ($owner !== '' && trim((string)($filters['indicador'] ?? '')) === '') {
                $filters['indicador'] = $owner;
            }
        }
        return $filters;
    }
}
