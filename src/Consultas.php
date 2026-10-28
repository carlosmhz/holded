<?php
declare(strict_types=1);
defined('APP_ROOT') || exit;

/**
 * Consultas de gasto por concepto y periodo (solo lectura, sin IA).
 *
 * Coste en llamadas a Holded:
 *  - Total de un periodo: 1 llamada por año natural que toque (GET /accounting-accounts con fechas,
 *    devuelve TODAS las cuentas). El resultado se guarda y vale para cualquier concepto con ese
 *    mismo periodo hasta que se pulse "Actualizar" en el panel.
 *  - Detalle de una cuenta: 1 llamada (o más si hay más de 200 apuntes), también guardado.
 *  - Lo pendiente de factura sale de la foto: 0 llamadas.
 * Importes de las cuentas 6 y 7 = base imponible (sin IVA).
 */
final class Consultas
{
    /** Conceptos: [nombre, cuentas, cajas de la foto con lo pagado sin factura]. */
    public const CONCEPTOS = [
        'combustible' => ['Combustible', [62900001, 62900012], [57000003, 57000004]],
        'parking'     => ['Parking y zona azul', [62900002, 62900003], [57000001, 57000005]],
        'coche'       => ['Coche (todo)', [62900001, 62900012, 62900002, 62900003, 62200001, 62500001, 62900006, 63100001, 62100004], [57000003, 57000004, 57000001, 57000005]],
        'telefono'    => ['Teléfono e internet', [62800002], []],
        'software'    => ['Software, IA y hosting', [62900007, 62900008, 62900009, 62900011, 62300002], []],
        'oficina'     => ['Oficina (alquiler, luz, material)', [62100003, 62100001, 62800001, 62800003], []],
        'ingresos'    => ['Ingresos (todas las cuentas 7)', ['7'], []],
        'gastos'      => ['Gastos (todas las cuentas 6)', ['6'], []],
    ];
    private const MAX_GUARDADAS = 30;

    public function __construct(private Holded $h, private Store $s, private ?array $foto) {}

    /* ───────── periodos ───────── */

    /** Atajos de periodo calculados para hoy: [id => [nombre, desde, hasta]]. */
    public static function atajos(?string $hoy = null): array
    {
        $hoy ??= hoyISO();
        $d = new DateTimeImmutable($hoy);
        $mesPas = $d->modify('first day of last month');
        $tri = (int) ceil((int) $d->format('n') / 3);
        $iniTri = $d->format('Y') . '-' . sprintf('%02d', $tri * 3 - 2) . '-01';
        return [
            'dia11'   => ['Desde el 11 del mes pasado', $mesPas->format('Y-m-11'), $hoy],
            'mes'     => ['Este mes', $d->format('Y-m-01'), $hoy],
            'mespas'  => ['El mes pasado', $mesPas->format('Y-m-01'), $mesPas->format('Y-m-t')],
            'tri'     => ['Este trimestre', $iniTri, $hoy],
            'anio'    => ['Este año', $d->format('Y-01-01'), $hoy],
            'anioPas' => ['El año pasado', ($d->format('Y') - 1) . '-01-01', ($d->format('Y') - 1) . '-12-31'],
        ];
    }

    /** Valida el periodo: fechas reales, desde ≤ hasta, desde el primer año en Holded y hasta fin de este año. */
    private static function periodo(mixed $desde, mixed $hasta): array
    {
        $a = fechaValida(is_string($desde) ? $desde : null);
        $b = fechaValida(is_string($hasta) ? $hasta : null);
        if ($a === null || $b === null) {
            throw new ErrorUsuario('Fechas no válidas');
        }
        if ($a > $b) {
            throw new ErrorUsuario('La fecha de inicio es posterior a la de fin');
        }
        $min = Contabilidad::ANIO_INICIO . '-01-01';
        $max = Contabilidad::anioActual() . '-12-31';
        if ($a < $min || $b > $max) {
            throw new ErrorUsuario('El periodo tiene que estar entre el ' . self::fecha($min) . ' y el ' . self::fecha($max));
        }
        return [$a, $b];
    }

    private static function fecha(string $iso): string
    {
        return (new DateTimeImmutable($iso))->format('d/m/Y');
    }

    private static function diaAntes(string $iso): string
    {
        return (new DateTimeImmutable($iso))->modify('-1 day')->format('Y-m-d');
    }

    private static function iso(mixed $s): string
    {
        $s = substr((string) $s, 0, 10);
        return preg_match('#^(\d{2})/(\d{2})/(\d{4})$#', $s, $m) ? "$m[3]-$m[2]-$m[1]" : $s;
    }

    /* ───────── lo guardado (se vacía al renovar la foto) ───────── */

    private function guardado(string $clave, callable $fn, int &$llamadas): array
    {
        $t = (int) ($this->foto['t'] ?? 0);
        $g = $this->s->leer('consultas', []);
        if (is_array($g) && ($g['t'] ?? -1) === $t && isset($g['r'][$clave])) {
            return $g['r'][$clave];
        }
        $antes = Holded::usoMes($this->s);
        $v = $fn();
        $llamadas = max(1, Holded::usoMes($this->s) - $antes);
        $this->s->actualizar('consultas', function ($g) use ($t, $clave, $v) {
            $g = is_array($g) && ($g['t'] ?? -1) === $t ? $g : ['t' => $t, 'r' => []];
            $g['r'][$clave] = $v;
            if (count($g['r']) > self::MAX_GUARDADAS) {
                $g['r'] = array_slice($g['r'], -self::MAX_GUARDADAS, null, true);
            }
            return $g;
        }, []);
        return $v;
    }

    /** Movimiento de todas las cuentas 6 y 7 en el periodo: [número => [nombre, debe, haber]]. */
    private function cuentasPeriodo(string $desde, string $hasta, int &$llamadas): array
    {
        return $this->guardado("p_{$desde}_$hasta", function () use ($desde, $hasta) {
            // Un tramo por año natural (Holded admite como mucho 1 año por consulta; inicio exclusivo)
            $tramos = [];
            for ($y = (int) substr($desde, 0, 4); $y <= (int) substr($hasta, 0, 4); $y++) {
                $ini = max($desde, "$y-01-01");
                $fin = min($hasta, "$y-12-31");
                $tramos["y$y"] = ['/accounting-accounts', ['start_date' => self::diaAntes($ini), 'end_date' => $fin]];
            }
            $out = [];
            foreach ($this->h->variosGet($tramos) as $r) {
                foreach ($r['items'] ?? (array_is_list($r) ? $r : []) as $c) {
                    $n = (string) ($c['number'] ?? '');
                    if ($n === '' || ($n[0] !== '6' && $n[0] !== '7')) {
                        continue;
                    }
                    $d = num($c['debit'] ?? 0);
                    $h = num($c['credit'] ?? 0);
                    if (abs($d) < 0.005 && abs($h) < 0.005 && !isset($out[$n])) {
                        continue;
                    }
                    $out[$n] = [trim((string) ($c['name'] ?? $n)), r2(($out[$n][1] ?? 0) + $d), r2(($out[$n][2] ?? 0) + $h)];
                }
            }
            return $out;
        }, $llamadas);
    }

    /* ───────── lo que ve la página ───────── */

    /** Datos para montar el formulario (0 llamadas). */
    public function opciones(): array
    {
        $conceptos = [];
        foreach (self::CONCEPTOS as $id => [$nombre]) {
            $conceptos[] = ['id' => $id, 'nombre' => $nombre];
        }
        // Cuentas sueltas: las de gasto que salen en la foto (año en curso) con su nombre
        $cuentas = [];
        foreach ($this->foto['b']['gc'] ?? [] as $num => $c) {
            $cuentas[] = ['num' => (int) $num, 'nombre' => $c['n']];
        }
        usort($cuentas, fn($a, $b) => $a['num'] <=> $b['num']);
        $atajos = [];
        foreach (self::atajos() as $id => [$n, $a, $b]) {
            $atajos[] = ['id' => $id, 'nombre' => $n, 'desde' => $a, 'hasta' => $b];
        }
        return ['conceptos' => $conceptos, 'cuentas' => $cuentas, 'atajos' => $atajos, 'hoy' => hoyISO(),
            'min' => Contabilidad::ANIO_INICIO . '-01-01', 'max' => Contabilidad::anioActual() . '-12-31'];
    }

    /** Total de un concepto (o de una cuenta suelta) en un periodo. */
    public function total(string $concepto, mixed $cuenta, mixed $desde, mixed $hasta): array
    {
        [$desde, $hasta] = self::periodo($desde, $hasta);
        if ($concepto === 'cuenta') {
            $num = (string) (int) $cuenta;
            if (!preg_match('/^[67]\d{7}$/', $num)) {
                throw new ErrorUsuario('Cuenta no válida');
            }
            $nombre = 'Cuenta ' . $num;
            $lista = [$num];
            $cajas = [];
        } elseif (isset(self::CONCEPTOS[$concepto])) {
            [$nombre, $lista, $cajas] = self::CONCEPTOS[$concepto];
        } else {
            throw new ErrorUsuario('Concepto no válido');
        }

        $llamadas = 0;
        $todas = $this->cuentasPeriodo($desde, $hasta, $llamadas);
        $lineas = [];
        foreach ($lista as $sel) {
            $sel = (string) $sel;
            foreach ($todas as $num => [$n, $d, $h]) {
                $num = (string) $num;
                if ($num === $sel || (strlen($sel) === 1 && $num[0] === $sel)) {
                    $lineas[$num] = ['num' => (int) $num, 'nombre' => $n, 'importe' => r2($num[0] === '7' ? $h - $d : $d - $h)];
                }
            }
            if (strlen($sel) > 1 && !isset($lineas[$sel])) { // sin movimiento en el periodo
                $lineas[$sel] = ['num' => (int) $sel, 'nombre' => $this->nombreCuenta($sel), 'importe' => 0.0];
            }
        }
        $todasLineas = array_values($lineas);
        $lineas = array_values(array_filter($todasLineas, fn($l) => abs($l['importe']) >= 0.005));
        if (!$lineas && $todasLineas) {
            $lineas = [$todasLineas[0]];
        }
        usort($lineas, fn($a, $b) => abs($b['importe']) <=> abs($a['importe']));
        if ($concepto === 'cuenta' && $lineas) {
            $nombre = $lineas[0]['nombre'];
        }

        // Pagado y aún sin factura (cajas de la foto, con IVA; es lo de hoy, no del periodo)
        $sinFactura = [];
        if ($this->foto !== null) {
            foreach ($cajas as $cta) {
                $v = Panel::saldoCaja($this->foto, $cta);
                if ($v > 0.004) {
                    $sinFactura[] = ['nombre' => Panel::CAJAS[$cta][0] ?? (string) $cta, 'importe' => $v];
                }
            }
        }

        return ['concepto' => $nombre, 'desde' => $desde, 'hasta' => $hasta,
            'total' => r2(array_sum(array_column($lineas, 'importe'))), 'lineas' => $lineas,
            'sinFactura' => $sinFactura, 'llamadas' => $llamadas, 'uso' => Holded::usoMes($this->s),
            'foto' => isset($this->foto['t']) ? date('c', (int) $this->foto['t']) : null];
    }

    /** Apuntes de una cuenta en el periodo (detalle bajo demanda). */
    public function detalle(mixed $cuenta, mixed $desde, mixed $hasta): array
    {
        [$desde, $hasta] = self::periodo($desde, $hasta);
        $num = (int) $cuenta;
        if (!preg_match('/^[67]\d{7}$/', (string) $num)) {
            throw new ErrorUsuario('Cuenta no válida');
        }
        $llamadas = 0;
        $apuntes = $this->guardado("d_{$num}_{$desde}_$hasta", function () use ($num, $desde, $hasta) {
            $hastaMas = (new DateTimeImmutable($hasta))->modify('+1 day')->format('Y-m-d');
            $out = [];
            foreach ($this->h->lineas(['start_date' => $desde, 'end_date' => $hastaMas, 'account' => $num]) as $i) {
                $f = self::iso($i['date'] ?? '');
                if ((int) ($i['account'] ?? 0) !== $num || $f < $desde || $f > $hasta) {
                    continue;
                }
                $d = num($i['debit'] ?? 0);
                $h = num($i['credit'] ?? 0);
                $out[] = ['fecha' => $f, 'concepto' => trim((string) (($i['description'] ?? '') ?: ($i['doc_description'] ?? ''))),
                    'doc' => trim((string) ($i['doc_description'] ?? '')), 'asiento' => (int) ($i['entry_number'] ?? 0),
                    'importe' => r2($num >= 70000000 ? $h - $d : $d - $h)];
            }
            usort($out, fn($a, $b) => strcmp($a['fecha'], $b['fecha']));
            return $out;
        }, $llamadas);
        return ['cuenta' => $num, 'apuntes' => $apuntes, 'llamadas' => $llamadas, 'uso' => Holded::usoMes($this->s)];
    }

    private function nombreCuenta(string $num): string
    {
        return $this->foto['b']['gc'][$num]['n'] ?? $this->foto['b']['nombres'][$num] ?? 'Cuenta ' . $num;
    }
}
