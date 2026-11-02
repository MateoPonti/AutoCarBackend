<?php
declare(strict_types=1);

namespace AutoCar\Chat;

use AutoCar\Auth\Auth;
use AutoCar\Catalogo\Vehiculo;
use AutoCar\Core\Request;
use AutoCar\Core\Response;

/**
 * Chat entre interesado y vendedor. Todas las rutas llevan el guard 'auth' en routes.php.
 * Una conversación de la que el usuario no participa responde 404, igual que si no existiera.
 */
final class ChatController
{
    /** POST /api/vehiculos/{id}/conversacion: abre (o recupera) la conversación con el vendedor. 201 si es nueva. */
    public function abrir(int $id): void
    {
        $yo = (int)Auth::id();
        $v = Vehiculo::find($id);
        if ($v === null || $v['estado'] !== 'disponible') {
            Response::error(404, 'no_encontrado', 'El vehículo no existe o ya no está disponible.');
        }
        if ($v['usuario_id'] === null) {
            Response::error(409, 'sin_vendedor', 'Este vehículo no tiene un vendedor con quien chatear.');
        }
        if ((int)$v['usuario_id'] === $yo) {
            Response::error(409, 'vehiculo_propio', 'No podés iniciar una conversación sobre tu propia publicación.');
        }
        [$conversacionId, $creada] = Conversacion::abrir($id, $yo, (int)$v['usuario_id']);
        Response::json(['conversacion' => ['id' => $conversacionId]], $creada ? 201 : 200);
    }

    /** GET /api/conversaciones: bandeja, la de actividad más reciente primero. */
    public function index(): void
    {
        $yo = (int)Auth::id();
        $items = array_map(fn(array $c) => Conversacion::paraApi($c, $yo), Conversacion::delUsuario($yo));
        Response::json(['items' => $items]);
    }

    /**
     * GET /api/conversaciones/{id}/mensajes?desde=N: mensajes con id > N (0 o sin parámetro = desde el principio).
     * El front guarda ultimo_id y lo manda en el siguiente pedido. Lo que se devuelve queda marcado como leído.
     */
    public function mensajes(int $id): void
    {
        $yo = (int)Auth::id();
        $c = self::participante($id, $yo);
        $desde = filter_var($_GET['desde'] ?? '0', FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        if ($desde === false) {
            Response::error(400, 'parametro_invalido', 'El parámetro desde debe ser un número entero.');
        }

        $mensajes = Mensaje::desde($id, $desde);
        $ultimo = $mensajes ? (int)end($mensajes)['id'] : $desde;
        if ($mensajes) {
            Mensaje::marcarLeidos($id, $yo, $ultimo);
        }
        Response::json([
            'items'     => array_map(fn(array $m) => Mensaje::paraApi($m, $yo), $mensajes),
            'ultimo_id' => $ultimo,
            'abierta'   => Conversacion::abierta($c),
        ]);
    }

    /** POST /api/conversaciones/{id}/mensajes  {"texto": "Hola, ¿sigue disponible?"} */
    public function enviar(int $id): void
    {
        $yo = (int)Auth::id();
        $c = self::participante($id, $yo);
        if (!Conversacion::abierta($c)) {
            Response::error(409, 'conversacion_cerrada', 'La publicación se eliminó: ya no se pueden enviar mensajes.');
        }
        $texto = Request::texto(Request::json(), 'texto');
        $largo = mb_strlen($texto);
        if ($largo < 1 || $largo > Mensaje::MAX_LARGO) {
            Response::error(422, 'validacion', 'Revisá los datos ingresados.',
                ['texto' => 'El mensaje debe tener entre 1 y ' . Mensaje::MAX_LARGO . ' caracteres.']);
        }
        Response::json(['mensaje' => Mensaje::paraApi(Mensaje::crear($id, $yo, $texto), $yo)], 201);
    }

    private static function participante(int $id, int $yo): array
    {
        $c = Conversacion::deParticipante($id, $yo);
        if ($c === null) {
            Response::error(404, 'no_encontrado', 'La conversación no existe.');
        }
        return $c;
    }
}
