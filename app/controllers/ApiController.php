<?php
class ApiController
{
    public function checkCpf(): void
    {
        header('Content-Type: application/json');
        
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
            return;
        }

        $input = json_decode(file_get_contents('php://input'), true);
        $cpf = $input['cpf'] ?? '';

        // Remove formatação do CPF
        $cpf = preg_replace('/\D/', '', $cpf);

        if (!Security::isValidCpf($cpf)) {
            http_response_code(400);
            echo json_encode(['error' => 'CPF inválido']);
            return;
        }

        $exists = Candidatura::cpfExists($cpf);
        echo json_encode(['exists' => $exists]);
    }
}