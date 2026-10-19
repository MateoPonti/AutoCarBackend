<?php
/** Variables: $titulo, $accion, $d (valores), $errores, $imagenActual */
$campoError = static fn(string $k): string => isset($errores[$k]) ? ' field--error' : '';
$msg = static function (string $k) use ($errores): string {
    return isset($errores[$k]) ? '<p class="field__error">' . e($errores[$k]) . '</p>' : '';
};
$val = static fn(string $k): string => e($d[$k] ?? '');
$img = Vehiculo::imagenUrl($imagenActual);
?>
<p><a class="btn-link" href="/admin/vehiculos">← Volver al listado</a></p>
<h1><?= e($titulo) ?></h1>

<form class="form" action="<?= e($accion) ?>" method="post" enctype="multipart/form-data" novalidate>
  <?= Csrf::field() ?>

  <div class="form__fila">
    <div class="field<?= $campoError('marca') ?>">
      <label for="marca">Marca</label>
      <input id="marca" name="marca" type="text" maxlength="50" required value="<?= $val('marca') ?>">
      <?= $msg('marca') ?>
    </div>
    <div class="field<?= $campoError('modelo') ?>">
      <label for="modelo">Modelo</label>
      <input id="modelo" name="modelo" type="text" maxlength="60" required value="<?= $val('modelo') ?>">
      <?= $msg('modelo') ?>
    </div>
  </div>

  <div class="form__fila">
    <div class="field<?= $campoError('anio') ?>">
      <label for="anio">Año</label>
      <input id="anio" name="anio" type="text" inputmode="numeric" maxlength="4" required value="<?= $val('anio') ?>">
      <?= $msg('anio') ?>
    </div>
    <div class="field<?= $campoError('precio') ?>">
      <label for="precio">Precio ($)</label>
      <input id="precio" name="precio" type="text" inputmode="decimal" maxlength="12" required value="<?= $val('precio') ?>">
      <?= $msg('precio') ?>
    </div>
    <div class="field<?= $campoError('kilometraje') ?>">
      <label for="kilometraje">Kilometraje</label>
      <input id="kilometraje" name="kilometraje" type="text" inputmode="numeric" maxlength="7" required value="<?= $val('kilometraje') ?>">
      <?= $msg('kilometraje') ?>
    </div>
  </div>

  <div class="form__fila">
    <div class="field<?= $campoError('combustible') ?>">
      <label for="combustible">Combustible</label>
      <select id="combustible" name="combustible" required>
        <option value="">Elegí…</option>
        <?php foreach (Vehiculo::COMBUSTIBLES as $c): ?>
        <option value="<?= e($c) ?>"<?= ($d['combustible'] ?? '') === $c ? ' selected' : '' ?>><?= e(Vehiculo::etiqueta($c)) ?></option>
        <?php endforeach; ?>
      </select>
      <?= $msg('combustible') ?>
    </div>
    <div class="field<?= $campoError('transmision') ?>">
      <label for="transmision">Transmisión</label>
      <select id="transmision" name="transmision" required>
        <option value="">Elegí…</option>
        <?php foreach (Vehiculo::TRANSMISIONES as $t): ?>
        <option value="<?= e($t) ?>"<?= ($d['transmision'] ?? '') === $t ? ' selected' : '' ?>><?= e(Vehiculo::etiqueta($t)) ?></option>
        <?php endforeach; ?>
      </select>
      <?= $msg('transmision') ?>
    </div>
    <div class="field<?= $campoError('estado') ?>">
      <label for="estado">Estado</label>
      <select id="estado" name="estado">
        <?php foreach (Vehiculo::ESTADOS as $s): ?>
        <option value="<?= e($s) ?>"<?= ($d['estado'] ?? 'disponible') === $s ? ' selected' : '' ?>><?= e(Vehiculo::etiqueta($s)) ?></option>
        <?php endforeach; ?>
      </select>
      <?= $msg('estado') ?>
    </div>
  </div>

  <div class="field<?= $campoError('descripcion') ?>">
    <label for="descripcion">Descripción (opcional)</label>
    <textarea id="descripcion" name="descripcion" rows="5" maxlength="2000"><?= $val('descripcion') ?></textarea>
    <?= $msg('descripcion') ?>
  </div>

  <div class="field<?= $campoError('imagen') ?>">
    <label for="imagen">Foto (JPG, PNG o WEBP, hasta 2 MB)</label>
    <?php if ($img): ?><p><img class="form__miniatura" src="<?= e($img) ?>" alt="Foto actual"></p><?php endif; ?>
    <input id="imagen" name="imagen" type="file" accept="image/jpeg,image/png,image/webp">
    <?= $msg('imagen') ?>
  </div>

  <button type="submit" class="btn">Guardar</button>
</form>
