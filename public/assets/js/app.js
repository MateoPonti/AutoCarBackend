// Confirmación antes de acciones destructivas: <form data-confirm="mensaje">.
document.querySelectorAll('form[data-confirm]').forEach(function (form) {
  form.addEventListener('submit', function (ev) {
    if (!window.confirm(form.getAttribute('data-confirm'))) ev.preventDefault();
  });
});
