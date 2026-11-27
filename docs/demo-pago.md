# Cómo dejar lista una inspección paga para la demo de desarrollo

**Resultado a mostrar.** Un comprador paga una inspección en Checkout Pro de prueba, vuelve al mismo pedido y lee «Pagado con Mercado Pago». En otra sesión, el mecánico asignado puede aceptar ese pedido y proponer el turno.

## Preparación

En Render de **desarrollo**, configurar `MP_ACTIVO=true` y el `MP_ACCESS_TOKEN` del entorno **Prueba** de la aplicación vendedora. El primer origen de `FRONT_URL` debe ser el preview de AutoCar para que el comprador regrese a la sesión correcta. La tabla `cobro` forma parte de `database/schema.sql` y de la migración 031 en bases anteriores. Si se usa notificación asíncrona, seleccionar el evento **Order** y la ruta `/api/pagos/mercadopago` del backend de desarrollo.

## Ensayo previo y evidencia

1. Abrí el preview de desarrollo en una ventana privada e ingresá a AutoCar como comprador. Elegí un aviso ajeno, un taller aprobado que el equipo pueda abrir en otra sesión y un pedido con saldo a pagar. Guardá el enlace al detalle desde **Mis inspecciones**.
2. En el detalle debería aparecer **Falta pagar con Mercado Pago**. Si no se abrió el checkout al pedir la inspección, usá **Pagar con Mercado Pago** en ese mismo pedido.
3. En el checkout de prueba elegí tarjeta. Los datos publicados para Argentina son Visa de prueba `4509 9535 6623 3704`, vencimiento `11/30`, código `123`, titular `APRO`, DNI `12345678`. Si solicita correo, usá el del comprador de prueba. La [guía oficial de Checkout Pro con Orders](https://www.mercadopago.com.ar/developers/es/docs/checkout-pro-orders/integration-test/test-purchase-with-card?scope=prod) mantiene la lista de escenarios.
4. Volvé al detalle de la misma inspección y comprobá **Pagado con Mercado Pago**. AutoCar consulta la orden en el servidor. Si aún dice pendiente, volvé a consultar el estado desde el retorno del checkout o esperá el aviso del proveedor y actualizá el detalle.
5. Entrá como el mecánico asignado en una segunda sesión. Ubicá el mismo pedido en su bandeja. Para el ensayo completo, aceptalo y proponé el turno. Para mostrar la aceptación en vivo, dejá otra inspección de prueba ya paga en estado **pedida**.

Para la presentación, guardá el enlace al pedido, la fecha del ensayo y el estado observado en ambos roles. El checkout puede vencer; el enlace reutilizable del equipo es el **detalle de AutoCar**. Si falló la creación de la orden, el comprador puede reintentar desde «Pagar» sin generar un segundo pedido.
