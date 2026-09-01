<?php
return [
    'app' => [
        'name' => 'RH Madeplant',
        'version' => '1.0.0',
        'release_date' => '',
        'base_url' => '',
        'public_jobs_url' => '',
        'env' => 'auto'
    ],
    'database' => [
        'dsn' => '',
        'user' => '',
        'pass' => ''
    ],
    // METADADOS (SQL Server) — sistema oficial de RH/DP, fonte de verdade de colaboradores.
    // Ex.: 'dsn' => 'sqlsrv:Server=servidor,1433;Database=METADADOS'. Exige a extensão
    // pdo_sqlsrv instalada. Usado só por scripts/sync_metadados_colaboradores.php.
    'metadados' => [
        'dsn' => '',
        'user' => '',
        'pass' => ''
    ],
    // Sincronização segura RHMADEPLANT -> Portal RH em produção (Fase 4): segredo HMAC
    // compartilhado entre scripts/sync_metadados_producao.php (sender, roda na rede Madeplant) e
    // o endpoint receptor /internal/metadados/colaboradores/sync. Gere com algo como
    // bin2hex(random_bytes(32)) e configure o MESMO valor nos dois lados, só em local.php.
    'metadados_sync' => [
        'shared_secret' => '',
        'endpoint_url' => 'https://portalrh.appmadeplant.com/internal/metadados/colaboradores/sync',
        'max_batch_size' => 2000,
        'replay_window_seconds' => 300
    ],
    'security' => [
        'csrf_key' => 'csrf_token',
        'session_name' => 'RHMADEPLANTSESSID',
        'supervisor_email' => 'admin@seu-dominio.com.br',
        'supervisor_password' => 'troque-por-uma-senha-forte',
        'allowed_upload_mime' => ['application/pdf'],
        'max_upload_bytes' => 5 * 1024 * 1024,
        'allowed_image_mime' => ['image/png', 'image/jpeg', 'image/webp'],
        'max_image_bytes' => 2 * 1024 * 1024,
        // Mantenha "false" em produção. Só ative como "true" em ambiente local para testar
        // webhooks contra um n8n rodando na própria máquina/rede interna — com "false" (padrão),
        // URLs de webhook que resolvem para IP privado/loopback/reservado são bloqueadas (SSRF).
        'webhook_allow_private_targets' => false
    ],
    'mail' => [
        'enabled' => true,
        'from' => 'no-reply@seu-dominio.com.br',
        'to_hr' => 'rh@seu-dominio.com.br',
        'subject_new_application' => 'Nova candidatura recebida',
        'subject_password_recovery' => 'Recuperação de senha',
        'subject_password_changed' => 'Senha redefinida',
        'subject_supervisor_created' => 'Usuário Supervisor criado'
    ],
    'logging' => [
        'level' => 'INFO',
        'alert_email' => '',
        'viewer_key' => ''
    ]
];
