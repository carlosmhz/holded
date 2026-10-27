<?php
declare(strict_types=1);
defined('APP_ROOT') || exit;

/**
 * Monedero Waylet (port de anadirWaylet / quitarWaylet / ajustarWaylet del script).
 *   añadir            → Debe 40700001 / Haber 62900012  "Bonificación Saldo Waylet"
 *   quitar con factura→ Debe 57000004 / Haber 40700001  "Pago Waylet pendiente de factura · …"
 *   quitar sin factura→ Debe 55000000 / Haber 40700001  "Saldo Waylet · …"
 *   ajustar           → diferencia contra 40700001 / 62900012 (al revés si es negativa)
 * Con escritura_holded = false (por defecto) NO crea nada: devuelve el asiento que crearía.
 */
final class Waylet
{
    public const CUENTA = 40700001, BONIF = 62900012, PENDIENTE = 57000004, TITULAR = 55000000;
    private const MAX_IMPORTE = 1000.0; // tope de seguridad contra errores de tecleo

    public function __construct(private Contabilidad $c, private Holded $h, private bool $escribir) {}

    public function saldo(): float
    {
        return $this->c->saldoCaja(self::CUENTA);
    }

    private static function importe(mixed $v): float
    {
        $n = r2(num($v));
        if (!($n > 0)) {
            throw new ErrorUsuario('Importe no válido');
        }
        if ($n > self::MAX_IMPORTE) {
            throw new ErrorUsuario('Importe demasiado alto (máx. ' . eur(self::MAX_IMPORTE) . ')');
        }
        return $n;
    }

    private static function fecha(mixed $f): string
    {
        return fechaValida(is_string($f) ? $f : null) ?? hoyISO();
    }

    private static function concepto(mixed $v): string
    {
        $s = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', trim((string) $v)) ?? '';
        return mb_substr($s, 0, 100);
    }

    /** Crea (o simula) el asiento y devuelve el resultado con el saldo nuevo. */
    private function asiento(string $fecha, int $debe, int $haber, float $imp, string $desc, float $delta, string $texto, ?float $saldoAntes = null): array
    {
        $a = ['fecha' => $fecha, 'debe' => $debe, 'haber' => $haber, 'importe' => $imp, 'descripcion' => $desc];
        if (!$this->escribir) {
            $saldo = r2(($saldoAntes ?? $this->saldo()) + $delta);
            return ['prueba' => true, 'asiento' => $a, 'saldo' => $saldo,
                'mensaje' => "MODO PRUEBA (no se ha creado nada) · $texto · saldo quedaría en " . eur($saldo)];
        }
        $r = $this->h->asientoSimple($fecha, $debe, $haber, $imp, $desc);
        $saldo = $this->saldo();
        error_log('[holded] asiento Waylet ' . json_encode($a, JSON_UNESCAPED_UNICODE) . ' → ' . json_encode($r));
        return ['prueba' => false, 'asiento' => $a, 'id' => $r['id'] ?? null, 'saldo' => $saldo,
            'mensaje' => "$texto · saldo " . eur($saldo)];
    }

    public function anadir(mixed $importe, mixed $fecha): array
    {
        $n = self::importe($importe);
        return $this->asiento(self::fecha($fecha), self::CUENTA, self::BONIF, $n, 'Bonificación Saldo Waylet', $n, 'Añadidos ' . eur($n));
    }

    public function quitar(mixed $importe, mixed $tipo, mixed $concepto, mixed $fecha): array
    {
        $n = self::importe($importe);
        $extra = self::concepto($concepto);
        if ($tipo === 'factura') {
            return $this->asiento(self::fecha($fecha), self::PENDIENTE, self::CUENTA, $n,
                'Pago Waylet pendiente de factura' . ($extra !== '' ? ' · ' . $extra : ''), -$n,
                'Quitados ' . eur($n) . ' → caja Pagos Waylet (paga la factura desde ahí cuando llegue)');
        }
        if ($tipo === 'gasto') {
            return $this->asiento(self::fecha($fecha), self::TITULAR, self::CUENTA, $n,
                'Saldo Waylet · ' . ($extra !== '' ? $extra : 'gasto sin factura'), -$n,
                'Quitados ' . eur($n) . ' → 550');
        }
        throw new ErrorUsuario('Elige el tipo');
    }

    public function ajustar(mixed $saldoApp): array
    {
        $real = r2(num($saldoApp));
        if (!is_numeric(str_replace(',', '.', (string) $saldoApp)) || $real < 0) {
            throw new ErrorUsuario('Saldo no válido');
        }
        $holded = $this->saldo();
        $dif = r2($real - $holded);
        if (abs($dif) < 0.005) {
            return ['prueba' => !$this->escribir, 'saldo' => $holded, 'mensaje' => 'Sin cambios: Holded ya tiene ' . eur($holded)];
        }
        if (abs($dif) > self::MAX_IMPORTE) {
            throw new ErrorUsuario('Diferencia demasiado grande (' . eur($dif) . '): revisa el saldo');
        }
        $texto = 'Ajustado ' . ($dif > 0 ? '+' : '−') . number_format(abs($dif), 2, ',', '.') . ' € (antes ' . eur($holded) . ')';
        return $dif > 0
            ? $this->asiento(hoyISO(), self::CUENTA, self::BONIF, $dif, 'Ajuste saldo Waylet', $dif, $texto, $holded)
            : $this->asiento(hoyISO(), self::BONIF, self::CUENTA, -$dif, 'Ajuste saldo Waylet', $dif, $texto, $holded);
    }
}
