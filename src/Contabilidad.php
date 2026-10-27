<?php
declare(strict_types=1);
defined('APP_ROOT') || exit;

/**
 * Lecturas contables compartidas por Waylet y el panel.
 *
 * Arrastre de años cerrados: no hay asiento de apertura (no se cierra el ejercicio), así que el
 * saldo de una caja = saldo del año en curso (consulta sin fechas) + suma de su saldo en cada
 * año cerrado desde ANIO_INICIO. Cada año cerrado es UNA consulta (todas las cuentas) en caché:
 *   - año anterior: 24 h (en enero-febrero aún pueden entrar asientos con fecha de diciembre)
 *   - años más antiguos: 1 año
 * El botón de refrescar borra la caché.
 */
final class Contabilidad
{
    public const ANIO_INICIO = 2025; // primer año con movimientos en Holded

    public function __construct(private Holded $h, private Store $s) {}

    public static function anioActual(): int
    {
        return (int) (new DateTimeImmutable('now'))->format('Y');
    }

    /** Saldos de todas las cuentas en un año cerrado: [número => ['b' => saldo, 'n' => nombre]]. */
    public function saldosAnio(int $anio): array
    {
        $ttl = $anio >= self::anioActual() - 1 ? 86400 : 365 * 86400;
        return $this->s->cache('anio' . $anio, $ttl, function () use ($anio) {
            $out = [];
            foreach ($this->h->cuentas(($anio - 1) . '-12-31', $anio . '-12-31') as $c) {
                $out[(string) $c['number']] = ['b' => num($c['balance'] ?? 0), 'n' => trim((string) ($c['name'] ?? ''))];
            }
            return $out;
        });
    }

    /** Suma del saldo de $cuenta en los años cerrados anteriores a $anio. */
    public function arrastre(int $cuenta, ?int $anio = null): float
    {
        $anio ??= self::anioActual();
        $t = 0.0;
        for ($y = self::ANIO_INICIO; $y < $anio; $y++) {
            $t += $this->saldosAnio($y)[(string) $cuenta]['b'] ?? 0.0;
        }
        return r2($t);
    }

    /** Saldo del año en curso de una cuenta (consulta sin fechas, filtrada por número). */
    public function saldoAnioActual(int $cuenta): float
    {
        foreach ($this->h->cuentas(null, null, (string) $cuenta) as $c) {
            if ((int) $c['number'] === $cuenta) {
                return num($c['balance'] ?? 0);
            }
        }
        throw new ErrorHolded('No encuentro la cuenta ' . $cuenta);
    }

    /** Saldo acumulado de una caja: año en curso + arrastre. */
    public function saldoCaja(int $cuenta): float
    {
        return r2($this->saldoAnioActual($cuenta) + $this->arrastre($cuenta));
    }
}
