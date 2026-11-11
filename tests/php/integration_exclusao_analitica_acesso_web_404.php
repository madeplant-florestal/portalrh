<?php

/**
 * Integração — a barreira de acesso individual, em SAPI WEB (php-cgi): para contrato excluído, RH/Viewer/
 * visitante recebem HTTP 404 e o corpo NÃO contém o registro; Admin em GET recebe 200 com o registro;
 * Admin em POST recebe 404. Fora do CLI o ramo `http_response_code(404); exit;` de
 * ColaboradorMetadadosElegibilidadeService::acessoIndividual() é o que protege os endpoints.
 *
 * Roda um script filho via php-cgi (sem servidor HTTP). PULA se o php-cgi não estiver disponível.
 */

if (PHP_SAPI !== 'cli') {
    exit(0);
}
$cgi = dirname(PHP_BINARY) . DIRECTORY_SEPARATOR . 'php-cgi' . (DIRECTORY_SEPARATOR === '\\' ? '.exe' : '');
if (!is_file($cgi)) {
    echo "PULADO: php-cgi não encontrado em " . dirname(PHP_BINARY) . "\n";
    exit(0);
}

require __DIR__ . '/../../app/core/bootstrap.php';
require __DIR__ . '/support/exclusao_fixtures.php';

$pdo = Database::conn();
$S = ColaboradorMetadadosElegibilidadeService::class;
if ($S::modoSchema() !== $S::MODO_NATURAL) {
    echo "PULADO: migration 2026-11-11 (chave natural) não aplicada neste banco.\n";
    exit(0);
}

$falhas = [];
$check = static function (bool $cond, string $msg) use (&$falhas): void {
    if (!$cond) {
        $falhas[] = $msg;
        fwrite(STDERR, "  [FALHOU] {$msg}\n");
    } else {
        echo "  [ok] {$msg}\n";
    }
};

$fx = new ExclusaoFixtures($pdo);
$scriptFilho = tempnam(sys_get_temp_dir(), 'excl404');

try {
    $idExc = $fx->contrato('1', 'PZW1', 'ZZEXF Web Excluido');
    $idOk = $fx->contrato('2', 'PZW2', 'ZZEXF Web Normal');
    $pdiExc = $fx->pdi($idExc, 'ZZEXF PDI Excluido');
    $pdiOk = $fx->pdi($idOk, 'ZZEXF PDI Normal');
    $fx->excluir('1');

    // Script filho: simula a requisição (perfil/método vêm do ambiente) e carrega UM PDI pelo id, como o controller faz.
    file_put_contents($scriptFilho, '<?php
require ' . var_export(realpath(__DIR__ . '/../../app/core/bootstrap.php'), true) . ';
$_SESSION = getenv("T_ROLE") !== false && getenv("T_ROLE") !== "" ? ["user_role" => getenv("T_ROLE"), "user_id" => 1] : [];
$pdi = (new PdiRepository())->buscarPdi((int)getenv("T_PDI"));
header("Content-Type: text/plain; charset=utf-8");
echo $pdi === null ? "VAZIO" : ("PDI:" . $pdi["snap_nome"]);
echo ColaboradorMetadadosElegibilidadeService::avisoAuditoria() !== null ? "|AVISO" : "";
');

    $rodar = static function (string $role, string $metodo, int $pdiId) use ($cgi, $scriptFilho): array {
        $env = [
            'REQUEST_METHOD' => $metodo, 'SCRIPT_FILENAME' => $scriptFilho, 'REDIRECT_STATUS' => '200',
            'GATEWAY_INTERFACE' => 'CGI/1.1', 'SERVER_PROTOCOL' => 'HTTP/1.1', 'T_ROLE' => $role, 'T_PDI' => (string)$pdiId,
            'SystemRoot' => getenv('SystemRoot') ?: 'C:\\Windows', 'PATH' => getenv('PATH') ?: '',
        ];
        $proc = proc_open([$cgi, '-q', $scriptFilho], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
        $out = stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        proc_close($proc);
        $status = 200;
        if (preg_match('/^Status:\s*(\d{3})/mi', $out, $m)) {
            $status = (int)$m[1];
        }
        $corpo = preg_replace('/^.*?\r?\n\r?\n/s', '', $out, 1) ?? $out;
        return ['status' => $status, 'corpo' => trim((string)$corpo), 'erro' => trim($err)];
    };

    $r = $rodar('', 'GET', $pdiOk);
    $check($r['status'] === 200 && str_contains($r['corpo'], 'PDI:ZZEXF PDI Normal'), 'Web: PDI de contrato legítimo → 200 com o registro (visitante, GET)');
    $r = $rodar('rh', 'GET', $pdiExc);
    $check($r['status'] === 404 && !str_contains($r['corpo'], 'ZZEXF PDI Excluido'), 'Web: RH (GET) no PDI de contrato excluído → HTTP 404 e corpo sem o registro');
    $r = $rodar('viewer', 'GET', $pdiExc);
    $check($r['status'] === 404 && !str_contains($r['corpo'], 'ZZEXF PDI Excluido'), 'Web: Viewer (GET) → HTTP 404');
    $r = $rodar('', 'GET', $pdiExc);
    $check($r['status'] === 404 && !str_contains($r['corpo'], 'ZZEXF PDI Excluido'), 'Web: visitante sem sessão (GET) → HTTP 404');
    $r = $rodar('admin', 'GET', $pdiExc);
    $check($r['status'] === 200 && str_contains($r['corpo'], 'PDI:ZZEXF PDI Excluido') && str_contains($r['corpo'], '|AVISO'), 'Web: Admin (GET) → 200 com o registro e marcado para o aviso de auditoria');
    $r = $rodar('admin', 'POST', $pdiExc);
    $check($r['status'] === 404 && !str_contains($r['corpo'], 'ZZEXF PDI Excluido'), 'Web: Admin (POST) sobre contrato excluído → HTTP 404 (consulta é só leitura)');

    echo "\nEXCLUSAO_ANALITICA_ACESSO_WEB_404_OK\n";
} finally {
    @unlink($scriptFilho);
    $fx->limpar();
}

if ($falhas !== []) {
    fwrite(STDERR, "\n" . count($falhas) . " verificação(ões) falharam.\n");
    exit(1);
}
echo "\nEXCLUSAO_ANALITICA_ACESSO_WEB_404_OK\n";
