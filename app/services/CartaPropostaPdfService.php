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

        $dompdf = self::novoDompdf();
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

    /**
     * Gera a VIA com comprovante de aceite — PDF SEPARADO do original (nunca sobrescreve/altera
     * o PDF emitido pelo RH), só chamado depois de um aceite já confirmado no banco
     * (CartaPropostaService::registrarDecisao()). Mesmo padrão de gerar(): template HTML próprio
     * (comprovante_pdf.php) renderizado para dompdf, salvo no MESMO diretório storage/cartas_propostas/.
     *
     * @param array $proposta Snapshot da carta (já com status='aceita', decisao_em preenchido).
     */
    public static function gerarComprovante(array $proposta, string $identificador): string
    {
        require_once BASE_PATH . '/vendor/autoload.php';

        $html = self::renderizarHtmlComprovante($proposta + ['comprovante_identificador' => $identificador]);

        $dompdf = self::novoDompdf();
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
            throw new \RuntimeException('Não foi possível salvar a via com comprovante de aceite da Carta Proposta.');
        }
        return $nomeArmazenado;
    }

    /**
     * Dompdf com a configuração ENDURECIDA (única fonte): sem recurso remoto, sem PHP/JavaScript embutidos no HTML, e `chroot` restrito a uma
     * pasta própria e vazia — o padrão do Dompdf permite ler arquivos locais da própria biblioteca. O template não usa imagens nem arquivos
     * locais e todo texto vindo de dados é escapado (Security::e), então nada disso altera o PDF; só fecha as superfícies dos avisos de
     * segurança do Dompdf (SVG/data-URI, font-face, chroot) caso um dia entre conteúdo não confiável.
     */
    private static function novoDompdf(): \Dompdf\Dompdf
    {
        $chroot = STORAGE_PATH . DIRECTORY_SEPARATOR . 'cartas_propostas' . DIRECTORY_SEPARATOR . '.chroot';
        if (!is_dir($chroot)) {
            @mkdir($chroot, 0775, true);
        }
        return new \Dompdf\Dompdf([
            'isRemoteEnabled' => false,
            'isPhpEnabled' => false,
            'isJavascriptEnabled' => false,
            'isHtml5ParserEnabled' => true,
            'chroot' => is_dir($chroot) ? realpath($chroot) : STORAGE_PATH,
        ]);
    }

    private static function renderizarHtmlComprovante(array $proposta): string
    {
        extract($proposta, EXTR_SKIP);
        ob_start();
        require APP_PATH . '/views/carta_proposta/comprovante_pdf.php';
        return (string)ob_get_clean();
    }

    private static function renderizarHtml(array $proposta): string
    {
        extract($proposta, EXTR_SKIP);
        ob_start();
        require APP_PATH . '/views/carta_proposta/pdf.php';
        return (string)ob_get_clean();
    }
}
