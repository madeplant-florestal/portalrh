<?php
class View
{
    public function render(string $template, array $params = [], string $layout = 'layouts/main'): void
    {
        $params = array_merge(['base' => Config::app()['base_url'] ?? ''], $params);
        $content = $this->renderPartial($template, $params);
        // Importante: imprimir o HTML do layout; antes estava apenas retornando a string
        echo $this->renderPartial($layout, array_merge($params, ['content' => $content]));
    }

    public function renderPartial(string $template, array $params = []): string
    {
        $file = APP_PATH . '/views/' . $template . '.php';
        if (!file_exists($file)) {
            return "View não encontrada: {$template}";
        }
        extract($params);
        ob_start();
        include $file;
        return ob_get_clean();
    }
}