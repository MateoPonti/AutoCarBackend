<?php
declare(strict_types=1);

namespace AutoCar\Parametros;

use AutoCar\Auth\Auth;
use AutoCar\Core\Request;
use AutoCar\Core\Response;

/** Parámetros del negocio: el admin los ve y los cambia. Los valores son públicos (el front muestra el desglose). */
final class ParametroController
{
    /** GET /api/admin/parametros (permiso parametros.editar) → {parametros, cambios} */
    public function index(): void
    {
        Response::json(['parametros' => Parametros::todos(), 'cambios' => Parametros::cambios()]);
    }

    /** PUT /api/admin/parametros {valores: {clave: valor}} → {parametros, cambios}. Solo cambia las claves que vienen. */
    public function actualizar(): void
    {
        $nuevos = Request::json()['valores'] ?? null;
        if (!is_array($nuevos) || $nuevos === []) {
            Response::error(422, 'validacion', 'Mandá los valores a cambiar en "valores".');
        }
        $actuales = [];
        foreach (Parametros::todos() as $p) {
            $actuales[$p['clave']] = $p;
        }
        [$cambian, $errores] = Parametros::validar($actuales, $nuevos);
        if ($errores) {
            Response::error(422, 'validacion', 'Revisá los valores.', $errores);
        }
        if ($cambian) {
            Parametros::guardar($actuales, $cambian, (int)Auth::id());
        }
        $this->index();
    }

    /**
     * GET /api/parametros (público) → {clave: valor}: el front los usa para el desglose del precio, y el rango que
     * acepta el formulario del taller. No hay nada secreto: son las reglas del sitio.
     */
    public function publicos(): void
    {
        $valores = [];
        foreach (Parametros::CLAVES as $clave) {
            $valores[$clave] = Parametros::valor($clave);
        }
        Response::json($valores);
    }
}
