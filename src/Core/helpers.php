<?php
declare(strict_types=1);

/** Escapa para HTML. Se usa en TODA salida de datos del usuario (anti-XSS). */
function e(mixed $valor): string
{
    return htmlspecialchars((string)($valor ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function redirect(string $to): never
{
    header('Location: ' . $to, true, 303);
    exit;
}

/** Aviso de una sola vez (se muestra en la próxima pantalla). */
function flash_set(string $mensaje): void
{
    $_SESSION['flash'] = $mensaje;
}

function flash_get(): ?string
{
    $m = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return is_string($m) ? $m : null;
}
