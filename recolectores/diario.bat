@echo off
rem Corrida diaria en la PC del grupo (9:00): recolecta las tres fuentes y actualiza los modelos del comparador.
rem Programarla una vez (cmd como el usuario de la PC, ajustar la ruta):
rem   schtasks /create /tn "AutoCar scraper" /sc daily /st 09:00 /tr "C:\ruta\Backend\recolectores\diario.bat"
rem El almacen sale de COMPARADOR_ALMACEN en el .env (archivos o firestore).
rem Si una fuente falla, las otras siguen: armar-modelos usa la ultima corrida que haya de cada una.

cd /d "%~dp0.."
if not exist storage\logs mkdir storage\logs
set LOG=storage\logs\scraper-%date:~-4%%date:~3,2%%date:~0,2%.log

echo ==== %date% %time% >> %LOG%
php recolectores\kavak\recolectar.php >> %LOG% 2>&1
php recolectores\autocosmos\recolectar.php >> %LOG% 2>&1
php recolectores\mercadolibre\recolectar.php >> %LOG% 2>&1
php bin\armar-modelos.php >> %LOG% 2>&1
echo ==== fin %date% %time% >> %LOG%
