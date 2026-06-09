<?php
class HomeController extends Controller
{
    public function index(): void
    {
        try {
            $vagas = Vaga::allActive();
        } catch (\Throwable $e) {
            $vagas = [];
            $erro = 'Falha ao consultar vagas: ' . $e->getMessage();
        }
        $this->view->render('home/index', ['vagas' => $vagas, 'erro' => $erro ?? null]);
    }

    public function vaga(string $id): void
    {
        $vaga = Vaga::find((int)$id);
        if (!$vaga || (int)$vaga['ativo'] !== 1) {
            http_response_code(404);
            echo 'Vaga não encontrada';
            return;
        }
        $csrf = Security::csrfToken();
        $beneficios = [];
        try {
            $beneficios = Beneficio::allActive();
        } catch (\Throwable $e) {
            $beneficios = [];
        }
        $this->view->render('home/vaga', ['vaga' => $vaga, 'csrf' => $csrf, 'beneficios' => $beneficios]);
    }

    public function candidatar(string $id): void
    {
        if (!Security::csrfCheck($_POST['csrf'] ?? '')) {
            http_response_code(400);
            echo 'Falha na verificação de segurança (CSRF).';
            return;
        }
        $vaga = Vaga::find((int)$id);
        if (!$vaga || (int)$vaga['ativo'] !== 1) {
            http_response_code(404);
            echo 'Vaga não encontrada';
            return;
        }
        // Sanitização
        $nome = Security::sanitizeString($_POST['nome'] ?? '');
        $email = Security::sanitizeString($_POST['email'] ?? '');
        $telefoneRaw = Security::sanitizeString($_POST['telefone'] ?? '');
        $cpf = preg_replace('/\D/', '', Security::sanitizeString($_POST['cpf'] ?? ''));
        $cargo = Security::sanitizeString($_POST['cargo_pretendido'] ?? '');
        $exp = Security::sanitizeString($_POST['experiencia'] ?? '');
        if (!$nome || !$email || !$telefoneRaw || !$cpf || !$cargo || !$exp) {
            http_response_code(422);
            echo 'Campos obrigatórios não preenchidos.';
            return;
        }

        $telefone = Phone::normalize($telefoneRaw);
        if ($telefone === null) {
            http_response_code(422);
            echo 'Telefone inválido. Informe 11 dígitos (DDD + número).';
            return;
        }
        // Validação do CPF
        if (!Security::isValidCpf($cpf)) {
            http_response_code(422);
            echo 'CPF inválido (formato ou dígitos verificadores).';
            return;
        }
        if (Candidatura::cpfExists($cpf)) {
            http_response_code(422);
            echo 'Você já possui uma candidatura ativa. Aguarde o resultado antes de se candidatar novamente.';
            return;
        }
        // Upload seguro
        try {
            $pdfName = Upload::savePdf($_FILES['curriculo'] ?? [], $nome, $vaga['titulo']);
        } catch (\Throwable $e) {
            http_response_code(400);
            echo Security::e($e->getMessage());
            return;
        }
        // Persistência
        $cid = Candidatura::create([
            'vaga_id' => (int)$id,
            'nome' => $nome,
            'email' => $email,
            'telefone' => $telefone,
            'cpf' => $cpf,
            'cargo_pretendido' => $cargo,
            'experiencia' => $exp,
            'pdf_path' => $pdfName,
            'status' => 'novo',
        ]);
        // Notificação RH
        $sent = Mailer::notifyHR(
            'Nova candidatura recebida',
            "Vaga: {$vaga['titulo']}\nNome: {$nome}\nE-mail: {$email}\nTelefone: " . Phone::format($telefone) . "\n"
        );
        $this->view->render('home/confirm', [
            'vaga' => $vaga,
            'cid' => $cid,
            'emailSent' => $sent,
        ]);
    }
}
