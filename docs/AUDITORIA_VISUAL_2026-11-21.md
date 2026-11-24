# Auditoría visual del 21 de noviembre de 2026

## Causa comprobada y reparación

La portada y los formularios públicos, junto con los cuatro layouts de roles,
dependen de `frontend_clinica/resources/views/layouts/app.blade.php`. Su directiva
`@vite` carga `resources/css/app.css` y `resources/js/app.js`; estos incorporan los
estilos y scripts de los módulos. No faltaban esos imports.

Antes de reparar, `frontend_clinica/public/hot` contenía
`http://127.0.0.1:5173`. Laravel respondía HTTP 200 en el puerto 8000, pero sus
etiquetas de recursos apuntaban al 5173. Las comprobaciones HTTP de
`/@vite/client` y `/resources/css/app.css` en ese puerto devolvieron conexión
rechazada. La portada mostró desbordamiento horizontal de 1.688 px en una ventana
de 714 px, imágenes gigantes y controles sin formato. Login, Registro y
Administración también se observaron directamente antes de la reparación.

El manifiesto compilado y todos sus archivos referenciados existían. Se apartó
únicamente el archivo temporal `public/hot`, conservándolo en
`../.local/clinical-audit/visual-20261121/hot.before.txt`. Laravel pasó a servir:

- `/build/assets/app-DxkMPxNq.css`: HTTP 200, `text/css`, 111.300 bytes.
- `/build/assets/app-CqBoHbSv.js`: HTTP 200, `application/javascript`, 41.872 bytes.

No se recompilaron ni modificaron CSS, JavaScript, Blade o dependencias. Se
recuperó la apariencia definida por los recursos existentes, sin rediseño.
La comparación SHA-256 de 324 archivos de código, recursos, configuración y
pruebas registró **0 cambios**. Se añadió únicamente documentación de la incidencia.

## Verificación individual después de reparar

En la tabla, **C1** significa el archivo `public/hot` obsoleto que dirigía la carga
global a Vite detenido; **R1** significa apartar ese temporal y utilizar el
manifiesto compilado existente. En Secretaría, Médico y Paciente no se capturó
un antes: se comprobó la dependencia común en código y se verificó cada panel
con su rol después de reparar. Las capturas son privadas y están excluidas de Git.

| Ruta | Problema identificado | Causa comprobada | Corrección aplicada | Estado visual final | Estado funcional comprobado | Evidencia |
|---|---|---|---|---|---|---|
| `/` | Imágenes gigantes y desbordamiento | C1 | R1 | Recuperado; imágenes cargadas y sin desbordamiento global | Navegación y menú abren; catálogo visible | [Antes](../.local/clinical-audit/visual-20261121/antes-portada.jpg), [después](../.local/clinical-audit/visual-20261121/despues-portada.jpg), [DOM](../.local/clinical-audit/visual-20261121/public-dom-evidence.json) |
| `/login` | Formulario sin estilos e imágenes fuera de escala | C1 | R1 | Recuperado | Inicio de sesión real y redirección correcta para los cuatro roles | [Antes](../.local/clinical-audit/visual-20261121/antes-login.jpg), [después](../.local/clinical-audit/visual-20261121/despues-login.jpg) |
| `/registro` | Distribución y controles sin formato | C1 | R1 | Recuperado | Avance Identidad → Contacto y regreso comprobados | [Antes](../.local/clinical-audit/visual-20261121/antes-registro.jpg), [después](../.local/clinical-audit/visual-20261121/despues-registro.jpg), [paso 2](../.local/clinical-audit/visual-20261121/despues-registro-paso2.jpg) |
| `/administracion/inicio` | Iconos gigantes, espacios vacíos, gráficos deformados | C1 | R1 | Recuperado | Login Administrador, filtro Hoy, menú, consulta de Bitácoras y alta autorizada de Secretaría | [Antes](../.local/clinical-audit/visual-20261121/antes-administracion.jpg), [después](../.local/clinical-audit/visual-20261121/despues-administracion.jpg), [Bitácoras](../.local/clinical-audit/visual-20261121/despues-bitacoras.jpg) |
| `/secretaria/inicio` | Dependencia de los recursos globales afectados | C1, layout compartido | R1 | Verificado con cuenta de Secretaría | Login, menú y acceso a Check-in QR comprobados | [Panel](../.local/clinical-audit/visual-20261121/despues-secretaria.jpg), [QR](../.local/clinical-audit/visual-20261121/despues-checkin.jpg) |
| `/medico/inicio` | Dependencia de los recursos globales afectados | C1, layout compartido | R1 | Verificado con cuenta Médico | Login, indicadores, menú y apertura de agenda comprobados | [Panel](../.local/clinical-audit/visual-20261121/despues-medico.jpg), [agenda](../.local/clinical-audit/visual-20261121/despues-agenda-medico.jpg) |
| `/paciente/inicio` | Dependencia de los recursos globales afectados | C1, layout compartido | R1 | Verificado con cuenta Paciente | Login, próxima cita, resumen y apertura de Mis citas comprobados | [Panel](../.local/clinical-audit/visual-20261121/despues-paciente.jpg), [citas](../.local/clinical-audit/visual-20261121/despues-citas-paciente.jpg) |
| `layouts.app`, layouts de roles y componentes compartidos | Un punto de carga fallido afectaba toda la interfaz | C1; todos extienden el layout base | R1 | Tipografía, campos, botones, gráficos y menús recuperados en las pantallas revisadas | Menús interactivos, filtro y pasos de registro prueban ejecución de JavaScript | Capturas anteriores y [preservación](../.local/clinical-audit/visual-20261121/preservation.json) |

## Alcance funcional y preservación

Las cuentas Administrador, Médico y Paciente fueron proporcionadas por el usuario.
Secretaría se creó desde el panel Administrador después de su autorización expresa:
`SEC000002`, nombre **Secretaría Prueba Auditoría Visual**, correo
`secretaria.auditoria.20261121@example.test`. Su contraseña inicial aleatoria se
conserva exclusivamente en el archivo local privado
`.local/clinical-audit/visual-20261121/secretaria-prueba.private.json`. La cuenta
permanece activa. Se usaron únicamente inicio y cierre normales de sesión;
no se editaron usuarios existentes, contraseñas, permisos ni almacenes de sesiones.

No se creó un paciente, no se envió OTP, no se probaron Google OAuth ni envíos
reales de correo, no se activó una cámara ni se registraron llegadas, reservas o
atenciones médicas. Esas operaciones no se declaran verificadas de extremo a extremo.
La inspección visual se hizo con la ventana disponible del navegador, guardando
capturas de la vista y de página completa. No se certifican todos los tamaños de
pantalla ni todas las subrutas de cada módulo. Las dimensiones del JSON del DOM
se recogieron después de una captura completa y pueden reflejar esa captura.

La suite aislada Unit + Feature aprobó **456 pruebas y 3.514 aserciones**, incluidos
los casos de logs y Mockery existentes. PHPUnit usa SQLite en memoria y no acredita
una nueva ejecución de `tests/Postgres`.

- [Resultado PHPUnit](../.local/clinical-audit/visual-20261121/regresion.xml).
- [Hashes anteriores](../.local/clinical-audit/visual-20261121/source-before.json).
- [Comparación de preservación](../.local/clinical-audit/visual-20261121/preservation.json).

## Evitar recurrencia

Usar Vite en ejecución cuando se trabaja con `npm.cmd run dev`. Para trabajar
con los recursos compilados, verificar `public/build/manifest.json` y sus archivos
y apartar `public/hot` solamente si corresponde a un servidor detenido. Un cierre
abrupto del proceso de desarrollo puede dejar ese temporal. No sustituir los
estilos ni los layouts para reparar una carga de recursos fallida.
