<?php
/**
 * Vista: login (GET /login, y se vuelve a mostrar si el POST falla).
 *
 * Variables que espera del controlador (todas opcionales):
 *   $errors  array        ['general' => 'mensaje']  credenciales inválidas o cuenta bloqueada
 *   $old     array        ['email' => '...']        se repuebla el email, nunca la contraseña
 *   $flash   string|null  aviso de éxito, p. ej. "Cuenta creada. Ya podés ingresar."
 */
$titulo = 'Ingresar';
$panelTitulo = 'Tu próximo auto empieza acá.';
$panelTexto = 'Ingresá para publicar, guardar favoritos y proponer canjes.';
include __DIR__ . '/_top.php';
?>
      <h1>Ingresá a tu cuenta</h1>
      <p class="auth__lead">Con tu email y tu contraseña.</p>

      <?php if (!empty($flash)): ?>
      <div class="alert alert--ok" role="status"><?= e($flash) ?></div>
      <?php endif; ?>

      <?php if (!empty($errors['general'])): ?>
      <div class="alert alert--error" role="alert"><?= e($errors['general']) ?></div>
      <?php endif; ?>

      <form action="/login" method="post" novalidate>
        <?= Csrf::field() ?>

        <div class="field">
          <label for="email">Email</label>
          <input id="email" name="email" type="email" inputmode="email" autocomplete="email"
                 autocapitalize="none" spellcheck="false" maxlength="254" required
                 value="<?= e($old['email'] ?? '') ?>">
        </div>

        <div class="field">
          <label for="password">Contraseña</label>
          <div class="pw">
            <input id="password" name="password" type="password" autocomplete="current-password"
                   maxlength="72" required>
            <button type="button" class="pw__toggle" data-toggle-password
                    aria-controls="password" aria-pressed="false" hidden>Mostrar</button>
          </div>
        </div>

        <button type="submit" class="btn">Ingresar</button>
      </form>

      <p class="auth__alt">¿Todavía no tenés cuenta? <a href="/registro">Registrate</a></p>
<?php include __DIR__ . '/_bottom.php'; ?>
