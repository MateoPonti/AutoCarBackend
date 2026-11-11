<?php
declare(strict_types=1);

namespace AutoCar\Inspecciones;

/**
 * De qué sitio es el link que pega el comprador. No se entra a la página: solo se mira la URL.
 * Los links de AutoCar son los del front (FRONT_URL) con vehiculo.html?id=N.
 */
final class Fuente
{
    public const FUENTES = ['mercadolibre', 'kavak', 'autocosmos', 'autocar', 'otro'];
    public const MAX_URL = 500; // igual que inspeccion.url

    // dominio => fuente. También vale cualquier subdominio (auto.mercadolibre.com.ar, www.kavak.com...).
    private const DOMINIOS = [
        'mercadolibre.com.ar' => 'mercadolibre',
        'kavak.com'           => 'kavak',
        'autocosmos.com.ar'   => 'autocosmos',
    ];

    /**
     * @param string[] $hostsAutoCar hosts del front (de FRONT_URL), p. ej. ['autocar-zeta.vercel.app']
     * @return array{fuente: string, vehiculo_id: ?int}|null null si no es un link http(s) válido
     */
    public static function detectar(string $url, array $hostsAutoCar = []): ?array
    {
        $url = trim($url);
        if ($url === '' || strlen($url) > self::MAX_URL || filter_var($url, FILTER_VALIDATE_URL) === false) {
            return null;
        }
        $partes = parse_url($url);
        $esquema = strtolower($partes['scheme'] ?? '');
        $host = strtolower($partes['host'] ?? '');
        if (!in_array($esquema, ['http', 'https'], true) || $host === '') {
            return null;
        }

        foreach (self::DOMINIOS as $dominio => $fuente) {
            if ($host === $dominio || str_ends_with($host, ".$dominio")) {
                return ['fuente' => $fuente, 'vehiculo_id' => null];
            }
        }

        if (in_array($host, array_map('strtolower', $hostsAutoCar), true)) {
            parse_str($partes['query'] ?? '', $query);
            $id = filter_var($query['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if (str_ends_with($partes['path'] ?? '', 'vehiculo.html') && $id !== false) {
                return ['fuente' => 'autocar', 'vehiculo_id' => $id];
            }
        }
        return ['fuente' => 'otro', 'vehiculo_id' => null];
    }

    /**
     * Clave del aviso: la misma para dos links que llevan al mismo auto, para que un comprador no tenga dos
     * pedidos en curso del mismo aviso (índice único de la 020). Sin esquema, sin "www.", sin ?parámetros ni #ancla
     * y sin la barra final; en MercadoLibre alcanza con el número del aviso (MLA-123...) y en AutoCar con el id.
     * Si cambia, cambiar también el UPDATE de database/020-seguridad.sql.
     */
    public static function clave(string $url, string $fuente, ?int $vehiculoId): string
    {
        if ($fuente === 'autocar' && $vehiculoId !== null) {
            return 'autocar:' . $vehiculoId;
        }
        if ($fuente === 'mercadolibre' && preg_match('/MLA-?(\d+)/i', $url, $m)) {
            return 'mercadolibre:MLA' . $m[1];
        }
        $sinParametros = (string)preg_replace('/[?#].*$/s', '', trim($url));
        $sinEsquema = (string)preg_replace('#^https?://(www\.)?#i', '', $sinParametros);
        return rtrim(mb_strtolower($sinEsquema), '/');
    }

    /** Hosts de una lista de orígenes separada por comas, como FRONT_URL. */
    public static function hosts(string $origenes): array
    {
        $hosts = [];
        foreach (explode(',', $origenes) as $origen) {
            $host = parse_url(trim($origen), PHP_URL_HOST);
            if (is_string($host) && $host !== '') {
                $hosts[] = strtolower($host);
            }
        }
        return $hosts;
    }
}
