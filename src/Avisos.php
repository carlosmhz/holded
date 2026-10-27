<?php
declare(strict_types=1);
defined('APP_ROOT') || exit;

/**
 * Avisos en el móvil (Web Push) y eventos de Holded (webhooks). Nada de esto llama a la API de Holded:
 * usa la foto del panel, las tareas y los webhooks recibidos.
 *
 * Datos en data_dir: secretos.json (clave del webhook y claves VAPID, se generan solas),
 * avisos.json (suscripciones y preferencias), avisos_enviados.json, eventos.json.
 */
final class Avisos
{
    public const TIPOS = [
        'tareas'    => 'Tareas del mes sin hacer (día 3)',
        'impuestos' => 'Domiciliar 303 y 130 (7 y 2 días antes)',
        'factura'   => 'Factura nueva de una caja con saldo',
        'conciliar' => 'Movimientos sin conciliar (más de 7 días)',
        'caja40'    => 'Caja esperando factura más de 40 días',
    ];
    private const POR_DEFECTO = ['tareas' => true, 'impuestos' => true, 'factura' => true, 'conciliar' => true, 'caja40' => false];
    /** Proveedor (texto en el contacto de la compra) → caja que paga sus facturas. */
    public const PROVEEDOR_CAJA = [
        'ballenoil' => 57000003, 'telpark' => 57000005, 'elparking' => 57000001,
        'repsol' => 57000004, 'waylet' => 57000004,
    ];
    private const CONTACTO = 'https://holded.mpi-sala.es';

    public function __construct(private Store $s) {}

    /* ── secretos ── */

    public function secretos(): array
    {
        $g = $this->s->leer('secretos');
        if (is_array($g) && !empty($g['webhook']) && !empty($g['vapid']['privada'])) {
            return $g;
        }
        return $this->s->actualizar('secretos', function ($g) {
            $g = is_array($g) ? $g : [];
            $g['webhook'] ??= bin2hex(random_bytes(24));
            $g['vapid'] ??= WebPush::generarVapid();
            return $g;
        }, []);
    }

    public function urlWebhook(): string
    {
        return 'https://holded.mpi-sala.es/api/webhook.php?k=' . $this->secretos()['webhook'];
    }

    /* ── suscripciones y preferencias ── */

    public function config(): array
    {
        $g = $this->s->leer('avisos', []);
        return [
            'prefs' => array_merge(self::POR_DEFECTO, is_array($g['prefs'] ?? null) ? $g['prefs'] : []),
            'subs' => is_array($g['subs'] ?? null) ? $g['subs'] : [],
        ];
    }

    public function suscribir(array $sub): void
    {
        $end = (string) ($sub['endpoint'] ?? '');
        $p = (string) ($sub['keys']['p256dh'] ?? '');
        $a = (string) ($sub['keys']['auth'] ?? '');
        if (!preg_match('#^https://[a-z0-9.\-]+/#i', $end) || strlen($end) > 1000 || !preg_match('/^[\w\-]{40,200}$/', $p) || !preg_match('/^[\w\-]{10,60}$/', $a)) {
            throw new ErrorUsuario('Suscripción no válida');
        }
        $this->s->actualizar('avisos', function ($g) use ($end, $p, $a) {
            $g = is_array($g) ? $g : [];
            $subs = array_values(array_filter($g['subs'] ?? [], fn($x) => $x['endpoint'] !== $end));
            $subs[] = ['endpoint' => $end, 'keys' => ['p256dh' => $p, 'auth' => $a], 'alta' => date('c')];
            $g['subs'] = array_slice($subs, -5); // máx. 5 dispositivos
            return $g;
        }, []);
    }

    public function baja(string $endpoint): void
    {
        $this->s->actualizar('avisos', function ($g) use ($endpoint) {
            $g = is_array($g) ? $g : [];
            $g['subs'] = array_values(array_filter($g['subs'] ?? [], fn($x) => $x['endpoint'] !== $endpoint));
            return $g;
        }, []);
    }

    public function preferencia(string $tipo, bool $on): void
    {
        if (!isset(self::TIPOS[$tipo])) {
            throw new ErrorUsuario('Aviso no válido');
        }
        $this->s->actualizar('avisos', function ($g) use ($tipo, $on) {
            $g = is_array($g) ? $g : [];
            $g['prefs'][$tipo] = $on;
            return $g;
        }, []);
    }

    /* ── envío ── */

    /** Envía a todos los dispositivos. $clave evita repetir el mismo aviso. Devuelve cuántos se enviaron. */
    public function enviar(string $tipo, string $titulo, string $cuerpo, string $url = '/panel.php', ?string $clave = null): int
    {
        $cfg = $this->config();
        if ($tipo !== 'prueba' && empty($cfg['prefs'][$tipo])) {
            return 0;
        }
        if ($clave !== null) {
            $env = $this->s->leer('avisos_enviados', []);
            if (isset($env[$clave])) {
                return 0;
            }
        }
        $vapid = $this->secretos()['vapid'];
        $msg = json_encode(['titulo' => $titulo, 'cuerpo' => $cuerpo, 'url' => $url, 'tag' => $tipo], JSON_UNESCAPED_UNICODE);
        $ok = 0;
        foreach ($cfg['subs'] as $sub) {
            try {
                $st = WebPush::enviar($sub, $vapid, $msg, self::CONTACTO);
            } catch (Throwable $e) {
                error_log('[holded] push: ' . $e->getMessage());
                continue;
            }
            if ($st >= 200 && $st < 300) {
                $ok++;
            } elseif ($st === 404 || $st === 410) {
                $this->baja($sub['endpoint']);
            } else {
                error_log('[holded] push: respuesta ' . $st);
            }
        }
        if ($clave !== null && $ok > 0) {
            $this->s->actualizar('avisos_enviados', function ($e) use ($clave) {
                $e = is_array($e) ? $e : [];
                $e[$clave] = date('c');
                return array_slice($e, -300, null, true);
            }, []);
        }
        $this->s->actualizar('avisos_log', function ($l) use ($tipo, $titulo, $ok) {
            $l = is_array($l) ? $l : [];
            $l[] = ['t' => date('c'), 'tipo' => $tipo, 'titulo' => $titulo, 'enviados' => $ok];
            return array_slice($l, -50);
        }, []);
        return $ok;
    }

    /* ── webhooks de Holded ── */

    /** Secreto de firma de Holded (whsec_…), guardado desde /diag.php. */
    public function secretoFirma(): string
    {
        return (string) ($this->s->leer('secretos', [])['firma'] ?? '');
    }

    public function guardarSecretoFirma(string $secreto): void
    {
        $secreto = trim($secreto);
        if ($secreto !== '' && !preg_match('/^whsec_[A-Za-z0-9+\/=_\-]{10,200}$/', $secreto)) {
            throw new ErrorUsuario('El secreto debe empezar por whsec_');
        }
        $this->s->actualizar('secretos', function ($g) use ($secreto) {
            $g = is_array($g) ? $g : [];
            $g['firma'] = $secreto;
            return $g;
        }, []);
    }

    /**
     * Comprueba la firma de Holded: HMAC-SHA256 de "<x-holded-webhook-date>.<cuerpo>" con el secreto whsec_,
     * en hexadecimal, en la cabecera x-holded-webhook-signature ("sha256=…").
     */
    public function firmaValida(string $cuerpo, string $fecha, string $firma): bool
    {
        $secreto = $this->secretoFirma();
        if ($secreto === '') {
            return true; // aún sin secreto guardado: basta la clave de la URL
        }
        $esperada = hash_hmac('sha256', $fecha . '.' . $cuerpo, $secreto);
        return $fecha !== '' && hash_equals($esperada, preg_replace('/^sha256=/', '', $firma));
    }

    /**
     * Guarda un evento recibido (formato de Holded: evento en la cabecera x-holded-webhook-event y
     * cuerpo con type, contactName, documentNumber, total…) y, si es una compra de un proveedor con
     * caja y saldo, avisa. $id = x-holded-webhook-id (los reintentos repiten el id: no se duplica).
     */
    public function eventoHolded(array $in, string $evento, string $id, ?array $datosPanel): ?array
    {
        $tipo = $evento !== '' ? $evento : (string) ($in['event'] ?? '');
        $ev = [
            't' => date('c'),
            'id' => mb_substr($id, 0, 80),
            'tipo' => mb_substr($tipo, 0, 40),
            'contacto' => mb_substr((string) ($in['contactName'] ?? ''), 0, 80),
            'num' => mb_substr((string) ($in['documentNumber'] ?? ''), 0, 40),
            'total' => is_numeric($in['total'] ?? null) ? (float) $in['total'] : null,
            'borrador' => !empty($in['isDraft']),
        ];
        $nuevo = true;
        $this->s->actualizar('eventos', function ($l) use ($ev, &$nuevo) {
            $l = is_array($l) ? $l : [];
            if ($ev['id'] !== '' && in_array($ev['id'], array_column($l, 'id'), true)) {
                $nuevo = false;
                return $l;
            }
            $l[] = $ev;
            return array_slice($l, -300);
        }, []);
        if (!$nuevo) {
            return null;
        }

        // Aviso: factura nueva de un proveedor cuya caja tiene saldo esperando
        if (str_starts_with($ev['tipo'], 'purchase.') && !str_ends_with($ev['tipo'], '.delete') && $datosPanel) {
            $caja = self::cajaDeContacto($ev['contacto']);
            foreach ($datosPanel['cajas'] ?? [] as $c) {
                if ($caja !== null && $c['num'] === $caja && $c['saldo'] > 0.004) {
                    $this->enviar('factura', 'Factura de ' . $ev['contacto'] . ' recibida',
                        'La caja ' . $c['nombre'] . ' tiene ' . eur($c['saldo']) . ': págala desde la caja.', '/panel.php',
                        'factura-' . $caja . '-' . ($ev['num'] ?: date('Y-m')));
                }
            }
        }
        return $ev;
    }

    public static function cajaDeContacto(string $contacto): ?int
    {
        $c = mb_strtolower($contacto);
        foreach (self::PROVEEDOR_CAJA as $txt => $caja) {
            if ($txt !== '' && str_contains($c, $txt)) {
                return $caja;
            }
        }
        return null;
    }

    /** Eventos recibidos después de una fecha (ISO). */
    public function eventosDesde(string $desde): array
    {
        $l = $this->s->leer('eventos', []);
        return array_values(array_filter(is_array($l) ? $l : [], fn($e) => ($e['t'] ?? '') > $desde));
    }

    /* ── revisión diaria (tarea programada) ── */

    /** Devuelve una lista de lo que ha hecho, para el registro del cron. */
    public function diario(Tareas $tareas, ?array $d): array
    {
        $hecho = [];
        $hoy = new DateTimeImmutable('today');
        $hoyIso = $hoy->format('Y-m-d');

        // 1) Tareas sin hacer, el día 3 (antes del reinicio del día 5)
        if ((int) $hoy->format('j') === 3) {
            $e = $tareas->estado();
            $pend = array_values(array_filter($e['tareas'], fn($t) => !$t['hecha']));
            if ($pend) {
                $n = $this->enviar('tareas', 'Tareas de ' . mb_strtolower($e['nombre']) . ' sin hacer',
                    'Quedan ' . count($pend) . ': ' . implode(', ', array_map(fn($t) => $t['titulo'], $pend)) . '. Se reinician el día 5.',
                    '/tareas.php', 'tareas-' . $e['periodo']);
                $hecho[] = "tareas: $n";
            }
        }
        if ($d === null) {
            return $hecho ?: ['sin foto del panel'];
        }

        // 2) Domiciliación de 303 y 130: 7 y 2 días antes
        $dias = (int) $hoy->diff(new DateTimeImmutable($d['domicilia']))->format('%r%a');
        if (in_array($dias, [7, 2], true)) {
            $i = $d['impuestos'];
            $total = max(0, $i['m303']) + $i['m130'];
            $n = $this->enviar('impuestos', 'Domiciliar 303 y 130',
                'Faltan ' . $dias . ' días (' . (new DateTimeImmutable($d['domicilia']))->format('d/m') . '). Estimado: ' . eur($total) . ' (' . $d['trimestre'] . ').',
                '/panel.php', 'imp-' . $d['domicilia'] . '-' . $dias);
            $hecho[] = "impuestos: $n";
        }

        // 3) Movimientos sin conciliar desde hace más de 7 días (según la última foto); como mucho 1 por semana
        if (($d['bancoPend'] ?? 0) > 0 && !empty($d['bancoPendDesde'])) {
            $desde = new DateTimeImmutable($d['bancoPendDesde']);
            if ($hoy->diff($desde)->days >= 7) {
                $n = $this->enviar('conciliar', 'Movimientos sin conciliar',
                    'El banco tiene ' . $d['bancoPend'] . ' sin conciliar desde el ' . $desde->format('d/m') . '.',
                    '/panel.php', 'conciliar-' . $hoy->format('o-W'));
                $hecho[] = "conciliar: $n";
            }
        }

        // 4) Cajas esperando factura más de 40 días; como mucho 1 por semana y caja
        foreach ($d['cajas'] as $c) {
            if (($c['tipo'] ?? '') === 'factura' && $c['saldo'] > 0.004 && !empty($c['desde'])
                && $hoy->diff(new DateTimeImmutable($c['desde']))->days > 40) {
                $n = $this->enviar('caja40', $c['nombre'] . ': ' . eur($c['saldo']) . ' sin factura',
                    'Lleva saldo desde el ' . (new DateTimeImmutable($c['desde']))->format('d/m') . '. ¿Ha llegado la factura?',
                    '/panel.php', 'caja40-' . $c['num'] . '-' . $hoy->format('o-W'));
                $hecho[] = "caja40 {$c['num']}: $n";
            }
        }
        return $hecho ?: ['nada que avisar'];
    }
}
