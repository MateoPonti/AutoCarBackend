// Mostrar / ocultar contraseña. Sin JavaScript el botón queda oculto y el formulario funciona igual.
document.querySelectorAll('[data-toggle-password]').forEach(function (btn) {
  var input = document.getElementById(btn.getAttribute('aria-controls'));
  if (!input) return;
  btn.hidden = false;
  btn.addEventListener('click', function () {
    var show = input.type === 'password';
    input.type = show ? 'text' : 'password';
    btn.textContent = show ? 'Ocultar' : 'Mostrar';
    btn.setAttribute('aria-pressed', show ? 'true' : 'false');
  });
});
