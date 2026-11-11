<?php
declare(strict_types=1);

namespace AutoCar\Recolectores\Comun;

use RuntimeException;

/**
 * Pedidos GET con curl, sin librerías. Espera entre pedidos para no cargar al sitio y reintenta los errores
 * transitorios (red, cortes por tiempo, 429, 5xx). Un 403 no se reintenta: suele ser un bloqueo y conviene frenar.
 */
final class Http
{
    private const AGENTE = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Safari/537.36';
    private const REINTENTOS = 3;

    private float $ultimo = 0.0;
    public int $pedidos = 0;

    /** @param float $pausa segundos mínimos entre un pedido y el siguiente */
    public function __construct(private float $pausa = 3.0)
    {
    }

    public function get(string $url): string
    {
        for ($intento = 1; ; $intento++) {
            $this->esperar();
            [$codigo, $cuerpo, $error] = $this->pedir($url);
            if ($codigo === 200 && $cuerpo !== '') {
                return $cuerpo;
            }
            if (!self::transitorio($codigo, $error) || $intento >= self::REINTENTOS) {
                throw new RuntimeException("GET $url respondió $codigo" . ($error !== '' ? " ($error)" : ''));
            }
            sleep(10 * $intento);
        }
    }

    /**
     * Si conviene reintentar. Un corte de curl (por ejemplo "Operation timed out") también, aunque el código sea 200:
     * el 11/11 una página de MercadoLibre empezó a responder, se colgó a mitad de camino y frenó toda la corrida.
     */
    public static function transitorio(int $codigo, string $errorCurl): bool
    {
        if ($codigo === 403) {
            return false;
        }
        return $errorCurl !== '' || $codigo === 0 || $codigo === 429 || $codigo >= 500;
    }

    private function esperar(): void
    {
        $falta = $this->ultimo + $this->pausa - microtime(true);
        if ($falta > 0) {
            usleep((int)($falta * 1_000_000));
        }
        $this->ultimo = microtime(true);
    }

    /** @return array{int, string, string} código HTTP (0 si no hubo respuesta), cuerpo y error de curl */
    private function pedir(string $url): array
    {
        $ch = curl_init($url);
        $opciones = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT        => 45,
            CURLOPT_ENCODING       => '', // acepta gzip
            CURLOPT_USERAGENT      => self::AGENTE,
            CURLOPT_HTTPHEADER     => ['Accept-Language: es-AR,es;q=0.9'],
        ];
        $ca = self::certificados();
        if ($ca !== null) {
            $opciones[CURLOPT_CAINFO] = $ca;
        }
        curl_setopt_array($ch, $opciones);
        $cuerpo = curl_exec($ch);
        $codigo = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        $this->pedidos++;
        return [$codigo, is_string($cuerpo) ? $cuerpo : '', $error];
    }

    /**
     * El PHP de Windows suele venir sin curl.cainfo. Se usa CURL_CAINFO si está, o el de Git for Windows.
     * En Linux (GitHub Actions) el sistema ya trae los certificados.
     */
    private static function certificados(): ?string
    {
        if (ini_get('curl.cainfo')) {
            return null;
        }
        $candidatos = [getenv('CURL_CAINFO') ?: '', 'C:/Program Files/Git/mingw64/etc/ssl/certs/ca-bundle.crt'];
        foreach ($candidatos as $c) {
            if ($c !== '' && is_file($c)) {
                return $c;
            }
        }
        return null;
    }
}
