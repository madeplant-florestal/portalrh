<?php
/**
 * Tela de login — ajuste puramente visual (não migrada para o AppShell V2; login/recuperação seguem em `layouts/main`
 * por decisão da revisão global). Nenhum campo, name, action, method ou CSRF foi tocado — só apresentação.
 *
 * Imagem de fundo do painel esquerdo (2026-10, pedido do RH): `assets/imgfundotelainicial.png` (fornecido, 1672×941,
 * PAISAGEM — troféu/logo institucional sobre cavaco de madeira, ao entardecer). Convertida uma única vez, localmente,
 * com o FFmpeg já presente na máquina de desenvolvimento (ferramenta do sistema operacional, não uma dependência nova
 * do projeto/build), para `imgfundotelainicial.webp` (formato preferencial) com `imgfundotelainicial.jpg` como
 * fallback via <picture> para navegador sem suporte a WebP — mesmo padrão já usado nesta tela. O .png original
 * permanece em assets/, intocado.
 *
 * `object-fit: cover` (diferente da imagem anterior, que era RETRATO): esta é paisagem e o elemento central
 * (o troféu/logo) já fica centralizado no enquadramento original — cobrir o painel recorta só as bordas
 * esquerda/direita da foto (floresta/céu), nunca o elemento central, sem o "respiro" lateral que a imagem anterior
 * (portrait) precisava.
 *
 * Ajuste de responsividade (2026-10, correção do RH): o painel só aparece a partir de `lg` (1024px), mas sua
 * largura muda por breakpoint — `lg:w-1/2` (1024-1279px) é estreito e ALTO (ex.: 512×768), enquanto `xl:w-2/3`
 * (≥1280px) é bem mais largo (ex.: 960×900). Com `object-fit: cover` fixo, a faixa `lg` (notebook comum, ex.:
 * 1024×768) força o troféu/texto "MADEPLANT" a cobrir uma proporção muito mais estreita que a da foto
 * (1672×941), cortando ~60% da largura da imagem — o troféu saía deformado/irreconhecível. Resolvido com
 * `object-contain` nessa faixa (`lg` a `xl-1`): a foto aparece INTEIRA, sem corte nem distorção. Para não deixar
 * faixas vazias/feias nas laterais quando `contain` sobra espaço, uma segunda camada (mesma imagem, `cover` +
 * blur + escurecida, só visível de `lg` a `xl-1`) preenche o fundo atrás da imagem principal — efeito usado em
 * telas de login modernas, não é gambiarra. De `xl` (≥1280px) em diante o painel já é largo o suficiente para
 * `cover` não cortar o troféu de forma perceptível (testado em 1366×768, 1440×900, 1920×1080) — a camada de
 * blur é desligada (`xl:hidden`) e a imagem principal volta a `object-cover`, preenchendo o painel por completo.
 */
?>
<div class="min-h-screen flex">
  <!-- Left Container - Branding Area -->
  <div class="hidden lg:flex lg:w-1/2 xl:w-2/3 relative overflow-hidden isolate" style="background-color: #13100d;">
    <!-- Camada de fundo (só lg→xl-1): mesma foto, cover + blur + escurecida, evita faixas vazias quando a
         camada principal usa object-contain nessa faixa estreita do painel -->
    <img src="<?= $base ?>/assets/imgfundotelainicial.jpg" alt="" aria-hidden="true" class="absolute inset-0 h-full w-full object-cover object-center scale-110 blur-2xl brightness-50 xl:hidden">

    <!-- Camada principal: contain (lg→xl-1, foto inteira sem corte) / cover (xl+, painel largo o bastante) -->
    <picture>
      <source srcset="<?= $base ?>/assets/imgfundotelainicial.webp" type="image/webp">
      <img src="<?= $base ?>/assets/imgfundotelainicial.jpg" alt="" class="absolute inset-0 h-full w-full object-contain xl:object-cover object-center">
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
