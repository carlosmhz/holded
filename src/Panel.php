<?php
declare(strict_types=1);
defined('APP_ROOT') || exit;

/**
 * Panel de consulta (solo lectura). Port de datosPanel() del script de Apps Script.
 * Estimaciones orientativas, no son asesoría fiscal:
 *   130 = 20 % del rendimiento acumulado del año (con el 5 % de gastos de difícil justificación,
 *         máx. 2.000 €) − pagos a cuenta (473 + pagos fraccionados 47510002 desde febrero).
 *   303 = IVA repercutido (477, haber) − soportado (472, debe) del trimestre.
 */
final class Panel
{
    /** [cuenta, nombre]. Banco: saldo real de Tesorería. Tarjeta: por fechas. El resto (cajas): sin fechas + arrastre. */
    public const SALDOS = [
        [57200001, 'Banco Santander'],
        [52000003, 'Tarjeta crédito Santander'],
        [40700001, 'Monedero Waylet (saldo app)'],
        [57000004, 'Pagos Waylet (debe estar a 0)'],
        [57000003, 'Pagos Ballenoil (pendiente de factura)'],
        [57000001, 'Pagos ElParking (pendiente de factura)'],
        [57000005, 'Pagos Telpark (pendiente de factura)'],
        [55500000, 'Partidas pendientes (555)'],
    ];
    public const BANCO = 57200001, TARJETA = 52000003;

    /**
     * Pagos que no están en ningún sitio: [día del mes, importe, concepto, último mes "aaaa-mm" o '' sin fin].
     * La cuota de autónomos se añade sola (último cargo de la 64200001).
     */
    public const PAGOS_FIJOS = [
        [30, 224.82, 'Aplazamiento RETA', '2026-11'],
        [3, 393.25, 'Alquiler oficina (Openhouse)', ''],
    ];

    public const PAGOS_130 = 47510002;           // pagos fraccionados del 130 (debe)
    public const AUTONOMOS = 64200001;
    private const PREFIJOS_4XX = ['400', '410', '430', '440'];

    public function __construct(private Holded $h, private Contabilidad $c, private Store $s) {}

    /** new Date(a, m0, d) de JS → aaaa-mm-dd (m0 = mes desde 0; desborda igual que JS). */
    private static function f(int $a, int $m0, int $d): string
    {
        return date('Y-m-d', mktime(12, 0, 0, $m0 + 1, $d, $a));
    }

    private static function sumarDias(string $iso, int $n): string
    {
        return (new DateTimeImmutable($iso))->modify(($n >= 0 ? '+' : '') . $n . ' day')->format('Y-m-d');
    }

    /** dd/mm/aaaa → aaaa-mm-dd (isoF_). */
    private static function iso(mixed $s): string
    {
        $s = substr((string) $s, 0, 10);
        return preg_match('#^(\d{2})/(\d{2})/(\d{4})$#', $s, $m) ? "$m[3]-$m[2]-$m[1]" : $s;
    }

    /** Fin de la domiciliación: 1T-3T el día 15 del mes siguiente; 4T la más temprana entre el 25/1 y 3 días hábiles antes del 30/1 (o siguiente hábil). */
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
        $t = self::f($anio + 1, 0, 25);
        return min($d->format('Y-m-d'), $t);
    }

    /** resumen_(): reduce la lista de cuentas a lo que usa el panel. */
    private function resumen(array $items): array
    {
        $o = ['ing' => 0.0, 'gas' => 0.0, 'noDed' => 0.0, 'p473' => 0.0, 'p130' => 0.0, 'rep' => 0.0, 'sop' => 0.0, 'cta' => [], 'gc' => []];
        $nums = array_column(self::SALDOS, 0);
        foreach ($items as $c) {
            $num = (string) $c['number'];
            $d = num($c['debit'] ?? 0);
            $h = num($c['credit'] ?? 0);
            $pref4 = false;
            foreach (self::PREFIJOS_4XX as $p) {
                $pref4 = $pref4 || str_starts_with($num, $p);
            }
            if ($pref4 || in_array((int) $num, $nums, true)) {
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

    public function datos(): array
    {
        $hoy = hoyISO();
        [$anio, $mes, $dia] = array_map('intval', explode('-', $hoy));
        $tri = (int) ceil($mes / 3);
        $finTri = self::f($anio, $tri * 3, 0);
        $finTriPrev = self::f($anio, ($tri - 1) * 3, 0);
        $plazo = self::f($anio, $tri * 3, $tri === 4 ? 30 : 20);
        $limS = $dia < 11 ? self::f($anio, $mes - 1, 11) : self::f($anio, $mes, 11);
        $out = ['hoy' => $hoy, 'trimestre' => "{$tri}T $anio", 'finTri' => $finTri, 'plazo' => $plazo,
            'domicilia' => self::finDomiciliacion($anio, $tri), 'limite' => $limS, 'errores' => []];
        $intenta = function (string $clave, callable $fn) use (&$out) {
            try {
                $out[$clave] = $fn();
            } catch (Throwable $e) {
                $out['errores'][] = $clave . ': ' . $e->getMessage();
            }
        };

        // Tesorería: saldo real del banco
        $bancoReal = null;
        try {
            $r = $this->h->peticion('GET', '/treasury/accounts');
            $items = array_is_list($r) ? $r : ($r['items'] ?? $r['accounts'] ?? []);
            $vivos = array_values(array_filter($items, fn($x) => empty($x['archived'])));
            $a = null;
            foreach ($vivos as $x) {
                if ((int) ($x['accounting_account_number'] ?? $x['accountNumber'] ?? $x['account_number_accounting'] ?? 0) === self::BANCO) {
                    $a = $x;
                    break;
                }
            }
            if ($a === null) {
                foreach ($vivos as $x) {
                    $txt = ($x['name'] ?? '') . ' ' . ($x['institution_name'] ?? '') . ' ' . ($x['bank_name'] ?? '');
                    if (($x['type'] ?? '') === 'bank' && stripos($txt, 'santander') !== false) {
                        $a = $x;
                        break;
                    }
                }
            }
            if ($a !== null) {
                $bancoReal = num($a['balance'] ?? $a['current_balance'] ?? 0);
            } else {
                $out['errores'][] = 'tesoreria: no encuentro el Santander. Campos: ' . (isset($items[0]) && is_array($items[0]) ? implode(',', array_keys($items[0])) : 'lista vacía');
            }
        } catch (Throwable $e) {
            $out['errores'][] = 'tesoreria: ' . $e->getMessage();
        }

        // Saldos: años cerrados (caché) + año en curso por fechas; las cajas, sin fechas + arrastre
        $cta = [];
        $ytd = null;
        $intenta('saldos', function () use (&$cta, &$ytd, $anio, $hoy, $bancoReal) {
            $previo = [];
            for ($y = Contabilidad::ANIO_INICIO; $y < $anio; $y++) {
                foreach ($this->c->saldosAnio($y) as $k => $v) {
                    $previo[$k] = ($previo[$k] ?? 0.0) + $v['b'];
                    $cta[$k] = ['b' => $previo[$k], 'n' => $v['n']];
                }
            }
            $ytd = $this->resumen($this->h->cuentas(($anio - 1) . '-12-31', $hoy));
            foreach ($ytd['cta'] as $k => $v) {
                $cta[$k] = ['b' => ($cta[$k]['b'] ?? 0.0) + $v['b'], 'n' => $v['n']];
            }
            // Cajas: saldo sin fechas (con fechas Holded no devuelve algunas cuentas, p. ej. 57000005) + arrastre
            $cajas = array_diff(array_column(self::SALDOS, 0), [self::BANCO, self::TARJETA]);
            foreach ($this->h->cuentas() as $c) {
                $n = (int) $c['number'];
                if (in_array($n, $cajas, true)) {
                    $cta[(string) $n] = ['b' => ($previo[(string) $n] ?? 0.0) + num($c['balance'] ?? 0), 'n' => trim((string) ($c['name'] ?? $n))];
                }
            }
            return array_map(function ($s) use ($cta, $bancoReal) {
                [$num, $nombre] = $s;
                $cont = r2($cta[(string) $num]['b'] ?? 0.0);
                if ($num === self::BANCO && $bancoReal !== null) {
                    return ['num' => $num, 'nombre' => 'Banco Santander (saldo real)', 'saldo' => r2($bancoReal),
                        'nota' => 'Contable ' . eur($cont) . ' · diferencia ' . eur(r2($bancoReal - $cont))];
                }
                return ['num' => $num, 'nombre' => $nombre, 'saldo' => $cont];
            }, self::SALDOS);
        });

        // Impuestos: trimestre en curso + acumulado de trimestres cerrados (caché 24 h)
        $intenta('impuestos', function () use ($anio, $tri, $finTri, $finTriPrev) {
            $C = $this->resumen($this->h->cuentas($finTriPrev, $finTri));
            $P = ['ing' => 0.0, 'gas' => 0.0, 'noDed' => 0.0, 'p473' => 0.0];
            $Q = ['p130' => 0.0];
            if ($tri > 1) {
                $P = $this->s->cache("ytd{$anio}_$tri", 86400, fn() => array_diff_key($this->resumen($this->h->cuentas(($anio - 1) . '-12-31', $finTriPrev)), ['cta' => 1, 'gc' => 1]));
                // Desde el 31/1: el pago de enero (4T del año anterior) no cuenta
                $Q = $this->s->cache("p130_{$anio}_$tri", 86400, fn() => array_diff_key($this->resumen($this->h->cuentas($anio . '-01-31', $finTriPrev, (string) self::PAGOS_130)), ['cta' => 1, 'gc' => 1]));
            }
            $ing = $P['ing'] + $C['ing'];
            $gas = $P['gas'] + $C['gas'];
            $noDed = $P['noDed'] + $C['noDed'];
            $pagos = $P['p473'] + $C['p473'] + ($tri > 1 ? $Q['p130'] + $C['p130'] : 0);
            $rendPrevio = $ing - ($gas - $noDed);
            $dificil = $rendPrevio > 0 ? min($rendPrevio * 0.05, 2000) : 0;
            $rend = $rendPrevio - $dificil;
            return [
                'ingresos' => r2($ing), 'gastos' => r2($gas), 'noDeducibles' => r2($noDed),
                'resultado' => r2($ing - $gas), 'rendimiento' => r2($rend),
                'cuota130' => r2(max(0, $rend) * 0.2), 'pagosCuenta' => r2($pagos),
                'm130' => r2(max(0, max(0, $rend) * 0.2 - $pagos)),
                'repercutido' => r2($C['rep']), 'soportado' => r2($C['sop']), 'm303' => r2($C['rep'] - $C['sop']),
            ];
        });

        // Reparto de gastos del año hasta hoy: las 6 cuentas mayores + "Resto"
        if ($ytd !== null) {
            $g = array_values(array_filter($ytd['gc'], fn($x) => $x['g'] > 0.004));
            usort($g, fn($a, $b) => $b['g'] <=> $a['g']);
            $top = array_map(fn($x) => ['nombre' => $x['n'], 'importe' => r2($x['g'])], array_slice($g, 0, 6));
            $resto = r2(array_sum(array_column(array_slice($g, 6), 'g')));
            if ($resto > 0) {
                $top[] = ['nombre' => 'Resto (' . (count($g) - 6) . ' cuentas)', 'importe' => $resto];
            }
            $out['gastosCuenta'] = $top;
        }

        // Pendientes por saldo de cuenta (sin llamadas extra)
        $pendientes = function (array $pref, int $signo) use ($cta) {
            $l = [];
            foreach ($cta as $k => $v) {
                $ok = false;
                foreach ($pref as $p) {
                    $ok = $ok || str_starts_with((string) $k, $p);
                }
                if ($ok && $signo * $v['b'] > 0.004) {
                    $l[] = ['num' => '', 'contacto' => $v['n'], 'fecha' => '', 'vence' => '', 'pendiente' => r2($signo * $v['b'])];
                }
            }
            usort($l, fn($a, $b) => $b['pendiente'] <=> $a['pendiente']);
            return $l;
        };
        if (isset($out['saldos'])) {
            $intenta('cobros', fn() => $pendientes(['430', '440'], 1));
            $intenta('pagosDocs', fn() => $pendientes(['400', '410'], -1));
        }

        // Pagos hasta el día 11
        $intenta('fijos', function () use ($anio, $mes, $dia, $hoy, $limS, &$out) {
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
            $tarjMes = 0.0;
            foreach ($out['saldos'] ?? [] as $x) {
                if ($x['num'] === self::TARJETA) {
                    $tarjMes = -$x['saldo'];
                }
            }
            // Asientos futuros de banco y tarjeta (fecha > hoy). Se pide un día más y se filtra,
            // para no depender de si Holded incluye o no los extremos del rango.
            foreach ($this->h->lineas(['start_date' => $hoy, 'end_date' => self::sumarDias($limS, 1)]) as $i) {
                $cuenta = (int) ($i['account'] ?? 0);
                $imp = num($i['credit'] ?? 0);
                $f = self::iso($i['date'] ?? '');
                if ($imp <= 0 || $f <= $hoy || $f > $limS || ($cuenta !== self::BANCO && $cuenta !== self::TARJETA)) {
                    continue;
                }
                if ($cuenta === self::TARJETA) {
                    if ($f <= $finMes) {
                        $tarjMes += $imp;
                    }
                    continue;
                }
                $lista[] = ['fecha' => $f, 'concepto' => (string) (($i['description'] ?? '') ?: ($i['doc_description'] ?? '') ?: 'Cuenta ' . $cuenta), 'importe' => r2($imp)];
            }
            if ($tarjMes > 0.004 && $liq <= $limS) {
                $lista[] = ['fecha' => $liq, 'concepto' => 'Liquidación tarjeta de crédito (aprox.)', 'importe' => r2($tarjMes)];
            }
            // Filas pendientes de la hoja "Asientos" (las envía Apps Script a /api/asientos.php)
            $hoja = $this->s->leer('asientos');
            foreach (($hoja['filas'] ?? []) as $r) {
                $f = (string) $r['fecha'];
                $imp = r2((float) $r['importe']);
                if ($f < $hoy || $f > $limS || !($imp > 0) || $ya($f, $imp)) {
                    continue;
                }
                $lista[] = ['fecha' => $f, 'concepto' => (string) $r['concepto'], 'importe' => $imp];
            }
            if ($hoja) {
                $out['hojaAsientos'] = $hoja['generado'];
            }
            // Pagos fijos y cuota de autónomos
            $meter = function (int $d, float $imp, string $concepto, string $hasta) use ($anio, $mes, $hoy, $limS, &$lista, $ya) {
                for ($k = 0; $k < 2; $k++) {
                    $ult = (int) date('t', mktime(12, 0, 0, $mes + $k, 1, $anio));
                    $f = self::f($anio, $mes - 1 + $k, min($d, $ult));
                    if ($f < $hoy || $f > $limS || ($hasta !== '' && substr($f, 0, 7) > $hasta) || $ya($f, $imp)) {
                        continue;
                    }
                    $lista[] = ['fecha' => $f, 'concepto' => $concepto, 'importe' => $imp];
                }
            };
            foreach (self::PAGOS_FIJOS as [$d, $imp, $concepto, $hasta]) {
                $meter($d, $imp, $concepto, $hasta);
            }
            $cuota = $this->s->cache('cuotaAut', 86400, function () use ($hoy) {
                $ult = null;
                foreach ($this->h->lineas(['start_date' => self::sumarDias($hoy, -62), 'end_date' => self::sumarDias($hoy, 1), 'account' => self::AUTONOMOS]) as $i) {
                    $f = self::iso($i['date'] ?? '');
                    if (num($i['debit'] ?? 0) > 0 && $f <= $hoy && ($ult === null || $f > $ult['f'])) {
                        $ult = ['f' => $f, 'imp' => num($i['debit'])];
                    }
                }
                return ['imp' => $ult['imp'] ?? 0.0];
            });
            if ($cuota['imp'] > 0) {
                $meter(31, (float) $cuota['imp'], 'Cuota autónomos', '');
            }
            usort($lista, fn($a, $b) => strcmp($a['fecha'], $b['fecha']));
            return $lista;
        });

        return $out;
    }

    /**
     * Ingresos y gastos de cada mes del año en curso (mismos criterios que el resultado).
     * Meses cerrados en caché 7 días; el mes en curso, hasta hoy. Consultas en paralelo.
     */
    public function meses(): array
    {
        $hoy = hoyISO();
        [$anio, $mes] = array_map('intval', explode('-', $hoy));
        $out = [];
        $pedir = [];
        for ($m = 1; $m <= $mes; $m++) {
            $clave = sprintf('mes%d_%02d', $anio, $m);
            $g = $m < $mes ? $this->s->leer('cache/' . $clave) : null;
            if (is_array($g) && time() - (int) $g['t'] < 7 * 86400) {
                $out[$m] = $g['v'];
            } else {
                $pedir[$m] = ['/accounting-accounts', ['start_date' => self::f($anio, $m - 1, 0), 'end_date' => $m === $mes ? $hoy : self::f($anio, $m, 0)]];
            }
        }
        foreach ($pedir ? $this->h->variosGet($pedir) : [] as $m => $d) {
            $r = $this->resumen($d['items'] ?? []);
            $out[$m] = ['ing' => r2($r['ing']), 'gas' => r2($r['gas'])];
            if ($m < $mes) {
                $this->s->escribir('cache/' . sprintf('mes%d_%02d', $anio, $m), ['t' => time(), 'v' => $out[$m]]);
            }
        }
        ksort($out);
        $l = [];
        foreach ($out as $m => $v) {
            $l[] = ['mes' => sprintf('%d-%02d', $anio, $m), 'ing' => $v['ing'], 'gas' => $v['gas']];
        }
        return ['anio' => $anio, 'meses' => $l];
    }
}
