# Auditoría final de Mockery y logs — Fase 4

**Fecha:** 21 de noviembre de 2026, America/La_Paz. **Alcance:** verificación del árbol de trabajo actual, sin implementar funcionalidades ni reescribir pruebas. Aplicación: `frontend_clinica/`.

La evidencia primaria es el código/configuración cargados y las nuevas ejecuciones PHPUnit. No se dieron por vigentes los resultados anteriores. Versiones comprobadas: PHP 8.5.10, Laravel 13.33.0, PHPUnit 12.5.35 y Mockery 1.6.15.

## A. Estado final de logs

**CUMPLE para el stack principal y los eventos propios revisados.**

| Comprobación | Evidencia actual |
|---|---|
| Configuración activa | Kernel CLI: default=stack, integrantes=[daily], level=debug, max_files=14, configuración sin caché. |
| Handler real | `Monolog\Handler\RotatingFileHandler` resuelto hacia `frontend_clinica/storage/logs/laravel-2026-11-21.log`. Consultar el handler no emitió un mensaje. |
| Retención/umbral | `LOG_DAILY_DAYS=14` y `LOG_LEVEL`; catorce archivos diarios, no necesariamente catorce días naturales sin actividad. |
| Archivo real | Existía al tomar la instantánea inicial: 214 bytes y dos INFO, auth.logout.success y auth.login.success. Solo se examinaron prefijos y nombres de claves; no se copiaron valores ni se atribuyeron esas operaciones a esta auditoría. |
| Eventos | **31 nombres explícitos en 40 puntos de servicios**. Cruce con inventario: cero faltantes y cero nombres documentados sin emisor. Se excluyeron vistas/rutas y prefijos del helper del conteo. |
| WARNING originales | Siete llamadas conservadas: AuthMailService 1, GoogleOidcIdentityProvider 4, AppointmentNotificationService 1 y AppointmentController de Paciente 1. |
| Confirmación | ClinicalTransaction::run emite INFO después de transaction; rollback/commit fallido no emite éxito. afterCommit difiere avisos/reintentos hasta commit exterior y los descarta con rollback. |
| Duplicación | WeakMap evita repetir la misma instancia de excepción entre transacción, servicio y reporter. Los casos cubiertos exigen un solo fallo sin INFO; dos intentos distintos pueden generar eventos legítimos separados. |
| Correo | transport_accepted requiere aceptación no nula y confirmación transaccional; no significa entrega al buzón. |

Se revisaron config/logging.php, ClinicalLog, ClinicalTransaction, emisores, bootstrap/app.php e inventario. [source-audit.json](../.local/clinical-audit/phase4-20261121/source-audit.json) permite comprobar nombres y ubicaciones. Las variantes dinámicas de rechazo/fallo se documentan aparte de los 31 nombres base.

**Canal browser — CUMPLE PARCIALMENTE.** El arranque completo agrega un canal de Laravel Boost: single a storage/logs/browser.log. Su opción days=14 no produce rotación en single y no pasa por ClinicalLog. Boost registra `POST /_boost/browser-logs`, acepta datos del navegador y guarda URL/user-agent, excluyendo su middleware CSRF. Es una entrada de escritura, no una ruta de lectura/descarga. Boost se desactiva en runningUnitTests, pero está activo en el entorno local observado. La política del stack principal no debe presentarse como protección global de ese canal. No se cambió su configuración.

## B. Estado final de Mockery

**CUMPLE en el alcance de aislamiento e interacciones existente.** Se inspeccionaron los once PHP de tests/Unit y tests/Support/MockeryIsolationTestCase.php.

- Veinte métodos expanden **24 casos**: 20 usan colaboradores Mockery directos o creados por el helper, uno un mock de fachada Mail y tres principalmente fakes especializados y espías.
- Los servicios/controladores bajo prueba son reales. Se simulan estado de cuenta, RateLimiter, guard, contratos y notificaciones, sin sustituir métodos privados del sujeto ni usar bypass de final, alias/overload.
- once/with verifican argumentos/cantidad; andThrow provoca errores; shouldNotReceive impide conexión, actor, guard o envío tras un rechazo; globally()->ordered() comprueba coordinación.
- El lifecycle de Laravel suma expectativas y ejecuta Mockery::close, comprobado en `vendor/laravel/framework/src/Illuminate/Foundation/Testing/Concerns/InteractsWithTestCaseLifecycle.php:150,154`. La base nueva conserva ese ciclo.
- La base exige testing, configura clinical como SQLite :memory:, activa Mail::fake/Log::spy y preventStrayRequests. Las nuevas respuestas HTTP usan URLs específicas.
- Las suites normales y el conjunto aleatorio con semilla 20261121 pasaron. No se observó dependencia del orden; una semilla no prueba todas las permutaciones posibles.

| Prueba / área | Límite concreto de representatividad |
|---|---|
| AuthenticationServiceMockeryTest, cuatro casos | Eloquent real sobre tabla mínima SQLite más colaboradores simulados. Pruebas de componente, no aislamiento absoluto ni validación PostgreSQL. |
| AdministrationServiceMockeryTest | Rechazo previo por identidad; no demuestra altas/ediciones SQL de personal, especialidades, horarios o bloqueos. |
| MedicalServicesMockeryTest / PatientAppointmentServiceMockeryTest | Entradas protegidas y validación previa, no propiedad, concurrencia o transiciones completas en base. |
| AppointmentOrchestrationMockeryTest | Controlador real y colaboradores simulados: prueba el corte de coordinación, no la lógica del servicio que se simula. |
| AppointmentNotificationServiceMockeryTest | Transporte inválido antes de consumir outbox, no procesamiento completo ni entrega real. |
| Mockery::type('string') en RateLimiter | Tipo, límite, cantidad y espera; no clave exacta del caché. Otro caso exige dos claves distintas sin correo/IP en claro. |
| assertClinicalAccessDenied con evento | WARNING exacto y colaboradores bloqueados; no prohíbe explícitamente todo INFO adicional en esas dos invocaciones del helper. Las pruebas generales de logging cubren ausencia de éxitos falsos en sus escenarios. Límite de cobertura, no fallo observado. |

No se detectó una justificación para cambiar código o pruebas. No se agregaron casos para aumentar el total.

## C. Resultados reales de pruebas

Orden de ejecución: Unit, Feature, logging específico y conjunto aleatorio.

| Ejecución | Pruebas | Aserciones | Aprobadas | Fallidas | Errores | Omitidas | Informe |
|---|---:|---:|---:|---:|---:|---:|---|
| Unit / Mockery específico | 24 | 187 | 24 | 0 | 0 | 0 | [unit.xml](../.local/clinical-audit/phase4-20261121/unit.xml) |
| Feature | 432 | 3.327 | 432 | 0 | 0 | 0 | [feature.xml](../.local/clinical-audit/phase4-20261121/feature.xml) |
| Logging, subconjunto de Feature | 74 | 1.075 | 74 | 0 | 0 | 0 | [logging.xml](../.local/clinical-audit/phase4-20261121/logging.xml) |
| Conjunto aleatorio, semilla 20261121 | **456** | **3.514** | **456** | **0** | **0** | **0** | [combined-random.xml](../.local/clinical-audit/phase4-20261121/combined-random.xml) |

**Total único: 456 casos y 3.514 aserciones.** No sumar ejecuciones individuales ni el subconjunto de 74. Mockery específico son los mismos 24 Unit, no otra suite.

Se usó LOG_CHANNEL con el nombre literal del canal null solo en los procesos de prueba para proteger históricos. Los comandos completos, con ubicación y restauración de la variable, están en [GUIA_CAPTURAS.md](GUIA_CAPTURAS.md). Los cuatro JUnit son nuevos informes privados, no copias de logs reales.

Otras verificaciones: **194 PHP** analizados sintácticamente sin errores; Pint --test aprobado en los **30 PHP** de implementación de logging/pruebas nuevas de Fases 2–3; git diff --check sin errores en los caminos revisados. No se afirma que Pint revisara todo el repositorio. phpunit.xml configura solo Unit y Feature; tests/Postgres no se seleccionó.

## D. Seguridad y protección de información

| Control | Comprobación | Límite |
|---|---|---|
| Nuevos eventos | Filtro de claves/tipos/formatos; contextos con códigos técnicos, rol, acción y categorías. Pruebas de privacidad aprobadas. | No controla terceros ni futuros emisores que omitan el helper. Los códigos técnicos requieren acceso restringido. |
| WARNING originales | No pasan contraseñas, OTP o tokens; clases de error y códigos Google acotados. | Son llamadas directas a Log; su seguridad depende de conservar ese contexto. |
| Reporte automático | Callback suprime volcado original de mensaje/SQL/bindings/stacktrace; ShouldntReport sigue intacto. | Otros logs del servidor/canales no quedan cubiertos; se pierde detalle de diagnóstico. |
| Secretos configurados | Comparación en memoria de 5 valores no vacíos de al menos 8 caracteres contra 42 archivos de ejecución y documentación: cero coincidencias exactas, sin revelar valores. | No cubre todas las contraseñas de usuarios, codificaciones o formatos desconocidos. |
| Indicadores históricos | Sin firmas detectadas de Bearer/JWT, URLs largas de recuperación/QR/callback con código, secretos de proveedor, bcrypt, OTP HTML o resultado/motivo JSON en los archivos examinados. | Búsquedas acotadas, no certificación de ausencia total. Hay 18 coincidencias de SQL en laravel.log histórico, sin publicar cuerpos o atribuirlas a esta fase. |
| Git | Reglas para storage/logs, *.log y .env verificadas; git ls-files solo listó su .gitignore y ningún log. QA privado bajo .local/clinical-audit también ignorado. | No se auditó todo el historial Git; logs raíz es documental y no debe recibir registros reales. |
| Navegador | Logs fuera de public, sin public/storage ni ruta propia de descarga; .htaccess contiene Options -Indexes bajo su condición. | No se verificaron DocumentRoot, aliases, proxy ni denegación HTTP de producción. La ruta Boost detectada escribe, no descarga. |
| ACL | Permisos heredados leídos; no se cambiaron. Hay propietario/SYSTEM/administradores con control y otros SID/grupo con Modify. | No se certificó identidad/acceso efectivo de todos los SID ni mínimo privilegio en producción. |
| Documentación | Dominios .invalid y datos sintéticos, sin valores de credenciales reales en las comprobaciones. | Revisar también el resto del editor/terminal antes de capturar. |

[Indicadores agregados](../.local/clinical-audit/phase4-20261121/security-indicators.json) y [comparación sin valores secretos](../.local/clinical-audit/phase4-20261121/secret-comparison.json).

No se consultó ni alteró PostgreSQL principal. Solo se corrigió documentación y se generaron informes privados. La comparación final de 270 archivos iniciales confirmó **223 archivos de código/configuración/pruebas/SQL sin cambios** y **43 archivos de storage/logs sin cambios**, incluido el diario real; no aparecieron nuevos archivos de ejecución. Los únicos cuatro archivos iniciales cambiados son los Markdown revisados. Se añadieron este informe y la guía, sin limpiar históricos. Véase [preservation.json](../.local/clinical-audit/phase4-20261121/preservation.json).

Los seis Markdown resultantes pasaron la validación de UTF-8, bloques de código, enlaces y rutas. Los ocho bloques PowerShell de la guía se analizaron sintácticamente sin errores, sin ejecutar de nuevo sus pruebas para fabricar capturas.

## E. Problemas encontrados

1. **browser omite retención diaria y filtro propios.** Se comprobó registro dinámico y ruta de desarrollo. Se aclaró en documentación, sin cambiar comportamiento.
2. **README mostraba 3.308 aserciones antiguas como validación final.** Se corrigió a 3.327 actuales y se conservaron los resultados históricos de Fase 2 identificados como tales.
3. **Unit necesita clasificación precisa.** Se aclaró que no son 24 mocks directos ni 24 unidades sin persistencia, y se documentaron límites de matchers y cobertura.
4. **Auditoría SQL previa:** la definición de auditar_fila elimina secretos/resultado/archivo, pero conserva motivo_derivacion y otros motivos libres. El visor filtra presentación, no lo almacenado. No se comprobó instalación/contenido PostgreSQL ni se modificó SQL.
5. **Destino indisponible:** ClinicalLog absorbe fallos del logger y puede perder eventos para conservar la operación clínica. Falta supervisión externa de espacio/destino/ACL.

No se reescribieron pruebas para ocultar estos límites o mejorar resultados.

## F. Limitaciones pendientes

| Tipo | Comprobado | Pendiente |
|---|---|---|
| Mocks/fakes | Interacciones, argumentos, orden, cortes y errores controlados; correo/HTTP interceptados. | Proveedores reales y conectividad del despliegue. |
| SQLite | Repositorio mínimo de cuatro Unit y commit exterior/rollback de logging en memoria. | Equivalencia PostgreSQL, funciones, triggers y locks. |
| Dobles manuales | Servicios reales y momento de INFO/errores en logging. | Validez SQL, persistencia y concurrencia reales. |
| PostgreSQL | Lectura del código y consumidores; ninguna suite de integración ejecutada. | Base desechable autorizada, no principal. |
| Manual | Guía y archivo diario real disponible. | Cuatro roles, lector QR/dispositivos y correlación integral de una operación. |
| Producción | Ubicación/rutas y permisos locales revisados estáticamente. | Denegación HTTP real, raíz/aliases, mínimo privilegio, herramientas de desarrollo, alertas, retención y respaldos. |

La relación operación/nombre de evento está probada por código y pruebas de componente. Las dos entradas reales no se atribuyen a una operación ejecutada aquí, a un usuario determinado o a un recorrido integral PostgreSQL. No hay request_id global propio comprobado.

## G. Guía de capturas

[GUIA_CAPTURAS.md](GUIA_CAPTURAS.md): trece capturas con nombre, archivo/pantalla, ruta exacta, parte a mostrar, explicación y comandos cuando corresponden. Combina expectativas Mockery para evitar imágenes repetidas. No se fabricaron capturas ni eventos.

## H. Cumplimiento de requisitos

| Requisito | Estado | Evidencia |
|---|---|---|
| Rotación/retención del canal principal | **CUMPLE** | Handler daily activo, max_files=14 y prueba física nativa aprobada. |
| Nivel configurable | **CUMPLE** | LOG_LEVEL y filtro DEBUG/INFO verificados. |
| 31 eventos y siete WARNING | **CUMPLE** | Cruce de nombres, 40 puntos y siete llamadas originales. |
| Commit y deduplicación | **CUMPLE** | Código y 74 casos de logging, en los escenarios cubiertos. |
| Archivo real con evidencia segura | **CUMPLE** | Diario preexistente de 214 bytes; prefijos de dos INFO sin valores privados. |
| Seguridad de nuevos contextos | **CUMPLE** | Filtro, contextos acotados y pruebas de exclusión aprobadas. |
| Protección global de canales/históricos | **CUMPLE PARCIALMENTE** | browser no cubierto, histórico con SQL y límites de búsquedas. |
| Exclusiones Git actuales | **CUMPLE** | Reglas y ausencia de registros versionados comprobadas. |
| HTTP/ACL de producción | **CUMPLE PARCIALMENTE** | Controles estáticos; despliegue/acceso efectivo sin certificar. |
| Mockery como aislamiento en PHP | **CUMPLE** | 1.6.15 instalado, colaboradores simulados, sujetos reales. |
| Verificación de expectativas | **CUMPLE** | Lifecycle con close y 24 Unit aprobados sin omisiones. |
| Independencia observada del orden | **CUMPLE** | Normal y aleatorio semilla 20261121 aprobados; alcance limitado a esos órdenes. |
| Ejecución y conteos sin duplicación | **CUMPLE** | Total único 456 / 3.514, cuatro informes actuales. |
| Integración PostgreSQL/SMTP/QR real | **PENDIENTE** | No se ejecutó integración ni recorrido del despliegue. |
| Validación integral en producción | **CUMPLE PARCIALMENTE** | Componentes/Feature aprobados; pendientes anteriores. |
| Guía para el docente | **CUMPLE** | Trece capturas propuestas con rutas y comandos. |
| Imágenes y demostración manual integral | **PENDIENTE** | Guía entregada; no se fabricaron imágenes ni operaciones. |
| Auditoría sin cambios funcionales | **CUMPLE** | Solo documentación e informes privados; hashes de código/pruebas/configuración comprobados. |

La aprobación de suites no equivale a certificación integral de PostgreSQL, producción o entrega de correo. No se instaló nada ni se hizo commit, push o cambio de rama; no se avanza automáticamente.
