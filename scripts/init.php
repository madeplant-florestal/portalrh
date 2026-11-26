<?php
require_once __DIR__ . '/../app/core/bootstrap.php';
require_once __DIR__ . '/lib/senha_cli.php';
// Uso: RH_ADMIN_SENHA='...' php scripts/init.php <email> [--seed]   (ou sem a variável, digitando a senha no terminal)
// Sem e-mail ou sem senha: nada é criado. Nunca existe senha padrão.
$email = trim((string)($argv[1] ?? ''));
if ($email === '' || str_starts_with($email, '--') || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    senhaCliAbortar('Informe o e-mail do administrador: php scripts/init.php <email> [--seed]. Nada foi alterado.');
}
senhaCliRecusarArgumento($argv, 2, 'RH_ADMIN_SENHA');
$seed = in_array('--seed', $argv, true);

$existing = User::findByEmail($email);
if ($existing) {
    echo "Usuário já existe: {$email} (senha NÃO alterada)\n";
} else {
    $hash = password_hash(senhaCliObrigatoria('RH_ADMIN_SENHA'), PASSWORD_BCRYPT);
    $id = User::create('Administrador', $email, $hash, 'admin');
    echo "Usuário admin criado (ID: {$id}) para {$email}\n";
}

if ($seed) {
    $vagas = Vaga::all();
    if (count($vagas) > 0) {
        echo "Já existem vagas cadastradas. Seed ignorado.\n";
    } else {
        Vaga::create([
            'titulo' => 'Desenvolvedor PHP Pleno',
            'descricao' => 'Atuação em projetos web, integração com APIs e manutenção de sistemas.',
            'requisitos' => 'PHP 8, MySQL, MVC, Git, testes básicos.',
            'area' => 'Tecnologia',
            'local' => 'São Paulo - SP',
            'ativo' => 1,
        ]);
        Vaga::create([
            'titulo' => 'Analista de Marketing Digital',
            'descricao' => 'Planejamento e execução de campanhas, SEO/SEM e análise de métricas.',
            'requisitos' => 'Google Analytics, Ads, SEO, Copywriting.',
            'area' => 'Marketing',
            'local' => 'Remoto',
            'ativo' => 1,
        ]);
        Vaga::create([
            'titulo' => 'Assistente Administrativo',
            'descricao' => 'Rotinas administrativas, atendimento e suporte a RH.',
            'requisitos' => 'Pacote Office, comunicação, organização.',
            'area' => 'Administrativo',
            'local' => 'Curitiba - PR',
            'ativo' => 1,
        ]);
        echo "Vagas de exemplo criadas.\n";
    }
}

echo "Concluído.\n";