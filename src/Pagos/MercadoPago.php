<?php
declare(strict_types=1);

namespace AutoCar\Pagos;

use AutoCar\Core\Config;
use RuntimeException;

/**
 * La API de Orders de Mercado Pago (Checkout Pro), con curl y sin SDK. Solo lo que usa AutoCar:
 *   crear una order (el link adonde va el comprador a pagar), consultarla y devolverla entera.
 * Variables: MP_ACTIVO (true para cobrar; con false el pago sigue simulado) y MP_ACCESS_TOKEN (el de la cuenta
 * vendedora; mientras sea la de prueba, no se mueve plata real). El token nunca va al front ni al repo.
 * Doc: https://www.mercadopago.com.ar/developers/es/reference/online-payments/checkout-pro-orders/overview
 */
final class MercadoPago
{
    public const API = 'https://api.mercadopago.com/v1/orders';

    /** true si se cobra de verdad (con la cuenta que diga el token). */
    public static function activo(): bool
    {
        return Config::get('MP_ACTIVO') === 'true' && Config::get('MP_ACCESS_TOKEN') !== null;
    }

    /**
     * Arma el cuerpo de la order (función pura, se prueba sin red). El monto va como texto con dos decimales,
     * como pide Mercado Pago, y tiene que ser igual a la suma de los ítems.
     */
    public static function cuerpoOrden(float $monto, string $titulo, string $referencia, string $email, string $vuelta): array
    {
        $importe = number_format($monto, 2, '.', '');
        return [
            'type'               => 'online',
            'processing_mode'    => 'manual',
            'total_amount'       => $importe,
            'external_reference' => mb_substr($referencia, 0, 64),
            'description'        => 'Inspección precompra en AutoCar',
            'payer'              => ['email' => $email],
            'items'              => [['title' => mb_substr($titulo, 0, 250), 'unit_price' => $importe, 'quantity' => 1]],
            'config'             => ['online' => [
                'success_url' => $vuelta,
                'failure_url' => $vuelta,
                'pending_url' => $vuelta,
                'auto_return' => 'approved',
            ]],
        ];
    }

    /** POST /v1/orders → la order (id, status, checkout_url, ...). La clave evita cobrar dos veces si se reintenta. */
    public static function crearOrden(array $cuerpo, string $clave): array
    {
        return self::pedir('POST', self::API, $cuerpo, $clave);
    }

    /** GET /v1/orders/{id} → la order con sus pagos (transactions.payments). */
    public static function orden(string $id): array
    {
        return self::pedir('GET', self::API . '/' . rawurlencode($id));
    }

    /** POST /v1/orders/{id}/refund sin cuerpo = devolución total. */
    public static function devolver(string $id, string $clave): array
    {
        return self::pedir('POST', self::API . '/' . rawurlencode($id) . '/refund', null, $clave);
    }

    /**
     * Cómo queda nuestro cobro según el status de la order (función pura).
     * processed = pagado; refunded = devuelto; canceled, expired y failed = hay que armar otra order (vencida);
     * el resto (created, processing, action_required) sigue pendiente.
     */
    public static function estadoDe(string $status): string
    {
        return match ($status) {
            'processed'                     => 'pagado',
            'refunded'                      => 'devuelto',
            'canceled', 'expired', 'failed' => 'vencida',
            default                         => 'pendiente',
        };
    }

    private static function pedir(string $metodo, string $url, ?array $cuerpo = null, ?string $clave = null): array
    {
        $headers = ['Authorization: Bearer ' . Config::get('MP_ACCESS_TOKEN'), 'Content-Type: application/json'];
        if ($clave !== null) {
            $headers[] = 'X-Idempotency-Key: ' . $clave;
        }
        $ch = curl_init($url);
        $opciones = [
            CURLOPT_CUSTOMREQUEST  => $metodo,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT        => 15,
        ];
        if ($metodo === 'POST') {
            $opciones[CURLOPT_POSTFIELDS] = $cuerpo === null ? '' : json_encode($cuerpo, JSON_UNESCAPED_UNICODE);
        }
        $ca = Config::get('CURL_CAINFO');
        if ($ca !== null && is_file($ca)) {
            $opciones[CURLOPT_CAINFO] = $ca;
        }
        curl_setopt_array($ch, $opciones);
        $respuesta = curl_exec($ch);
        $codigo = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        $datos = is_string($respuesta) ? json_decode($respuesta, true) : null;
        if ($codigo < 200 || $codigo >= 300 || !is_array($datos)) {
            // Sin el token: el error de Mercado Pago trae el código y el mensaje, nunca la credencial
            throw new RuntimeException("Mercado Pago respondió $codigo a $metodo $url: " . mb_substr((string)$respuesta, 0, 300));
        }
        return $datos;
    }
}
