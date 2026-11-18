# Auditoría y corrección de los flujos clínicos

Fecha: 17 de noviembre de 2026, America/La_Paz.

**Actualización 18/11/2026 — reenvío QR y error 429:** el límite anterior `throttle:1,2` devolvía una página 429 y compartía la espera entre citas del mismo usuario. Se mantuvo un intento cada dos minutos, ahora identificado por paciente y cita. Una solicitud HTML bloqueada vuelve al detalle mediante 303, conserva `Retry-After` y explica cuántos segundos faltan; las solicitudes JSON reciben 429 con `retryAfter`. El botón muestra una cuenta regresiva, queda deshabilitado durante la espera y bloquea dobles envíos. La protección SQL de intentos y la propiedad de la cita siguen vigentes.

Se verificó además un fallo de entorno: el proceso PHP que atendía `127.0.0.1:8000` estaba iniciado antes de habilitar GD y no tenía cargada `php_gd.dll`. Se reinició únicamente el servidor de esta clínica con su configuración privada existente; se comprobó GD cargada y HTTP 200 en salud. No se modificaron credenciales. La confirmación de `CIT00000003`, pendiente tras dos errores, fue aceptada por SMTP el **18/11/2026 a las 00:11:04, America/La_Paz**, en su tercer intento y sin error. Esto acredita aceptación por el transporte, no recepción en bandeja. Se agregó el diagnóstico seguro `QR_RENDERER` para identificar fallos futuros de GD sin registrar secretos.

Verificación: **293 pruebas Laravel aprobadas**, 1787 aserciones; **10 pruebas JavaScript aprobadas** y compilación Vite correcta. Los casos nuevos cubren espera visible, rechazo de doble solicitud, independencia entre citas y reintento permitido al vencer el límite. La integración PostgreSQL de envío inmediato pasó con 15 aserciones e incluye solicitar nuevamente una confirmación ya enviada: conserva un solo intento y no duplica el correo. No se almacenaron las cookies o tokens aportados en el reporte del navegador.

**Actualización — escaneo automático y aviso de llegada:** Secretaría ahora mantiene el vídeo visible, con recuadro e indicador «Escaneando». Leer un QR dentro de la plataforma ejecuta directamente el check-in autorizado y muestra paciente, cita, hora de llegada y En Espera junto a la cámara. Se eliminó la segunda confirmación manual. Los errores distinguen QR inválido, cita cancelada, otra fecha o llegada ya registrada; los códigos repetidos no producen nuevas llegadas.

La migración reversible V13 amplía el trigger existente: al cambiar a En Espera crea, dentro de la misma transacción, un aviso Web y un aviso Email para el paciente. El correo se intenta después del commit, reutilizando el consumidor existente. Los paneles de Paciente y las citas de Secretaría consultan cambios cada ocho segundos y al recibir una señal entre pestañas; no reemplazan formularios que se estén editando. La migración quedó aplicada en la base local y en pruebas. No se acreditaron pacientes reales ni se alteró la fecha de la cita del usuario.

Verificación de esta actualización: **290 pruebas Laravel**, 1758 aserciones; **8 pruebas JavaScript**; integración PostgreSQL de escaneo→En Espera→notificación→panel del paciente aprobada con **33 aserciones**, además de definición SQL final y control de uso único. Un navegador real leyó desde vídeo simulado un QR de longitud equivalente al pase firmado, registró una sola solicitud y mantuvo el indicador Escaneando sin errores de consola. Se revisó la interfaz a 1440/768/390 px. El aviso heurístico de vídeo sin poster corresponde a la cámara en vivo, que tiene su propio marcador visible al estar apagada. La cámara física continúa dependiendo del permiso del navegador y de HTTPS/localhost. Estas instrucciones sustituyen el paso de confirmación manual descrito más abajo en el reporte inicial.

**Actualización posterior al reporte inicial — envío inmediato del QR:** se confirmó que el scheduler no estaba activo y por eso una confirmación permanecía Pendiente con cero intentos. Las reservas y reprogramaciones de Paciente y Secretaría ahora intentan enviar su confirmación al terminar la operación confirmada en PostgreSQL, reclamando únicamente el aviso existente de esa cita. El scheduler sigue atendiendo reintentos y recordatorios; ya no es requisito para el primer envío. Se agregó el botón «Enviar QR a mi correo» para confirmaciones anteriores aún pendientes, sin crear otra reserva.

La ampliación V12 concede únicamente la actualización del contador de intentos al rol de la aplicación; incluye reversión y se aplica con el script existente. Las pruebas de correo permanecen aisladas. Además, por solicitud expresa del usuario, se procesó la confirmación real de `CIT00000001`: SMTP la aceptó el **17/11/2026 a las 22:08:50, America/La_Paz**, con **un intento** y sin error. Una segunda invocación conservó el mismo contador y fecha, sin reenviar. Esa aceptación no certifica recepción en bandeja; el check-in continúa habilitado para el día de la cita.

Verificación posterior: **287 pruebas Feature aprobadas**, 1742 aserciones; integración adicional de envío inmediato Web/Ventanilla con adjunto PNG, sin scheduler, sin procesar avisos ajenos y sin duplicación. Las secciones siguientes conservan los resultados y límites de la auditoría inicial.

Se contrastaron las páginas 17–20 de `C:/Users/LENOVO/Downloads/EVALUACION PROCESUAL.pdf`, el código Laravel, PostgreSQL real y pruebas ejecutadas. No se utilizaron README ni auditorías históricas como evidencia. La ejecución clínica actual está en Laravel; estas correcciones no necesitan Quarkus.

## 1. Diagnóstico inicial

Estado inicial: rama `main`, commit `801d46f96e2366ffe807a9632ff73dc1d7b5d82c`, árbol limpio. Los números de esta tabla corresponden a ese commit cuando se indica «inicial».

| Hallazgo confirmado | Evidencia y causa |
|---|---|
| Se afirmaba un envío de correo aun si fallaba | `frontend_clinica/app/Http/Controllers/Paciente/AppointmentController.php`, inicial líneas 104–107, 182–188: el controlador capturaba el error y continuaba con «enviamos». |
| El QR estaba fuera de la sección del layout | `frontend_clinica/resources/views/paciente/detalle.blade.php`, inicial línea 52 cerraba `panel`; el bloque QR comenzaba en la 54. |
| Las pruebas del contrato podían ignorarse al resolver el controlador | `AppointmentController.php`, inicial línea 33, dependía de la clase concreta en lugar de `PatientAppointmentManager`, que era el contrato configurado y simulado en las pruebas. |
| QR no renderizable en el PHP CLI local | La extensión GD estaba instalada pero deshabilitada en `C:/xampp/php/php.ini`. La integración que exigió PNG encontró el defecto; se habilitó únicamente `extension=gd`. |
| Confirmaciones y recordatorios existían, pero faltaba el consumidor Laravel | B9 genera filas Web y Email en la transacción. `routes/console.php` programaba mantenimiento de agendas y recepción, sin consumir notificaciones. El envío directo del paciente ignoraba esta cola; Secretaría no lo ejecutaba. |
| La cola SQL fallaba al invocarla realmente | B9, `tomar_notificaciones`, línea 1162: contrato `text`/`integer`, pero la consulta devolvía `varchar`/`smallint`. PostgreSQL produjo `42804` al ejecutar la integración. |
| Había operación de reprogramación, pero faltaba el acceso de Secretaría | Existían POST y `reprogramar_cita()` en V10, que acepta citas afectadas. No existía GET ni formulario de selección de nueva fecha en Secretaría. |
| Citas afectadas quedaban fuera de próximas | `PatientAppointmentService::list()` clasificaba todo estado distinto de Confirmada/En Espera como pasado; `fetchOwnedDetail()` solo habilitaba reprogramación para Confirmada. |
| El escáner solo aceptaba teclado | `secretaria/checkin/index.blade.php` tenía el campo oculto, sin cámara ni vista previa administrativa. |
| La derivación podía permanecer Agendada tras concluir su cita hija | B9/V10 no actualizaban la derivación a Completada al atender la cita hija ni a Cancelada al cancelarla. Se comprobaron las definiciones reales y se añadió una prueba de ambos desenlaces. |
| Un formulario obsoleto de reprogramación generaba un error genérico | V10 rechazaba el estado con SQLSTATE `P0001`; Laravel no lo traducía a conflicto. Se asignó `P4090` a ese rechazo específico. |
| El pase público mostraba CI y conservaba acceso en estados no vigentes | `QrPassController` comprobaba firma y coincidencia de token, pero no restringía la respuesta a Confirmada vigente. |

No estaban ausentes RF-13, RF-15 ni RF-16: V10 ya valida D..D+14 al escribir, usa bloqueos transaccionales y conserva las exclusiones de solapamiento. Se mantuvo esa arquitectura.

## 2. Correcciones aplicadas

### Agenda unificada y QR

- Se mantiene `reservar_cita()` como única autoridad de reserva para Web, Ventanilla y Derivación. Canal, secretaría y autor siguen en los registros existentes.
- El controlador del paciente vuelve a depender de su contrato. Ya no envía por una vía independiente ni afirma entrega no verificada.
- La generación PNG se reutiliza desde `app/Services/Notifications/AppointmentNotificationService.php` tanto en pantalla como en correo. El QR contiene solamente la URL del token firmado existente.
- El bloque QR está dentro de `panel`. Se muestran Pendiente, En proceso, Aceptado por el transporte, Error recuperable y Fallida, según la fila Email de confirmación.
- Reintentar modifica el aviso pendiente existente, exige propiedad de la cita, conserva el máximo de intentos y tiene límite HTTP de una solicitud cada dos minutos. No vuelve a reservar.
- Las citas afectadas permanecen visibles y admiten la búsqueda de nueva fecha. Secretaría tiene una pantalla GET integrada en el módulo de citas y utiliza el POST existente.
- El pase público requiere una cita Confirmada vigente, omite CI, evita caché y referencias externas, y convierte fallos SQL en una respuesta segura sin registrar bindings del token.

### Notificaciones y recordatorios

- `AppointmentNotificationService` consume `notificaciones`, `tomar_notificaciones()` y `finalizar_notificacion()` existentes. No hay una segunda tabla de colas ni un chat.
- Los triggers generan los avisos dentro de la transacción clínica. El consumidor solo puede ver operaciones confirmadas por PostgreSQL.
- Se reutilizan `AppointmentQrMail` y su plantilla, incluyendo confirmaciones, recordatorios con QR y avisos administrativos sin QR.
- Solo se envía a un correo válido, verificado y coincidente con el destinatario registrado del aviso. Un cambio de dirección requiere revisión, en lugar de enviar silenciosamente a otra dirección.
- `Enviada` significa aceptación del transporte, no recepción en la bandeja. Los transportes de registro y los fallbacks no verificables se rechazan; `array` solo se admite en testing.
- Se mantienen intentos, espera exponencial y recuperación de workers existentes. Los errores registrados no incluyen respuestas SMTP, credenciales ni tokens.
- La publicación Web tiene una página propia para el paciente, limitada a sus avisos administrativos ya programados para publicación.
- El comando `clinical:notifications` está programado cada minuto. Se descartan confirmaciones/recordatorios obsoletos y se cancelan también avisos reclamados por un worker cuando cambia la vigencia de la cita.
- `clinical:notifications --check` revisa presencia de configuración sin consumir avisos, conectarse a SMTP ni revelar valores secretos.

### Check-in e información de estados

- `resources/js/qr-checkin.js` usa `qr-scanner`, empaquetado localmente por Vite con su worker.
- La cámara se inicia por acción explícita, utiliza preferencia trasera, permite detenerse y maneja contexto inseguro, permiso denegado y ausencia de cámara.
- El mismo formulario admite lector USB y entrada manual. Tanto navegador como servidor extraen el token de `/pase-qr/{token}`.
- La vista previa es POST, de solo lectura, autorizada para Secretaría. Muestra paciente, código de cita, fecha, hora y estado antes de confirmar llegada.
- El POST final vuelve a validar y compara la cita con la validada. PostgreSQL conserva firma, fecha, estado y uso único; el navegador no modifica estados.
- Se evita el envío repetido y se limpia el token al salir/restaurar la página. No se usa almacenamiento persistente del navegador.
- El componente Blade `clinical-progress` representa Confirmada → En Espera → Atendida y Pendiente → Agendada → Completada; los estados alternativos se indican por separado.
- La derivación se completa o cancela en el trigger existente de eventos de cita. La bitácora existente conserva el actor de la transición.

### SQL y entorno

Migración incremental: `database_clinica/upgrade/V11__permisos_notificaciones.sql`. Reversión: `U11__permisos_notificaciones.sql`.

Se amplían tres funciones existentes con `CREATE OR REPLACE`: cola, eventos de cita y código de conflicto en reprogramación. No se crean estados, tablas ni triggers paralelos; no se eliminan tablas, funciones, triggers ni datos históricos.

El script `scripts/clinical/Apply-Notifications-Migration.ps1` comprueba el contenedor, la base permitida, las dependencias y hashes conocidos de las funciones. Rechaza definiciones ajenas para no sobrescribir trabajo externo. `Install-Schema.ps1` aplica esta ampliación después de los permisos originales en una instalación normal.

La migración se aplicó en `clinica_laravel_phase1_tests` y en la base local `clinica_laravel`. La reversión y reaplicación se comprobaron, conservando historiales. No se ejecutó el consumidor contra la base principal ni se enviaron correos a pacientes reales. No se modificaron credenciales.

## 3. Estado por requisito

«Verificado en pruebas» se limita al alcance indicado; no equivale a una certificación de producción.

| Requisito | Estado | Evidencia y límite |
|---|---|---|
| RF-12 | Corregido y verificado en integración | Bloqueo parcial, cita afectada visible para el paciente, formulario de Secretaría, nueva cita enlazada y nuevo QR. |
| RF-13 | Verificado en servidor | D−1 y D+15 rechazados por función SQL directa; D+14 aceptado; D se ejercita con reservas/check-in del día. America/La_Paz coincide con PostgreSQL. Falta observar un cambio real de medianoche. |
| RF-15 | Verificado en escritura | Distintos médicos/especialidades, ambos canales, coincidencia total y parcial rechazadas; contigüidad aceptada; reprogramación conflictiva conserva la cita. Exclusión PostgreSQL vigente. |
| RF-16 | Verificado en concurrencia | Una solicitud Web y una de Ventanilla al mismo slot producen un ganador, una cita y una confirmación Email. Se conservan locks, transacciones y rollback. |
| RF-18 | Corregido y verificado en integración | Token firmado existente, PNG en el layout, pase público inválido tras afectación/reprogramación, QR nuevo en la nueva cita. GD habilitado localmente. |
| RF-19 | Implementado y probado con límites físicos | Check-in real de uso único, actor autorizado, previa sin mutación y discrepancia de identidad rechazada. Cámara/USB probados mediante lógica simulada; falta hardware real. |
| RF-20 | Verificado en integración | Suite existente del médico y nueva prueba de médico destino: En Espera → Atendida, propiedad y auditoría. |
| RF-21 | Corregido y verificado en integración | Origen, ticket, cita hija, consumo único, reprogramación enlazada y transición Completada/Cancelada con actor. |
| RF-22 | Verificado en integración | Cancelación compartida, autor/motivo/fecha, liberación por agenda calculada, avisos y rechazo de estados incompatibles. |
| RF-26 | Implementado; validación externa pendiente | Outbox, consumidor, QR por correo, página Web, recordatorio de 24 h, reintentos y descarte obsoleto probados con transporte en memoria. Falta verificar SMTP real y mantener activo el scheduler. WhatsApp continúa como integración futura. |

## 4. Resultados de pruebas

| Ejecución | Resultado |
|---|---|
| Suite Feature final de Laravel | 286/286, 1736 aserciones. SQLite en memoria y correo aislado. |
| Suite PostgreSQL inicial completa | 53/53, 814 aserciones. Incluye recorrido de los cuatro roles, seguridad, concurrencia y permisos. |
| Nuevas regresiones PostgreSQL | Siete casos nuevos comprobados: afectación/reprogramación, outbox/recordatorios, Web frente a Ventanilla, fallo SMTP/reintento, fechas SQL, cierre/cancelación de derivación y solapamientos entre médicos. |
| Reejecución final de afectación y outbox | 2/2, 23 aserciones; incluye formulario obsoleto 409 y recordatorios ya EnProceso al cancelar. |
| Integridad del esquema tras ampliación | 5/5, 130 aserciones; definiciones versionadas, restricciones y privilegios. |
| Verificación final de funciones SQL e IDOR de notificaciones | Definiciones finales: 1/1, 60 aserciones. Flujo cruzado final: 1/1, 24 aserciones; otro paciente no puede consultar ni reintentar los avisos y una cita fuera del bloqueo conserva Confirmada. |
| Escáner JavaScript | 5/5: formato, permisos/ausencia/contexto, detener cámara, repetición y USB/manual. |
| Vite | Compilación correcta; QR y worker incluidos localmente. |
| Navegador real | Vistas de escáner, reprogramación y detalle a 1440/768/390 px. Sin errores de consola ni desbordamiento horizontal. Advertencia estética de altura en una tarjeta preexistente del paciente; se conserva el diseño solicitado. |
| Configuración de correo | SMTP, remitente válido, host/puerto y autenticación configurados; solo se mostraron indicadores booleanos. No se probó conexión ni entrega externa. |
| Auditoría de paquetes | Producción: 0 vulnerabilidades reportadas. Desarrollo: 6 alertas altas en la cadena Vite/globbing. No se aplicaron cambios mayores ajenos al flujo clínico. |

No se volvió a ejecutar toda la suite PostgreSQL después de cada edición. Se repitieron los casos afectados por las correcciones; las ejecuciones no son una única certificación final de todos los casos juntos.

Fallos encontrados durante el trabajo: tipos incompatibles en la cola SQL, GD deshabilitado y fixtures que no abrían la sesión HTTP. Se corrigieron y se repitieron sus casos. La prueba de recordatorios se ajustó a D+8: con una cita dentro de las próximas 24 horas es válido que el recordatorio ya se haya enviado antes de cancelar.

Las pruebas que escriben usan exclusivamente la base aislada, usuarios sintéticos y dominios `.invalid`. No hay pruebas omitidas declaradas como aprobadas. La cámara real, recepción SMTP, cambio físico de medianoche y disponibilidad continua del scheduler quedan como comprobaciones manuales/operativas.

### Reproducción

Desde la raíz del proyecto, usando PowerShell:

```powershell
# Consumidor/configuración: este comando NO envía ni consume mensajes.
php frontend_clinica/artisan clinical:notifications --check

# Pruebas PostgreSQL completas: solo base aislada.
./scripts/clinical/Invoke-Laravel.ps1 -TestDatabase vendor/phpunit/phpunit/phpunit tests/Postgres --no-progress

# Las nuevas pruebas también pueden ejecutarse por nombre con --filter.
./scripts/clinical/Invoke-Laravel.ps1 -TestDatabase vendor/phpunit/phpunit/phpunit tests/Postgres/AdministrationReceptionMigrationTest.php --filter 'affected_appointment|outbox_accepts|mixed_web|failed_email|postgres_itself' --no-progress
./scripts/clinical/Invoke-Laravel.ps1 -TestDatabase vendor/phpunit/phpunit/phpunit tests/Postgres/MedicoMigrationTest.php --filter 'derived_child_completion|patient_overlap_is_rejected' --no-progress

Set-Location frontend_clinica
php vendor/phpunit/phpunit/phpunit --no-progress
node --test tests/Unit/QrCheckin.test.mjs
php vendor/bin/pint --dirty --test
npm run build
```

Aplicar/revertir SQL local mediante el script comprobado:

```powershell
./scripts/clinical/Apply-Notifications-Migration.ps1 -TestDatabase
./scripts/clinical/Apply-Notifications-Migration.ps1 -TestDatabase -Rollback
```

Sin `-TestDatabase`, el script opera sobre la base local principal. La ampliación ya quedó aplicada en ambas; no es necesario repetirla para probar las pantallas.

## 5. Comprobaciones manuales exactas

Utilizar cuentas sintéticas y un buzón de pruebas controlado. No apuntar las pruebas de correo al listado de pacientes reales.

1. **Preparar entorno.** Reiniciar el servidor PHP si estaba abierto antes de habilitar GD. Confirmar `clinical:notifications --check`. Verificar que el servicio de Laravel ejecuta `schedule:run` cada minuto o mantener `schedule:work` en el entorno de pruebas. No iniciar el consumidor sobre la base principal hasta revisar sus avisos pendientes y destinatarios.
2. **Secretaría → Paciente.** En Nueva reserva, buscar al paciente sintético, seleccionar especialidad/médico/fecha dentro de D..D+14 y confirmar un turno. En otra sesión, entrar como ese paciente, abrir Mis citas y el detalle. Deben coincidir fecha, médico y estado, aparecer el PNG y la notificación Web. Tras procesar la cola de pruebas, comprobar «Aceptado por el transporte» y QR adjunto en el buzón de pruebas.
3. **Cancelación cruzada.** Cancelar desde Secretaría con motivo. Actualizar Mis citas y Notificaciones del paciente. Debe figurar Cancelada; el pase anterior debe responder como no vigente. Buscar el turno desde otra cuenta: debe ser reservable si no tiene bloqueo. Repetir cancelando desde Paciente y actualizando Gestión de citas en Secretaría.
4. **Reprogramación cruzada.** Desde Secretaría, abrir detalle → Reprogramar cita → fecha → Buscar turnos → nuevo turno → motivo → confirmar. Paciente debe ver una cita nueva Confirmada y la original Reprogramada; los QR deben ser distintos. El enlace anterior no debe servir. Repetir desde Paciente y comprobar el resultado en Secretaría. Enviar un formulario antiguo de la cita original debe producir conflicto y ninguna cita adicional.
5. **Fallo de correo.** En el entorno aislado, usar un transporte SMTP de pruebas que rechace la operación. La cita debe persistir y el QR seguir visible; el correo debe figurar con error recuperable. Solicitar Reintentar correo, restaurar el transporte y procesar la cola. Debe conservarse una cita y un aviso de confirmación, sin volver a reservar.
6. **Cámara y lectores.** Crear una cita de hoy que admita llegada. En Secretaría → Check-in QR, pulsar Activar cámara, conceder permiso y mostrar el pase del paciente. Comprobar nombre/código/fecha en la previa; el estado todavía debe ser Confirmada. Pulsar Confirmar llegada y comprobar En Espera y una sola llegada. Repetir con permiso denegado, sin cámara, Detener cámara, lector USB y entrada manual. Volver a escanear el mismo pase no debe registrar una segunda llegada.
7. **QR inválidos.** Probar token falso, cita de mañana, cita cancelada y pase ya usado. Ninguno debe registrar llegada. Validar el pase de un paciente, sustituirlo por el de otro antes del POST final y comprobar que se exige validar de nuevo; no debe confirmarse al paciente inicialmente mostrado.
8. **Médico.** Entrar como el médico asignado, revisar En Espera y finalizar la consulta. Paciente y Secretaría deben ver Atendida. Otro médico no debe cerrar esa cita.
9. **Administrador → Secretaría → Paciente.** Reservar dos turnos distintos del médico. Bloquear únicamente el intervalo de uno. El detalle administrativo debe listar exactamente el afectado; el otro debe conservar Confirmada. En Secretaría, abrir la cita afectada y reprogramar a un horario disponible. Paciente debe ver la afectación, la resolución y el pase nuevo; no debe poder elegirse el intervalo bloqueado.
10. **Derivación completa.** El médico emisor cierra una consulta y crea una derivación habilitada. Secretaría debe verla Pendiente y programar su cita hija. Paciente debe recibirla y el ticket pasar a Agendada. Acreditar la llegada y cerrar la cita con el médico destino: la derivación debe ser Completada. Repetir con otro ticket y cancelar su cita hija: debe quedar Cancelada, conservando enlaces y auditoría.
11. **Colisiones y ventana.** Con dos sesiones, intentar reservar simultáneamente el mismo slot desde Web y Ventanilla. Solo una debe ganar. Intentar horarios superpuestos con dos médicos, incluyendo solapamiento parcial; deben rechazarse. Dos turnos contiguos deben admitirse. Comprobar D−1/D+15 manipulando peticiones y observar el desplazamiento del rango tras medianoche en America/La_Paz.
12. **Recordatorio.** En la base de pruebas, elegir una cita a más de 24 horas. Antes de la hora programada no debe enviarse el recordatorio; al vencer debe procesarse una sola vez. Repetir cancelando o reprogramando antes: no debe enviarse el aviso del horario anterior.

## 6. Riesgos y pendientes

- SMTP real: la presencia de configuración no demuestra autenticación aceptada, entrega, reputación del remitente ni recepción. No se enviaron mensajes de prueba a personas reales.
- Scheduler: el evento Laravel está registrado, pero la continuidad requiere un proceso/servicio operativo de `schedule:run` o `schedule:work`. No se dejó un proceso nuevo enviando pendientes de la base principal; al cierre se detectaron 0 procesos PHP schedule:work/schedule:run.
- Hardware: falta comprobar cámara y lector USB reales en los navegadores y teléfonos de uso. La cámara necesita HTTPS o localhost y permiso del usuario.
- Entrega exactamente una vez: la fila se reclama con locks y se evita el reenvío de estados ya finalizados. Si el proceso cae después de que SMTP acepta el correo pero antes de registrar la aceptación, existe una ventana de entrega ambigua. SMTP no garantiza deduplicación; no se afirma garantía absoluta ante ese fallo externo.
- Medianoche: se verificó la función de fecha y el rango en SQL, pero no se esperó el cruce físico de medianoche. Las vistas abiertas deben actualizarse al operar; el servidor vuelve a validar.
- Avisos pendientes heredados: al habilitar el consumidor se procesa la cola previa vigente. Revisar destinatarios antes de activarlo en un entorno con pacientes reales.
- El estado Fallida conserva el máximo de intentos; no hay botón de reenvío ilimitado. Requiere revisión operativa. El reintento del paciente solo actúa sobre fallos recuperables pendientes y no cambia destinatarios.
- WhatsApp conserva el alcance de preparación para integración del PDF; no se añadió proveedor ni un chat.
- Seis alertas altas de desarrollo reportadas por npm: `fdir`, `picomatch`, `tinyglobby`, `vite`, `laravel-vite-plugin` y `vite-plugin-full-reload`. La biblioteca QR y las dependencias de producción no presentaron alertas en la auditoría ejecutada. No se hizo un downgrade/upgrade mayor automático del tooling.
- Las capturas usan fixtures y no certifican disponibilidad ni autorización de un servidor desplegado. Se conservó el diseño actual; la advertencia de tarjeta estirada del paciente no afecta a la operación.

## 7. Control Git

Se trabajó sobre `main`, conservando el commit inicial. No se ejecutó `git add`, `git commit`, `git push`, creación de rama, limpieza de datos históricos ni restauración de trabajo ajeno.

Los cambios incluyen controladores/servicios existentes, vistas Blade y rutas; siete pruebas PostgreSQL nuevas; pruebas Feature/JavaScript y soporte de capturas; consumidor de la cola; migración/reversión SQL y script de aplicación. `AGENTS.md` no quedó modificado. Las capturas de QA están excluidas en `.local/clinical-audit/`; los builds de Vite continúan excluidos por la configuración existente.

Estado y diferencias finales se pueden reproducir con:

```powershell
git status --short
git diff --stat
git diff --check
git diff
```

El estado observado al cerrar se adjunta a continuación. Los archivos nuevos permanecen sin preparar para commit.


~~~text
 M .gitignore
 M frontend_clinica/app/Http/Controllers/Paciente/AppointmentController.php
 M frontend_clinica/app/Http/Controllers/QrPassController.php
 M frontend_clinica/app/Http/Controllers/Secretaria/CheckinController.php
 M frontend_clinica/app/Http/Controllers/Secretaria/CitaController.php
 M frontend_clinica/app/Http/Requests/Secretaria/CheckinRequest.php
 M frontend_clinica/app/Mail/AppointmentQrMail.php
 M frontend_clinica/app/Services/Paciente/PatientAppointmentService.php
 M frontend_clinica/app/Services/Reception/ReceptionService.php
 M frontend_clinica/package-lock.json
 M frontend_clinica/package.json
 M frontend_clinica/resources/css/app.css
 M frontend_clinica/resources/js/administracion.js
 M frontend_clinica/resources/js/app.js
 M frontend_clinica/resources/views/administracion/bloqueos/show.blade.php
 M frontend_clinica/resources/views/layouts/paciente.blade.php
 M frontend_clinica/resources/views/mail/appointment-qr.blade.php
 M frontend_clinica/resources/views/medico/derivaciones/show.blade.php
 M frontend_clinica/resources/views/paciente/detalle.blade.php
 M frontend_clinica/resources/views/secretaria/checkin/index.blade.php
 M frontend_clinica/resources/views/secretaria/citas/show.blade.php
 M frontend_clinica/resources/views/secretaria/derivaciones/show.blade.php
 M frontend_clinica/routes/console.php
 M frontend_clinica/routes/web.php
 M frontend_clinica/tests/Feature/PatientPanelTest.php
 M frontend_clinica/tests/Feature/SecretariaPanelTest.php
 M frontend_clinica/tests/Postgres/AdministrationReceptionMigrationTest.php
 M frontend_clinica/tests/Postgres/ClinicalInfrastructureTest.php
 M frontend_clinica/tests/Postgres/MedicoMigrationTest.php
 M scripts/clinical/Install-Schema.ps1
?? database_clinica/upgrade/U11__permisos_notificaciones.sql
?? database_clinica/upgrade/V11__permisos_notificaciones.sql
?? docs/AUDITORIA_FLUJOS_CLINICOS_2026-11-17.md
?? frontend_clinica/app/Console/Commands/ClinicalNotifications.php
?? frontend_clinica/app/Http/Controllers/Paciente/NotificationController.php
?? frontend_clinica/app/Services/Notifications/AppointmentNotificationService.php
?? frontend_clinica/resources/js/qr-checkin.js
?? frontend_clinica/resources/views/components/clinical-progress.blade.php
?? frontend_clinica/resources/views/paciente/notificaciones.blade.php
?? frontend_clinica/resources/views/secretaria/citas/reprogramar.blade.php
?? frontend_clinica/tests/Feature/AppointmentNotificationTransportTest.php
?? frontend_clinica/tests/Support/ClinicalVisualSnapshots.php
?? frontend_clinica/tests/Unit/QrCheckin.test.mjs
?? scripts/clinical/Apply-Notifications-Migration.ps1
~~~
