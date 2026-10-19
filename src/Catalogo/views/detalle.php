<?php
/** Variables: $v (fila de vehiculos) */
$img = Vehiculo::imagenUrl($v['imagen']);
?>
<p><a class="btn-link" href="/vehiculos">← Volver al listado</a></p>
<article class="detalle">
  <div class="detalle__foto">
    <?php if ($img): ?><img src="<?= e($img) ?>" alt="<?= e($v['marca'] . ' ' . $v['modelo']) ?>"><?php else: ?><span class="card__sinfoto">Sin foto</span><?php endif; ?>
  </div>
  <div class="detalle__info">
    <h1><?= e($v['marca']) ?> <?= e($v['modelo']) ?></h1>
    <?php if ($v['estado'] === 'vendido'): ?><p class="etiqueta etiqueta--vendido">Vendido</p><?php endif; ?>
    <p class="detalle__precio">$ <?= number_format((float)$v['precio'], 0, ',', '.') ?></p>
    <dl class="ficha">
      <div><dt>Año</dt><dd><?= (int)$v['anio'] ?></dd></div>
      <div><dt>Kilometraje</dt><dd><?= number_format((int)$v['kilometraje'], 0, ',', '.') ?> km</dd></div>
      <div><dt>Combustible</dt><dd><?= e(Vehiculo::etiqueta($v['combustible'])) ?></dd></div>
      <div><dt>Transmisión</dt><dd><?= e(Vehiculo::etiqueta($v['transmision'])) ?></dd></div>
    </dl>
    <?php if (!empty($v['descripcion'])): ?>
    <h2>Descripción</h2>
    <p class="detalle__desc"><?= nl2br(e($v['descripcion'])) ?></p>
    <?php endif; ?>
  </div>
</article>
