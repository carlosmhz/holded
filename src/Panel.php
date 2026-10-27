<?php
declare(strict_types=1);
defined('APP_ROOT') || exit;

/**
 * Panel de consulta (solo lectura) a partir de una FOTO de Holded guardada en el servidor.
 *
 * La API de Holded tiene un límite mensual, así que:
 *  - Abrir el panel o Waylet NO llama a Holded: se lee la foto (data_dir/foto.json).
 *  - La foto solo se renueva con el botón "Actualizar" (o la primera vez que no existe): 3 llamadas
 *      A) cuentas sin fechas            → cajas y monederos (+ arrastre de años cerrados)
 *      B) cuentas del 1/1 al fin del trimestre → resultado, 130, 303, pendientes, banco y tarjeta
 *      C) libro diario de hoy al fin del trimestre (o al día 11 si es más tarde) → pagos próximos
 *         y lo ya programado con fecha futura (se descuenta para tener banco y tarjeta a hoy)
 *  - Datos que cambian poco, en caché aparte: años cerrados (1 llamada por año), IVA de los
 *    trimestres cerrados (1 por trimestre), pago del 130 de enero (1 por año), cuota de autónomos (1 al mes).
 *  - Las operaciones de Waylet ajustan la foto sin volver a consultar.
 *
 * Estimaciones orientativas, no son asesoría fiscal:
 *   130 = 20 % del rendimiento acumulado (con el 5 % de gastos de difícil justificación, máx. 2.000 €)
 *         − pagos a cuenta (473 + pagos fraccionados 47510002 desde febrero).
 *   303 = IVA repercutido (477, haber) − soportado (472, debe) del trimestre.
 */
final class Panel
{
    public const BANCO = 57200001, TARJETA = 52000003;
    /** Cajas y monederos, en el orden en que se muestran. 'cero' = debería estar a 0. */
    public const CAJAS = [
        40700001 => ['Monedero Waylet', 'monedero'],
        57000003 => ['Pagos Ballenoil', 'factura'],
        57000001 => ['Pagos ElParking', 'factura'],
        57000005 => ['Pagos Telpark', 'factura'],
        57000004 => ['Pagos Waylet', 'cero'],
        55500000 => ['Partidas pendientes 555', 'cero'],
    ];

    /** Pagos que no están en ningún sitio: [día del mes, importe, concepto, último mes "aaaa-mm" o '' sin fin]. */
    public const PAGOS_FIJOS = [
        [30, 224.82, 'Aplazamiento RETA', '2026-11'],
        [3, 393.25, 'Alquiler oficina (Openhouse)', ''],
    ];

    public const PAGOS_130 = 47510002;
    public const AUTONOMOS = 64200001;
    private const PREFIJOS_4XX = ['400', '410', '430', '440'];
    private const SEMANA = 7 * 86400;

    public function __construct(private Holded $h, private Contabilidad $c, private Store $s) {}

    /* ───────── utilidades de fechas ───────── */

    /** new Date(a, m0, d) de JS → aaaa-mm-dd (m0 = mes desde 0; desborda igual que JS). */
    private static function f(int $a, int $m0, int $d): string
    {
        return date('Y-m-d', mktime(12, 0, 0, $m0 + 1, $d, $a));
    }

    private static function sumarDias(string $iso, int $n): string
    {
        return (new DateTimeImmutable($iso))->modify(($n >= 0 ? '+' : '') . $n . ' day')->format('Y-m-d');
    }

    private static function iso(mixed $s): string
    {
        $s = substr((string) $s, 0, 10);
        return preg_match('#^(\d{2})/(\d{2})/(\d{4})$#', $s, $m) ? "$m[3]-$m[2]-$m[1]" : $s;
    }

    /** Fin de la domiciliación: 1T-3T el día 15 del mes siguiente; 4T la más temprana entre el 25/1 y 3 días hábiles antes del 30/1. */
    public static function finDomiciliacion(int $anio, int $tri): string
    {
        if ($tri < 4) {
            return self::f($anio, $tri * 3, 15);
        }
        $d = new DateTimeImmutable(self::f($anio + 1, 0, 30));
        while ((int) $d->format('N') >= 6) {
            $d = $d->modify('+1 day');
        }
        for ($n = 0; $n < 3;) {
            $d = $d->modify('-1 day');
            if ((int) $d->format('N') < 6) {
                $n++;
            }
        }
        return min($d->format('Y-m-d'), self::f($anio + 1, 0, 25));
    }

    private static function es4xx(string $num): bool
    {
        foreach (self::PREFIJOS_4XX as $p) {
            if (str_starts_with($num, $p)) {
                return true;
            }
        }
        return false;
    }

    /** resumen_(): ingresos, gastos, impuestos y saldos de las cuentas que usa el panel. */
    private function resumen(array $items): array
    {
        $o = ['ing' => 0.0, 'gas' => 0.0, 'noDed' => 0.0, 'p473' => 0.0, 'p130' => 0.0, 'rep' => 0.0, 'sop' => 0.0, 'cta' => [], 'gc' => []];
        foreach ($items as $c) {
            $num = (string) $c['number'];
            $d = num($c['debit'] ?? 0);
            $h = num($c['credit'] ?? 0);
            if (self::es4xx($num) || (int) $num === self::BANCO || (int) $num === self::TARJETA) {
                $o['cta'][$num] = ['b' => num($c['balance'] ?? 0), 'n' => trim((string) ($c['name'] ?? $num))];
            }
            if (str_starts_with($num, '67') || str_starts_with($num, '77')) {
                continue;
            }
            if ($num[0] === '7') {
                $o['ing'] += $h - $d;
            } elseif ($num[0] === '6') {
                $o['gas'] += $d - $h;
                $o['gc'][$num] = ['n' => trim((string) ($c['name'] ?? $num)), 'g' => $d - $h];
                if (!empty($c['non_deductible'])) {
                    $o['noDed'] += $d - $h;
                }
            } elseif (str_starts_with($num, '473')) {
                $o['p473'] += $d - $h;
            } elseif ((int) $num === self::PAGOS_130) {
                $o['p130'] += $d;
            } elseif (str_starts_with($num, '477')) {
                $o['rep'] += $h;
            } elseif (str_starts_with($num, '472')) {
                $o['sop'] += $d;
            }
        }
        return $o;
    }

    /* ───────── la foto ───────── */

    public function foto(): ?array
    {
        $f = $this->s->leer('foto');
        return is_array($f) && isset($f['t']) ? $f : null;
    }

    /** Foto guardada o, si no hay ninguna, una nueva. */
    public function fotoOCrear(): array
    {
        return $this->foto() ?? $this->actualizar();
    }

    /** Pide los datos a Holded (3 llamadas + lo que falte en caché) y guarda la foto. */
    public function actualizar(): array
    {
        $hoy = hoyISO();
        [$anio, $mes, $dia] = array_map('intval', explode('-', $hoy));
        $tri = (int) ceil($mes / 3);
        $finTri = self::f($anio, $tri * 3, 0);
        $finTriPrev = self::f($anio, ($tri - 1) * 3, 0);
        $limS = $dia < 11 ? self::f($anio, $mes - 1, 11) : self::f($anio, $mes, 11);
        $finC = max($finTri, $limS);

        // A, B y C en paralelo
        $r = $this->h->variosGet([
            'A' => ['/accounting-accounts', []],
            'B' => ['/accounting-accounts', ['start_date' => ($anio - 1) . '-12-31', 'end_date' => $finTri]],
            'C' => ['/ledger-entries', ['start_date' => $hoy, 'end_date' => self::sumarDias($finC, 1), 'limit' => 200]],
        ]);
        $lineasC = $r['C']['items'] ?? [];
        if (!empty($r['C']['has_more']) && !empty($r['C']['cursor'])) { // más de 200 líneas: resto de páginas
            $lineasC = array_merge($lineasC, $this->h->lineas(['start_date' => $hoy, 'end_date' => self::sumarDias($finC, 1), 'cursor' => $r['C']['cursor']]));
        }

        $a = [];
        foreach ($r['A']['items'] ?? [] as $c) {
            if (isset(self::CAJAS[(int) $c['number']])) {
                $a[(string) (int) $c['number']] = num($c['balance'] ?? 0);
            }
        }
        $b = $this->resumen($r['B']['items'] ?? []);

        // Futuro: solo banco y tarjeta (para los pagos y para llevar sus saldos a hoy)
        $fut = [];
        foreach ($lineasC as $i) {
            $cta = (int) ($i['account'] ?? 0);
            $f = self::iso($i['date'] ?? '');
            if ($f <= $hoy || ($cta !== self::BANCO && $cta !== self::TARJETA)) {
                continue;
            }
            $fut[] = ['f' => $f, 'c' => $cta, 'd' => r2(num($i['debit'] ?? 0)), 'h' => r2(num($i['credit'] ?? 0)),
                'q' => (string) (($i['description'] ?? '') ?: ($i['doc_description'] ?? '') ?: 'Cuenta ' . $cta)];
        }

        // Arrastre de años cerrados (caché larga)
        $arr = [];
        for ($y = Contabilidad::ANIO_INICIO; $y < $anio; $y++) {
            foreach ($this->c->saldosAnio($y) as $k => $v) {
                $arr[$k] = ($arr[$k] ?? 0.0) + $v['b'];
                $b['nombres'][$k] = $v['n'];
            }
        }

        // IVA de los trimestres cerrados del año (para sacar el del trimestre en curso)
        $p = ['rep' => 0.0, 'sop' => 0.0];
        if ($tri > 1) {
            $p = $this->s->cache("iva_{$anio}_$tri", self::SEMANA, function () use ($anio, $finTriPrev) {
                $x = $this->resumen($this->h->cuentas(($anio - 1) . '-12-31', $finTriPrev));
                return ['rep' => $x['rep'], 'sop' => $x['sop']];
            });
        }
        // Pago del 130 de enero (4T del año anterior): no cuenta como pago a cuenta de este año
        $enero = $this->s->cache("p130enero_$anio", $mes > 1 ? 365 * 86400 : 86400, function () use ($anio) {
            return $this->resumen($this->h->cuentas(($anio - 1) . '-12-31', $anio . '-01-31', (string) self::PAGOS_130))['p130'];
        });
        // Última cuota de autónomos (caché 30 días)
        $cuota = $this->s->cache('cuotaAut', 30 * 86400, function () use ($hoy) {
            $ult = null;
            foreach ($this->h->lineas(['start_date' => self::sumarDias($hoy, -62), 'end_date' => self::sumarDias($hoy, 1), 'account' => self::AUTONOMOS]) as $i) {
                $f = self::iso($i['date'] ?? '');
                if (num($i['debit'] ?? 0) > 0 && $f <= $hoy && ($ult === null || $f > $ult['f'])) {
                    $ult = ['f' => $f, 'imp' => num($i['debit'])];
                }
            }
            return $ult['imp'] ?? 0.0;
        });

        $foto = ['t' => time(), 'hoy' => $hoy, 'anio' => $anio, 'tri' => $tri, 'finTri' => $finTri, 'limS' => $limS,
            'a' => $a, 'b' => $b, 'fut' => $fut, 'arr' => $arr, 'p' => $p, 'enero' => (float) $enero, 'cuota' => (float) $cuota, 'ajustes' => 0];
        $this->s->escribir('foto', $foto);
        return $foto;
    }

    /**
     * Aplica a la foto un asiento creado desde la web (Waylet), sin consultar a Holded.
     * $lineas = [[cuenta, debe, haber], …]
     */
    public function ajustarFoto(string $fecha, array $lineas): void
    {
        $this->s->actualizar('foto', function ($f) use ($fecha, $lineas) {
            if (!is_array($f) || !isset($f['t'])) {
                return $f;
            }
            foreach ($lineas as [$cta, $d, $h]) {
                $k = (string) $cta;
                if (isset(self::CAJAS[$cta])) {
                    $f['a'][$k] = r2(($f['a'][$k] ?? 0) + $d - $h);
                }
                if ($fecha <= $f['finTri'] && substr($fecha, 0, 4) === (string) $f['anio']) {
                    if ($k[0] === '6' && !str_starts_with($k, '67')) {
                        $f['b']['gas'] += $d - $h;
                        if (isset($f['b']['gc'][$k])) {
                            $f['b']['gc'][$k]['g'] += $d - $h;
                        }
                    } elseif ($k[0] === '7' && !str_starts_with($k, '77')) {
                        $f['b']['ing'] += $h - $d;
                    }
                }
            }
            $f['ajustes'] = (int) ($f['ajustes'] ?? 0) + 1;
            return $f;
        });
    }

    /** Fija el saldo de una caja con un dato recién leído de Holded (p. ej. al ajustar Waylet). */
    public function fijarCaja(int $cta, float $saldoAnio): void
    {
        $this->s->actualizar('foto', function ($f) use ($cta, $saldoAnio) {
            if (is_array($f) && isset($f['t'])) {
                $f['a'][(string) $cta] = $saldoAnio;
            }
            return $f;
        });
    }

    /** Saldo de una caja según la foto (año en curso + arrastre). */
    public static function saldoCaja(array $f, int $cta): float
    {
        return r2(($f['a'][(string) $cta] ?? 0.0) + ($f['arr'][(string) $cta] ?? 0.0));
    }

    /* ───────── de la foto a lo que pinta el panel (sin llamadas) ───────── */

    public function datos(array $f): array
    {
        $hoy = $f['hoy'];
        [$anio, $mes] = array_map('intval', explode('-', $hoy));
        $tri = $f['tri'];
        $limS = $f['limS'];
        $b = $f['b'];
        $out = ['foto' => date('c', $f['t']), 'ajustes' => $f['ajustes'] ?? 0, 'hoy' => $hoy, 'trimestre' => "{$tri}T $anio",
            'plazo' => self::f($anio, $tri * 3, $tri === 4 ? 30 : 20), 'domicilia' => self::finDomiciliacion($anio, $tri),
            'limite' => $limS, 'uso' => Holded::usoMes($this->s)];

        // Cajas
        $out['cajas'] = [];
        foreach (self::CAJAS as $cta => [$nombre, $tipo]) {
            $out['cajas'][] = ['num' => $cta, 'nombre' => $nombre, 'tipo' => $tipo, 'saldo' => self::saldoCaja($f, $cta)];
        }

        // Banco y tarjeta a hoy: hasta fin de trimestre − lo programado con fecha futura (hasta fin de trimestre)
        $aHoy = function (int $cta) use ($f, $b) {
            $v = ($b['cta'][(string) $cta]['b'] ?? 0.0) + ($f['arr'][(string) $cta] ?? 0.0);
            foreach ($f['fut'] as $l) {
                if ($l['c'] === $cta && $l['f'] <= $f['finTri']) {
                    $v -= $l['d'] - $l['h'];
                }
            }
            return r2($v);
        };
        $out['banco'] = $aHoy(self::BANCO);
        $out['tarjeta'] = $aHoy(self::TARJETA);
        $out['cajas'][] = ['num' => self::TARJETA, 'nombre' => 'Tarjeta de crédito', 'tipo' => 'tarjeta', 'saldo' => $out['tarjeta'],
            'cargo' => self::f($anio, $mes, 1)];

        // Impuestos y resultado
        $ing = $b['ing'];
        $gas = $b['gas'];
        $rendPrevio = $ing - ($gas - $b['noDed']);
        $rend = $rendPrevio - ($rendPrevio > 0 ? min($rendPrevio * 0.05, 2000) : 0);
        $pagos = $b['p473'] + ($tri > 1 ? $b['p130'] - $f['enero'] : 0);
        $rep = $b['rep'] - $f['p']['rep'];
        $sop = $b['sop'] - $f['p']['sop'];
        $out['impuestos'] = [
            'ingresos' => r2($ing), 'gastos' => r2($gas), 'noDeducibles' => r2($b['noDed']), 'resultado' => r2($ing - $gas),
            'rendimiento' => r2($rend), 'pagosCuenta' => r2($pagos), 'm130' => r2(max(0, max(0, $rend) * 0.2 - $pagos)),
            'repercutido' => r2($rep), 'soportado' => r2($sop), 'm303' => r2($rep - $sop),
        ];

        // Reparto de gastos: 5 cuentas mayores + resto
        $g = array_values(array_filter($b['gc'], fn($x) => $x['g'] > 0.004));
        usort($g, fn($x, $y) => $y['g'] <=> $x['g']);
        $out['gastosCuenta'] = array_map(fn($x) => ['nombre' => $x['n'], 'importe' => r2($x['g'])], array_slice($g, 0, 5));
        $resto = r2(array_sum(array_column(array_slice($g, 5), 'g')));
        if ($resto > 0) {
            $out['gastosCuenta'][] = ['nombre' => 'Resto (' . (count($g) - 5) . ' cuentas)', 'importe' => $resto];
        }

        // Pendientes de cobro y pago: saldo hasta fin de trimestre + años cerrados
        $cobros = $pagosDocs = [];
        $ctas = $b['cta'];
        foreach ($f['arr'] as $k => $v) {
            if (self::es4xx((string) $k) && !isset($ctas[$k])) {
                $ctas[$k] = ['b' => 0.0, 'n' => $b['nombres'][$k] ?? (string) $k];
            }
        }
        foreach ($ctas as $k => $v) {
            if (!self::es4xx((string) $k)) {
                continue;
            }
            $s = $v['b'] + ($f['arr'][$k] ?? 0.0);
            if ((str_starts_with((string) $k, '430') || str_starts_with((string) $k, '440')) && $s > 0.004) {
                $cobros[] = ['contacto' => $v['n'], 'pendiente' => r2($s)];
            } elseif ((str_starts_with((string) $k, '400') || str_starts_with((string) $k, '410')) && -$s > 0.004) {
                $pagosDocs[] = ['contacto' => $v['n'], 'pendiente' => r2(-$s)];
            }
        }
        usort($cobros, fn($x, $y) => $y['pendiente'] <=> $x['pendiente']);
        usort($pagosDocs, fn($x, $y) => $y['pendiente'] <=> $x['pendiente']);
        $out['cobros'] = $cobros;
        $out['pagosDocs'] = $pagosDocs;

        // Pagos hasta el día 11
        $lista = [];
        $ya = function (string $fecha, float $imp) use (&$lista) {
            foreach ($lista as $x) {
                if (substr($x['fecha'], 0, 7) === substr($fecha, 0, 7) && abs($x['importe'] - $imp) < 0.01) {
                    return true;
                }
            }
            return false;
        };
        $finMes = self::f($anio, $mes, 0);
        $liq = self::f($anio, $mes, 1);
        $tarjMes = -$out['tarjeta'];
        foreach ($f['fut'] as $l) {
            if ($l['h'] <= 0 || $l['f'] > $limS) {
                continue;
            }
            if ($l['c'] === self::TARJETA) {
                if ($l['f'] <= $finMes) {
                    $tarjMes += $l['h'];
                }
                continue;
            }
            $lista[] = ['fecha' => $l['f'], 'concepto' => $l['q'], 'importe' => $l['h']];
        }
        if ($tarjMes > 0.004 && $liq <= $limS) {
            $lista[] = ['fecha' => $liq, 'concepto' => 'Tarjeta de crédito (aprox.)', 'importe' => r2($tarjMes)];
        }
        $hoja = $this->s->leer('asientos');
        foreach (($hoja['filas'] ?? []) as $r) {
            $fe = (string) $r['fecha'];
            $imp = r2((float) $r['importe']);
            if ($fe < $hoy || $fe > $limS || !($imp > 0) || $ya($fe, $imp)) {
                continue;
            }
            $lista[] = ['fecha' => $fe, 'concepto' => (string) $r['concepto'], 'importe' => $imp];
        }
        $meter = function (int $d, float $imp, string $concepto, string $hasta) use ($anio, $mes, $hoy, $limS, &$lista, $ya) {
            for ($k = 0; $k < 2; $k++) {
                $ult = (int) date('t', mktime(12, 0, 0, $mes + $k, 1, $anio));
                $fe = self::f($anio, $mes - 1 + $k, min($d, $ult));
                if ($fe < $hoy || $fe > $limS || ($hasta !== '' && substr($fe, 0, 7) > $hasta) || $ya($fe, $imp)) {
                    continue;
                }
                $lista[] = ['fecha' => $fe, 'concepto' => $concepto, 'importe' => $imp];
            }
        };
        foreach (self::PAGOS_FIJOS as [$d, $imp, $concepto, $hasta]) {
            $meter($d, $imp, $concepto, $hasta);
        }
        if ($f['cuota'] > 0) {
            $meter(31, $f['cuota'], 'Cuota autónomos', '');
        }
        usort($lista, fn($x, $y) => strcmp($x['fecha'], $y['fecha']));
        $out['fijos'] = $lista;
        $out['hojaAsientos'] = $hoja['generado'] ?? null;
        return $out;
    }
}
