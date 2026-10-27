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
