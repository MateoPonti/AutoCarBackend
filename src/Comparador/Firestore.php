<?php
declare(strict_types=1);

namespace AutoCar\Comparador;

use AutoCar\Core\Config;
use RuntimeException;

/**
 * Cliente mínimo de la API REST de Firestore, con curl y openssl (sin el SDK de Google).
 *
 * Credencial: el .json de una cuenta de servicio, en FIRESTORE_CREDENCIALES (ruta al archivo) o en
 * FIRESTORE_CREDENCIALES_JSON (el contenido, para Render). En Render va una cuenta de solo lectura
 * (rol "Cloud Datastore Viewer"); la de escritura solo en la PC del scraper y en el secreto de GitHub Actions.
 *
 * El token se pide firmando un JWT con la clave privada (RS256) y se reutiliza 50 minutos.
 * NOTA: escrito sin un proyecto creado todavía; probar con bin/firestore-prueba.php cuando exista.
 */
final class Firestore
{
    private const ALCANCE = 'https://www.googleapis.com/auth/datastore';
    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';
    private ?string $token = null;

    /** @param array{project_id: string, client_email: string, private_key: string} $credencial */
    public function __construct(private array $credencial)
    {
    }

    public static function desdeConfig(): self
    {
        $json = Config::get('FIRESTORE_CREDENCIALES_JSON');
        $ruta = Config::get('FIRESTORE_CREDENCIALES');
        if (($json === null || $json === '') && $ruta !== null && is_file($ruta)) {
            $json = (string)file_get_contents($ruta);
        }
        $c = json_decode((string)$json, true);
        if (!is_array($c) || !isset($c['project_id'], $c['client_email'], $c['private_key'])) {
            throw new RuntimeException('Falta la credencial de Firestore (FIRESTORE_CREDENCIALES o FIRESTORE_CREDENCIALES_JSON).');
        }
        return new self($c);
    }

    /** @return array<string, mixed>|null campos del documento ya convertidos a PHP, o null si no existe */
    public function leer(string $ruta): ?array
    {
        [$codigo, $r] = $this->pedir('GET', $this->base() . '/' . $ruta);
        if ($codigo === 404) {
            return null;
        }
        if ($codigo !== 200) {
            throw new RuntimeException("Firestore GET $ruta respondió $codigo");
        }
        return self::desdeCampos($r['fields'] ?? []);
    }

    /**
     * Escribe (reemplaza) varios documentos. Firestore acepta hasta 500 escrituras por commit.
     *
     * @param array<string, array<string, mixed>> $documentos ruta → campos
     */
    public function escribir(array $documentos): void
    {
        $nombre = 'projects/' . $this->credencial['project_id'] . '/databases/(default)/documents/';
        foreach (array_chunk($documentos, 400, true) as $tanda) {
            $writes = [];
            foreach ($tanda as $ruta => $campos) {
                $writes[] = ['update' => ['name' => $nombre . $ruta, 'fields' => self::aCampos($campos)]];
            }
            [$codigo] = $this->pedir('POST', $this->base() . ':commit', ['writes' => $writes]);
            if ($codigo !== 200) {
                throw new RuntimeException("Firestore commit respondió $codigo");
            }
        }
    }

    // ---------- Conversión entre PHP y los valores tipados de Firestore ----------

    /** @param array<string, mixed> $datos */
    public static function aCampos(array $datos): array
    {
        $campos = [];
        foreach ($datos as $k => $v) {
            $campos[(string)$k] = self::aValor($v);
        }
        return $campos;
    }

    public static function aValor(mixed $v): array
    {
        return match (true) {
            $v === null     => ['nullValue' => null],
            is_bool($v)     => ['booleanValue' => $v],
            is_int($v)      => ['integerValue' => (string)$v],
            is_float($v)    => ['doubleValue' => $v],
            is_string($v)   => ['stringValue' => $v],
            is_array($v) && array_is_list($v) => ['arrayValue' => ['values' => array_map([self::class, 'aValor'], $v)]],
            is_array($v)    => ['mapValue' => ['fields' => self::aCampos($v)]],
            default         => throw new RuntimeException('Tipo no soportado para Firestore: ' . get_debug_type($v)),
        };
    }

    public static function desdeCampos(array $campos): array
    {
        $salida = [];
        foreach ($campos as $k => $v) {
            $salida[$k] = self::desdeValor($v);
        }
        return $salida;
    }

    public static function desdeValor(array $v): mixed
    {
        return match (array_key_first($v)) {
            'nullValue'    => null,
            'booleanValue' => (bool)$v['booleanValue'],
            'integerValue' => (int)$v['integerValue'],
            'doubleValue'  => (float)$v['doubleValue'],
            'stringValue', 'timestampValue', 'referenceValue' => (string)current($v),
            'arrayValue'   => array_map([self::class, 'desdeValor'], $v['arrayValue']['values'] ?? []),
            'mapValue'     => self::desdeCampos($v['mapValue']['fields'] ?? []),
            default        => null,
        };
    }

    // ---------- Autenticación ----------

    /** JWT firmado con la clave de la cuenta de servicio (público para poder probarlo sin red). */
    public static function jwt(array $credencial, int $ahora): string
    {
        $b64 = static fn(string $s): string => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
        $cabecera = $b64(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
        $cuerpo = $b64(json_encode([
            'iss' => $credencial['client_email'], 'scope' => self::ALCANCE, 'aud' => self::TOKEN_URL,
            'iat' => $ahora, 'exp' => $ahora + 3600,
        ]));
        if (!openssl_sign("$cabecera.$cuerpo", $firma, $credencial['private_key'], OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('No se pudo firmar el JWT: revisá private_key.');
        }
        return "$cabecera.$cuerpo." . $b64($firma);
    }

    private function token(): string
    {
        if ($this->token !== null) {
            return $this->token;
        }
        $cache = sys_get_temp_dir() . '/autocar-firestore-' . md5($this->credencial['client_email']) . '.json';
        $guardado = is_file($cache) ? json_decode((string)file_get_contents($cache), true) : null;
        if (is_array($guardado) && ($guardado['vence'] ?? 0) > time() + 60) {
            return $this->token = (string)$guardado['token'];
        }
        $ch = curl_init(self::TOKEN_URL);
        curl_setopt_array($ch, self::opcionesCurl() + [
            CURLOPT_POST       => true,
            CURLOPT_POSTFIELDS => http_build_query([
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion'  => self::jwt($this->credencial, time()),
            ]),
        ]);
        $r = json_decode((string)curl_exec($ch), true);
        curl_close($ch);
        if (!isset($r['access_token'])) {
            throw new RuntimeException('Google no entregó el token de Firestore.');
        }
        file_put_contents($cache, json_encode(['token' => $r['access_token'], 'vence' => time() + 3000]));
        return $this->token = (string)$r['access_token'];
    }

    private function base(): string
    {
        return 'https://firestore.googleapis.com/v1/projects/' . rawurlencode($this->credencial['project_id']) . '/databases/(default)/documents';
    }

    /** @return array{int, array<string, mixed>} */
    private function pedir(string $metodo, string $url, ?array $cuerpo = null): array
    {
        $ch = curl_init($url);
        $opciones = self::opcionesCurl() + [
            CURLOPT_CUSTOMREQUEST => $metodo,
            CURLOPT_HTTPHEADER    => ['Authorization: Bearer ' . $this->token(), 'Content-Type: application/json'],
        ];
        if ($cuerpo !== null) {
            $opciones[CURLOPT_POSTFIELDS] = json_encode($cuerpo, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        curl_setopt_array($ch, $opciones);
        $respuesta = (string)curl_exec($ch);
        $codigo = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        $datos = json_decode($respuesta, true);
        return [$codigo, is_array($datos) ? $datos : []];
    }

    private static function opcionesCurl(): array
    {
        $o = [CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => 30];
        $ca = Config::get('CURL_CAINFO');
        if ($ca !== null && is_file($ca)) {
            $o[CURLOPT_CAINFO] = $ca;
        }
        return $o;
    }
}
