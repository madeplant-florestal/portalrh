<?php

/**
 * Geração do PDF da Carta Proposta (dompdf — única dependência Composer do projeto, escopada
 * para esta feature, ver CLAUDE.md §3). `vendor/autoload.php` é carregado só aqui, nunca no
 * bootstrap global: o resto do Portal continua sem Composer.
 *
 * O PDF é salvo em storage/cartas_propostas/ (fora do document root, .htaccess igual a
 * storage/resumes) com nome gerado — nunca o nome original/previsível. Uma vez gerado para uma
 * versão (status 'gerada' em diante), o arquivo nunca é sobrescrito: uma nova versão gera um novo
 * arquivo (ver CartaPropostaService::emitir()).
 */
class CartaPropostaPdfService
{
    public static function gerar(array $proposta): string
    {
        require_once BASE_PATH . '/vendor/autoload.php';

        $html = self::renderizarHtml($proposta);

        $dompdf = new \Dompdf\Dompdf([
            'isRemoteEnabled' => false, // nunca busca recurso externo (CSS/imagem) — só o que é embutido inline
            'isHtml5ParserEnabled' => true,
        ]);
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->render();

        $destDir = STORAGE_PATH . DIRECTORY_SEPARATOR . 'cartas_propostas';
        if (!is_dir($destDir)) {
            @mkdir($destDir, 0775, true);
        }
        $nomeArmazenado = bin2hex(random_bytes(16)) . '.pdf';
        $destPath = $destDir . DIRECTORY_SEPARATOR . $nomeArmazenado;
        if (file_put_contents($destPath, $dompdf->output()) === false) {
            throw new \RuntimeException('Não foi possível salvar o PDF da Carta Proposta.');
        }
        return $nomeArmazenado;
    }

    private static function renderizarHtml(array $proposta): string
    {
        extract($proposta, EXTR_SKIP);
        ob_start();
        require APP_PATH . '/views/carta_proposta/pdf.php';
        return (string)ob_get_clean();
    }
}
