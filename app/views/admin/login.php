<?php
?>
<div class="min-h-screen flex">
  <!-- Left Container - Branding Area -->
  <div class="hidden lg:flex lg:w-1/2 xl:w-2/3 relative" style="background-color: #1d2d44;">
    <!-- Main Logo - Centered -->
    <div class="flex items-center justify-center w-full">
      <img src="<?= $base ?>/assets/logo.png" alt="MADEPLANT - Recrutamento e Seleção" class="w-128 h-auto">
    </div>
    
    <!-- Isotipo - Bottom Left -->
    <div class="absolute bottom-0 left-0">
      <img src="<?= $base ?>/assets/Isotipolinear.png" alt="MADEPLANT - Recrutamento e Seleção" class="w-60 h-auto opacity-50">
    </div>
  </div>

  <!-- Right Container - Login Form -->
  <div class="flex-1 flex items-center justify-center px-4 sm:px-6 lg:px-20 xl:px-24 bg-gray-50">
    <div class="w-full max-w-md">
      <div class="bg-white shadow-lg rounded-lg p-8">
        <div class="text-center mb-8">
          <!-- Mobile Logo -->
          <div class="lg:hidden mb-6">
            <img src="<?= $base ?>/assets/logo.png" alt="MADEPLANT - Recrutamento e Seleção" class="w-60 h-auto mx-auto">
          </div>
          <h2 class="text-2xl font-semibold text-ctpblue">RECRUTAMENTO E SELEÇÃO</h2>
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
