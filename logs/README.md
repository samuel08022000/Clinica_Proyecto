# Logs de la clínica — Fase 2 implementada

Actualizado el **21 de noviembre de 2026 (America/La_Paz)**. Aplicación: `frontend_clinica/`; Laravel instalado: **13.33.0**, Monolog **3.12.0**. El estado se comprobó en código, configuración y pruebas actuales, incluidos cambios locales previos. Los README antiguos no acreditan funcionalidades.

## Qué son y para qué sirven

Un log es un registro fechado de una operación y su resultado. En la clínica permite investigar accesos, cambios administrativos, reservas, check-in, atención y problemas de notificación. No sustituye al historial clínico ni a la tabla PostgreSQL `bitacora`.

La documentación está en `logs/`, en la raíz del repositorio:

- [INVENTARIO_LOGS.md](INVENTARIO_LOGS.md): eventos, niveles, archivos, métodos, condiciones, verificación y relación completa de cambios.
- [EVIDENCIAS.md](EVIDENCIAS.md): comprobaciones reales, pruebas y guía PowerShell.

Los registros reales permanecen en `frontend_clinica/storage/logs/`. No se copian a esta carpeta documental.

## Configuración anterior y nueva

| Parámetro | Fase 1 | Fase 2 |
|---|---|---|
| Canal por defecto | `stack` | `stack` |
| Integrantes del stack | `single` | `daily` |
| Archivo activo | `storage/logs/laravel.log` | `storage/logs/laravel-AAAA-MM-DD.log`, creado al primer evento |
| Retención | Sin rotación nativa de single | Hasta 14 archivos diarios mediante el handler nativo |
| Nivel local | `debug` | `debug`, configurable mediante `LOG_LEVEL` |

Se actualizó `LOG_STACK=daily` y se agregó `LOG_DAILY_DAYS=14` en el `.env` local y en `.env.example`. En `config/logging.php` el stack usa ahora daily si no hay variable que lo sobrescriba. El canal daily ya usaba `LOG_DAILY_DAYS` con valor por defecto 14; no se cambió su driver ni se agregó otra dependencia.

`max_files=14` conserva 14 archivos fechados; con actividad diaria equivale a 14 días. Si hay días sin mensajes, los archivos retenidos pueden abarcar más de 14 días naturales. Monolog realiza la limpieza al rotar/escribir, no mediante una tarea cron agregada. Solo considera la familia diaria de nombres correspondiente; no se ejecutó limpieza de históricos reales. `laravel.log` y otros nombres ajenos a esa familia quedan fuera de esa rotación.

La configuración CLI consultada resolvió `stack → daily`, `debug` y retención 14, sin caché de configuración. Otro entorno puede sobrescribir estas variables. La zona de la aplicación continúa siendo UTC; la clínica usa America/La_Paz para operaciones y horarios. Un proceso web o worker persistente debe volver a cargar su configuración por el procedimiento habitual del despliegue; no se reiniciaron servicios.

Se mantienen los canales single, daily, monthly, slack, papertrail, stderr, syslog, errorlog, null y emergency. Declararlos no demuestra conectividad con destinos externos. Deprecaciones sigue usando el descarte del framework (`null`, sin trace). No se cambiaron PostgreSQL, rutas, permisos ni política de autenticación.

**Aclaración verificada en Fase 4:** al arrancar los proveedores en el entorno local, Laravel Boost agrega además el canal `browser` y la ruta de escritura `POST /_boost/browser-logs`. Ese canal usa `single` hacia `storage/logs/browser.log` y no pasa por ClinicalLog. Su opción `days=14` no activa rotación en un driver single. La política diaria anterior corresponde al stack principal, no a todos los emisores de desarrollo. Boost se desactiva durante runningUnitTests; la exposición y retención de ese canal en un despliegue requieren revisión.

## Niveles

| Nivel | Significado y aplicación |
|---|---|
| DEBUG | Diagnóstico detallado; no se agregaron eventos propios de este nivel. Nunca debe contener secretos. |
| INFO | Operación confirmada: acceso, cambios administrativos, reserva, atención o aceptación de correo por el transporte. |
| WARNING | Credenciales rechazadas, límite de intentos, conflicto/regla operativa o fallo recuperable de correo/QR. |
| ERROR | Fallo técnico registrado con categoría segura; no implica volcar SQL, bindings ni stacktrace. |
| CRITICAL | Fallo grave de infraestructura. No se inventó un detector ni se agregaron eventos propios de este nivel. |

Laravel también admite NOTICE, ALERT y EMERGENCY. `LOG_LEVEL` es el umbral mínimo: `debug` permite todos los niveles; `warning` filtra los INFO. La ausencia de INFO con un umbral mayor no demuestra que el código no se ejecutó.

## Cómo se generan los eventos

`App\Support\ClinicalLog` escribe mediante la fachada de Laravel en el canal por defecto. Filtra claves y valores de contexto; no recibe mensajes libres de negocio. Los servicios declaran nombres estables como `auth.login.success` y `reception.appointment.reserved`.

`ClinicalTransaction::run` mantiene las operaciones y reglas existentes, y recibe argumentos opcionales de observabilidad. Emite INFO después de que la transacción dueña finaliza correctamente. Si hay rollback o error de commit, no emite éxito. Los rechazos de dominio 4xx producen `<evento>.rejected` en WARNING; los fallos 5xx o inesperados producen `<evento>.failed` en ERROR. Las consultas y vistas previas no emiten éxitos de mutación.

Los servicios de Administración/Secretaría agregan cobertura a errores de sus fronteras `request`. Una WeakMap evita registrar otra vez la misma excepción al atravesar la transacción, el servicio y el manejador de Laravel. No se serializa la excepción.

El callback de reporte de `bootstrap/app.php` sustituye el volcado automático de mensaje/excepción por `app.exception.failed` con contexto seguro. Se conservan las exclusiones nativas y `ShouldntReport`; no se cambiaron las clases de excepción ni sus respuestas. Los errores de dominio capturados se registran expresamente en los puntos instrumentados. Los siete WARNING originales siguen presentes con sus mensajes y contextos originales.

Para notificaciones y reintentos, `ClinicalLog::afterCommit` difiere el INFO si hay una transacción exterior. Un rollback exterior descarta el evento. Los nuevos logs no hacen escrituras adicionales en `bitacora` ni modifican triggers.

## Correo y QR

`notification.mail.transport_accepted` significa que el transporte devolvió una aceptación no nula y que la actualización transaccional de estado terminó correctamente. **No significa entrega al buzón ni lectura por el paciente**. En las pruebas el transporte es array y el mensaje es sintético; no se contactó un proveedor real.

`notification.mail.failed` describe un fallo recuperable de procesamiento con clase del error; conserva el manejo/reintento existente. La advertencia exterior de procesamiento QR y la advertencia del controlador de renderización se conservaron. No se agregaron logs al generador PNG ni a cada validación interna de OAuth/OTP.

`auth.password_reset.mail_failed` hace visible el rechazo del transporte que el flujo de recuperación puede absorber manteniendo su respuesta neutral. El WARNING de correo OTP ya existía y no se duplicó.

## Contexto permitido y protección

Los nuevos eventos permiten únicamente:

- Identificadores técnicos con prefijos controlados: actor, entidad, cita anterior y notificación.
- Rol validado, acción de una lista cerrada y transporte conocido.
- Código de error controlado, estado HTTP, SQLSTATE saneado cuando procede y nombre válido de la clase del error.

No se admiten nombres, correos, documentos, teléfonos, contraseñas/hashes, OTP, cookies, tokens OAuth/sesión/recuperación/QR, cabeceras, cuerpos HTTP, SQL/bindings, motivos, diagnósticos, tipos de análisis, resultados ni objetos completos. Los identificadores técnicos también requieren acceso restringido.

`storage/logs/.gitignore` excluye todo salvo su propio `.gitignore`; `frontend_clinica/.gitignore` excluye `*.log` y `.env`. Se verificó la exclusión del archivo diario. La carpeta documental raíz `logs/` debe seguir conteniendo solo Markdown y no tiene una exclusión general propia para registros de ejecución.

Los logs están fuera de `frontend_clinica/public/` y no hay una ruta propia de descarga de logs. No se cambió el servidor HTTP ni se certificaron sus aliases/ACL de producción. La raíz pública debe seguir siendo `frontend_clinica/public`.

Si el destino de observabilidad falla, los nuevos logs no cambian el resultado de la operación clínica. Puede perderse el evento; la supervisión externa de disco, ACL y disponibilidad sigue siendo necesaria y no forma parte de esta implementación.

## Consulta desde PowerShell

Desde la raíz del repositorio:

~~~powershell
Get-ChildItem .\frontend_clinica\storage\logs -Filter 'laravel-????-??-??.log' |
    Sort-Object Name -Descending | Select-Object Name, Length, LastWriteTime

$latestLog = Get-ChildItem .\frontend_clinica\storage\logs -Filter 'laravel-????-??-??.log' |
    Sort-Object Name -Descending | Select-Object -First 1
if ($latestLog) {
    Get-Content -LiteralPath $latestLog.FullName -Tail 0 -Wait
}
~~~

El archivo se crea al primer evento real admitido por el umbral. `Ctrl+C` detiene la observación. Inspeccionar contenido únicamente en una terminal privada y revisar/redactar cualquier captura antes de compartirla. La guía de evidencias incluye una salida limitada a prefijos de nivel y las pruebas aisladas.

## Validación y límites

Revalidación del **21 de noviembre de 2026, Fase 4**: Unit **24 pruebas / 187 aserciones**; Feature **432 / 3.327**; conjunto **456 / 3.514**, también aprobado en orden aleatorio con semilla 20261121. Cero fallos, errores u omisiones. Las **74 pruebas específicas de logging / 1.075 aserciones** pertenecen a Feature y no se suman otra vez. Las 3.308 aserciones de Feature correspondían a Fase 2, antes de fortalecer las expectativas de Fase 3.

Se verificó rotación física en una carpeta temporal, sin limpiar la carpeta real. También se inspeccionó un archivo diario real que ya existía antes de esta auditoría, mostrando solo prefijos de eventos y sin copiar valores de contexto. No se ejecutaron suites PostgreSQL ni operaciones sobre usuarios/citas reales, ni se certificó SMTP real o entrega al buzón.

El [informe final de QA](AUDITORIA_FINAL.md) distingue cumplimiento comprobado y límites. La [guía de capturas](GUIA_CAPTURAS.md) ofrece rutas exactas y comandos PowerShell sin generar registros de demostración artificiales.

`admin.block.updated` queda **NO APLICA** porque no hay método/ruta actual para actualizar bloqueos. La auditoría SQL puede conservar motivos clínicos: ese riesgo previo permanece y se documenta, sin modificar esquema, triggers o datos. Los detalles y límites del histórico están en [EVIDENCIAS.md](EVIDENCIAS.md).

Esta fase no implementa una fase completa de Mockery ni avanza a la Fase 3.
