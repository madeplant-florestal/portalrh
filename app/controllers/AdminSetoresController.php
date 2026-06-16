<?php
class AdminSetoresController extends AdminCatalogosController
{
    private CargoSetorService $cargoSetorService;
    private SetorService $setorService;

    public function __construct()
    {
        parent::__construct();
        $this->cargoSetorService = new CargoSetorService();
        $this->setorService = new SetorService();
    }

    public function index(): void
    {
        Auth::requireRole(['admin', 'rh', 'viewer']);

        $filters = $this->normalizeFilters();
        $page = max(1, (int)($_GET['page'] ?? 1));
        $result = $this->setorService->paginateAdmin($filters, $page, 15);

        $this->view->render('admin/setores/index', [
            'meta' => CadastroOrganizacional::meta('setores'),
            'routeBase' => '/admin/setores',
            'items' => $result['items'],
            'filters' => $filters,
            'summary' => $this->setorService->reportSummary($filters),
            'companies' => $this->setorService->companyFilterOptions(),
            'legacyWithoutEmpresaCount' => $this->setorService->legacyWithoutEmpresaCount(),
            'flashError' => Security::sanitizeString($_GET['erro'] ?? ''),
            'flashSuccess' => Security::sanitizeString($_GET['ok'] ?? ''),
            'page' => $result['page'],
            'pages' => $result['pages'],
            'perPage' => $result['per_page'],
            'total' => $result['total'],
        ], 'layouts/admin');
    }

    public function create(): void
    {
        Auth::requireRole(['admin', 'rh']);
        $this->renderForm([
            'item' => null,
            'error' => '',
            'flashError' => '',
            'flashSuccess' => '',
            'linkedCargos' => [],
            'availableCargos' => [],
            'companies' => $this->setorService->companyOptions(),
            'formAction' => '/admin/setores/novo',
        ]);
    }

    public function store(): void
    {
        Auth::requireRole(['admin', 'rh']);
        if (!Security::csrfCheck($_POST['csrf'] ?? '')) {
            http_response_code(400);
            echo 'CSRF inválido';
            return;
        }

        $data = $this->captureFormData();

        try {
            $setorId = $this->setorService->create($data);
            redirect('/admin/setores?ok=' . urlencode('Setor cadastrado com sucesso.'));
        } catch (Throwable $e) {
            $this->renderForm([
                'item' => $data,
                'error' => $this->normalizeError($e, 'setores'),
                'flashError' => '',
                'flashSuccess' => '',
                'linkedCargos' => [],
                'availableCargos' => [],
                'companies' => $this->setorService->companyOptions(),
                'formAction' => '/admin/setores/novo',
            ]);
        }
    }

    public function edit(string $id): void
    {
        Auth::requireRole(['admin', 'rh']);
        $setorId = (int)$id;

        try {
            $relationData = $this->cargoSetorService->dadosTelaSetor($setorId);
        } catch (Throwable $e) {
            http_response_code(404);
            echo 'Setor não encontrado';
            return;
        }

        $this->renderForm([
            'item' => $relationData['setor'],
            'error' => '',
            'flashError' => Security::sanitizeString($_GET['erro'] ?? ''),
            'flashSuccess' => Security::sanitizeString($_GET['ok'] ?? ''),
            'linkedCargos' => $relationData['linkedCargos'],
            'availableCargos' => $relationData['availableCargos'],
            'companies' => $this->setorService->companyOptions(),
            'formAction' => '/admin/setores/editar/' . $setorId,
        ]);
    }

    public function update(string $id): void
    {
        Auth::requireRole(['admin', 'rh']);
        $setorId = (int)$id;
        if (!Security::csrfCheck($_POST['csrf'] ?? '')) {
            http_response_code(400);
            echo 'CSRF inválido';
            return;
        }

        $current = $this->setorService->find($setorId);
        if (!$current) {
            http_response_code(404);
            echo 'Setor não encontrado';
            return;
        }

        $data = $this->captureFormData();
        $data['id'] = $setorId;

        try {
            $this->setorService->update($setorId, $data);
            redirect('/admin/setores/editar/' . $setorId . '?ok=' . urlencode('Setor atualizado com sucesso.'));
        } catch (Throwable $e) {
            $relationData = $this->cargoSetorService->dadosTelaSetor($setorId);
            $this->renderForm([
                'item' => $data,
                'error' => $this->normalizeError($e, 'setores'),
                'flashError' => '',
                'flashSuccess' => '',
                'linkedCargos' => $relationData['linkedCargos'],
                'availableCargos' => $relationData['availableCargos'],
                'companies' => $this->setorService->companyOptions(),
                'formAction' => '/admin/setores/editar/' . $setorId,
            ]);
        }
    }

    public function delete(string $id): void
    {
        $this->handleDelete('setores', (int)$id);
    }

    public function export(): void
    {
        Auth::requireRole(['admin', 'rh', 'viewer']);

        $format = strtolower(Security::sanitizeString($_GET['format'] ?? 'excel'));
        $filters = $this->normalizeFilters();
        $rows = $this->setorService->exportDataset($filters);
        if (empty($rows)) {
            redirect('/admin/setores?erro=' . urlencode('Nenhum dado encontrado para exportação com os filtros atuais.'));
        }

        if ($format === 'csv') {
            $this->exportCsv($rows);
            return;
        }

        if ($format === 'pdf') {
            $this->exportPdf($rows, $filters);
            return;
        }

        $this->exportExcel($rows, $filters);
    }

    public function sanitizeLegacy(): void
    {
        Auth::requireRole(['admin', 'rh']);
        if (!Security::csrfCheck($_POST['csrf'] ?? '')) {
            http_response_code(400);
            echo 'CSRF inválido';
            return;
        }

        try {
            $count = $this->setorService->sanitizeLegacyWithoutEmpresa((int)($_POST['empresa_id'] ?? 0));
            redirect('/admin/setores?ok=' . urlencode($count . ' setor(es) legado(s) saneado(s) com empresa vinculada.'));
        } catch (Throwable $e) {
            redirect('/admin/setores?erro=' . urlencode($e->getMessage()));
        }
    }

    private function renderForm(array $data): void
    {
        $this->view->render('admin/setores/form', [
            'meta' => CadastroOrganizacional::meta('setores'),
            'table' => 'setores',
            'routeBase' => '/admin/setores',
            'formAction' => $data['formAction'],
            'item' => $data['item'],
            'error' => $data['error'] ?? '',
            'flashError' => $data['flashError'] ?? '',
            'flashSuccess' => $data['flashSuccess'] ?? '',
            'csrf' => Security::csrfToken(),
            'linkedCargos' => $data['linkedCargos'] ?? [],
            'availableCargos' => $data['availableCargos'] ?? [],
            'companies' => $data['companies'] ?? [],
        ], 'layouts/admin');
    }

    private function captureFormData(): array
    {
        return [
            'nome' => Security::sanitizeString($_POST['nome'] ?? ''),
            'slug' => Security::sanitizeString($_POST['slug'] ?? ''),
            'ativo' => isset($_POST['ativo']) ? 1 : 0,
            'empresa_id' => $_POST['empresa_id'] ?? null,
        ];
    }

    private function normalizeFilters(): array
    {
        return [
            'q' => Security::sanitizeString($_GET['q'] ?? ''),
            'status' => Security::sanitizeString($_GET['status'] ?? ''),
            'empresa' => Security::sanitizeString($_GET['empresa'] ?? ''),
        ];
    }

    private function exportCsv(array $rows): void
    {
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="setores-' . date('Ymd-His') . '.csv"');
        echo "\xEF\xBB\xBF";

        $output = fopen('php://output', 'wb');
        fputcsv($output, ['Setor', 'Empresa', 'Slug', 'Status', 'Cargos vinculados', 'Colaboradores vinculados', 'Data cadastro'], ';');
        foreach ($rows as $row) {
            fputcsv($output, [
                (string)($row['nome'] ?? ''),
                (string)($row['empresa_nome'] ?? 'Sem empresa'),
                (string)($row['slug'] ?? ''),
                (int)($row['ativo'] ?? 0) === 1 ? 'Ativo' : 'Inativo',
                (int)($row['cargos_vinculados'] ?? 0),
                (int)($row['colaboradores_vinculados'] ?? 0),
                !empty($row['created_at']) ? date('d/m/Y H:i', strtotime((string)$row['created_at'])) : '-',
            ], ';');
        }
        fclose($output);
    }

    private function exportExcel(array $rows, array $filters): void
    {
        $empresaFiltro = trim((string)($filters['empresa'] ?? ''));
        $empresaLabel = $empresaFiltro === 'sem_empresa' ? 'Somente sem empresa'
            : ($empresaFiltro !== '' ? 'Empresa específica' : 'Todas');

        $xml = '<?xml version="1.0" encoding="UTF-8"?>';
        $xml .= '<?mso-application progid="Excel.Sheet"?>';
        $xml .= '<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet" xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet">';
        $xml .= '<Styles><Style ss:ID="h"><Font ss:Bold="1"/><Interior ss:Color="#DCEEFF" ss:Pattern="Solid"/></Style><Style ss:ID="foot"><Font ss:Bold="1"/></Style></Styles>';
        $xml .= '<Worksheet ss:Name="Setores"><Table>';
        $xml .= $this->excelRow([['Relatório de setores', 'h']]);
        $xml .= $this->excelRow([['Filtro empresa: ' . $empresaLabel, null]]);
        $xml .= $this->excelRow([['Gerado em: ' . date('d/m/Y H:i'), null]]);
        $xml .= $this->excelRow([]);
        $xml .= $this->excelRow([
            ['Setor', 'h'], ['Empresa', 'h'], ['Slug', 'h'], ['Status', 'h'], ['Cargos vinculados', 'h'], ['Colaboradores vinculados', 'h'], ['Data cadastro', 'h']
        ]);
        foreach ($rows as $row) {
            $xml .= $this->excelRow([
                [(string)($row['nome'] ?? ''), null],
                [(string)($row['empresa_nome'] ?? 'Sem empresa'), null],
                [(string)($row['slug'] ?? ''), null],
                [(int)($row['ativo'] ?? 0) === 1 ? 'Ativo' : 'Inativo', null],
                [(string)(int)($row['cargos_vinculados'] ?? 0), null],
                [(string)(int)($row['colaboradores_vinculados'] ?? 0), null],
                [!empty($row['created_at']) ? date('d/m/Y H:i', strtotime((string)$row['created_at'])) : '-', null],
            ]);
        }
        $xml .= '</Table></Worksheet></Workbook>';

        header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
        header('Content-Disposition: attachment; filename="setores-' . date('Ymd-His') . '.xls"');
        echo $xml;
    }

    private function exportPdf(array $rows, array $filters): void
    {
        $empresaFiltro = trim((string)($filters['empresa'] ?? ''));
        $empresaLabel = $empresaFiltro === 'sem_empresa' ? 'Somente sem empresa'
            : ($empresaFiltro !== '' ? 'Empresa específica' : 'Todas');

        $lines = [
            'RH Madeplant - Relatório de setores',
            'Filtro empresa: ' . $empresaLabel,
            'Gerado em: ' . date('d/m/Y H:i'),
            '',
            'Setor | Empresa | Status | Cargos | Colaboradores',
        ];

        foreach ($rows as $row) {
            $lines[] = implode(' | ', [
                $this->truncateText((string)($row['nome'] ?? ''), 24),
                $this->truncateText((string)($row['empresa_nome'] ?? 'Sem empresa'), 24),
                (int)($row['ativo'] ?? 0) === 1 ? 'Ativo' : 'Inativo',
                (string)(int)($row['cargos_vinculados'] ?? 0),
                (string)(int)($row['colaboradores_vinculados'] ?? 0),
            ]);
        }

        $pdf = $this->buildSimplePdfLandscape($lines);
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="setores-' . date('Ymd-His') . '.pdf"');
        header('Content-Length: ' . strlen($pdf));
        echo $pdf;
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
}
