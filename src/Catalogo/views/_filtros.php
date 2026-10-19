<?php
/** Formulario de búsqueda/filtros. Variables: $filtros, $marcas, $accion (URL a la que envía por GET) */
?>
<form class="filtros" method="get" action="<?= e($accion) ?>">
  <div class="filtros__campo filtros__campo--grande">
    <label for="q">Buscar</label>
    <input id="q" name="q" type="search" maxlength="60" placeholder="Marca, modelo o palabra clave"
           value="<?= e($filtros['q']) ?>">
  </div>
  <div class="filtros__campo">
    <label for="marca">Marca</label>
    <select id="marca" name="marca">
      <option value="">Todas</option>
      <?php foreach ($marcas as $m): ?>
      <option value="<?= e($m) ?>"<?= $filtros['marca'] === $m ? ' selected' : '' ?>><?= e($m) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="filtros__campo">
    <label for="combustible">Combustible</label>
    <select id="combustible" name="combustible">
      <option value="">Todos</option>
      <?php foreach (Vehiculo::COMBUSTIBLES as $c): ?>
      <option value="<?= e($c) ?>"<?= $filtros['combustible'] === $c ? ' selected' : '' ?>><?= e(Vehiculo::etiqueta($c)) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="filtros__campo">
    <label for="precio_max">Precio máximo ($)</label>
    <input id="precio_max" name="precio_max" type="text" inputmode="decimal" maxlength="12"
           value="<?= e($filtros['precio_max'] ?? '') ?>">
  </div>
  <div class="filtros__acciones">
    <button type="submit" class="btn">Filtrar</button>
    <a class="btn-link" href="<?= e($accion) ?>">Limpiar</a>
  </div>
</form>
