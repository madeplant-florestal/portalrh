<?php
require __DIR__ . '/../../app/core/bootstrap.php';

$controller = new AdminController();
$reflection = new ReflectionClass($controller);
$fallbackMethod = $reflection->getMethod('dashboardDataset');
$fallbackMethod->setAccessible(true);
$fallback = $fallbackMethod->invoke($controller);

$service = new CollaboratorDashboardDataService();
$dataset = $service->build($fallback, 'maio-2026', 'todas');

if (!isset($dataset['kpis'][0]['value'], $dataset['sourceSummary'])) {
    throw new RuntimeException('O dataset do dashboard nao foi montado corretamente.');
}

$sourceSummary = $dataset['sourceSummary'];
if (!isset($sourceSummary['real_metrics'], $sourceSummary['fallback_metrics'], $sourceSummary['notes'])) {
    throw new RuntimeException('O resumo de origem de dados do dashboard nao foi preenchido.');
}

if (($sourceSummary['real_metrics'] ?? 0) < 1) {
    throw new RuntimeException('Era esperado ao menos um indicador real derivado de colaboradores.');
}

if (!is_array($dataset['colaboradoresArea']) || $dataset['colaboradoresArea'] === []) {
    throw new RuntimeException('A distribuicao real por area deveria estar disponivel.');
}

if (!is_array($dataset['tempoEmpresa']) || $dataset['tempoEmpresa'] === []) {
    throw new RuntimeException('A distribuicao real por tempo de empresa deveria estar disponivel.');
}

echo "DASHBOARD_COLABORADORES_SERVICE_OK\n";
