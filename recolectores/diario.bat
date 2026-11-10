@echo off
rem Corrida diaria en la PC del grupo (9:00): recolecta las tres fuentes y actualiza los modelos del comparador.
rem Programarla una vez (cmd como el usuario de la PC, ajustar la ruta):
rem   schtasks /create /tn "AutoCar scraper" /sc daily /st 09:00 /tr "C:\ruta\Backend\recolectores\diario.bat"
rem El almacen sale de COMPARADOR_ALMACEN y MERCADO_ALMACEN en el .env (archivos o firestore).
rem Si una fuente falla, las otras siguen: armar-modelos usa la ultima corrida que haya de cada una.
rem MercadoLibre completo son ~80.000 autos (~66 MB de JSON, ~210 MB de pico): con los 128 MB que PHP trae por
rem defecto se queda sin memoria al guardar y se pierde la corrida. Por eso esos pasos van con 1 GB.

cd /d "%~dp0.."
if not exist storage\logs mkdir storage\logs
set LOG=storage\logs\scraper-%date:~-4%%date:~3,2%%date:~0,2%.log

echo ==== %date% %time% >> %LOG%
php recolectores\kavak\recolectar.php >> %LOG% 2>&1
php recolectores\autocosmos\recolectar.php >> %LOG% 2>&1
php -d memory_limit=1G recolectores\mercadolibre\recolectar.php >> %LOG% 2>&1
php -d memory_limit=1G bin\armar-modelos.php >> %LOG% 2>&1
rem Metabuscador: carga la corrida de hoy de cada fuente (historial, retirados y avisos) en MERCADO_ALMACEN
php -d memory_limit=1G bin\importar-autos.php --hoy >> %LOG% 2>&1
echo ==== fin %date% %time% >> %LOG%
