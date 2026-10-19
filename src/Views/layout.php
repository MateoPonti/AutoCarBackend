<?php
/** Layout general. Variables: $contenido, $titulo */
$usuario = Auth::user();
$flash = flash_get();
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
  <link rel="stylesheet" href="/assets/css/app.css">
</head>
<body class="sitio">
<header class="topbar">
  <div class="topbar__in">
    <a class="plate plate--chica" href="/" aria-label="AutoCar, ir al inicio">
      <span class="plate__name">AUTOCAR</span>
    </a>
    <nav class="topbar__nav" aria-label="Principal">
      <a href="/vehiculos">Vehículos</a>
      <?php if ($usuario && Auth::isAdmin()): ?><a href="/admin/vehiculos">Administrar</a><?php endif; ?>
      <?php if ($usuario): ?>
        <span class="topbar__user"><?= e($usuario['nombre']) ?></span>
        <?php include SRC_PATH . '/Auth/views/logout_form.php'; ?>
      <?php else: ?>
        <a href="/login">Ingresar</a>
        <a class="btn btn--chico" href="/registro">Registrarse</a>
      <?php endif; ?>
    </nav>
  </div>
</header>
<main class="contenedor">
  <?php if ($flash): ?><div class="alert alert--ok" role="status"><?= e($flash) ?></div><?php endif; ?>
  <?= $contenido ?>
</main>
<footer class="pie">AutoCar · TP Integrador, Programación en Ambiente Web, UNLu</footer>
<script src="/assets/js/app.js" defer></script>
</body>
</html>
