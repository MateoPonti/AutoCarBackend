<?php
/** Variables: $items, $total, $filtros, $marcas, $pagina, $paginas */
$accion = '/vehiculos';
?>
<h1>Vehículos disponibles</h1>
<?php include __DIR__ . '/_filtros.php'; ?>

<p class="resultados"><?= (int)$total ?> <?= $total === 1 ? 'resultado' : 'resultados' ?></p>

<?php if (!$items): ?>
  <p class="vacio">No encontramos vehículos con esos filtros. Probá con otra búsqueda.</p>
<?php else: ?>
<div class="grilla">
  <?php foreach ($items as $v): $img = Vehiculo::imagenUrl($v['imagen']); ?>
  <article class="card">
    <a class="card__foto" href="/vehiculos/<?= (int)$v['id'] ?>" tabindex="-1" aria-hidden="true">
      <?php if ($img): ?><img src="<?= e($img) ?>" alt="" loading="lazy"><?php else: ?><span class="card__sinfoto">Sin foto</span><?php endif; ?>
    </a>
    <div class="card__cuerpo">
      <h2><a href="/vehiculos/<?= (int)$v['id'] ?>"><?= e($v['marca']) ?> <?= e($v['modelo']) ?></a></h2>
      <p class="card__datos"><?= (int)$v['anio'] ?> · <?= number_format((int)$v['kilometraje'], 0, ',', '.') ?> km · <?= e(Vehiculo::etiqueta($v['combustible'])) ?></p>
      <p class="card__precio">$ <?= number_format((float)$v['precio'], 0, ',', '.') ?></p>
    </div>
  </article>
  <?php endforeach; ?>
</div>
<?php include __DIR__ . '/_paginacion.php'; ?>
<?php endif; ?>
