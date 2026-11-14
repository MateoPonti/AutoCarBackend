<?php
declare(strict_types=1);

namespace AutoCar\Auth;

use AutoCar\Core\Database;

/**
 * Qué busca el comprador (tabla usuario_preferencia). Es lo que lo clasifica: el profe pidió pocas preguntas al entrar.
 * "¿Para qué buscás el auto?" es obligatoria: una cuenta personal que no la contestó la ve la primera vez que entra
 * y no puede seguir hasta responderla. Tipo de auto, presupuesto y localidad son opcionales.
 * Las cuentas del equipo y de los talleres no compran, así que no la contestan.
 */
final class Preferencias
{
    /** "¿Para qué buscás el auto?" (el revendedor no está: no paga inspecciones y compite con el comprador) */
    public const USOS = [
        'personal'   => 'Para mí',
        'familia'    => 'Para la familia',
        'trabajo'    => 'Para trabajar',
        'aplicacion' => 'Para manejar con aplicaciones',
    ];

    /** "¿Qué tipo de auto?" (sin elegir = me da igual) */
    public const TIPOS = [
        'chico'  => 'Chico',
        'sedan'  => 'Sedán',
        'suv'    => 'SUV',
        'pickup' => 'Pickup',
    ];

    public const PRESUPUESTO_MAXIMO = 2000000000;

    public static function delUsuario(int $usuarioId): ?array
    {
        $st = Database::pdo()->prepare('SELECT uso, tipo, presupuesto, localidad FROM usuario_preferencia WHERE usuario_id = :id');
        $st->execute(['id' => $usuarioId]);
        $fila = $st->fetch();
        if (!$fila) {
            return null;
        }
        return [
            'uso'         => $fila['uso'],
            'tipo'        => $fila['tipo'],
            'presupuesto' => $fila['presupuesto'] === null ? null : (int)$fila['presupuesto'],
            'localidad'   => $fila['localidad'],
        ];
    }

    /**
     * Lo que se suma al usuario en el login y en GET /api/me: sus preferencias y si todavía le falta contestar.
     * Solo las cuentas personales (rol usuario) tienen que contestar y aceptar la política de privacidad.
     */
    public static function paraSesion(array $usuario): array
    {
        $personal = $usuario['rol'] === 'usuario';
        $preferencias = self::delUsuario((int)$usuario['id']);
        return [
            'preferencias'            => $preferencias,
            'preferencias_pendientes' => $personal && $preferencias === null,
            'privacidad_pendiente'    => $personal && ($usuario['privacidad_aceptada_at'] ?? null) === null,
        ];
    }

    /**
     * Valida el cuerpo de PUT /api/me/preferencias. Función pura (se prueba sin base).
     * @return array{0: array, 1: array<string, string>} [datos limpios, errores por campo]
     */
    public static function validar(array $in): array
    {
        $errores = [];

        $uso = $in['uso'] ?? null;
        if (!is_string($uso) || !isset(self::USOS[$uso])) {
            $errores['uso'] = 'Contanos para qué buscás el auto.';
            $uso = null;
        }

        $tipo = $in['tipo'] ?? null;
        if ($tipo === '') {
            $tipo = null;
        }
        if ($tipo !== null && (!is_string($tipo) || !isset(self::TIPOS[$tipo]))) {
            $errores['tipo'] = 'Elegí un tipo de auto de la lista.';
            $tipo = null;
        }

        $presupuesto = $in['presupuesto'] ?? null;
        if ($presupuesto === '') {
            $presupuesto = null;
        }
        if (is_string($presupuesto) && ctype_digit($presupuesto)) {
            $presupuesto = (int)$presupuesto;
        }
        if ($presupuesto !== null && (!is_int($presupuesto) || $presupuesto < 1 || $presupuesto > self::PRESUPUESTO_MAXIMO)) {
            $errores['presupuesto'] = 'Ingresá el presupuesto en pesos, sin puntos ni centavos.';
            $presupuesto = null;
        }

        $localidad = $in['localidad'] ?? null;
        $localidad = is_string($localidad) ? trim($localidad) : null;
        if ($localidad === '') {
            $localidad = null;
        }
        if ($localidad !== null && mb_strlen($localidad) > 100) {
            $errores['localidad'] = 'La localidad puede tener hasta 100 caracteres.';
        }

        return [['uso' => $uso, 'tipo' => $tipo, 'presupuesto' => $presupuesto, 'localidad' => $localidad], $errores];
    }

    /** Alta o cambio (una fila por usuario). $datos ya validados. */
    public static function guardar(int $usuarioId, array $datos): void
    {
        Database::pdo()->prepare(
            'INSERT INTO usuario_preferencia (usuario_id, uso, tipo, presupuesto, localidad)
             VALUES (:id, :uso, :tipo, :presupuesto, :localidad)
             ON CONFLICT (usuario_id) DO UPDATE SET uso = EXCLUDED.uso, tipo = EXCLUDED.tipo,
               presupuesto = EXCLUDED.presupuesto, localidad = EXCLUDED.localidad, updated_at = now()'
        )->execute([
            'id'          => $usuarioId,
            'uso'         => $datos['uso'],
            'tipo'        => $datos['tipo'],
            'presupuesto' => $datos['presupuesto'],
            'localidad'   => $datos['localidad'],
        ]);
    }

    /** Cuántas cuentas eligieron cada uso, con el texto de la opción ("Para la familia" => 4). Para las métricas. */
    public static function porUso(): array
    {
        $filas = Database::pdo()->query('SELECT uso, COUNT(*) AS n FROM usuario_preferencia GROUP BY uso')->fetchAll();
        $conteo = [];
        foreach (self::USOS as $texto) {
            $conteo[$texto] = 0;
        }
        foreach ($filas as $fila) {
            $conteo[self::USOS[$fila['uso']]] = (int)$fila['n'];
        }
        return $conteo;
    }
}
