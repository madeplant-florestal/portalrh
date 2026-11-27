<?php

/**
 * Versão do código PUBLICADO (commit em execução) e data desse commit — rodapé "Versão e suporte" do Manual.
 *
 * Fontes, nesta ordem:
 *   1. o repositório Git do PRÓPRIO diretório publicado (`.git`) — o deploy automático da hospedagem faz checkout do commit, então o HEAD local
 *      É o código em execução (não o HEAD remoto do GitHub). Leitura direta de arquivos (HEAD, refs, packed-refs, objeto do commit solto ou
 *      empacotado): sem `shell_exec`, sem binário `git`, sem rede;
 *   2. `app/config/build.php` (gerado por scripts/generate_build_info.php nos deploys por cópia de arquivos, sem `.git`), via Config::app();
 *   3. nada — estado neutro ("Versão não disponível"), nunca uma versão fictícia.
 * A data é a do COMMIT (committer date), não a do deploy; apresentada em America/Campo_Grande. Nunca usa filemtime.
 * Resultado memorizado por requisição; nenhum dado sensível (só hash e data).
 */
final class VersaoPublicadaService
{
    /** @var array{versao:?string,hash:?string,data_commit:?DateTimeImmutable,fonte:string}|null */
    private static ?array $memo = null;

    /** @return array{versao:?string,hash:?string,data_commit:?DateTimeImmutable,fonte:string} */
    public static function atual(?string $raiz = null): array
    {
        if ($raiz === null && self::$memo !== null) {
            return self::$memo;
        }
        $raizEfetiva = $raiz ?? dirname(__DIR__, 2);
        $r = self::doGit($raizEfetiva);
        if ($r === null) {
            $r = self::doBuild();
        }
        $r ??= ['versao' => null, 'hash' => null, 'data_commit' => null, 'fonte' => 'indisponivel'];
        if ($raiz === null) {
            self::$memo = $r;
        }
        return $r;
    }

    /** "DD/MM/AAAA HH:MM" em America/Campo_Grande, ou null. */
    public static function dataFormatada(?DateTimeImmutable $data): ?string
    {
        return $data?->setTimezone(new DateTimeZone('America/Campo_Grande'))->format('d/m/Y H:i');
    }

    /** Para testes: descarta o memo da requisição. */
    public static function limparMemo(): void
    {
        self::$memo = null;
    }

    /** @return array{versao:string,hash:string,data_commit:?DateTimeImmutable,fonte:string}|null */
    public static function doGit(string $raiz): ?array
    {
        try {
            $gitDir = self::localizarGitDir($raiz);
            if ($gitDir === null) {
                return null;
            }
            $hash = self::resolverHead($gitDir);
            if ($hash === null) {
                return null;
            }
            $epoch = self::epochDoCommit($gitDir, $hash);
            return [
                'versao' => 'git-' . substr($hash, 0, 7),
                'hash' => $hash,
                'data_commit' => $epoch === null ? null : (new DateTimeImmutable('@' . $epoch)),
                'fonte' => 'git',
            ];
        } catch (\Throwable) {
            return null;
        }
    }

    /** @return array{versao:string,hash:?string,data_commit:?DateTimeImmutable,fonte:string}|null */
    private static function doBuild(): ?array
    {
        return class_exists('Config', false) ? self::daConfiguracao(Config::app()) : null;
    }

    /**
     * Interpreta `app.version`/`app.release_date_iso` (build.php). Só aceita versão real `git-<hash>`; o padrão '1.0.0' ou vazio = null.
     *
     * @return array{versao:string,hash:?string,data_commit:?DateTimeImmutable,fonte:string}|null
     */
    public static function daConfiguracao(array $app): ?array
    {
        $versao = trim((string)($app['version'] ?? ''));
        if (!preg_match('/^git-([0-9a-f]{7,40})$/', $versao, $m)) {
            return null; // '1.0.0' (padrão) ou vazio = sem metadado real
        }
        $data = null;
        $iso = trim((string)($app['release_date_iso'] ?? ''));
        if ($iso !== '') {
            try {
                $data = new DateTimeImmutable($iso);
            } catch (\Throwable) {
                $data = null;
            }
        }
        return ['versao' => 'git-' . substr($m[1], 0, 7), 'hash' => $m[1], 'data_commit' => $data, 'fonte' => 'build'];
    }

    // ---- leitura do repositório (somente arquivos) --------------------------------------------------------------------------------------------

    private static function localizarGitDir(string $raiz): ?string
    {
        $git = rtrim($raiz, '/\\') . DIRECTORY_SEPARATOR . '.git';
        if (is_dir($git)) {
            return $git;
        }
        if (is_file($git)) { // worktree/submódulo: "gitdir: <caminho>"
            $c = trim((string)@file_get_contents($git));
            if (str_starts_with($c, 'gitdir:')) {
                $p = trim(substr($c, 7));
                $p = preg_match('#^([A-Za-z]:)?[/\\\\]#', $p) ? $p : rtrim($raiz, '/\\') . DIRECTORY_SEPARATOR . $p;
                return is_dir($p) ? $p : null;
            }
        }
        return null;
    }

    /** Diretório comum (objetos/refs) — difere do gitdir só em worktrees. */
    private static function dirComum(string $gitDir): string
    {
        $f = $gitDir . DIRECTORY_SEPARATOR . 'commondir';
        if (is_file($f)) {
            $c = trim((string)@file_get_contents($f));
            $p = preg_match('#^([A-Za-z]:)?[/\\\\]#', $c) ? $c : $gitDir . DIRECTORY_SEPARATOR . $c;
            if (is_dir($p)) {
                return $p;
            }
        }
        return $gitDir;
    }

    private static function resolverHead(string $gitDir): ?string
    {
        $head = trim((string)@file_get_contents($gitDir . DIRECTORY_SEPARATOR . 'HEAD'));
        if (preg_match('/^[0-9a-f]{40}$/', $head)) {
            return $head; // HEAD destacado
        }
        if (!preg_match('#^ref:\s*(refs/[A-Za-z0-9._/-]+)$#', $head, $m) || str_contains($m[1], '..')) {
            return null;
        }
        return self::resolverRef(self::dirComum($gitDir), $m[1], $gitDir);
    }

    private static function resolverRef(string $comum, string $ref, string $gitDir): ?string
    {
        foreach (array_unique([$gitDir, $comum]) as $base) {
            $arq = $base . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $ref);
            if (is_file($arq)) {
                $v = trim((string)@file_get_contents($arq));
                if (preg_match('/^[0-9a-f]{40}$/', $v)) {
                    return $v;
                }
            }
        }
        $packed = $comum . DIRECTORY_SEPARATOR . 'packed-refs';
        if (is_file($packed)) {
            foreach (file($packed, FILE_IGNORE_NEW_LINES) ?: [] as $linha) {
                if (preg_match('/^([0-9a-f]{40}) (\S+)$/', $linha, $m) && $m[2] === $ref) {
                    return $m[1];
                }
            }
        }
        return null;
    }

    /** Committer timestamp (epoch) do commit, ou null se o objeto não puder ser lido (ex.: delta). */
    private static function epochDoCommit(string $gitDir, string $hash): ?int
    {
        $objetos = self::dirComum($gitDir) . DIRECTORY_SEPARATOR . 'objects';
        $conteudo = self::lerObjetoSolto($objetos, $hash) ?? self::lerObjetoEmpacotado($objetos, $hash);
        if ($conteudo === null) {
            return null;
        }
        if (preg_match('/^committer .* (\d{9,11}) [+-]\d{4}$/m', $conteudo, $m)) {
            return (int)$m[1];
        }
        return null;
    }

    private static function lerObjetoSolto(string $objetos, string $hash): ?string
    {
        $arq = $objetos . DIRECTORY_SEPARATOR . substr($hash, 0, 2) . DIRECTORY_SEPARATOR . substr($hash, 2);
        if (!is_file($arq)) {
            return null;
        }
        $bruto = @gzuncompress((string)file_get_contents($arq));
        if (!is_string($bruto) || !str_starts_with($bruto, 'commit ')) {
            return null;
        }
        $nul = strpos($bruto, "\0");
        return $nul === false ? null : substr($bruto, $nul + 1);
    }

    /** Objeto `commit` não-delta dentro de um pack (índice v2). */
    private static function lerObjetoEmpacotado(string $objetos, string $hash): ?string
    {
        $alvo = hex2bin($hash);
        foreach (glob($objetos . DIRECTORY_SEPARATOR . 'pack' . DIRECTORY_SEPARATOR . 'pack-*.idx') ?: [] as $idx) {
            $offset = self::offsetNoIndice($idx, $alvo);
            if ($offset === null) {
                continue;
            }
            $pack = substr($idx, 0, -4) . '.pack';
            $f = @fopen($pack, 'rb');
            if ($f === false) {
                return null;
            }
            try {
                fseek($f, $offset);
                $b = ord((string)fread($f, 1));
                $tipo = ($b >> 4) & 7;
                $tamanho = $b & 0x0f;
                $shift = 4;
                while ($b & 0x80) {
                    $b = ord((string)fread($f, 1));
                    $tamanho |= ($b & 0x7f) << $shift;
                    $shift += 7;
                }
                if ($tipo !== 1 || $tamanho <= 0 || $tamanho > 1048576) {
                    return null; // não é commit inteiro (delta) — a data fica indisponível, a versão não
                }
                $z = inflate_init(ZLIB_ENCODING_DEFLATE);
                $saida = '';
                while (!feof($f) && strlen($saida) < $tamanho) {
                    $parte = @inflate_add($z, (string)fread($f, 8192), ZLIB_SYNC_FLUSH);
                    if ($parte === false) {
                        return null;
                    }
                    $saida .= $parte;
                    if (inflate_get_status($z) === ZLIB_STREAM_END) {
                        break;
                    }
                }
                return strlen($saida) >= $tamanho ? substr($saida, 0, $tamanho) : null;
            } finally {
                fclose($f);
            }
        }
        return null;
    }

    private static function offsetNoIndice(string $idx, string $alvo): ?int
    {
        $f = @fopen($idx, 'rb');
        if ($f === false) {
            return null;
        }
        try {
            if (fread($f, 8) !== "\377tOc\0\0\0\2") {
                return null; // só índice v2 (padrão do Git desde 1.5.2)
            }
            $primeiro = ord($alvo[0]);
            fseek($f, 8 + ($primeiro === 0 ? 0 : ($primeiro - 1) * 4));
            $ini = $primeiro === 0 ? 0 : unpack('N', (string)fread($f, 4))[1];
            fseek($f, 8 + $primeiro * 4);
            $fim = unpack('N', (string)fread($f, 4))[1];
            fseek($f, 8 + 255 * 4);
            $total = unpack('N', (string)fread($f, 4))[1];
            $baseSha = 8 + 256 * 4;
            $lo = $ini;
            $hi = $fim - 1;
            while ($lo <= $hi) {
                $meio = intdiv($lo + $hi, 2);
                fseek($f, $baseSha + $meio * 20);
                $cmp = strcmp((string)fread($f, 20), $alvo);
                if ($cmp === 0) {
                    fseek($f, $baseSha + $total * 24 + $meio * 4); // após SHAs (20) e CRCs (4)
                    $off = unpack('N', (string)fread($f, 4))[1];
                    if ($off & 0x80000000) { // offset grande (tabela de 8 bytes)
                        fseek($f, $baseSha + $total * 28 + ($off & 0x7fffffff) * 8);
                        $p = unpack('N2', (string)fread($f, 8));
                        return ($p[1] << 32) | $p[2];
                    }
                    return $off;
                }
                if ($cmp < 0) {
                    $lo = $meio + 1;
                } else {
                    $hi = $meio - 1;
                }
            }
            return null;
        } finally {
            fclose($f);
        }
    }
}
