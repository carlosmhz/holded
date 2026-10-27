<?php
declare(strict_types=1);
defined('APP_ROOT') || exit;

/**
 * Tareas del mes. La lista se reinicia el DÍA 5 (algunas facturas llegan después del día 1):
 * el periodo "noviembre" va del 5 de noviembre al 4 de diciembre, y sus facturas a descargar son las de octubre.
 * Se guarda en data_dir/tareas.json. No llama a Holded.
 */
final class Tareas
{
    public const DIA_REINICIO = 5;
    private const MESES = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];

    public function __construct(private Store $s) {}

    /** Periodo actual "aaaa-mm" (mes en que empezó la lista). */
    public static function periodo(?DateTimeImmutable $hoy = null): string
    {
        $hoy ??= new DateTimeImmutable('now');
        return ((int) $hoy->format('j') >= self::DIA_REINICIO ? $hoy : $hoy->modify('first day of last month'))->format('Y-m');
    }

    /** Lista de tareas del periodo. %ant% = mes anterior (el de las facturas). */
    public static function lista(string $periodo): array
    {
        $m = (int) substr($periodo, 5, 2);
        $ant = self::MESES[($m + 10) % 12];
        $drive = 'Déjala en Drive, carpeta Facturas → Holded.';
        return [
            ['id' => 'telpark',   'titulo' => "Descargar la factura de $ant de Telpark", 'ayuda' => "App o web, Mis facturas. $drive"],
            ['id' => 'elparking', 'titulo' => "Descargar la factura de $ant de ElParking (zona azul)", 'ayuda' => "App o web, con el código que te envían. $drive"],
            ['id' => 'movistar',  'titulo' => "Descargar la factura de $ant de Movistar", 'ayuda' => "Web de Movistar, área de clientes. $drive"],
            ['id' => 'waylet',    'titulo' => 'Comprobar el saldo de Waylet', 'ayuda' => 'Si no coincide con la app, usa Ajustar en la pestaña Waylet.'],
            ['id' => 'amortiz',   'titulo' => 'Amortización del ordenador', 'ayuda' => 'Holded → Activos, botón del asiento de este mes.'],
        ];
    }

    public static function nombrePeriodo(string $periodo): string
    {
        return ucfirst(self::MESES[(int) substr($periodo, 5, 2) - 1]) . ' ' . substr($periodo, 0, 4);
    }

    /** Estado: tareas con 'hecha' (fecha o null) para el periodo actual. */
    public function estado(): array
    {
        $p = self::periodo();
        $g = $this->s->leer('tareas', []);
        $hechas = is_array($g) && ($g['periodo'] ?? '') === $p ? ($g['hechas'] ?? []) : [];
        $l = array_map(fn($t) => $t + ['hecha' => $hechas[$t['id']] ?? null], self::lista($p));
        $sig = (new DateTimeImmutable($p . '-01'))->modify('+1 month')->format('Y-m-') . sprintf('%02d', self::DIA_REINICIO);
        return ['periodo' => $p, 'nombre' => self::nombrePeriodo($p), 'reinicio' => $sig, 'tareas' => $l];
    }

    public function marcar(string $id, bool $hecha): array
    {
        $p = self::periodo();
        if (!in_array($id, array_column(self::lista($p), 'id'), true)) {
            throw new ErrorUsuario('Tarea no válida');
        }
        $this->s->actualizar('tareas', function ($g) use ($p, $id, $hecha) {
            $g = is_array($g) && ($g['periodo'] ?? '') === $p ? $g : ['periodo' => $p, 'hechas' => []];
            if ($hecha) {
                $g['hechas'][$id] = date('c');
            } else {
                unset($g['hechas'][$id]);
            }
            return $g;
        }, []);
        return $this->estado();
    }
}
