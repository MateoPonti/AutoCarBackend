<?php
/**
 * Vista: registro (GET /registro, y se vuelve a mostrar si el POST no valida).
 *
 * Variables que espera del controlador (todas opcionales):
 *   $errors  array  errores por campo: 'nombre', 'email', 'password', 'password_confirm', 'general'
 *   $old     array  ['nombre' => '...', 'email' => '...']  se repueblan; las contraseñas nunca
 */
$titulo = 'Crear cuenta';
$panelTitulo = 'Publicá tu auto o encontrá el próximo.';
$panelTexto = 'Creá tu cuenta en un minuto. Podés empezar sin pagar nada.';
include __DIR__ . '/_top.php';
?>
      <h1>Creá tu cuenta</h1>
      <p class="auth__lead">¿Ya tenés una? <a href="/login">Ingresá</a></p>

      <?php if (!empty($errors['general'])): ?>
      <div class="alert alert--error" role="alert"><?= e($errors['general']) ?></div>
      <?php endif; ?>

      <form action="/registro" method="post" novalidate>
        <?= Csrf::field() ?>

        <div class="field<?php if (!empty($errors['nombre'])): ?> field--error<?php endif; ?>">
          <label for="nombre">Nombre y apellido</label>
          <input id="nombre" name="nombre" type="text" autocomplete="name" maxlength="80" required
                 value="<?= e($old['nombre'] ?? '') ?>"
                 <?php if (!empty($errors['nombre'])): ?>aria-invalid="true" aria-describedby="nombre-error"<?php endif; ?>>
          <?php if (!empty($errors['nombre'])): ?>
          <p class="field__error" id="nombre-error"><?= e($errors['nombre']) ?></p>
          <?php endif; ?>
        </div>

        <div class="field<?php if (!empty($errors['email'])): ?> field--error<?php endif; ?>">
          <label for="email">Email</label>
          <input id="email" name="email" type="email" inputmode="email" autocomplete="email"
                 autocapitalize="none" spellcheck="false" maxlength="254" required
                 value="<?= e($old['email'] ?? '') ?>"
                 <?php if (!empty($errors['email'])): ?>aria-invalid="true" aria-describedby="email-error"<?php endif; ?>>
          <?php if (!empty($errors['email'])): ?>
          <p class="field__error" id="email-error"><?= e($errors['email']) ?></p>
          <?php endif; ?>
        </div>

        <div class="field<?php if (!empty($errors['password'])): ?> field--error<?php endif; ?>">
          <label for="password">Contraseña</label>
          <div class="pw">
            <input id="password" name="password" type="password" autocomplete="new-password"
                   minlength="8" maxlength="72" required
                   <?php if (!empty($errors['password'])): ?>aria-invalid="true" aria-describedby="password-hint password-error"<?php else: ?>aria-describedby="password-hint"<?php endif; ?>>
            <button type="button" class="pw__toggle" data-toggle-password
                    aria-controls="password" aria-pressed="false" hidden>Mostrar</button>
          </div>
          <p class="field__hint" id="password-hint">De 8 a 72 caracteres, con al menos una letra y un número.</p>
          <?php if (!empty($errors['password'])): ?>
          <p class="field__error" id="password-error"><?= e($errors['password']) ?></p>
          <?php endif; ?>
        </div>

        <div class="field<?php if (!empty($errors['password_confirm'])): ?> field--error<?php endif; ?>">
          <label for="password_confirm">Repetí la contraseña</label>
          <div class="pw">
            <input id="password_confirm" name="password_confirm" type="password" autocomplete="new-password"
                   maxlength="72" required
                   <?php if (!empty($errors['password_confirm'])): ?>aria-invalid="true" aria-describedby="password-confirm-error"<?php endif; ?>>
            <button type="button" class="pw__toggle" data-toggle-password
                    aria-controls="password_confirm" aria-pressed="false" hidden>Mostrar</button>
          </div>
          <?php if (!empty($errors['password_confirm'])): ?>
          <p class="field__error" id="password-confirm-error"><?= e($errors['password_confirm']) ?></p>
          <?php endif; ?>
        </div>

        <button type="submit" class="btn">Crear cuenta</button>
      </form>
<?php include __DIR__ . '/_bottom.php'; ?>
