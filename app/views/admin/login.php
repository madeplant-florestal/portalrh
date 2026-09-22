<?php
/**
 * Tela de login — ajuste puramente visual (não migrada para o AppShell V2; login/recuperação seguem em `layouts/main`
 * por decisão da revisão global). Nenhum campo, name, action, method ou CSRF foi tocado — só apresentação.
 *
 * Imagem de fundo do painel esquerdo: `assets/imgfundo.HEIC` (fornecido) não é utilizável diretamente — HEIC não tem
 * suporte confiável nos navegadores-alvo (Chrome/Firefox/Edge no Windows, a base real de usuários do Portal). Sem
 * Imagick/GD com HEIF disponíveis no ambiente PHP (não instalados; não é o caso de adicionar extensão nova por causa
 * desta tela), a conversão foi feita localmente, uma única vez, com o FFmpeg já presente na máquina de desenvolvimento
 * (ferramenta do sistema operacional, não uma dependência nova do projeto/build) — gerando `imgfundo-login.webp`
 * (formato preferencial, leitura universal nos navegadores-alvo) com `imgfundo-login.jpg` como fallback via <picture>
 * para qualquer navegador sem suporte a WebP. O .HEIC original permanece em assets/, intocado.
 */
?>
<div class="min-h-screen flex">
  <!-- Left Container - Branding Area -->
  <div class="hidden lg:flex lg:w-1/2 xl:w-2/3 relative overflow-hidden isolate">
    <!-- Imagem de fundo: cover, sem distorção -->
    <picture class="absolute inset-0 -z-10">
      <source srcset="<?= $base ?>/assets/imgfundo-login.webp" type="image/webp">
      <img src="<?= $base ?>/assets/imgfundo-login.jpg" alt="" class="h-full w-full object-cover object-center">
    </picture>
    <!-- Overlay azul institucional (identidade + legibilidade, sem apagar a fotografia) -->
    <div class="absolute inset-0 -z-10" style="background: linear-gradient(160deg, rgba(13,19,33,.88) 0%, rgba(29,45,68,.74) 55%, rgba(13,19,33,.86) 100%);"></div>

    <!-- Main Logo - Centered -->
    <div class="flex items-center justify-center w-full p-12">
      <img src="<?= $base ?>/assets/logooficial.png" alt="Madeplant Florestal" class="w-full max-w-lg h-auto" style="filter: drop-shadow(0 6px 18px rgba(0,0,0,.35));">
    </div>

    <!-- Isotipo - Bottom Left (marca d'água) -->
    <div class="absolute bottom-0 left-0">
      <img src="<?= $base ?>/assets/Isotipolinear.png" alt="" aria-hidden="true" class="w-60 h-auto" style="opacity: .2;">
    </div>
  </div>

  <!-- Right Container - Login Form -->
  <div class="flex-1 flex items-center justify-center px-4 sm:px-6 lg:px-20 xl:px-24 bg-gray-50">
    <div class="w-full max-w-md">
      <div class="bg-white shadow-lg rounded-lg p-8">
        <div class="text-center mb-8">
          <img src="<?= $base ?>/assets/logooficial.png" alt="Madeplant Florestal" class="h-10 w-auto mx-auto mb-5">
          <h2 class="text-2xl font-semibold text-ctpblue">PORTAL RH</h2>
          <span class="text-sm text-gray-500">Acesso ao Painel</span>
        </div>
        
        <?php if (!empty($error)): ?>
          <div class="mb-6 bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-lg">
            <?= Security::e($error) ?>
          </div>
        <?php endif; ?>
        <?php if (!empty($success)): ?>
          <div class="mb-6 bg-ctlight border border-ctdark text-white px-4 py-3 rounded-lg">
            <?= Security::e($success) ?>
          </div>
        <?php endif; ?>
        
        <form class="space-y-6" action="<?= $base ?>/admin/login" method="post">
          <input type="hidden" name="csrf_token" value="<?= Security::e($_SESSION['csrf_token']) ?>">
          
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-2">E-mail</label>
            <input 
              type="email" 
              name="email" 
              required 
              class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-ctgreen focus:border-ctgreen transition-colors"
              placeholder="fabio.ozuna@madeplant.com.br"
            />
          </div>
          
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-2">Senha</label>
            <input 
              type="password" 
              name="password" 
              required 
              class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-ctgreen focus:border-ctgreen transition-colors"
              placeholder="••••••••"
            />
          </div>
          
          <button 
            type="submit" 
            class="w-full bg-ctgreen text-white py-3 px-4 rounded-lg font-medium hover:bg-ctdark focus:ring-2 focus:ring-ctgreen focus:ring-offset-2 transition-colors"
          >
            Entrar
          </button>
          <div class="text-center">
            <a href="<?= $base ?>/admin/forgot-password" class="text-sm text-ctpblue hover:text-ctgreen">Esqueci minha senha</a>
          </div>
        </form>
      </div>
    </div>
  </div>
</div>
