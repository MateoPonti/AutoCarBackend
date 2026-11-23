<?php
declare(strict_types=1);

namespace AutoCar\Afiliacion;

use AutoCar\Auth\Auth;
use AutoCar\Core\Logger;
use AutoCar\Core\Request;
use AutoCar\Core\Response;
use AutoCar\MetaBusqueda\Almacenamiento;
use AutoCar\MetaBusqueda\Mercado;
use DateTimeImmutable;
use Throwable;

final class AfiliacionController
{
    /**
     * GET /api/salida?id=fuente~modelo~id&origen=para_vos|destacado|metabuscador
     * El "Ver en Kavak / MercadoLibre / Autocosmos" del metabuscador: anota el clic y redirige (302) al aviso.
     * La dirección sale de los datos del scraper, nunca de la URL que llega (así no sirve para redirigir a cualquier lado).
     * No pide sesión: si la hay, el clic queda a nombre del usuario (solo los de cuentas personales se cobran).
     */
    public function salida(): never
    {
        $id = is_string($_GET['id'] ?? null) ? $_GET['id'] : '';
        try {
            $encontrado = (new Mercado(Almacenamiento::documentos()))->auto($id);
        } catch (Throwable $e) {
            Logger::error('Salida: ' . $e->getMessage());
            Response::error(503, 'metabuscador_no_disponible', 'El metabuscador no está disponible en este momento.');
        }
        $auto = $encontrado[0] ?? null;
        $url = is_string($auto['url'] ?? null) ? $auto['url'] : '';
        if ($auto === null || !preg_match('#^https?://#i', $url)) {
            Response::error(404, 'no_encontrado', 'El aviso no existe.');
        }
        Afiliacion::registrar(Auth::id(), Auth::usuario()['rol'] ?? null, (string)$auto['fuente'], $id, $_GET['origen'] ?? null);
        Response::redireccion($url);
    }

    /** GET /api/admin/afiliacion → {convenios, este_mes, evolucion} */
    public function panel(): void
    {
        Response::json(Afiliacion::panel(new DateTimeImmutable()));
    }

    /** PUT /api/admin/afiliacion/convenios {fuente, tarifa_clic, activo} (la fuente va en el cuerpo: es texto) */
    public function convenio(): void
    {
        $d = Request::json();
        $fuente = is_string($d['fuente'] ?? null) ? $d['fuente'] : '';
        $tarifa = $d['tarifa_clic'] ?? null;
        $errores = [];
        if ((!is_int($tarifa) && !is_float($tarifa)) || $tarifa < 0 || $tarifa > 1000000) {
            $errores['tarifa_clic'] = 'La tarifa tiene que ser un número entre 0 y 1.000.000.';
        }
        if (!is_bool($d['activo'] ?? null)) {
            $errores['activo'] = 'Indicá si el convenio está activo.';
        }
        if ($errores) {
            Response::error(422, 'validacion', 'Revisá los datos ingresados.', $errores);
        }
        if (!Afiliacion::guardarConvenio($fuente, (float)$tarifa, $d['activo'])) {
            Response::error(404, 'no_encontrado', 'No hay convenio con esa plataforma.');
        }
        Response::json(['convenios' => Afiliacion::convenios()]);
    }

    /**
     * POST /api/admin/afiliacion/cobros {fuente, mes: "AAAA-MM"} (una plataforma) o {tipo: "empresa", socio: id, mes}
     * → marca cobrado ese mes cerrado y devuelve el panel
     */
    public function cobrar(): void
    {
        $d = Request::json();
        $tipo = ($d['tipo'] ?? 'plataforma') === 'empresa' ? 'empresa' : 'plataforma';
        $socio = $tipo === 'empresa'
            ? (is_int($d['socio'] ?? null) || (is_string($d['socio'] ?? null) && ctype_digit($d['socio'])) ? (string)$d['socio'] : '')
            : (is_string($d['fuente'] ?? null) ? $d['fuente'] : (is_string($d['socio'] ?? null) ? $d['socio'] : ''));
        $mes = is_string($d['mes'] ?? null) && preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $d['mes']) ? $d['mes'] . '-01' : null;
        if ($socio === '' || $mes === null) {
            Response::error(422, 'validacion', 'Indicá el socio y el mes (AAAA-MM).');
        }
        $r = Afiliacion::cobrar($tipo, $socio, $mes, (int)Auth::id(), new DateTimeImmutable());
        if (!$r['ok']) {
            match ($r['error']) {
                'mes_en_curso' => Response::error(409, 'mes_en_curso', 'El mes todavía no cerró: se cobra cuando termina.'),
                'ya_cobrado'   => Response::error(409, 'ya_cobrado', 'Ese mes ya está cobrado.'),
                default        => Response::error(404, 'no_encontrado', 'No hay nada para cobrar de ese socio en ese mes.'),
            };
        }
        Response::json(Afiliacion::panel(new DateTimeImmutable()));
    }
}
