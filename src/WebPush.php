<?php
declare(strict_types=1);
defined('APP_ROOT') || exit;

/**
 * Web Push sin librerías (RFC 8291 aes128gcm + VAPID RFC 8292), solo con openssl y hash_hkdf.
 * Las claves VAPID se generan solas y se guardan en data_dir (fuera de public_html).
 */
final class WebPush
{
    private const SPKI_P256 = '3059301306072a8648ce3d020106082a8648ce3d030107034200';

    public static function b64u(string $s): string
    {
        return rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
    }

    public static function deb64u(string $s): string
    {
        return (string) base64_decode(strtr($s, '-_', '+/') . str_repeat('=', (4 - strlen($s) % 4) % 4));
    }

    /** Punto público sin comprimir (65 bytes) de una clave EC P-256. */
    private static function puntoPublico($clave): string
    {
        $d = openssl_pkey_get_details($clave);
        return "\x04" . str_pad($d['ec']['x'], 32, "\0", STR_PAD_LEFT) . str_pad($d['ec']['y'], 32, "\0", STR_PAD_LEFT);
    }

    private static function nuevaClave()
    {
        $k = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        if ($k === false) {
            throw new RuntimeException('openssl no puede generar claves EC');
        }
        return $k;
    }

    /** Genera un par VAPID: ['privada' => PEM, 'publica' => base64url del punto]. */
    public static function generarVapid(): array
    {
        $k = self::nuevaClave();
        openssl_pkey_export($k, $pem);
        return ['privada' => $pem, 'publica' => self::b64u(self::puntoPublico($k))];
    }

    /** Firma ES256 en formato JOSE (r||s de 64 bytes) a partir de la firma DER de openssl. */
    private static function firmaEs256(string $datos, $privada): string
    {
        openssl_sign($datos, $der, $privada, OPENSSL_ALGO_SHA256);
        $pos = 2;
        $leer = function () use ($der, &$pos) {
            $len = ord($der[$pos + 1]);
            $v = substr($der, $pos + 2, $len);
            $pos += 2 + $len;
            return str_pad(ltrim($v, "\0"), 32, "\0", STR_PAD_LEFT);
        };
        return $leer() . $leer();
    }

    /** Cifra el mensaje para una suscripción (aes128gcm). */
    private static function cifrar(string $mensaje, string $p256dh, string $auth): string
    {
        $uaPub = self::deb64u($p256dh);
        $authSecret = self::deb64u($auth);
        if (strlen($uaPub) !== 65 || strlen($authSecret) !== 16) {
            throw new InvalidArgumentException('Suscripción no válida');
        }
        $local = self::nuevaClave();
        $asPub = self::puntoPublico($local);
        $uaKey = openssl_pkey_get_public("-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode(hex2bin(self::SPKI_P256) . $uaPub), 64, "\n") . "-----END PUBLIC KEY-----\n");
        $compartido = openssl_pkey_derive($uaKey, $local, 32);
        if ($compartido === false) {
            throw new RuntimeException('No se pudo derivar la clave compartida');
        }
        $ikm = hash_hkdf('sha256', $compartido, 32, "WebPush: info\0" . $uaPub . $asPub, $authSecret);
        $sal = random_bytes(16);
        $cek = hash_hkdf('sha256', $ikm, 16, "Content-Encoding: aes128gcm\0", $sal);
        $nonce = hash_hkdf('sha256', $ikm, 12, "Content-Encoding: nonce\0", $sal);
        $cifrado = openssl_encrypt($mensaje . "\x02", 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag);
        return $sal . pack('N', 4096) . chr(65) . $asPub . $cifrado . $tag;
    }

    /**
     * Envía un aviso. Devuelve el código HTTP del servicio push (201 = enviado; 404/410 = suscripción caducada).
     * $sub = ['endpoint' => …, 'keys' => ['p256dh' => …, 'auth' => …]]
     */
    public static function enviar(array $sub, array $vapid, string $mensaje, string $contacto): int
    {
        $endpoint = (string) $sub['endpoint'];
        $u = parse_url($endpoint);
        if (($u['scheme'] ?? '') !== 'https' || empty($u['host'])) {
            return 0;
        }
        $jwtCab = self::b64u(json_encode(['typ' => 'JWT', 'alg' => 'ES256']));
        $jwtDatos = self::b64u(json_encode(['aud' => 'https://' . $u['host'], 'exp' => time() + 12 * 3600, 'sub' => $contacto]));
        $privada = openssl_pkey_get_private($vapid['privada']);
        $jwt = $jwtCab . '.' . $jwtDatos . '.' . self::b64u(self::firmaEs256($jwtCab . '.' . $jwtDatos, $privada));
        $cuerpo = self::cifrar($mensaje, (string) $sub['keys']['p256dh'], (string) $sub['keys']['auth']);

        $ch = curl_init($endpoint);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $cuerpo,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_HTTPHEADER => [
                'Authorization: vapid t=' . $jwt . ', k=' . $vapid['publica'],
                'Content-Encoding: aes128gcm',
                'Content-Type: application/octet-stream',
                'TTL: 86400',
                'Urgency: normal',
            ],
        ]);
        curl_exec($ch);
        $st = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        return $st;
    }
}
