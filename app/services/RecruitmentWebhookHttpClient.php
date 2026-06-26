<?php
class RecruitmentWebhookHttpClient
{
    public function postJson(string $url, array $payload, array $headers = []): array
    {
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new RuntimeException('Falha ao serializar payload do webhook.');
        }

        $baseHeaders = [
            'Content-Type: application/json',
            'Accept: application/json',
            'User-Agent: RHMadeplant-RecruitmentWebhook/1.0',
        ];
        $httpHeaders = array_merge($baseHeaders, $headers);

        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $json,
                CURLOPT_HTTPHEADER => $httpHeaders,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_TIMEOUT => 10,
            ]);

            $body = curl_exec($ch);
            $error = curl_error($ch);
            $statusCode = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            curl_close($ch);

            if ($body === false) {
                throw new RuntimeException($error !== '' ? $error : 'Falha desconhecida no envio HTTP.');
            }

            return [
                'status_code' => $statusCode,
                'body' => (string)$body,
            ];
        }

        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => implode("\r\n", $httpHeaders),
                'content' => $json,
                'timeout' => 10,
                'ignore_errors' => true,
            ],
        ]);

        $body = @file_get_contents($url, false, $context);
        $statusCode = 0;
        foreach (($http_response_header ?? []) as $headerLine) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $headerLine, $matches)) {
                $statusCode = (int)$matches[1];
                break;
            }
        }

        if ($body === false) {
            throw new RuntimeException('Falha ao enviar webhook com stream HTTP.');
        }

        return [
            'status_code' => $statusCode,
            'body' => (string)$body,
        ];
    }
}
