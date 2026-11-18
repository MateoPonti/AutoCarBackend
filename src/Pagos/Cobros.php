<?php
declare(strict_types=1);

namespace AutoCar\Pagos;

use AutoCar\Core\Config;
use AutoCar\Core\Database;
use AutoCar\Core\Logger;
use DateTimeImmutable;
use Throwable;

/**
 * El cobro de cada inspección con Mercado Pago (tabla cobro, migración 031). Toda la plata entra a la cuenta de
 * AutoCar; a los mecánicos se les sigue pagando aparte (a los 10 y 15 días, simulado), como en Inspecciones\Fondo.
 *   Al pedir la inspección se arma la order y el comprador va a pagar. Hasta que paga, el mecánico no la puede aceptar.
 *   Se entera de que pagó por el aviso de Mercado Pago (webhook) o cuando el comprador vuelve al sitio, pero
 *   nunca le cree al aviso: siempre le pregunta la order a Mercado Pago con el token de AutoCar.
 *   Si la inspección se rechaza, se cancela, no se hace, se cae o procede un reclamo, se devuelve por Mercado Pago.
 * Con MP_ACTIVO=false no hace nada (ni lee la tabla): el pago sigue simulado como antes.
 */
final class Cobros
{
    /** La fila del cobro de la inspección, o null si no tiene (MP apagado, o lo pagó entero con un crédito). */
    public static function de(int $inspeccionId): ?array
    {
        if (!MercadoPago::activo()) {
            return null;
        }
        $st = Database::pdo()->prepare('SELECT * FROM cobro WHERE inspeccion_id = :i');
        $st->execute(['i' => $inspeccionId]);
        return $st->fetch() ?: null;
    }

    /** Lo que ve el front: {estado, monto, checkout_url (solo si falta pagar)} o null. */
    public static function paraApi(?array $c): ?array
    {
        if ($c === null) {
            return null;
        }
        return [
            'estado'       => $c['estado'],
            'monto'        => (float)$c['monto'],
            'checkout_url' => $c['estado'] === 'pendiente' ? $c['checkout_url'] : null,
            'pagado_at'    => $c['pagado_at'] === null ? null : (new DateTimeImmutable($c['pagado_at']))->format(DATE_ATOM),
        ];
    }

    /** true si el comprador todavía no pagó: el mecánico no la puede aceptar. */
    public static function faltaPagar(int $inspeccionId): bool
    {
        return (self::de($inspeccionId)['estado'] ?? null) === 'pendiente';
    }

    /**
     * Arma la order de una inspección recién pedida (después del commit). Si Mercado Pago no responde, la
     * inspección queda pedida con el cobro pendiente y sin link: el comprador reintenta con "Pagar".
     */
    public static function iniciar(int $inspeccionId, float $monto): ?array
    {
        if (!MercadoPago::activo() || $monto <= 0) {
            return null;
        }
        Database::pdo()->prepare('INSERT INTO cobro (inspeccion_id, monto) VALUES (:i, :m) ON CONFLICT (inspeccion_id) DO NOTHING')
            ->execute(['i' => $inspeccionId, 'm' => $monto]);
        return self::armarOrden(self::de($inspeccionId));
    }

    /**
     * "Pagar" del comprador: pregunta cómo va y, si la order venció o no se pudo armar, arma otra.
     * → el cobro actualizado (con checkout_url si falta pagar).
     */
    public static function link(int $inspeccionId): ?array
    {
        $c = self::actualizar($inspeccionId);
        if ($c === null || $c['estado'] !== 'pendiente') {
            return $c;
        }
        if ($c['orden_id'] === null || $c['estado_mp'] === 'vencida') {
            if ($c['orden_id'] !== null) { // la anterior venció: otra order con otra clave
                Database::pdo()->prepare('UPDATE cobro SET intento = intento + 1, orden_id = NULL, checkout_url = NULL WHERE inspeccion_id = :i')
                    ->execute(['i' => $inspeccionId]);
            }
            return self::armarOrden(self::de($inspeccionId));
        }
        return $c;
    }

    /** Le pregunta la order a Mercado Pago y guarda cómo quedó. Si Mercado Pago no responde, queda como estaba. */
    public static function actualizar(int $inspeccionId): ?array
    {
        $c = self::de($inspeccionId);
        if ($c === null || $c['orden_id'] === null || $c['estado'] !== 'pendiente') {
            return $c;
        }
        try {
            $orden = MercadoPago::orden($c['orden_id']);
        } catch (Throwable $e) {
            Logger::error("Cobro de la inspección $inspeccionId: " . $e->getMessage());
            return $c;
        }
        $status = (string)($orden['status'] ?? '');
        $estado = MercadoPago::estadoDe($status);
        if ($estado === 'pagado') {
            Database::pdo()->prepare("UPDATE cobro SET estado = 'pagado', estado_mp = :s, pagado_at = now(), updated_at = now()
                WHERE inspeccion_id = :i AND estado = 'pendiente'")->execute(['s' => $status, 'i' => $inspeccionId]);
        } else {
            // vencida queda anotada en estado_mp para que "Pagar" arme otra order
            Database::pdo()->prepare('UPDATE cobro SET estado_mp = :s, updated_at = now() WHERE inspeccion_id = :i')
                ->execute(['s' => $estado === 'vencida' ? 'vencida' : $status, 'i' => $inspeccionId]);
        }
        return self::de($inspeccionId);
    }

    /** El aviso de Mercado Pago: busca el cobro por la order y lo actualiza. false si la order no es nuestra. */
    public static function aviso(string $ordenId): bool
    {
        if (!MercadoPago::activo()) {
            return false;
        }
        $st = Database::pdo()->prepare('SELECT inspeccion_id FROM cobro WHERE orden_id = :o');
        $st->execute(['o' => $ordenId]);
        $inspeccionId = $st->fetchColumn();
        if ($inspeccionId === false) {
            return false;
        }
        self::actualizar((int)$inspeccionId);
        return true;
    }

    /**
     * La inspección no siguió (o procedió un reclamo): si pagó, se le devuelve todo por Mercado Pago; si no pagó,
     * el cobro se cancela. Se llama fuera de las transacciones (es un pedido a otro servidor). Si Mercado Pago falla,
     * queda en el log y el cobro sigue pagado, para devolverlo a mano.
     */
    public static function devolver(int $inspeccionId): void
    {
        $c = self::actualizar($inspeccionId);
        if ($c === null) {
            return;
        }
        if ($c['estado'] === 'pendiente') {
            Database::pdo()->prepare("UPDATE cobro SET estado = 'cancelado', updated_at = now() WHERE inspeccion_id = :i AND estado = 'pendiente'")
                ->execute(['i' => $inspeccionId]);
            return;
        }
        if ($c['estado'] !== 'pagado') {
            return;
        }
        try {
            $orden = MercadoPago::devolver($c['orden_id'], "autocar-devolucion-$inspeccionId");
            Database::pdo()->prepare("UPDATE cobro SET estado = 'devuelto', estado_mp = :s, devuelto_at = now(), updated_at = now()
                WHERE inspeccion_id = :i")->execute(['s' => (string)($orden['status'] ?? 'refunded'), 'i' => $inspeccionId]);
        } catch (Throwable $e) {
            Logger::error("Devolución de la inspección $inspeccionId (order {$c['orden_id']}): " . $e->getMessage());
        }
    }

    /** Arma la order del cobro y guarda el link. Si Mercado Pago falla, el cobro queda pendiente y sin link. */
    private static function armarOrden(?array $c): ?array
    {
        if ($c === null) {
            return null;
        }
        $id = (int)$c['inspeccion_id'];
        $st = Database::pdo()->prepare('SELECT i.titulo, u.email FROM inspeccion i JOIN usuario u ON u.id = i.comprador_id WHERE i.id = :i');
        $st->execute(['i' => $id]);
        $i = $st->fetch();
        $cuerpo = MercadoPago::cuerpoOrden((float)$c['monto'], 'Inspección precompra: ' . ($i['titulo'] ?: 'auto'),
            "inspeccion-$id", (string)$i['email'], self::vuelta($id));
        try {
            $orden = MercadoPago::crearOrden($cuerpo, "autocar-inspeccion-$id-{$c['intento']}");
        } catch (Throwable $e) {
            Logger::error("Cobro de la inspección $id: " . $e->getMessage());
            return $c;
        }
        Database::pdo()->prepare('UPDATE cobro SET orden_id = :o, checkout_url = :u, estado_mp = :s, updated_at = now() WHERE inspeccion_id = :i')
            ->execute(['o' => $orden['id'], 'u' => $orden['checkout_url'], 's' => $orden['status'] ?? 'created', 'i' => $id]);
        return self::de($id);
    }

    /** Adónde vuelve el comprador después de pagar: el detalle de su inspección en el front (el primero de FRONT_URL). */
    public static function vuelta(int $inspeccionId): string
    {
        $front = rtrim(trim(explode(',', (string)Config::get('FRONT_URL', ''))[0]), '/');
        return "$front/front/pages/talleres/verificacion.html?id=$inspeccionId&pago=vuelta";
    }
}
