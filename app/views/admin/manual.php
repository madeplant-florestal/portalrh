<?php
$app = Config::app();
$versao = trim((string)($app['version'] ?? ''));
$releaseDate = trim((string)($app['release_date'] ?? ''));
?>
<style>
  .manual-wrap{max-width:1100px;margin:0 auto;color:#0d1321}
  .manual-card{background:#fff;border:1px solid #3e5c76;border-radius:16px;box-shadow:0 8px 24px rgba(13,19,33,.08)}
  .manual-hero{padding:28px;background:linear-gradient(135deg,#0d1321,#1d2d44);color:#fff}
  .manual-kpi{background:rgba(255,255,255,.12);border:1px solid rgba(255,255,255,.22);border-radius:12px;padding:12px}
  .manual-kpi-label{font-size:12px;opacity:.9}
  .manual-kpi-value{font-size:15px;font-weight:700;margin-top:4px}
  .manual-grid{display:grid;gap:16px}
  .manual-grid-3{grid-template-columns:2fr 1fr}
  .manual-grid-2{grid-template-columns:repeat(2,minmax(0,1fr))}
  .manual-title{font-size:34px;line-height:1.15;font-weight:800;letter-spacing:-.01em}
  .manual-subtitle{font-size:17px;line-height:1.6;color:#0d1321}
  .manual-h2{font-size:24px;font-weight:800;color:#0d1321}
  .manual-h3{font-size:18px;font-weight:800;color:#0d1321}
  .manual-p{font-size:16px;line-height:1.7;color:#0d1321}
  .manual-muted{font-size:14px;color:#3e5c76}
  .manual-link{color:#1d2d44;font-weight:700;text-decoration:none}
  .manual-link:hover{text-decoration:underline}
  .manual-section{padding:20px}
  .manual-chip{display:inline-block;background:#3e5c76;color:#fff;border:1px solid #1d2d44;border-radius:999px;padding:3px 10px;font-size:12px;font-weight:700}
  .manual-accordion{display:flex;flex-direction:column;gap:14px}
  .manual-details summary{list-style:none;cursor:pointer;padding:18px 20px;display:flex;justify-content:space-between;align-items:center;gap:12px}
  .manual-details summary::-webkit-details-marker{display:none}
  .manual-summary-left{display:flex;align-items:center;gap:10px}
  .manual-icon{width:22px;height:22px;min-width:22px;display:inline-block;color:#1d2d44}
  .manual-details[open] .manual-chevron{transform:rotate(90deg)}
  .manual-chevron{width:18px;height:18px;color:#3e5c76;transition:transform .2s ease}
  .manual-content{padding:0 20px 20px}
  .manual-block{border:1px solid #3e5c76;background:#fff;border-radius:12px;padding:14px}
  .manual-list{margin:8px 0 0 0;padding-left:18px}
  .manual-list li{margin:5px 0;color:#0d1321}
  @media (max-width:960px){
    .manual-grid-3,.manual-grid-2{grid-template-columns:1fr}
    .manual-title{font-size:30px}
  }
  @media (max-width:640px){
    .manual-hero{padding:20px}
    .manual-title{font-size:28px}
    .manual-h2{font-size:22px}
    .manual-p{font-size:15px}
    .manual-details summary{padding:15px 16px}
    .manual-content{padding:0 16px 16px}
    .manual-section{padding:16px}
  }
</style>

<div class="manual-wrap max-w-6xl">
  <section class="manual-card manual-hero">
    <span class="manual-chip">Central de Ajuda</span>
    <h1 class="manual-title" style="margin-top:10px">Manual de Uso</h1>
    <p class="manual-subtitle" style="margin-top:10px;color:rgba(255,255,255,.92)">Guia operacional do painel administrativo com foco em produtividade, clareza e uso consistente dos módulos reais do sistema.</p>
    <div class="manual-grid manual-grid-2" style="margin-top:16px">
      <div class="manual-kpi"><div class="manual-kpi-label">Acesso</div><div class="manual-kpi-value">Usuários autenticados</div></div>
      <div class="manual-kpi"><div class="manual-kpi-label">Base de conteúdo</div><div class="manual-kpi-value">Módulos reais do painel</div></div>
      <div class="manual-kpi"><div class="manual-kpi-label">Contato rápido</div><a class="manual-link" style="color:#fff;text-decoration:underline" href="https://wa.me/5567993256260" target="_blank" rel="noopener noreferrer">(67) 99325-6260</a></div>
      <div class="manual-kpi"><div class="manual-kpi-label">Canal</div><a class="manual-link" style="color:#fff;text-decoration:underline" href="https://wa.me/5567993256260" target="_blank" rel="noopener noreferrer">wa.me/5567993256260</a></div>
    </div>
  </section>

  <section class="manual-grid manual-grid-3" style="margin-top:18px">
    <article class="manual-card manual-section">
      <h2 class="manual-h2">Sobre o sistema</h2>
      <p class="manual-p" style="margin-top:8px">O portal <img src="<?= $base ?>/assets/logo-escura.png" alt="RH Madeplant" class="h-4 inline align-middle"> concentra operações de recrutamento em um fluxo único: publicação de vagas, recebimento de candidaturas, acompanhamento por etapas no pipeline, gestão de benefícios e administração de usuários com perfis de acesso.</p>
      <p class="manual-p" style="margin-top:8px">As funcionalidades principais incluem painel com indicadores, filtros por vaga/etapa/período, movimentação no kanban, histórico de alterações e download de currículos com nome otimizado por candidato e vaga.</p>
    </article>
    <article class="manual-card manual-section">
      <h2 class="manual-h2">Sobre a empresa</h2>
      <p class="manual-p" style="margin-top:8px"><strong>RH Madeplant</strong> foi estruturado para centralizar processos de RH com foco em segurança, governança e continuidade operacional.</p>
      <p class="manual-muted" style="margin-top:8px">A plataforma reúne publicação de vagas, gestão de candidaturas, pipeline, indicadores e rotinas administrativas em um ambiente único.</p>
      <p class="manual-muted" style="margin-top:8px">A arquitetura foi preparada para evolução incremental, integração com outros sistemas internos e operação em ambiente corporativo.</p>
    </article>
  </section>

  <section class="manual-card manual-section" style="margin-top:18px">
    <h2 class="manual-h2">Versão e atualização</h2>
    <div class="manual-grid manual-grid-2" style="margin-top:12px">
      <div class="manual-block"><p class="manual-muted">Versão</p><p class="manual-p" style="font-weight:700"><?= Security::e($versao !== '' ? $versao : 'não informada na configuração') ?></p></div>
      <div class="manual-block"><p class="manual-muted">Data de release</p><p class="manual-p" style="font-weight:700"><?= Security::e($releaseDate !== '' ? $releaseDate : 'não informada na configuração') ?></p></div>
      <div class="manual-block"><p class="manual-muted">Canal de suporte</p><a class="manual-link" href="https://wa.me/5567993256260" target="_blank" rel="noopener noreferrer">WhatsApp: (67) 99325-6260</a></div>
      <div class="manual-block"><p class="manual-muted">Notas da atualização atual</p><ul class="manual-list"><li>Navegação pública/admin revisada.</li><li>Estabilidade na atualização de etapa.</li><li>Download de currículo com nome inteligente.</li><li>Inclusão do manual no painel admin.</li></ul></div>
    </div>
  </section>

  <section class="manual-accordion" style="margin-top:18px">
    <details class="manual-card manual-details" open>
      <summary>
        <span class="manual-summary-left">
          <svg class="manual-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="12" cy="12" r="10"/><path d="M12 16v-4"/><path d="M12 8h.01"/></svg>
          <span class="manual-h3">1. Introdução</span>
        </span>
        <svg class="manual-chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M9 18l6-6-6-6"/></svg>
      </summary>
      <div class="manual-content">
        <p class="manual-p">
          O Portal RH da 
          <img src="<?= $base ?>/assets/logo-escura.png"
              alt="RH Madeplant"
              class="h-4 inline align-middle">
          centraliza a gestão de recrutamento...
        </p>
        <p class="manual-p" style="margin-top:6px">Ele organiza o funil de seleção em um painel único, com histórico de movimentações, download de currículos e controles de acesso por perfil.</p>
      </div>
    </details>

    <details class="manual-card manual-details">
      <summary>
        <span class="manual-summary-left">
          <svg class="manual-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M16 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="8.5" cy="7" r="4"/><path d="M20 8v6"/><path d="M23 11h-6"/></svg>
          <span class="manual-h3">2. Como acessar</span>
        </span>
        <svg class="manual-chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M9 18l6-6-6-6"/></svg>
      </summary>
      <div class="manual-content manual-grid manual-grid-2">
        <div class="manual-block">
          <p class="manual-p" style="font-weight:700">Login</p>
          <p class="manual-p" style="margin-top:4px">Acesse <span style="font-family:monospace">/admin/login</span> ou <span style="font-family:monospace">/login</span>, informe e-mail e senha e clique em <strong>Entrar</strong>.</p>
          <p class="manual-muted">O login valida token CSRF e aplica limite de tentativas por IP/e-mail.</p>
        </div>
        <div class="manual-block">
          <p class="manual-p" style="font-weight:700">Recuperação de senha</p>
          <p class="manual-p" style="margin-top:4px">Na tela de login, clique em <strong>Esqueci minha senha</strong> e informe o e-mail para receber link válido por 30 minutos.</p>
          <p class="manual-muted">A redefinição exige senha forte e confirmação correta.</p>
        </div>
      </div>
    </details>

    <details class="manual-card manual-details">
      <summary>
        <span class="manual-summary-left">
          <svg class="manual-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M3 8h18"/><path d="M5 8v11a2 2 0 002 2h10a2 2 0 002-2V8"/><path d="M9 8V6a3 3 0 016 0v2"/></svg>
          <span class="manual-h3">3. Módulos do sistema</span>
        </span>
        <svg class="manual-chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M9 18l6-6-6-6"/></svg>
      </summary>
      <div class="manual-content manual-grid manual-grid-2">
        <article class="manual-block"><h3 class="manual-h3">Vagas</h3><p class="manual-p">Cadastra e mantém vagas públicas. Acesso em <span style="font-family:monospace">/admin/vagas</span>. Campos: título, descrição, requisitos, área, local, ativo e csrf.</p></article>
        <article class="manual-block"><h3 class="manual-h3">Candidaturas</h3><p class="manual-p">Lista e filtra candidatos, permite atualizar etapa e baixar currículo. Acesso em <span style="font-family:monospace">/admin/candidaturas</span>.</p></article>
        <article class="manual-block"><h3 class="manual-h3">Pipeline Kanban</h3><p class="manual-p">Organiza candidaturas por etapa. Acesso em <span style="font-family:monospace">/admin/pipeline</span>. Movimentação com candidatura_id, stage_id e csrf.</p></article>
        <article class="manual-block"><h3 class="manual-h3">Benefícios</h3><p class="manual-p">Gerencia benefícios/parcerias exibidos no público. Acesso em <span style="font-family:monospace">/admin/beneficios</span>. Campos: nome, parceiro, descrição, logo, ativo e csrf.</p></article>
        <article class="manual-block"><h3 class="manual-h3">Colaboradores</h3><p class="manual-p">Consulta a base cadastral de RH, permite editar dados de vínculo e importar arquivos `.xlsx` ou `.csv` diretamente pela tela <span style="font-family:monospace">/admin/colaboradores</span>.</p></article>
        <article class="manual-block" style="grid-column:1 / -1"><h3 class="manual-h3">Usuários</h3><p class="manual-p">Cria contas administrativas com perfil (Leitor, RH, Admin) e operação de supervisor protegido.</p></article>
      </div>
    </details>

    <details class="manual-card manual-details">
      <summary>
        <span class="manual-summary-left">
          <svg class="manual-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M4 6h16"/><path d="M4 12h16"/><path d="M4 18h10"/></svg>
          <span class="manual-h3">4. Fluxos importantes</span>
        </span>
        <svg class="manual-chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M9 18l6-6-6-6"/></svg>
      </summary>
      <div class="manual-content">
        <ul class="manual-list">
          <li>Vaga ativa no público → candidatura com currículo PDF → entrada em “Novo”.</li>
          <li>Movimentação no Pipeline (Triagem, Entrevista, Proposta) com histórico.</li>
          <li>Importação de colaboradores pela tela administrativa com validação do arquivo, processamento transacional e resumo de erros/rejeições.</li>
          <li>Recuperação de senha por token temporário e novo login após redefinição.</li>
        </ul>
      </div>
    </details>

    <details class="manual-card manual-details">
      <summary>
        <span class="manual-summary-left">
          <svg class="manual-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="12" cy="12" r="10"/><path d="M8 12h8"/><path d="M12 8v8"/></svg>
          <span class="manual-h3">5. Erros comuns e solução</span>
        </span>
        <svg class="manual-chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M9 18l6-6-6-6"/></svg>
      </summary>
      <div class="manual-content manual-grid manual-grid-2">
        <div class="manual-block"><p class="manual-p" style="font-weight:700">Credenciais inválidas</p><p class="manual-muted">Verifique e-mail/senha. Em bloqueio por tentativas, aguarde e tente novamente.</p></div>
        <div class="manual-block"><p class="manual-p" style="font-weight:700">Falha CSRF</p><p class="manual-muted">Atualize a página e reenviar formulário em aba ativa.</p></div>
        <div class="manual-block"><p class="manual-p" style="font-weight:700">Importação rejeitada</p><p class="manual-muted">Confirme o formato `.xlsx` ou `.csv`, revise os campos obrigatórios e verifique o resumo de inconsistências exibido na tela de colaboradores.</p></div>
        <div class="manual-block"><p class="manual-p" style="font-weight:700">Falha ao atualizar candidatura</p><p class="manual-muted">Confirme etapa selecionada e tente novamente; persistindo, revisar logs.</p></div>
        <div class="manual-block"><p class="manual-p" style="font-weight:700">Token inválido/expirado</p><p class="manual-muted">Solicite novo link em “Esqueci minha senha”.</p></div>
      </div>
    </details>

    <details class="manual-card manual-details">
      <summary>
        <span class="manual-summary-left">
          <svg class="manual-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M9 9a3 3 0 116 0c0 2-3 2-3 4"/><path d="M12 17h.01"/><circle cx="12" cy="12" r="10"/></svg>
          <span class="manual-h3">6. FAQ</span>
        </span>
        <svg class="manual-chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M9 18l6-6-6-6"/></svg>
      </summary>
      <div class="manual-content">
        <div class="manual-block">
          <p class="manual-p"><strong>Quem pode acessar este painel?</strong><br>Usuários autenticados com perfil administrativo (Leitor, RH, Admin e Supervisor).</p>
          <p class="manual-p" style="margin-top:8px"><strong>Posso cadastrar candidatura manualmente no admin?</strong><br>No fluxo atual, candidaturas entram pelo formulário público das vagas.</p>
          <p class="manual-p" style="margin-top:8px"><strong>Como importo colaboradores pela tela?</strong><br>Acesse <span style="font-family:monospace">/admin/colaboradores</span>, clique em <strong>Importar colaboradores</strong>, selecione um `.xlsx` ou `.csv` e acompanhe o resumo apresentado na própria página.</p>
          <p class="manual-p" style="margin-top:8px"><strong>Como falar com suporte rapidamente?</strong><br><a class="manual-link" href="https://wa.me/5567993256260" target="_blank" rel="noopener noreferrer">WhatsApp (67) 99325-6260</a>.</p>
        </div>
      </div>
    </details>
  </section>
</div>
