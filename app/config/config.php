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
    // Sincronização segura RHMADEPLANT -> Portal RH em produção (Fase 4): o sender roda dentro da
    // rede Madeplant e assina o lote com este segredo; o endpoint receptor (produção) verifica a
    // assinatura com o mesmo segredo. Preencha em local.php/build.php, nunca aqui.
    //
    // orchestrator_url / orchestrator_secret (Sincronização operacional — Etapa 1): webhook da
    // camada de orquestração interna (n8n) que o Dashboard de Indicadores aciona para solicitar
    // uma sincronização sob demanda. O Portal só dispara o webhook (HTTPS + HMAC via
    // MetadadosSyncSignature, com este segredo separado) e responde na hora; a conclusão chega
    // pelo receiver já existente. Vazio = botão "Atualizar dados" responde "não configurado".
    // Preencha em local.php/build.php, nunca aqui, nunca no HTML/JS.
    'metadados_sync' => [
        'shared_secret' => '',
        'endpoint_url' => '',
        'max_batch_size' => 2000,
        'replay_window_seconds' => 300,
        'orchestrator_url' => '',
        'orchestrator_secret' => ''
    ],
    'security' => [
        'csrf_key' => 'csrf_token',
        'session_name' => 'RHMADEPLANTSESSID',
        'supervisor_email' => 'admin@seu-dominio.com.br',
        'supervisor_password' => '', // defina SOMENTE em app/config/local.php (não versionado); vazio = "garantir Supervisor" recusado
        'allowed_upload_mime' => ['application/pdf'],
        'max_upload_bytes' => 5 * 1024 * 1024,
        'allowed_image_mime' => ['image/png', 'image/jpeg', 'image/webp'],
        'max_image_bytes' => 2 * 1024 * 1024,
        // Documentos de contratação anexados à candidatura (correção de 2026-11) — separado de
        // allowed_upload_mime (currículo, só PDF): RG/CPF/comprovantes tipicamente chegam como
        // foto/imagem, não só PDF.
        'allowed_document_mime' => ['application/pdf', 'image/png', 'image/jpeg'],
        'max_document_bytes' => 5 * 1024 * 1024
    ],
    // E-mail (Mailer + PHPMailer/SMTP autenticado). Envio DESLIGADO por padrão: ligue `enabled` em app/config/local.php (fora do Git) junto das credenciais.
    // Hostinger: smtp.hostinger.com, porta 465 + 'ssl' (TLS implícito) ou 587 + 'tls' (STARTTLS). A senha vai SOMENTE no local.php.
    'mail' => [
        'enabled' => false,
        'driver' => 'smtp',
        'from' => 'no-reply@appmadeplant.com',
        'from_name' => 'Portal RH Madeplant',
        'reply_to' => '',
        'to_hr' => 'rh@seu-dominio.com.br',
        'smtp_host' => 'smtp.hostinger.com',
        'smtp_port' => 465,
        'smtp_secure' => 'ssl',
        'smtp_user' => 'no-reply@appmadeplant.com',
        'smtp_pass' => '', // NUNCA versionar: definir apenas no local.php do servidor
        'smtp_timeout' => 10,
        'smtp_cafile' => '',
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
