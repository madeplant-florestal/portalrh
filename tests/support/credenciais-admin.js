/**
 * Credenciais do administrador usado pelos testes de navegador: SOMENTE variáveis de ambiente locais (ADMIN_EMAIL, ADMIN_PASSWORD).
 * Não existe valor padrão — sem as variáveis, o teste FALHA com mensagem explícita, em vez de tentar uma conta real.
 * Use uma conta de teste do banco de DESENVOLVIMENTO, nunca uma credencial de produção.
 */
function credenciaisAdmin() {
  const email = (process.env.ADMIN_EMAIL || '').trim();
  const senha = process.env.ADMIN_PASSWORD || '';
  if (email === '' || senha === '') {
    throw new Error('Defina ADMIN_EMAIL e ADMIN_PASSWORD (conta de teste do ambiente local) para rodar este teste. Não há credencial padrão.');
  }
  return { email, senha };
}

module.exports = { credenciaisAdmin };
