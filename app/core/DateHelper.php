<?php
class DateHelper
{
    public static function parseBrazilianDate(?string $value): ?DateTimeImmutable
    {
        $value = trim((string)$value);
        if ($value === '') {
            return null;
        }

        if (!preg_match('/^\d{2}\/\d{2}\/\d{4}$/', $value)) {
            return null;
        }

        $date = DateTimeImmutable::createFromFormat('d/m/Y', $value);
        if (!$date) {
            return null;
        }

        $errors = DateTimeImmutable::getLastErrors();
        if (($errors['warning_count'] ?? 0) > 0 || ($errors['error_count'] ?? 0) > 0) {
            return null;
        }

        return $date->setTime(0, 0, 0);
    }

    public static function formatBrazilianDate(?string $value): string
    {
        $value = trim((string)$value);
        if ($value === '') {
            return '';
        }

        try {
            return (new DateTimeImmutable($value))->format('d/m/Y');
        } catch (Throwable $e) {
            return '';
        }
    }

    /**
     * Formata um datetime "YYYY-MM-DD HH:MM:SS" (formato de retorno do MySQL) como
     * "DD/MM/AAAA às HH:MM", via regex ao invés de DateTime/timezone — o valor é
     * exibido exatamente como foi persistido, sem qualquer conversão de fuso.
     */
    public static function formatBrazilianDateTime(?string $value): string
    {
        $value = trim((string)$value);
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})/', $value, $m)) {
            return '';
        }
        [, $ano, $mes, $dia, $hora, $min] = $m;
        return "{$dia}/{$mes}/{$ano} às {$hora}:{$min}";
    }

    public static function toDatabaseDate(?string $value): ?string
    {
        $date = self::parseBrazilianDate($value);
        return $date ? $date->format('Y-m-d') : null;
    }

    public static function daysBetween(string $startDate, string $endDate): ?int
    {
        try {
            $start = new DateTimeImmutable($startDate);
            $end = new DateTimeImmutable($endDate);
        } catch (Throwable $e) {
            return null;
        }

        return (int)$start->diff($end)->format('%r%a');
    }

    // =====================================================================================================
    // POLÍTICA TEMPORAL (Frente D, 2026-11-15)
    //  - Instantes de eventos são PERSISTIDOS em UTC (TIMESTAMP/CURRENT_TIMESTAMP, DATETIME via NOW() com a sessão do
    //    banco em +00:00 — ver Database::conn() — e DATETIME gravados pelo PHP via agoraUtc()).
    //  - A APRESENTAÇÃO é sempre em America/Campo_Grande, por conversão EXPLÍCITA de uma origem declarada.
    //  - Datas civis (colunas DATE: admissão, validade da proposta, data do exame…) NÃO são convertidas.
    // Famílias de origem (nunca deduzidas pelo aspecto da string):
    //    ORIGEM_UTC         TIMESTAMP e DATETIME via NOW(); DATETIME gravado por agoraUtc().
    //    ORIGEM_PHP_LEGADO  DATETIME gravado pelo relógio do PHP ANTES desta política (cartas, exames, expirações,
    //                       avaliações…). O diagnóstico de produção de 2026-11-15 (senha/cartas/sincronização: desvio 0 min
    //                       contra o banco) comprova PHP web em UTC; se algum dia houver um corte de fuso, só esta
    //                       constante/rotina muda, nunca os chamadores.
    //    ORIGEM_CIVIL       data/hora de calendário sem fuso (agendamento): devolvida sem deslocamento.
    // =====================================================================================================
    public const TZ_APRESENTACAO = 'America/Campo_Grande';
    public const ORIGEM_UTC = 'utc';
    public const ORIGEM_PHP_LEGADO = 'php_legado';
    public const ORIGEM_CIVIL = 'civil';
    private const FUSO_PHP_LEGADO = 'UTC';

    /** Instante atual, com fuso UTC explícito (independe do fuso padrão do PHP). */
    public static function agoraUtc(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }

    /** "Y-m-d H:i:s" do instante atual em UTC — para gravar em DATETIME que representa um instante. */
    public static function agoraUtcSql(): string
    {
        return self::agoraUtc()->format('Y-m-d H:i:s');
    }

    /** Converte um DateTimeInterface para "Y-m-d H:i:s" em UTC (preserva o instante). */
    public static function paraUtcSql(DateTimeInterface $instante): string
    {
        return DateTimeImmutable::createFromInterface($instante)->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    /** Data civil de HOJE em Campo Grande ("Y-m-d"), independente do fuso do PHP/servidor. */
    public static function hojeCivilLocal(): DateTimeImmutable
    {
        return (new DateTimeImmutable('now', new DateTimeZone(self::TZ_APRESENTACAO)))->setTime(0, 0, 0);
    }

    /**
     * Converte um valor temporal para America/Campo_Grande. Strings só são aceitas no formato do MySQL
     * (`Y-m-d H:i:s[.u]` ou `Y-m-dTH:i:s`); qualquer outra coisa devolve null. Um DateTimeInterface já carrega o
     * seu fuso: é apenas convertido (nunca reinterpretado — evita conversão dupla). ORIGEM_CIVIL não desloca.
     */
    public static function paraLocal(DateTimeInterface|string|null $valor, string $origem = self::ORIGEM_UTC): ?DateTimeImmutable
    {
        if ($valor === null) {
            return null;
        }
        $tzLocal = new DateTimeZone(self::TZ_APRESENTACAO);
        if ($valor instanceof DateTimeInterface) {
            return DateTimeImmutable::createFromInterface($valor)->setTimezone($tzLocal);
        }
        $valor = trim($valor);
        if ($valor === '' || !preg_match('/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}(:\d{2}(\.\d+)?)?$/', $valor)) {
            return null;
        }
        if ($origem === self::ORIGEM_CIVIL) {
            $tzOrigem = $tzLocal;
        } elseif ($origem === self::ORIGEM_PHP_LEGADO) {
            $tzOrigem = new DateTimeZone(self::FUSO_PHP_LEGADO);
        } elseif ($origem === self::ORIGEM_UTC) {
            $tzOrigem = new DateTimeZone('UTC');
        } else {
            throw new InvalidArgumentException('Origem temporal desconhecida: ' . $origem);
        }
        try {
            return (new DateTimeImmutable(str_replace('T', ' ', $valor), $tzOrigem))->setTimezone($tzLocal);
        } catch (Throwable) {
            return null;
        }
    }

    /** Formata um instante no fuso de Campo Grande; '' quando nulo/inválido (o chamador define o placeholder). */
    public static function formatLocal(DateTimeInterface|string|null $valor, string $formato = 'd/m/Y H:i', string $origem = self::ORIGEM_UTC): string
    {
        $local = self::paraLocal($valor, $origem);
        return $local === null ? '' : $local->format($formato);
    }

    /** "13/11/2026 09:30 (horário de Campo Grande, UTC-04:00)" — para documentos e evidências. */
    public static function formatLocalComFuso(DateTimeInterface|string|null $valor, string $origem = self::ORIGEM_UTC): string
    {
        $local = self::paraLocal($valor, $origem);
        return $local === null ? '' : $local->format('d/m/Y H:i') . ' (horário de Campo Grande, UTC' . $local->format('P') . ')';
    }

    /** Equivalente em UTC, para evidência complementar: "13/11/2026 13:30:00 UTC". */
    public static function formatUtc(DateTimeInterface|string|null $valor, string $origem = self::ORIGEM_UTC): string
    {
        $local = self::paraLocal($valor, $origem);
        return $local === null ? '' : $local->setTimezone(new DateTimeZone('UTC'))->format('d/m/Y H:i:s') . ' UTC';
    }

    /** ISO-8601 com offset local (ex.: 2026-11-13T09:30:00-04:00) para APIs. */
    public static function iso8601Local(DateTimeInterface|string|null $valor, string $origem = self::ORIGEM_UTC): ?string
    {
        return self::paraLocal($valor, $origem)?->format('c');
    }

    /** ISO-8601 em UTC (ex.: 2026-11-13T13:30:00+00:00). */
    public static function iso8601Utc(DateTimeInterface|string|null $valor, string $origem = self::ORIGEM_UTC): ?string
    {
        return self::paraLocal($valor, $origem)?->setTimezone(new DateTimeZone('UTC'))->format('c');
    }

    /**
     * Limites, EM UTC, de um dia civil de Campo Grande ('Y-m-d') — para filtrar colunas de instante por dia local.
     * [inicio, fim] inclusivos, no formato de coluna ("Y-m-d H:i:s"). null quando a data é inválida.
     *
     * @return array{0:string,1:string}|null
     */
    public static function limitesDiaLocalEmUtc(string $dataYmd): ?array
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dataYmd)) {
            return null;
        }
        try {
            $inicio = new DateTimeImmutable($dataYmd . ' 00:00:00', new DateTimeZone(self::TZ_APRESENTACAO));
        } catch (Throwable) {
            return null;
        }
        $fim = $inicio->modify('+1 day')->modify('-1 second');
        return [self::paraUtcSql($inicio), self::paraUtcSql($fim)];
    }
}
