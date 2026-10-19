<?php
/** Variables: $pagina, $paginas, $filtros, $accion */
if ($paginas > 1):
    $base = array_filter($filtros, static fn($v) => $v !== '' && $v !== null);
?>
<nav class="paginacion" aria-label="Paginación">
  <?php for ($n = 1; $n <= $paginas; $n++): ?>
    <?php if ($n === $pagina): ?>
      <span class="paginacion__actual" aria-current="page"><?= $n ?></span>
    <?php else: ?>
      <a href="<?= e($accion . '?' . http_build_query($base + ['page' => $n])) ?>"><?= $n ?></a>
    <?php endif; ?>
  <?php endfor; ?>
</nav>
<?php endif; ?>
