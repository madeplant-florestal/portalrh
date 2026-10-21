<?php

/**
 * Unitário — MetadadosSyncEndpointValidator::permitido() (correção 2026-10, auditoria de
 * materialização Cargo×Setor): HTTPS continua obrigatório para qualquer host normal; HTTP só é
 * aceito para destinos EXPLICITAMENTE locais de desenvolvimento (localhost, 127.0.0.1, *.test).
 * Nunca testa rede real — só a função pura de decisão de esquema/host.
 */

require __DIR__ . '/../../app/core/bootstrap.php';

$falhas = [];
$check = static function (bool $cond, string $msg) use (&$falhas): void {
    if (!$cond) {
        $falhas[] = $msg;
        fwrite(STDERR, "  [FALHOU] {$msg}\n");
    } else {
        echo "  [ok] {$msg}\n";
    }
};

// ---- ACEITAR --------------------------------------------------------------------------------------
$check(MetadadosSyncEndpointValidator::permitido('https://portalrh.appmadeplant.com/internal/metadados/setores/sync') === true,
    'ACEITA https://portalrh.appmadeplant.com/... (produção real, HTTPS)');
$check(MetadadosSyncEndpointValidator::permitido('https://qualquer-host-valido.com/teste') === true,
    'ACEITA https://qualquer-host-valido.com/... (qualquer host, desde que HTTPS)');
$check(MetadadosSyncEndpointValidator::permitido('http://localhost/internal/metadados/setores/sync') === true,
    'ACEITA http://localhost/... (destino local explícito)');
$check(MetadadosSyncEndpointValidator::permitido('http://127.0.0.1/internal/metadados/setores/sync') === true,
    'ACEITA http://127.0.0.1/... (destino local explícito)');
$check(MetadadosSyncEndpointValidator::permitido('http://rhmadeplant.test/internal/metadados/setores/sync') === true,
    'ACEITA http://rhmadeplant.test/... (host termina em .test — Laragon local)');

// ---- REJEITAR ---------------------------------------------------------------------------------------
$check(MetadadosSyncEndpointValidator::permitido('http://portalrh.appmadeplant.com/internal/metadados/setores/sync') === false,
    'REJEITA http://portalrh.appmadeplant.com/... (produção real, HTTP não permitido)');
$check(MetadadosSyncEndpointValidator::permitido('http://appmadeplant.com/teste') === false,
    'REJEITA http://appmadeplant.com/... (domínio externo, não local)');
$check(MetadadosSyncEndpointValidator::permitido('http://192.168.1.10/teste') === false,
    'REJEITA http://192.168.1.10/... (IP externo — só 127.0.0.1 é aceito, nunca outro IP)');
$check(MetadadosSyncEndpointValidator::permitido('não é uma url') === false,
    'REJEITA URL inválida');
$check(MetadadosSyncEndpointValidator::permitido('') === false,
    'REJEITA string vazia');

// ---- casos adicionais de robustez (não pedidos explicitamente, mas cobrem a borda da regra) -------
$check(MetadadosSyncEndpointValidator::permitido('http://RHMADEPLANT.TEST/sync') === true,
    'ACEITA host .test em caixa alta (comparação sempre case-insensitive)');
$check(MetadadosSyncEndpointValidator::permitido('http://naologicamentetest/sync') === false,
    'REJEITA host que só "contém" test sem terminar exatamente em .test (ex.: "naologicamentetest")');
$check(MetadadosSyncEndpointValidator::permitido('ftp://localhost/sync') === false,
    'REJEITA esquema que não é http nem https, mesmo em host local');
$check(MetadadosSyncEndpointValidator::permitido('https://') === false,
    'REJEITA HTTPS sem host');

if ($falhas !== []) {
    fwrite(STDERR, "\n" . count($falhas) . " falha(s).\n");
    exit(1);
}

echo "\nMETADADOS_SYNC_ENDPOINT_VALIDATOR_OK\n";
