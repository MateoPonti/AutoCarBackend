<?php
/** Variables: $items, $total, $filtros, $marcas, $pagina, $paginas */
$accion = '/admin/vehiculos';
?>
<div class="barra">
  <h1>Administrar vehículos</h1>
  <a class="btn" href="/admin/vehiculos/nuevo">+ Nuevo vehículo</a>
</div>
<?php include SRC_PATH . '/Catalogo/views/_filtros.php'; ?>
<p class="resultados"><?= (int)$total ?> <?= $total === 1 ? 'vehículo' : 'vehículos' ?></p>

<?php if (!$items): ?>
  <p class="vacio">No hay vehículos para mostrar.</p>
<?php else: ?>
<div class="tabla-wrap">
  <table class="tabla">
    <thead>
      <tr><th>ID</th><th>Vehículo</th><th>Año</th><th>Precio</th><th>Estado</th><th><span class="sr-only">Acciones</span></th></tr>
    </thead>
    <tbody>
      <?php foreach ($items as $v): ?>
      <tr>
        <td><?= (int)$v['id'] ?></td>
        <td><a href="/vehiculos/<?= (int)$v['id'] ?>"><?= e($v['marca']) ?> <?= e($v['modelo']) ?></a></td>
        <td><?= (int)$v['anio'] ?></td>
        <td>$ <?= number_format((float)$v['precio'], 0, ',', '.') ?></td>
        <td><span class="etiqueta etiqueta--<?= e($v['estado']) ?>"><?= e(Vehiculo::etiqueta($v['estado'])) ?></span></td>
        <td class="tabla__acciones">
          <a class="btn-link" href="/admin/vehiculos/<?= (int)$v['id'] ?>/editar">Editar</a>
          <form action="/admin/vehiculos/<?= (int)$v['id'] ?>/eliminar" method="post"
                data-confirm="¿Eliminar <?= e($v['marca'] . ' ' . $v['modelo']) ?>? Esta acción no se puede deshacer.">
            <?= Csrf::field() ?>
            <button type="submit" class="btn-link btn-link--peligro">Eliminar</button>
          </form>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php include SRC_PATH . '/Catalogo/views/_paginacion.php'; ?>
<?php endif; ?>
