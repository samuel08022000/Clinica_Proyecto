# Ajuste de Fase 13 — Welcome clásico y panel de Paciente

## 1. Diagnóstico y referencia

Se ejecutaron `git status --short`, `git log -1 --oneline` y la revisión del diff antes de modificar. El último commit continúa siendo `b16283b` (Integrar y validar paneles de administracion y secretaria). La Fase 13 sigue sin confirmar, con sus cambios y los documentos personales previamente eliminados; no se restauraron archivos indiscriminadamente.

Se compararon `welcome.blade.php`, `PortalController.php`, `PortalFrontendTest.php`, rutas y vista de Paciente con HEAD. La Fase 13 había sustituido las tarjetas «Reservas web» y «Recordatorio 24 h antes» por «Reservas en recepción» y «Acceso protegido», cambiado indicadores de progreso por «Secretaría/Por rol», y reescrito la invitación al registro y el mensaje del catálogo público.

**El panel de Paciente no había sido eliminado.** `resources/views/dashboard/home.blade.php` y las rutas ROL001 permanecían iguales al commit. Sus módulos futuros se anunciaban mediante la nota existente; no había una grilla adicional de reservas, pagos o historial que hubiera que recuperar.

## 2–3. Restauración selectiva del welcome

Se recuperaron las cuatro tarjetas clásicas y su intención informativa:

| Tarjeta | Estado mostrado | Alcance real |
|---|---|---|
| Reservas web | En desarrollo | El motor y la reserva en recepción existen; el acceso desde el panel del paciente sigue pendiente. |
| Acreditación con QR | Disponible | Check-in para personal autorizado de Secretaría. No se ofrece una operación de paciente ficticia. |
| Recordatorio 24 h antes | Próximamente | Se conserva la referencia a la cola PostgreSQL; el texto aclara que aún no se envían recordatorios automáticos. |
| Consulta de citas | En desarrollo | La consulta administrativa existe en recepción; su interfaz para paciente continúa pendiente. |

Se recuperó la invitación a ingresar o crear cuenta, explicando el estado de la reserva web. El botón clásico «Reservar cita» sigue conduciendo al login y ahora se relaciona mediante `aria-describedby` con la explicación de disponibilidad. El catálogo no conectado vuelve a comunicar su estado de desarrollo, sin afirmar que no existen los endpoints administrativos del backend.

Se conservaron composición, título «Tus citas, en orden», identidad configurable, imágenes, paleta, tipografía, ventana ilustrativa D..D+14, secciones Inicio/Especialidades/Servicios/Contacto, acceso por roles, footer y navegación responsive. No se reemplazó el welcome ni se modificaron CSS o JavaScript en este ajuste. La ventana mantiene su advertencia: no representa cupos disponibles.

## 4–5. Paciente y rutas ROL001

La vista y las rutas de Paciente se conservaron **sin cambios**, con comprobación contra HEAD y contra la captura de archivos al inicio del ajuste.

| Ruta | Nombre | Protección y comportamiento |
|---|---|---|
| `GET /paciente/inicio` | `paciente.home` | `rol:ROL001`, sesión local y autorización remota Quarkus; renderiza `dashboard.home`. |
| `POST /login` | `login.attempt` | El login ROL001 redirige a `paciente.home`; se mantiene la validación existente. |
| `POST /logout` | `logout` | Se conserva el cierre local y la solicitud de revocación remota. |
| `GET /` | `home` | Portal público disponible sin autenticación. |

El panel mantiene layout compartido `layouts.app`, marca y enlace al inicio, botón de cierre con CSRF, nombre/correo/rol/estado de la cuenta obtenidos de la sesión validada y la nota «Los módulos operativos de este perfil se implementarán en las siguientes fases». No se sustituyó por una página vacía ni se incorporaron estadísticas o información clínica inventadas.

Las nuevas pruebas verifican invitado, identidad del paciente, navegación y estado futuro, denegación a ROL002/003/004, rol local falsificado frente al rol remoto, revocación y caída del autorizador. Una caída devuelve 503 y no presenta el panel como una operación exitosa.

## 6–8. Funciones reales, futuras y simulaciones excluidas

Se conservan login, autorización, consulta de la identidad conectada, navegación pública y cierre de sesión. Las reservas web del paciente, su consulta de citas y los recordatorios permanecen visibles como planificación, con límites explícitos. QR y reservas/consulta en recepción se describen con su rol operativo real.

No se restauraron accesos demo, credenciales, generadores de contraseñas, `/hello`, controladores vacíos ni simulaciones de éxito. No se añadieron endpoints, confirmaciones de reservas, pagos, historiales o notificaciones ficticias. B9, las migraciones históricas, DBeaver, Docker, aislamiento de pruebas, Google OAuth, OTP y los paneles de Administrador/Secretaría no se modificaron.

Este documento corrige únicamente el alcance visual descrito en el [informe original de Fase 13](fase13-auditoria.md); aquel se conserva como evidencia de la intervención anterior.

## 9. Archivos de este ajuste

Modificados respecto al estado recibido:

- `frontend_clinica/resources/views/welcome.blade.php`: contenido clásico y estados honestos.
- `frontend_clinica/app/Http/Controllers/PortalController.php`: mensaje de catálogo público pendiente.
- `frontend_clinica/tests/Feature/PortalFrontendTest.php`: conservación de secciones, navegación, tarjetas futuras y exclusión de demo.

Creados:

- `frontend_clinica/tests/Feature/PatientPanelTest.php`: regresión específica de Paciente y ROL001.
- `docs/fase13-ajuste-portal-paciente.md`: este informe.

Se compararon huellas de los archivos existentes antes y después del trabajo: no hay cambios ajenos a esos tres archivos modificados. La vista `dashboard/home.blade.php`, rutas, migraciones, `.env.example` y demás mejoras previas conservan su contenido recibido. No se editaron archivos `.env` ni configuraciones de asistentes.

## 10. Verificaciones

| Verificación | Resultado |
|---|---|
| Portal + autenticación + Paciente + Administración + Secretaría | 219 pruebas aprobadas, 1.480 aserciones. |
| Portal y Paciente específicos | 14 pruebas aprobadas, 78 aserciones. |
| `php artisan test --compact` | 264 casos: 252 aprobados, 12 Live omitidos; 1.653 aserciones. Sin fallos. |
| `php artisan route:list --except-vendor --json` | Ejecutado; rutas pública y ROL001 conservadas. |
| `php artisan view:cache` | Aprobado. |
| `npm.cmd run build` | Aprobado; aviso opcional de fontaine sin impedir compilación. |
| Laravel Pint sobre los tres PHP modificados/creados | Aprobado. |
| `git diff --check` | Sin errores. |
| Chrome, 1440/768/390 px | 28 comprobaciones aprobadas: desbordamiento, recursos, títulos, estados, menú móvil/Escape y ausencia de excepciones JS. Capturas revisadas. |

Las pruebas Laravel emplean los dobles HTTP ya aislados. No se ejecutaron las suites Live porque requieren servicios y fixtures; no se arrancó PostgreSQL ni se modificó ninguna base. La revisión responsive utilizó los Blade reales renderizados a HTML de QA, con una identidad sintética marcada para la captura del panel; no representa un login real ni datos clínicos persistidos. El HTML de QA y sus capturas quedan en `backend_clinica/target/fase13-ajuste`, ignorado por Git, sin rutas nuevas en la aplicación. El servidor temporal y Chrome se cierran al finalizar.

Se aplicaron las comprobaciones pertinentes de las skills locales design-taste-frontend y ui-ux-pro-max para conservar identidad, claridad de estados y responsive. No se generó una nueva dirección visual: la instrucción del usuario de mantener el diseño clásico prevalece.

## 11. Estado final de Git

Sin ramas nuevas, staging, commit, push ni reset. Permanecen los cambios anteriores de Fase 13, las seis eliminaciones académicas previas y los dos archivos previamente sin seguimiento. Este ajuste solo añade las diferencias y los dos archivos indicados arriba.

```text
 M .env.docker.example
 D "Analisis de Requerimientos Clinica.docx"
 D Documento_de_Requisitos_Sistema_Gestion_Citas_Medicas.docx
 D "EVALUACION CONTINUA.pdf"
 D "HISTORIAS DE USUARIO CLINICA (1).xlsx"
 D "PROYECTO CLINICA.docx"
 M README.md
 M backend_clinica/.env.example
 M backend_clinica/.gitignore
 M backend_clinica/README.md
 M backend_clinica/db/dev-migration/README.md
 M backend_clinica/docs/gmail-smtp.md
 M backend_clinica/docs/integracion-fase9.md
 M backend_clinica/docs/inventario-api-fase9.md
 D backend_clinica/scripts/dev/V3__corregir_credenciales_usuarios_demo.sql.template
 D backend_clinica/scripts/dev/prepare-demo-credentials.ps1
 D backend_clinica/scripts/dev/prepare_demo_credentials.php
 D backend_clinica/scripts/dev/verify-demo-logins.ps1
 D backend_clinica/src/main/java/org/clinica/GreetingResource.java
 D backend_clinica/src/main/java/org/clinica/controller/AtencionCheckinController.java
 M backend_clinica/src/main/java/org/clinica/controller/AuthController.java
 D backend_clinica/src/main/java/org/clinica/controller/EspecialidadController.java
 D backend_clinica/src/main/java/org/clinica/controller/IncidenciaNotificacionController.java
 D backend_clinica/src/main/java/org/clinica/controller/ReservaCitaController.java
 D backend_clinica/src/main/java/org/clinica/repository/AuditoriaRepository.java
 D backend_clinica/src/main/java/org/clinica/service/IncidenciaNotificacionService.java
 M backend_clinica/src/main/resources/application.properties
 D backend_clinica/src/main/resources/import.sql
 D backend_clinica/src/test/java/org/clinica/GreetingResourceIT.java
 D backend_clinica/src/test/java/org/clinica/GreetingResourceTest.java
 M backend_clinica/src/test/java/org/clinica/service/AgendaMigrationUpgradeTest.java
 M backend_clinica/src/test/resources/integration-fase12-integrity.sql
 D "diccionario de datos.docx"
 M docker-compose.yml
 M docs/ejecucion-integracion.md
 M frontend_clinica/.env.example
 M frontend_clinica/README.md
 D frontend_clinica/app/Http/Controllers/AgendaHorarioController.php
 D frontend_clinica/app/Http/Controllers/AtencionCheckinController.php
 M frontend_clinica/app/Http/Controllers/AuthController.php
 D frontend_clinica/app/Http/Controllers/EspecialidadController.php
 D frontend_clinica/app/Http/Controllers/IncidenciaNotificacionController.php
 D frontend_clinica/app/Http/Controllers/PersonalController.php
 M frontend_clinica/app/Http/Controllers/PortalController.php
 D frontend_clinica/app/Http/Controllers/ReservaCitaController.php
 M frontend_clinica/composer.json
 M frontend_clinica/config/clinic.php
 M frontend_clinica/database/seeders/DatabaseSeeder.php
 M frontend_clinica/docs/fase12-validacion.md
 M frontend_clinica/phpunit.xml
 M frontend_clinica/resources/css/app.css
 M frontend_clinica/resources/js/app.js
 M frontend_clinica/resources/views/auth/login.blade.php
 D frontend_clinica/resources/views/pacientes.blade.php
 M frontend_clinica/resources/views/welcome.blade.php
 M frontend_clinica/tests/Feature/AuthFlowTest.php
 D frontend_clinica/tests/Feature/DemoLoginIntegrationTest.php
 D frontend_clinica/tests/Feature/ExampleTest.php
 M frontend_clinica/tests/Feature/GoogleAndPasswordRecoveryFlowTest.php
 M frontend_clinica/tests/Feature/PortalFrontendTest.php
 M frontend_clinica/tests/Feature/RegistrationOtpFlowTest.php
 D frontend_clinica/tests/Unit/ExampleTest.php
 M scripts/integration/Audit-Database.ps1
 M scripts/integration/Invoke-Verification.ps1
 M scripts/integration/Start-Applications.ps1
 M scripts/integration/browser-e2e.mjs
 M scripts/integration/session-expiry.mjs
?? "EVALUACION PROCESUAL.pdf"
?? backend_clinica/src/main/java/org/clinica/controller/DevelopmentAuthController.java
?? backend_clinica/src/main/resources/db/migration/B9__instalacion_sin_datos_demo.sql
?? backend_clinica/src/main/resources/db/migration/README.md
?? backend_clinica/src/test/java/org/clinica/RuntimeSecurityIT.java
?? backend_clinica/src/test/java/org/clinica/service/FlywayCompatibilityTest.java
?? backend_clinica/src/test/java/org/clinica/service/HistoricalMigrations.java
?? docs/configuracion-dbeaver.md
?? docs/fase13-ajuste-portal-paciente.md
?? docs/fase13-auditoria.md
?? docs/fase13-inventario.md
?? frontend_clinica/docs/fase10-administracion.md
?? frontend_clinica/tests/Feature/PatientPanelTest.php
```
