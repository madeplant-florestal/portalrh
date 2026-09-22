<?php

/**
 * Unitário — assets JS públicos e CSP (fechamento técnico do Bloco B). Prova que:
 *   - todo `assets/*.js` referenciado por views/layouts (tag literal ou `ui_script_pagina('x.js')`) EXISTE em `public/assets/`
 *     (o 404 de `phone-utils.js` era um arquivo só em `assets/`, nunca publicado) e as cópias versionadas são idênticas à fonte;
 *   - a CSP do Portal continua `script-src 'self'` (não foi relaxada) e as telas migradas do módulo Recrutamento NÃO têm
 *     `<script>` inline nem `onclick=`/`onsubmit=` (que essa CSP bloquearia) — o JS vive em arquivos locais registrados por
 *     `ui_script_pagina()` e carregados só nas páginas que precisam;
 *   - o registro de scripts aceita só nomes simples `x.js`, sem duplicar, e o layout do AppShell V2 emite as tags com `?v=`.
 * Verificação por código/comportamento — não depende do estado do Git.
 */

require __DIR__ . '/../../app/core/bootstrap.php';
require_once APP_PATH . '/views/partials/ui-shell.php';

$falhas = [];
$check = static function (bool $cond, string $msg) use (&$falhas): void {
    if (!$cond) {
        $falhas[] = $msg;
        fwrite(STDERR, "  [FALHOU] {$msg}\n");
    } else {
        echo "  [ok] {$msg}\n";
    }
};
$raiz = str_replace('\\', '/', BASE_PATH);

// ---- referências a JS existem em public/assets ---------------------------------------------------------------------------
$referenciados = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($raiz . '/app/views', FilesystemIterator::SKIP_DOTS));
$fontesViews = [];
foreach ($it as $arq) {
    if ($arq->isFile() && $arq->getExtension() === 'php') {
        $c = (string)file_get_contents($arq->getPathname());
        $fontesViews[str_replace('\\', '/', $arq->getPathname())] = $c;
        if (preg_match_all('#/assets/([A-Za-z0-9._-]+\.js)#', $c, $m)) {
            foreach ($m[1] as $j) {
                $referenciados[$j] = true;
            }
        }
        if (preg_match_all("#ui_script_pagina\\('([a-z0-9._-]+\\.js)'\\)#", $c, $m)) {
            foreach ($m[1] as $j) {
                $referenciados[$j] = true;
            }
        }
    }
}
ksort($referenciados);
$ausentes = array_values(array_filter(array_keys($referenciados), static fn(string $j): bool => !is_file($raiz . '/public/assets/' . $j)));
$check($ausentes === [], '(assets) Todo JS referenciado pelas views/layouts existe em public/assets (' . implode(', ', array_keys($referenciados)) . ')' . ($ausentes === [] ? '' : ' — FALTAM: ' . implode(', ', $ausentes)));
$check(is_file($raiz . '/public/assets/phone-utils.js') && (string)file_get_contents($raiz . '/assets/phone-utils.js') === (string)file_get_contents($raiz . '/public/assets/phone-utils.js'), '(phone-utils) O asset agora é publicado em public/assets e idêntico à fonte — fim do 404 (a máscara de telefone do site público, que depende de window.PhoneUtils, volta a funcionar)');
$divergentes = [];
foreach (['admin.js', 'phone-utils.js', 'candidaturas.js', 'indicacoes.js', 'colaboradores.js', 'usuarios.js', 'share-utils.js', 'integracao-qr.js', 'qrcode.js', 'indicadores-rh.js', 'manual.js'] as $j) {
    if (!is_file($raiz . '/assets/' . $j) || !is_file($raiz . '/public/assets/' . $j) || (string)file_get_contents($raiz . '/assets/' . $j) !== (string)file_get_contents($raiz . '/public/assets/' . $j)) {
        $divergentes[] = $j;
    }
}
$check($divergentes === [], '(cópias) assets/ e public/assets/ idênticos para os JS do Portal administrativo' . ($divergentes === [] ? '' : ': DIVERGEM ' . implode(', ', $divergentes)));

// ---- CSP ---------------------------------------------------------------------------------------------------------------------
$htaccess = (string)file_get_contents($raiz . '/public/.htaccess');
preg_match('/Content-Security-Policy "([^"]*)"/', $htaccess, $csp);
$check(isset($csp[1]) && preg_match("/script-src 'self'(;|$)/", $csp[1]) === 1 && !preg_match("/script-src[^;]*unsafe-(inline|eval)/", $csp[1]) && !str_contains($csp[1], 'nonce-'), '(CSP) A política segue script-src \'self\' — sem unsafe-inline/unsafe-eval e sem nonce improvisado');
$modulo = ['dashboard-recrutamento.php', 'solicitacoes_vaga/', 'candidaturas/', 'pipeline/', 'vagas/', 'recruitment_webhooks/', 'indicacoes/', 'central.php', 'colaboradores/', 'usuarios/', 'pdis/', 'dashboard-entrevista-desligamento.php', 'dashboard-turnover.php', 'entrevista_desligamento/', 'pesquisa_integracao_qr/', 'pesquisa_reacao_integracao/', 'dashboard.php', 'indicadores-rh.php', 'manual.php', 'avaliacoes/'];
$infratores = [];
foreach ($fontesViews as $caminho => $conteudo) {
    $relativo = substr($caminho, strlen($raiz . '/app/views/admin/'));
    $ehModulo = false;
    foreach ($modulo as $m) {
        if (str_starts_with($relativo, $m)) {
            $ehModulo = true;
        }
    }
    if (!$ehModulo || !str_starts_with($caminho, $raiz . '/app/views/admin/')) {
        continue;
    }
    $limpo = preg_replace('/<\?php.*?\?>/s', '', $conteudo);
    if (preg_match('/<script(?![^>]*\bsrc=)(?![^>]*application\/json)[^>]*>/i', $limpo) || preg_match('/\son(click|submit|change|input|load|keyup|blur|focus)\s*=/i', $limpo)) {
        $infratores[] = $relativo;
    }
}
$check($infratores === [], '(CSP) Nenhuma tela dos módulos migrados (Recrutamento, Colaboradores, Usuários, PDI, Pesquisas, Turnover/Desligamento e Indicadores) tem <script> inline nem onclick=/onsubmit= (a CSP os bloquearia)' . ($infratores === [] ? '' : ': ' . implode(', ', $infratores)));

// ---- registro de scripts por página ---------------------------------------------------------------------------------------------
ui_script_pagina('candidaturas.js');
ui_script_pagina('candidaturas.js');
ui_script_pagina('../hack.js');
ui_script_pagina('http://x.com/a.js');
ui_script_pagina('sem-extensao');
ui_script_pagina('indicacoes.js');
$check(ui_script_pagina() === ['candidaturas.js', 'indicacoes.js'], '(registro) Só nomes simples x.js, sem duplicar; caminhos, URLs e nomes inválidos são ignorados');
$html = (new View())->renderPartial('layouts/app-shell', ['base' => '', 'content' => 'x']);
$check(preg_match('#<script src="/assets/candidaturas\.js\?v=\d+" defer></script>#', $html) === 1 && preg_match('#<script src="/assets/indicacoes\.js\?v=\d+" defer></script>#', $html) === 1 && !str_contains($html, 'hack.js') && substr_count($html, 'candidaturas.js') === 1, '(layout) O AppShell V2 emite <script src defer> com ?v= só para os scripts registrados pela página');
$check(!str_contains((string)file_get_contents($raiz . '/app/views/layouts/app-shell.php'), '<script>') && substr_count($html, 'assets/admin.js') === 1, '(layout) Continua sem script inline; admin.js segue global e os scripts de página são adicionais');

// ---- os JS extraídos preservam a lógica -----------------------------------------------------------------------------------------
$cand = (string)file_get_contents($raiz . '/assets/candidaturas.js');
$ind = (string)file_get_contents($raiz . '/assets/indicacoes.js');
$check(str_contains($cand, "getElementById('indicacao-modal')") && str_contains($cand, 'data-indicacao-check') && str_contains($cand, 'indicacao-colaborador-check') && str_contains($cand, 'data-toggle-target') && str_contains($cand, 'form.submit()'), '(candidaturas.js) Mantém modal de indicação, checkbox do colaborador indicador e o toggle do histórico (antes onclick inline)');
$check(str_contains($ind, "getElementById('pagamento-modal')") && str_contains($ind, 'validateDate') && str_contains($ind, 'data-pagamento-open') && str_contains($ind, 'data-pagamento-edit-open') && str_contains($ind, '90'), '(indicacoes.js) Mantém os modais de pagamento, a máscara/validação de data (não futura, até 90 dias) e os data-attributes');
$check(str_contains($cand, 'return; // outras telas') && str_contains($ind, 'if (!modal || !editModal'), '(js) Blocos se protegem quando os elementos não existem (arquivo seguro em qualquer tela)');

if ($falhas !== []) {
    fwrite(STDERR, "\n" . count($falhas) . " verificação(ões) falharam.\n");
    exit(1);
}
echo "\nUNIT_ASSETS_PUBLICOS_OK\n";
