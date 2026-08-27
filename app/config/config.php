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
    // METADADOS (SQL Server) — fonte oficial de colaboradores, consumida só pela sincronização
    // (MetadadosSyncService / scripts/sync_metadados_colaboradores.php), nunca durante
    // navegação normal do Portal. Preencha em app/config/local.php ou build.php, nunca aqui.
    'metadados' => [
        'dsn' => '',
        'user' => '',
        'pass' => ''
    ],
    'security' => [
        'csrf_key' => 'csrf_token',
        'session_name' => 'RHMADEPLANTSESSID',
        'supervisor_email' => 'admin@seu-dominio.com.br',
        'supervisor_password' => 'troque-por-uma-senha-forte',
        'allowed_upload_mime' => ['application/pdf'],
        'max_upload_bytes' => 5 * 1024 * 1024,
        'allowed_image_mime' => ['image/png', 'image/jpeg', 'image/webp'],
        'max_image_bytes' => 2 * 1024 * 1024
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
