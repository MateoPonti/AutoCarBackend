<?php
/**
 * Parcial: abre la página de autenticación (head, panel de marca y tarjeta del formulario).
 * Variables: $titulo, $panelTitulo, $panelTexto
 */
if (!function_exists('e')) {
    function e(?string $valor): string
    {
        return htmlspecialchars($valor ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= e($titulo) ?> | AutoCar</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Barlow+Semi+Condensed:wght@600;700&family=Public+Sans:wght@400;600&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="/assets/css/auth.css">
</head>
<body>
<div class="auth">
  <aside class="auth__panel">
    <a class="plate" href="/" aria-label="AutoCar, ir al inicio">
      <span class="plate__band">Comprá, vendé, canjeá</span>
      <span class="plate__name">AUTOCAR</span>
    </a>
    <div class="auth__pitch">
      <h2><?= e($panelTitulo) ?></h2>
      <p><?= e($panelTexto) ?></p>
    </div>
  </aside>
  <main class="auth__main">
    <div class="auth__card">
