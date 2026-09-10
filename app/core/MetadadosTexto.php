<?php

/**
 * Utilitários de texto compartilhados pela reconciliação com o METADADOS (Empresas, Setores,
 * Cargos). Uma única implementação de `normalizarNome()` — usada só como heurística de ADOÇÃO
 * (match único e exato de nome local x descrição oficial), NUNCA como identidade. A identidade
 * oficial é sempre o código.
 */
class MetadadosTexto
{
    /** Transliteração determinística de acentos PT-BR — independente de locale/libiconv (Windows). */
    private const ACENTOS = [
        'Á' => 'A', 'À' => 'A', 'Ã' => 'A', 'Â' => 'A', 'Ä' => 'A',
        'É' => 'E', 'È' => 'E', 'Ê' => 'E', 'Ë' => 'E',
        'Í' => 'I', 'Ì' => 'I', 'Î' => 'I', 'Ï' => 'I',
        'Ó' => 'O', 'Ò' => 'O', 'Õ' => 'O', 'Ô' => 'O', 'Ö' => 'O',
        'Ú' => 'U', 'Ù' => 'U', 'Û' => 'U', 'Ü' => 'U',
        'Ç' => 'C', 'Ñ' => 'N',
        'á' => 'a', 'à' => 'a', 'ã' => 'a', 'â' => 'a', 'ä' => 'a',
        'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
        'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i',
        'ó' => 'o', 'ò' => 'o', 'õ' => 'o', 'ô' => 'o', 'ö' => 'o',
        'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u',
        'ç' => 'c', 'ñ' => 'n',
    ];

    /**
     * Maiúsculas, sem acento, só alfanumérico, espaços colapsados. Usado para comparar um nome
     * local com uma descrição oficial na adoção — só há adoção quando o resultado é EXATAMENTE
     * igual e há um único candidato. Nunca é fuzzy, nunca aproxima.
     */
    public static function normalizarNome(string $nome): string
    {
        $nome = strtr(trim($nome), self::ACENTOS);
        $nome = preg_replace('/[^A-Za-z0-9]+/', ' ', $nome) ?? '';
        $nome = preg_replace('/\s+/', ' ', $nome) ?? '';
        return strtoupper(trim($nome));
    }
}
