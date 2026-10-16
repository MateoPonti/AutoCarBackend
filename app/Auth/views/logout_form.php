<?php
/**
 * Botón "Salir" para el menú (lo incluye el layout de Fede cuando hay sesión).
 * Cierra sesión por POST y lleva el token CSRF, como pide la consigna.
 */
?>
<form class="logout-form" action="/logout" method="post">
  <?= Csrf::field() ?>
  <button type="submit" class="btn-link">Salir</button>
</form>
