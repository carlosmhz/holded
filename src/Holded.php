<?php
declare(strict_types=1);
defined('APP_ROOT') || exit;

final class ErrorHolded extends RuntimeException {}

/**
 * Cliente mínimo de la API REST de Holded v2 (cURL nativo, sin Composer).
 * La clave solo se usa aquí, en el servidor; nunca llega al navegador.
 */
final class Holded
{
    public function __construct(private string $clave, private string $base) {}

    public function peticion(string $metodo, string $ruta, array $query = [], ?array $cuerpo = null): array
    {
        if ($this->clave === '') {
            throw new ErrorHolded('Falta holded_api_key en el config');
        }
        $url = rtrim($this->base, '/') . $ruta . ($query ? '?' . http_build_query($query) : '');
        $ch = curl_init($url);
        $cab = ['Authorization: Bearer ' . $this->clave, 'Accept: application/json'];
        if ($cuerpo !== null) {
            $cab[] = 'Content-Type: application/json';
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($cuerpo, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        }
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => strtoupper($metodo),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $cab,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_FOLLOWLOCATION => false,
        ]);
        $txt = curl_exec($ch);
        if ($txt === false) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new ErrorHolded('Sin conexión con Holded: ' . $err);
        }
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if ($status >= 300) {
            throw new ErrorHolded('Holded ' . $status . ': ' . mb_substr((string) $txt, 0, 150), $status);
        }
        $d = json_decode((string) $txt, true);
        return is_array($d) ? $d : [];
    }

    /**
     * Varias consultas GET en paralelo (curl_multi). $peticiones = [clave => [ruta, query]].
     * Devuelve [clave => datos]; si alguna falla, lanza ErrorHolded.
     */
    public function variosGet(array $peticiones): array
    {
        if ($this->clave === '') {
            throw new ErrorHolded('Falta holded_api_key en el config');
        }
        $mh = curl_multi_init();
        $hs = [];
        foreach ($peticiones as $k => [$ruta, $q]) {
            $ch = curl_init(rtrim($this->base, '/') . $ruta . ($q ? '?' . http_build_query($q) : ''));
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $this->clave, 'Accept: application/json'],
                CURLOPT_CONNECTTIMEOUT => 8,
                CURLOPT_TIMEOUT        => 30,
                CURLOPT_SSL_VERIFYPEER => true,
            ]);
            curl_multi_add_handle($mh, $ch);
            $hs[$k] = $ch;
        }
        do {
            $st = curl_multi_exec($mh, $activos);
            if ($activos) {
                curl_multi_select($mh, 1.0);
            }
        } while ($activos && $st === CURLM_OK);
        $out = [];
        $error = null;
        foreach ($hs as $k => $ch) {
            $txt = curl_multi_getcontent($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            if ($txt === null || $txt === '' || $status >= 300 || $status === 0) {
                $error ??= 'Holded ' . $status . ': ' . mb_substr((string) $txt, 0, 120) . (curl_error($ch) ? ' ' . curl_error($ch) : '');
            } else {
                $d = json_decode($txt, true);
                $out[$k] = is_array($d) ? $d : [];
            }
            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);
        }
        curl_multi_close($mh);
        if ($error !== null) {
            throw new ErrorHolded($error);
        }
        return $out;
    }

    /** Líneas del libro diario con paginación por cursor. */
    public function lineas(array $q): array
    {
        $out = [];
        $q['limit'] = 200;
        for ($pag = 0; $pag < 20; $pag++) {
            $r = $this->peticion('GET', '/ledger-entries', $q);
            array_push($out, ...($r['items'] ?? []));
            if (empty($r['has_more']) || empty($r['cursor'])) {
                break;
            }
            $q['cursor'] = $r['cursor'];
        }
        return $out;
    }

    /**
     * GET /accounting-accounts. $ini es EXCLUSIVO (Holded cuenta desde el día siguiente), $fin inclusive.
     * Sin fechas: saldo del año natural en curso. Máximo 1 año por consulta.
     */
    public function cuentas(?string $ini = null, ?string $fin = null, ?string $texto = null): array
    {
        $q = [];
        if ($ini !== null) { $q['start_date'] = $ini; }
        if ($fin !== null) { $q['end_date'] = $fin; }
        if ($texto !== null && $texto !== '') { $q['text'] = $texto; }
        $d = $this->peticion('GET', '/accounting-accounts', $q);
        return $d['items'] ?? (array_is_list($d) ? $d : []);
    }

    /** POST /ledger-entries con un asiento de dos líneas (como asientoWaylet_). Devuelve la respuesta. */
    public function asientoSimple(string $fecha, int $debe, int $haber, float $importe, string $desc): array
    {
        $i = number_format($importe, 2, '.', '');
        return $this->peticion('POST', '/ledger-entries', [], ['date' => $fecha, 'lines' => [
            ['account' => $debe,  'description' => $desc, 'debit' => $i,      'credit' => '0.00'],
            ['account' => $haber, 'description' => $desc, 'debit' => '0.00', 'credit' => $i],
        ]]);
    }
}
