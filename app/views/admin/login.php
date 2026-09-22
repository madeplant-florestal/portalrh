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
 *
 * Ajuste (correção do RH): a foto é RETRATO (1600×2133, 4284×5712 no HEIC original — mesma proporção, nenhum corte foi
 * aplicado na geração do asset web) e o painel esquerdo é PAISAGEM (`lg:w-1/2 xl:w-2/3` × altura da viewport). Com
 * `object-fit: cover` isso forçava a imagem a crescer até cobrir a largura do painel, cortando ~35-40% da altura da
 * foto (céu e/ou cavaco de madeira nas bordas). Trocado para `object-fit: contain`: a fotografia aparece INTEIRA, sem
 * corte e sem distorção; sobra respiro nas laterais, preenchido com um tom neutro escuro tirado da própria paleta da
 * foto (cavaco/sombra — nunca azul) em vez de esticar ou cortar mais a imagem. O overlay azul institucional que havia
 * sobre a foto foi removido por completo — a fotografia aparece com as cores originais.
 */
?>
<div class="min-h-screen flex">
  <!-- Left Container - Branding Area -->
  <div class="hidden lg:flex lg:w-1/2 xl:w-2/3 relative overflow-hidden isolate" style="background-color: #13100d;">
    <!-- Fotografia INTEIRA, sem corte e sem distorção (object-fit: contain) — cores originais, sem overlay de cor -->
    <picture>
      <source srcset="<?= $base ?>/assets/imgfundo-login.webp" type="image/webp">
      <img src="<?= $base ?>/assets/imgfundo-login.jpg" alt="" class="absolute inset-0 h-full w-full object-contain object-center">
    </picture>

    <!-- Main Logo - Centered -->
    <div class="relative flex items-center justify-center w-full p-12">
      <img src="<?= $base ?>/assets/logooficial.png" alt="Madeplant Florestal" class="w-full max-w-lg h-auto" style="filter: drop-shadow(0 6px 18px rgba(0,0,0,.6));">
    </div>

    <!-- Isotipo - Bottom Left (marca d'água) -->
    <div class="absolute bottom-0 left-0 z-10">
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
