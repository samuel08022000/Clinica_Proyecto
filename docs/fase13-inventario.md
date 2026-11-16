# Inventario de auditoría de Fase 13

Revisión de archivos versionados existentes, referencias y candidatos por contenido. Los nombres y coincidencias se usan para localizar; no deciden por sí solos una eliminación. No incluye documentos personales ausentes, dependencias vendor/node_modules, artefactos target ni configuraciones de asistentes. Las migraciones nuevas y los cambios finales están en el informe y la guía Flyway.

| Archivo revisado | Clasificación | Coincidencias candidatas |
|---|---|---:|
| `backend_clinica/src/main/java/org/clinica/controller/DevelopmentAuthController.java` | A/B: funcional/configuración | 15 |
| `backend_clinica/src/main/resources/db/migration/B9__instalacion_sin_datos_demo.sql` | A/B: funcional/configuración | 12 |
| `backend_clinica/src/main/resources/db/migration/README.md` | B: documentación | 41 |
| `backend_clinica/src/test/java/org/clinica/RuntimeSecurityIT.java` | D: pruebas aisladas | 3 |
| `backend_clinica/src/test/java/org/clinica/service/FlywayCompatibilityTest.java` | D: pruebas aisladas | 7 |
| `backend_clinica/src/test/java/org/clinica/service/HistoricalMigrations.java` | D: pruebas aisladas | 0 |
| `docs/configuracion-dbeaver.md` | B: documentación | 3 |
| `docs/fase13-auditoria.md` | B: documentación | 69 |
| `docs/fase13-inventario.md` | B: documentación | 73 |
| `frontend_clinica/docs/fase10-administracion.md` | B: documentación | 38 |
| `.env.docker.example` | A/B: funcional/configuración | 0 |
| `.gitignore` | A/B: funcional/configuración | 0 |
| `.vscode/settings.json` | A/B: funcional/configuración | 0 |
| `README.md` | B: documentación | 4 |
| `backend_clinica/.dockerignore` | A/B: funcional/configuración | 0 |
| `backend_clinica/.env.example` | A/B: funcional/configuración | 2 |
| `backend_clinica/.gitignore` | A/B: funcional/configuración | 0 |
| `backend_clinica/.mvn/wrapper/maven-wrapper.properties` | A/B: funcional/configuración | 0 |
| `backend_clinica/README.md` | B: documentación | 6 |
| `backend_clinica/db/dev-migration/README.md` | B: documentación | 4 |
| `backend_clinica/docs/cancelaciones-inasistencias-historial.md` | B: documentación | 15 |
| `backend_clinica/docs/gmail-smtp.md` | B: documentación | 3 |
| `backend_clinica/docs/google-oauth.md` | B: documentación | 8 |
| `backend_clinica/docs/integracion-fase9.md` | B: documentación | 45 |
| `backend_clinica/docs/inventario-api-fase9.md` | B: documentación | 19 |
| `backend_clinica/docs/qr-checkin.md` | B: documentación | 10 |
| `backend_clinica/docs/sanciones-temporales.md` | B: documentación | 14 |
| `backend_clinica/mvnw` | A/B: funcional/configuración | 0 |
| `backend_clinica/mvnw.cmd` | A/B: funcional/configuración | 0 |
| `backend_clinica/pom.xml` | A/B: funcional/configuración | 0 |
| `backend_clinica/scripts/dev/read-demo-otp.ps1` | B: herramienta dev opt-in | 6 |
| `backend_clinica/scripts/dev/read-demo-password-reset.ps1` | B: herramienta dev opt-in | 5 |
| `backend_clinica/src/main/docker/Dockerfile.jvm` | A/B: funcional/configuración | 0 |
| `backend_clinica/src/main/docker/Dockerfile.legacy-jar` | A/B: funcional/configuración | 0 |
| `backend_clinica/src/main/docker/Dockerfile.native` | A/B: funcional/configuración | 0 |
| `backend_clinica/src/main/docker/Dockerfile.native-micro` | A/B: funcional/configuración | 0 |
| `backend_clinica/src/main/java/org/clinica/config/AccesoAdministrativo.java` | A/B: funcional/configuración | 0 |
| `backend_clinica/src/main/java/org/clinica/config/AuthExceptionMapper.java` | A/B: funcional/configuración | 0 |
| `backend_clinica/src/main/java/org/clinica/config/BearerToken.java` | A/B: funcional/configuración | 0 |
| `backend_clinica/src/main/java/org/clinica/config/DbActorHelper.java` | A/B: funcional/configuración | 2 |
| `backend_clinica/src/main/java/org/clinica/config/TriggerExceptionMapper.java` | A/B: funcional/configuración | 0 |
| `backend_clinica/src/main/java/org/clinica/controller/AgendaHorarioController.java` | A/B: funcional/configuración | 0 |
| `backend_clinica/src/main/java/org/clinica/controller/AuthController.java` | A/B: funcional/configuración | 0 |
| `backend_clinica/src/main/java/org/clinica/controller/PersonalController.java` | A/B: funcional/configuración | 0 |
| `backend_clinica/src/main/java/org/clinica/controller/SancionController.java` | A/B: funcional/configuración | 0 |
| `backend_clinica/src/main/java/org/clinica/controller/SecretariaController.java` | A/B: funcional/configuración | 0 |
| `backend_clinica/src/main/java/org/clinica/dto/AuthDtos.java` | A/B: funcional/configuración | 4 |
| `backend_clinica/src/main/java/org/clinica/dto/agenda/AgendaDtos.java` | A/B: funcional/configuración | 0 |
| `backend_clinica/src/main/java/org/clinica/dto/personal/ActualizarMedicoDTO.java` | A/B: funcional/configuración | 0 |
| `backend_clinica/src/main/java/org/clinica/dto/personal/CambiarEstadoDTO.java` | A/B: funcional/configuración | 0 |
| `backend_clinica/src/main/java/org/clinica/dto/personal/CrearMedicoDTO.java` | A/B: funcional/configuración | 0 |
| `backend_clinica/src/main/java/org/clinica/dto/personal/EspecialidadDetalleDTO.java` | A/B: funcional/configuración | 0 |
| `backend_clinica/src/main/java/org/clinica/dto/personal/EspecialidadOpcionDTO.java` | A/B: funcional/configuración | 0 |
| `backend_clinica/src/main/java/org/clinica/dto/personal/EspecialidadSolicitudes.java` | A/B: funcional/configuración | 0 |
| `backend_clinica/src/main/java/org/clinica/dto/personal/MedicoRespuestaDTO.java` | A/B: funcional/configuración | 0 |
| `backend_clinica/src/main/java/org/clinica/dto/personal/SecretariaRespuestaDTO.java` | A/B: funcional/configuración | 0 |
| `backend_clinica/src/main/java/org/clinica/dto/personal/SecretariaSolicitudes.java` | A/B: funcional/configuración | 0 |
| `backend_clinica/src/main/java/org/clinica/dto/personal/SolicitudConCamposPermitidos.java` | A/B: funcional/configuración | 0 |
| `backend_clinica/src/main/java/org/clinica/dto/recepcion/RecepcionDtos.java` | A/B: funcional/configuración | 0 |
| `backend_clinica/src/main/java/org/clinica/dto/sancion/SancionDtos.java` | A/B: funcional/configuración | 0 |
| `backend_clinica/src/main/java/org/clinica/entity/Analisis.java` | A/B: funcional/configuración | 0 |
| `backend_clinica/src/main/java/org/clinica/entity/Bitacora.java` | A/B: funcional/configuración | 0 |
| `backend_clinica/src/main/java/org/clinica/entity/BloqueoAgenda.java` | A/B: funcional/configuración | 0 |
| `backend_clinica/src/main/java/org/clinica/entity/Cita.java` | A/B: funcional/configuración | 0 |
| `backend_clinica/src/main/java/org/clinica/entity/Derivacion.java` | A/B: funcional/configuración | 0 |
| `backend_clinica/src/main/java/org/clinica/entity/Especialidad.java` | A/B: funcional/configuración | 0 |
| `backend_clinica/src/main/java/org/clinica/entity/Horario.java` | A/B: funcional/configuración | 0 |
| `backend_clinica/src/main/java/org/clinica/entity/IncidenciaSancion.java` | A/B: funcional/configuración | 0 |
| `backend_clinica/src/main/java/org/clinica/entity/Medico.java` | A/B: funcional/configuración | 0 |
| `backend_clinica/src/main/java/org/clinica/entity/MedicoEspecialidad.java` | A/B: funcional/configuración | 0 |
| `backend_clinica/src/main/java/org/clinica/entity/MedicoEspecialidadId.java` | A/B: funcional/configuración | 0 |
| `backend_clinica/src/main/java/org/clinica/entity/Notificacion.java` | A/B: funcional/configuración | 0 |
| `backend_clinica/src/main/java/org/clinica/entity/Paciente.java` | A/B: funcional/configuración | 0 |
| `backend_clinica/src/main/java/org/clinica/entity/Rol.java` | A/B: funcional/configuración | 0 |
| `backend_clinica/src/main/java/org/clinica/entity/Secretaria.java` | A/B: funcional/configuración | 0 |
| `backend_clinica/src/main/java/org/clinica/entity/SlotAgenda.java` | A/B: funcional/configuración | 0 |
| `backend_clinica/src/main/java/org/clinica/entity/Usuario.java` | A/B: funcional/configuración | 0 |
| `backend_clinica/src/main/java/org/clinica/entity/UsuarioIdentidadExterna.java` | A/B: funcional/configuración | 0 |
| `backend_clinica/src/main/java/org/clinica/entity/VistaAgendaDisponibilidad.java` | A/B: funcional/configuración | 0 |
| `backend_clinica/src/main/java/org/clinica/entity/VistaHistorialCitas.java` | A/B: funcional/configuración | 0 |
| `backend_clinica/src/main/java/org/clinica/repository/AgendaRepository.java` | A/B: funcional/configuración | 1 |
| `backend_clinica/src/main/java/org/clinica/repository/AuthAccountGateway.java` | A/B: funcional/configuración | 0 |
| `backend_clinica/src/main/java/org/clinica/repository/CitaFunctionRepository.java` | A/B: funcional/configuración | 2 |
| `backend_clinica/src/main/java/org/clinica/repository/CitaRepository.java` | A/B: funcional/configuración | 0 |
| `backend_clinica/src/main/java/org/clinica/repository/EspecialidadRepository.java` | A/B: funcional/configuración | 0 |
| `backend_clinica/src/main/java/org/clinica/repository/GoogleAccountGateway.java` | A/B: funcional/configuración | 0 |
| `backend_clinica/src/main/java/org/clinica/repository/IncidenciaRepository.java` | A/B: funcional/configuración | 0 |
| `backend_clinica/src/main/java/org/clinica/repository/PacienteRepository.java` | A/B: funcional/configuración | 1 |
| `backend_clinica/src/main/java/org/clinica/repository/PatientRegistrationGateway.java` | A/B: funcional/configuración | 0 |
| `backend_clinica/src/main/java/org/clinica/repository/PersonalRepository.java` | A/B: funcional/configuración | 0 |
| `backend_clinica/src/main/java/org/clinica/repository/UsuarioRepository.java` | A/B: funcional/configuración | 0 |
| `backend_clinica/src/main/java/org/clinica/scheduler/ClinicaDaemonScheduler.java` | A/B: funcional/configuración | 0 |
| `backend_clinica/src/main/java/org/clinica/service/AgendaHorarioService.java` | A/B: funcional/configuración | 0 |
| `backend_clinica/src/main/java/org/clinica/service/AtencionCheckinService.java` | A/B: funcional/configuración | 0 |
| `backend_clinica/src/main/java/org/clinica/service/AuthException.java` | A/B: funcional/configuración | 0 |
| `backend_clinica/src/main/java/org/clinica/service/AuthService.java` | A/B: funcional/configuración | 7 |
| `backend_clinica/src/main/java/org/clinica/service/AuthSessionStore.java` | A/B: funcional/configuración | 0 |
| `backend_clinica/src/main/java/org/clinica/service/ConfiguredOtpDeliveryProvider.java` | A/B: funcional/configuración | 31 |
| `backend_clinica/src/main/java/org/clinica/service/DemoOtpDeliveryProvider.java` | A/B: funcional/configuración | 64 |
| `backend_clinica/src/main/java/org/clinica/service/EspecialidadService.java` | A/B: funcional/configuración | 1 |
| `backend_clinica/src/main/java/org/clinica/service/GestionCitaService.java` | A/B: funcional/configuración | 0 |
| `backend_clinica/src/main/java/org/clinica/service/GoogleAuthService.java` | A/B: funcional/configuración | 0 |
| `backend_clinica/src/main/java/org/clinica/service/GoogleContinuationStore.java` | A/B: funcional/configuración | 0 |
| `backend_clinica/src/main/java/org/clinica/service/GoogleIdentityProvider.java` | A/B: funcional/configuración | 0 |
| `backend_clinica/src/main/java/org/clinica/service/GoogleOidcProvider.java` | A/B: funcional/configuración | 0 |
| `backend_clinica/src/main/java/org/clinica/service/LoginAttemptGuard.java` | A/B: funcional/configuración | 0 |
| `backend_clinica/src/main/java/org/clinica/service/OtpDeliveryProvider.java` | A/B: funcional/configuración | 0 |
| `backend_clinica/src/main/java/org/clinica/service/PasswordRecoveryService.java` | A/B: funcional/configuración | 0 |
| `backend_clinica/src/main/java/org/clinica/service/PatientRegistrationService.java` | A/B: funcional/configuración | 4 |
| `backend_clinica/src/main/java/org/clinica/service/PersonalService.java` | A/B: funcional/configuración | 0 |
| `backend_clinica/src/main/java/org/clinica/service/RecepcionService.java` | A/B: funcional/configuración | 0 |
| `backend_clinica/src/main/java/org/clinica/service/RegistrationChallengeStore.java` | A/B: funcional/configuración | 0 |
| `backend_clinica/src/main/java/org/clinica/service/ReservaCitaService.java` | A/B: funcional/configuración | 0 |
| `backend_clinica/src/main/java/org/clinica/service/SancionService.java` | A/B: funcional/configuración | 1 |
| `backend_clinica/src/main/java/org/clinica/service/SecretariaService.java` | A/B: funcional/configuración | 0 |
| `backend_clinica/src/main/java/org/clinica/service/SmtpOtpDeliveryProvider.java` | A/B: funcional/configuración | 8 |
| `backend_clinica/src/main/resources/application.properties` | A/B: funcional/configuración | 7 |
| `backend_clinica/src/main/resources/db/migration/R__verificacion_email_otp.sql` | A/B: funcional/configuración | 1 |
| `backend_clinica/src/main/resources/db/migration/V1__control_citas_postgresql_completo.sql` | A/B: funcional/configuración | 5 |
| `backend_clinica/src/main/resources/db/migration/V2__seed_usuarios_prueba.sql` | C: demo histórico conservado | 1 |
| `backend_clinica/src/main/resources/db/migration/V4__identidades_google.sql` | A/B: funcional/configuración | 0 |
| `backend_clinica/src/main/resources/db/migration/V5__administracion_agendas_consistentes.sql` | A/B: funcional/configuración | 0 |
| `backend_clinica/src/main/resources/db/migration/V6__reservas_recepcion_errores_y_habilitacion.sql` | A/B: funcional/configuración | 0 |
| `backend_clinica/src/main/resources/db/migration/V7__checkin_qr_validacion_y_concurrencia.sql` | A/B: funcional/configuración | 0 |
| `backend_clinica/src/main/resources/db/migration/V8__cancelaciones_inasistencias_recepcion.sql` | A/B: funcional/configuración | 0 |
| `backend_clinica/src/main/resources/db/migration/V9__sanciones_temporales_consistentes.sql` | A/B: funcional/configuración | 5 |
| `backend_clinica/src/test/java/org/clinica/integration/IsolatedIntegrationFixture.java` | D: pruebas aisladas | 22 |
| `backend_clinica/src/test/java/org/clinica/integration/Phase12IntegrationFixture.java` | D: pruebas aisladas | 35 |
| `backend_clinica/src/test/java/org/clinica/repository/GoogleAccountGatewayPersistenceTest.java` | D: pruebas aisladas | 0 |
| `backend_clinica/src/test/java/org/clinica/scheduler/DaemonTestAccess.java` | D: pruebas aisladas | 1 |
| `backend_clinica/src/test/java/org/clinica/service/AgendaAdministrationTest.java` | D: pruebas aisladas | 11 |
| `backend_clinica/src/test/java/org/clinica/service/AgendaMigrationUpgradeTest.java` | D: pruebas aisladas | 1 |
| `backend_clinica/src/test/java/org/clinica/service/AuthServiceTest.java` | D: pruebas aisladas | 5 |
| `backend_clinica/src/test/java/org/clinica/service/CheckinTest.java` | D: pruebas aisladas | 100 |
| `backend_clinica/src/test/java/org/clinica/service/DemoOtpDeliveryProviderTest.java` | D: pruebas aisladas | 13 |
| `backend_clinica/src/test/java/org/clinica/service/EspecialidadAdministrationTest.java` | D: pruebas aisladas | 12 |
| `backend_clinica/src/test/java/org/clinica/service/GestionCitaTest.java` | D: pruebas aisladas | 68 |
| `backend_clinica/src/test/java/org/clinica/service/GoogleAuthServiceTest.java` | D: pruebas aisladas | 15 |
| `backend_clinica/src/test/java/org/clinica/service/GoogleContinuationStoreTest.java` | D: pruebas aisladas | 0 |
| `backend_clinica/src/test/java/org/clinica/service/GoogleOidcProviderTest.java` | D: pruebas aisladas | 0 |
| `backend_clinica/src/test/java/org/clinica/service/PasswordRecoveryPersistenceTest.java` | D: pruebas aisladas | 11 |
| `backend_clinica/src/test/java/org/clinica/service/PasswordRecoveryServiceTest.java` | D: pruebas aisladas | 15 |
| `backend_clinica/src/test/java/org/clinica/service/PatientRegistrationServiceTest.java` | D: pruebas aisladas | 11 |
| `backend_clinica/src/test/java/org/clinica/service/PersonalAuthorizationTest.java` | D: pruebas aisladas | 15 |
| `backend_clinica/src/test/java/org/clinica/service/PersonalTestFixtures.java` | D: pruebas aisladas | 3 |
| `backend_clinica/src/test/java/org/clinica/service/RecepcionTest.java` | D: pruebas aisladas | 9 |
| `backend_clinica/src/test/java/org/clinica/service/SancionTest.java` | D: pruebas aisladas | 10 |
| `backend_clinica/src/test/java/org/clinica/service/SecretariaAdministrationTest.java` | D: pruebas aisladas | 14 |
| `backend_clinica/src/test/java/org/clinica/service/SmtpOtpDeliveryProviderTest.java` | D: pruebas aisladas | 9 |
| `backend_clinica/src/test/resources/META-INF/microprofile-config.properties` | D: pruebas aisladas | 0 |
| `backend_clinica/src/test/resources/integration-fase12-integrity.sql` | D: pruebas aisladas | 2 |
| `backend_clinica/src/test/resources/integration-fase9-integrity.sql` | D: pruebas aisladas | 0 |
| `compose.integration.yml` | D: pruebas aisladas | 0 |
| `docker-compose.yml` | A/B: funcional/configuración | 0 |
| `docs/ejecucion-integracion.md` | B: documentación | 26 |
| `frontend_clinica/.editorconfig` | A/B: funcional/configuración | 0 |
| `frontend_clinica/.env.example` | A/B: funcional/configuración | 1 |
| `frontend_clinica/.gitattributes` | A/B: funcional/configuración | 0 |
| `frontend_clinica/.gitignore` | A/B: funcional/configuración | 0 |
| `frontend_clinica/.npmrc` | A/B: funcional/configuración | 0 |
| `frontend_clinica/README.md` | B: documentación | 6 |
| `frontend_clinica/app/Exceptions/QuarkusApiException.php` | A/B: funcional/configuración | 0 |
| `frontend_clinica/app/Http/Controllers/Administracion/AdminController.php` | A/B: funcional/configuración | 0 |
| `frontend_clinica/app/Http/Controllers/Administracion/AgendaController.php` | A/B: funcional/configuración | 0 |
| `frontend_clinica/app/Http/Controllers/Administracion/BloqueoController.php` | A/B: funcional/configuración | 0 |
| `frontend_clinica/app/Http/Controllers/Administracion/DashboardController.php` | A/B: funcional/configuración | 0 |
| `frontend_clinica/app/Http/Controllers/Administracion/EspecialidadController.php` | A/B: funcional/configuración | 2 |
| `frontend_clinica/app/Http/Controllers/Administracion/MedicoController.php` | A/B: funcional/configuración | 0 |
| `frontend_clinica/app/Http/Controllers/Administracion/PacienteController.php` | A/B: funcional/configuración | 1 |
| `frontend_clinica/app/Http/Controllers/Administracion/PersonalController.php` | A/B: funcional/configuración | 0 |
| `frontend_clinica/app/Http/Controllers/Administracion/SancionController.php` | A/B: funcional/configuración | 0 |
| `frontend_clinica/app/Http/Controllers/Administracion/SecretariaController.php` | A/B: funcional/configuración | 0 |
| `frontend_clinica/app/Http/Controllers/AuthController.php` | A/B: funcional/configuración | 8 |
| `frontend_clinica/app/Http/Controllers/Controller.php` | A/B: funcional/configuración | 0 |
| `frontend_clinica/app/Http/Controllers/PortalController.php` | A/B: funcional/configuración | 0 |
| `frontend_clinica/app/Http/Controllers/Secretaria/CheckinController.php` | A/B: funcional/configuración | 2 |
| `frontend_clinica/app/Http/Controllers/Secretaria/CitaController.php` | A/B: funcional/configuración | 0 |
| `frontend_clinica/app/Http/Controllers/Secretaria/DashboardController.php` | A/B: funcional/configuración | 0 |
| `frontend_clinica/app/Http/Controllers/Secretaria/HistorialController.php` | A/B: funcional/configuración | 0 |
| `frontend_clinica/app/Http/Controllers/Secretaria/PacienteController.php` | A/B: funcional/configuración | 0 |
| `frontend_clinica/app/Http/Controllers/Secretaria/ReceptionController.php` | A/B: funcional/configuración | 0 |
| `frontend_clinica/app/Http/Controllers/Secretaria/ReservaController.php` | A/B: funcional/configuración | 0 |
| `frontend_clinica/app/Http/Middleware/AdministrativeResponses.php` | A/B: funcional/configuración | 0 |
| `frontend_clinica/app/Http/Middleware/CheckRolSession.php` | A/B: funcional/configuración | 0 |
| `frontend_clinica/app/Http/Middleware/ReceptionResponses.php` | A/B: funcional/configuración | 0 |
| `frontend_clinica/app/Http/Requests/Administracion/AdminRequest.php` | A/B: funcional/configuración | 0 |
| `frontend_clinica/app/Http/Requests/Administracion/BloqueoRequest.php` | A/B: funcional/configuración | 0 |
| `frontend_clinica/app/Http/Requests/Administracion/DuracionRequest.php` | A/B: funcional/configuración | 0 |
| `frontend_clinica/app/Http/Requests/Administracion/EspecialidadRequest.php` | A/B: funcional/configuración | 0 |
| `frontend_clinica/app/Http/Requests/Administracion/EstadoRequest.php` | A/B: funcional/configuración | 0 |
| `frontend_clinica/app/Http/Requests/Administracion/HorarioRequest.php` | A/B: funcional/configuración | 0 |
| `frontend_clinica/app/Http/Requests/Administracion/PasswordRequest.php` | A/B: funcional/configuración | 0 |
| `frontend_clinica/app/Http/Requests/Administracion/PersonalRequest.php` | A/B: funcional/configuración | 0 |
| `frontend_clinica/app/Http/Requests/Administracion/SancionRequest.php` | A/B: funcional/configuración | 0 |
| `frontend_clinica/app/Http/Requests/Secretaria/AppointmentActionRequest.php` | A/B: funcional/configuración | 0 |
| `frontend_clinica/app/Http/Requests/Secretaria/AppointmentFiltersRequest.php` | A/B: funcional/configuración | 0 |
| `frontend_clinica/app/Http/Requests/Secretaria/CheckinRequest.php` | A/B: funcional/configuración | 0 |
| `frontend_clinica/app/Http/Requests/Secretaria/PatientSearchRequest.php` | A/B: funcional/configuración | 0 |
| `frontend_clinica/app/Http/Requests/Secretaria/ReceptionRequest.php` | A/B: funcional/configuración | 0 |
| `frontend_clinica/app/Http/Requests/Secretaria/ReservationBrowseRequest.php` | A/B: funcional/configuración | 0 |
| `frontend_clinica/app/Http/Requests/Secretaria/ReservationRequest.php` | A/B: funcional/configuración | 0 |
| `frontend_clinica/app/Models/User.php` | A/B: funcional/configuración | 0 |
| `frontend_clinica/app/Providers/AppServiceProvider.php` | A/B: funcional/configuración | 0 |
| `frontend_clinica/app/Services/QuarkusApiClient.php` | A/B: funcional/configuración | 1 |
| `frontend_clinica/app/Support/AppointmentPresentation.php` | A/B: funcional/configuración | 0 |
| `frontend_clinica/artisan` | A/B: funcional/configuración | 0 |
| `frontend_clinica/bootstrap/app.php` | A/B: funcional/configuración | 0 |
| `frontend_clinica/bootstrap/cache/.gitignore` | A/B: funcional/configuración | 0 |
| `frontend_clinica/bootstrap/providers.php` | A/B: funcional/configuración | 0 |
| `frontend_clinica/composer.json` | A/B: funcional/configuración | 6 |
| `frontend_clinica/composer.lock` | A/B: funcional/configuración | 87 |
| `frontend_clinica/config/app.php` | A/B: funcional/configuración | 2 |
| `frontend_clinica/config/auth.php` | A/B: funcional/configuración | 0 |
| `frontend_clinica/config/cache.php` | A/B: funcional/configuración | 0 |
| `frontend_clinica/config/clinic.php` | A/B: funcional/configuración | 0 |
| `frontend_clinica/config/database.php` | A/B: funcional/configuración | 0 |
| `frontend_clinica/config/filesystems.php` | A/B: funcional/configuración | 0 |
| `frontend_clinica/config/logging.php` | A/B: funcional/configuración | 6 |
| `frontend_clinica/config/mail.php` | A/B: funcional/configuración | 0 |
| `frontend_clinica/config/queue.php` | A/B: funcional/configuración | 0 |
| `frontend_clinica/config/services.php` | A/B: funcional/configuración | 0 |
| `frontend_clinica/config/session.php` | A/B: funcional/configuración | 0 |
| `frontend_clinica/database/.gitignore` | A/B: funcional/configuración | 0 |
| `frontend_clinica/database/factories/UserFactory.php` | A/B: funcional/configuración | 2 |
| `frontend_clinica/database/migrations/0001_01_01_000000_create_users_table.php` | A/B: funcional/configuración | 0 |
| `frontend_clinica/database/migrations/0001_01_01_000001_create_cache_table.php` | A/B: funcional/configuración | 0 |
| `frontend_clinica/database/migrations/0001_01_01_000002_create_jobs_table.php` | A/B: funcional/configuración | 0 |
| `frontend_clinica/database/seeders/DatabaseSeeder.php` | A/B: funcional/configuración | 7 |
| `frontend_clinica/docs/fase11-secretaria.md` | B: documentación | 27 |
| `frontend_clinica/docs/fase12-validacion.md` | B: documentación | 61 |
| `frontend_clinica/package-lock.json` | A/B: funcional/configuración | 1 |
| `frontend_clinica/package.json` | A/B: funcional/configuración | 0 |
| `frontend_clinica/phpunit.xml` | A/B: funcional/configuración | 0 |
| `frontend_clinica/public/.htaccess` | A/B: funcional/configuración | 0 |
| `frontend_clinica/public/index.php` | A/B: funcional/configuración | 0 |
| `frontend_clinica/public/robots.txt` | A/B: funcional/configuración | 0 |
| `frontend_clinica/resources/css/administracion.css` | A/B: funcional/configuración | 1 |
| `frontend_clinica/resources/css/app.css` | A/B: funcional/configuración | 1 |
| `frontend_clinica/resources/css/secretaria.css` | A/B: funcional/configuración | 0 |
| `frontend_clinica/resources/js/administracion.js` | A/B: funcional/configuración | 0 |
| `frontend_clinica/resources/js/app.js` | A/B: funcional/configuración | 0 |
| `frontend_clinica/resources/js/secretaria.js` | A/B: funcional/configuración | 0 |
| `frontend_clinica/resources/views/administracion/agendas/form.blade.php` | A/B: funcional/configuración | 0 |
| `frontend_clinica/resources/views/administracion/agendas/index.blade.php` | A/B: funcional/configuración | 0 |
| `frontend_clinica/resources/views/administracion/bloqueos/form.blade.php` | A/B: funcional/configuración | 0 |
| `frontend_clinica/resources/views/administracion/bloqueos/index.blade.php` | A/B: funcional/configuración | 0 |
| `frontend_clinica/resources/views/administracion/bloqueos/show.blade.php` | A/B: funcional/configuración | 0 |
| `frontend_clinica/resources/views/administracion/dashboard.blade.php` | A/B: funcional/configuración | 1 |
| `frontend_clinica/resources/views/administracion/error.blade.php` | A/B: funcional/configuración | 0 |
| `frontend_clinica/resources/views/administracion/especialidades/form.blade.php` | A/B: funcional/configuración | 0 |
| `frontend_clinica/resources/views/administracion/especialidades/index.blade.php` | A/B: funcional/configuración | 3 |
| `frontend_clinica/resources/views/administracion/especialidades/show.blade.php` | A/B: funcional/configuración | 0 |
| `frontend_clinica/resources/views/administracion/pacientes/incidencias.blade.php` | A/B: funcional/configuración | 0 |
| `frontend_clinica/resources/views/administracion/pacientes/index.blade.php` | A/B: funcional/configuración | 6 |
| `frontend_clinica/resources/views/administracion/pacientes/show.blade.php` | A/B: funcional/configuración | 1 |
| `frontend_clinica/resources/views/administracion/partials/brand.blade.php` | A/B: funcional/configuración | 0 |
| `frontend_clinica/resources/views/administracion/partials/doctor-selector.blade.php` | A/B: funcional/configuración | 0 |
| `frontend_clinica/resources/views/administracion/partials/navigation.blade.php` | A/B: funcional/configuración | 0 |
| `frontend_clinica/resources/views/administracion/personal/form.blade.php` | A/B: funcional/configuración | 0 |
| `frontend_clinica/resources/views/administracion/personal/index.blade.php` | A/B: funcional/configuración | 2 |
| `frontend_clinica/resources/views/administracion/personal/password.blade.php` | A/B: funcional/configuración | 0 |
| `frontend_clinica/resources/views/administracion/personal/show.blade.php` | A/B: funcional/configuración | 0 |
| `frontend_clinica/resources/views/administracion/sanciones/form.blade.php` | A/B: funcional/configuración | 6 |
| `frontend_clinica/resources/views/administracion/sanciones/show.blade.php` | A/B: funcional/configuración | 0 |
| `frontend_clinica/resources/views/auth/forgot-password.blade.php` | A/B: funcional/configuración | 1 |
| `frontend_clinica/resources/views/auth/google-register.blade.php` | A/B: funcional/configuración | 0 |
| `frontend_clinica/resources/views/auth/login.blade.php` | A/B: funcional/configuración | 0 |
| `frontend_clinica/resources/views/auth/register.blade.php` | A/B: funcional/configuración | 0 |
| `frontend_clinica/resources/views/auth/reset-password.blade.php` | A/B: funcional/configuración | 0 |
| `frontend_clinica/resources/views/auth/verify-email.blade.php` | A/B: funcional/configuración | 3 |
| `frontend_clinica/resources/views/components/admin/alerts.blade.php` | A/B: funcional/configuración | 0 |
| `frontend_clinica/resources/views/components/admin/badge.blade.php` | A/B: funcional/configuración | 1 |
| `frontend_clinica/resources/views/components/admin/button.blade.php` | A/B: funcional/configuración | 0 |
| `frontend_clinica/resources/views/components/admin/confirmation.blade.php` | A/B: funcional/configuración | 0 |
| `frontend_clinica/resources/views/components/admin/empty.blade.php` | A/B: funcional/configuración | 1 |
| `frontend_clinica/resources/views/components/admin/field.blade.php` | A/B: funcional/configuración | 0 |
| `frontend_clinica/resources/views/components/admin/form.blade.php` | A/B: funcional/configuración | 0 |
| `frontend_clinica/resources/views/components/admin/heading.blade.php` | A/B: funcional/configuración | 0 |
| `frontend_clinica/resources/views/components/admin/icon.blade.php` | A/B: funcional/configuración | 0 |
| `frontend_clinica/resources/views/components/admin/notice.blade.php` | A/B: funcional/configuración | 0 |
| `frontend_clinica/resources/views/components/admin/pagination.blade.php` | A/B: funcional/configuración | 0 |
| `frontend_clinica/resources/views/components/admin/state.blade.php` | A/B: funcional/configuración | 0 |
| `frontend_clinica/resources/views/components/admin/table.blade.php` | A/B: funcional/configuración | 0 |
| `frontend_clinica/resources/views/components/appointment-status.blade.php` | A/B: funcional/configuración | 0 |
| `frontend_clinica/resources/views/components/appointment-summary.blade.php` | A/B: funcional/configuración | 0 |
| `frontend_clinica/resources/views/components/appointments-table.blade.php` | A/B: funcional/configuración | 1 |
| `frontend_clinica/resources/views/components/patient-results.blade.php` | A/B: funcional/configuración | 1 |
| `frontend_clinica/resources/views/components/workspace-brand.blade.php` | A/B: funcional/configuración | 0 |
| `frontend_clinica/resources/views/components/workspace.blade.php` | A/B: funcional/configuración | 0 |
| `frontend_clinica/resources/views/dashboard/home.blade.php` | A/B: funcional/configuración | 0 |
| `frontend_clinica/resources/views/layouts/administracion.blade.php` | A/B: funcional/configuración | 0 |
| `frontend_clinica/resources/views/layouts/alerts.blade.php` | A/B: funcional/configuración | 0 |
| `frontend_clinica/resources/views/layouts/app.blade.php` | A/B: funcional/configuración | 0 |
| `frontend_clinica/resources/views/layouts/secretaria.blade.php` | A/B: funcional/configuración | 0 |
| `frontend_clinica/resources/views/secretaria/checkin/accreditation.blade.php` | A/B: funcional/configuración | 0 |
| `frontend_clinica/resources/views/secretaria/checkin/attendance.blade.php` | A/B: funcional/configuración | 0 |
| `frontend_clinica/resources/views/secretaria/checkin/index.blade.php` | A/B: funcional/configuración | 0 |
| `frontend_clinica/resources/views/secretaria/citas/action.blade.php` | A/B: funcional/configuración | 0 |
| `frontend_clinica/resources/views/secretaria/citas/index.blade.php` | A/B: funcional/configuración | 0 |
| `frontend_clinica/resources/views/secretaria/citas/show.blade.php` | A/B: funcional/configuración | 0 |
| `frontend_clinica/resources/views/secretaria/dashboard.blade.php` | A/B: funcional/configuración | 0 |
| `frontend_clinica/resources/views/secretaria/error.blade.php` | A/B: funcional/configuración | 0 |
| `frontend_clinica/resources/views/secretaria/historial/show.blade.php` | A/B: funcional/configuración | 0 |
| `frontend_clinica/resources/views/secretaria/pacientes/index.blade.php` | A/B: funcional/configuración | 0 |
| `frontend_clinica/resources/views/secretaria/pacientes/show.blade.php` | A/B: funcional/configuración | 0 |
| `frontend_clinica/resources/views/secretaria/partials/appointment-filters.blade.php` | A/B: funcional/configuración | 1 |
| `frontend_clinica/resources/views/secretaria/partials/navigation.blade.php` | A/B: funcional/configuración | 0 |
| `frontend_clinica/resources/views/secretaria/reservas/confirm.blade.php` | A/B: funcional/configuración | 0 |
| `frontend_clinica/resources/views/secretaria/reservas/create.blade.php` | A/B: funcional/configuración | 1 |
| `frontend_clinica/resources/views/welcome.blade.php` | A/B: funcional/configuración | 0 |
| `frontend_clinica/routes/console.php` | A/B: funcional/configuración | 0 |
| `frontend_clinica/routes/web.php` | A/B: funcional/configuración | 0 |
| `frontend_clinica/storage/app/.gitignore` | A/B: funcional/configuración | 0 |
| `frontend_clinica/storage/app/private/.gitignore` | A/B: funcional/configuración | 0 |
| `frontend_clinica/storage/app/public/.gitignore` | A/B: funcional/configuración | 0 |
| `frontend_clinica/storage/framework/.gitignore` | A/B: funcional/configuración | 0 |
| `frontend_clinica/storage/framework/cache/.gitignore` | A/B: funcional/configuración | 0 |
| `frontend_clinica/storage/framework/cache/data/.gitignore` | A/B: funcional/configuración | 0 |
| `frontend_clinica/storage/framework/sessions/.gitignore` | A/B: funcional/configuración | 0 |
| `frontend_clinica/storage/framework/testing/.gitignore` | A/B: funcional/configuración | 0 |
| `frontend_clinica/storage/framework/views/.gitignore` | A/B: funcional/configuración | 0 |
| `frontend_clinica/storage/logs/.gitignore` | A/B: funcional/configuración | 0 |
| `frontend_clinica/tests/Feature/AdministracionPanelTest.php` | D: pruebas aisladas | 5 |
| `frontend_clinica/tests/Feature/ApiResilienceTest.php` | D: pruebas aisladas | 5 |
| `frontend_clinica/tests/Feature/AuthFlowTest.php` | D: pruebas aisladas | 10 |
| `frontend_clinica/tests/Feature/GoogleAndPasswordRecoveryFlowTest.php` | D: pruebas aisladas | 7 |
| `frontend_clinica/tests/Feature/LiveBackendIntegrationTest.php` | D: pruebas aisladas | 47 |
| `frontend_clinica/tests/Feature/LiveHttpStackTest.php` | D: pruebas aisladas | 12 |
| `frontend_clinica/tests/Feature/PortalFrontendTest.php` | D: pruebas aisladas | 4 |
| `frontend_clinica/tests/Feature/RegistrationOtpFlowTest.php` | D: pruebas aisladas | 22 |
| `frontend_clinica/tests/Feature/SecretariaPanelTest.php` | D: pruebas aisladas | 4 |
| `frontend_clinica/tests/Integration/router.php` | D: pruebas aisladas | 0 |
| `frontend_clinica/tests/Support/AdministrativeFixtures.php` | D: pruebas aisladas | 3 |
| `frontend_clinica/tests/Support/ReceptionFixtures.php` | D: pruebas aisladas | 1 |
| `frontend_clinica/tests/TestCase.php` | D: pruebas aisladas | 0 |
| `frontend_clinica/vite.config.js` | A/B: funcional/configuración | 0 |
| `scripts/integration/Audit-Database.ps1` | D: pruebas aisladas | 14 |
| `scripts/integration/Invoke-Verification.ps1` | D: pruebas aisladas | 2 |
| `scripts/integration/New-BrowserDataset.ps1` | D: pruebas aisladas | 4 |
| `scripts/integration/New-LegacyDataset.ps1` | D: pruebas aisladas | 2 |
| `scripts/integration/Start-Applications.ps1` | D: pruebas aisladas | 13 |
| `scripts/integration/Start-Database.ps1` | D: pruebas aisladas | 2 |
| `scripts/integration/Stop-Applications.ps1` | D: pruebas aisladas | 0 |
| `scripts/integration/Stop-Database.ps1` | D: pruebas aisladas | 0 |
| `scripts/integration/Write-Fixtures.ps1` | D: pruebas aisladas | 5 |
| `scripts/integration/browser-client.mjs` | D: pruebas aisladas | 1 |
| `scripts/integration/browser-e2e.mjs` | D: pruebas aisladas | 80 |
| `scripts/integration/contract-http.mjs` | D: pruebas aisladas | 18 |
| `scripts/integration/fault-e2e.mjs` | D: pruebas aisladas | 9 |
| `scripts/integration/session-expiry.mjs` | D: pruebas aisladas | 10 |
| `scripts/integration/visual-audit.mjs` | D: pruebas aisladas | 20 |

## Candidatos sin referencia textual externa al nombre de archivo

No equivale a falta de uso: JPA, CDI, PSR-4, nombres de vista, rutas y descubrimiento de tests pueden resolverlos dinámicamente. Se conservan las entidades mapeadas, tests y componentes cuando existe ese contrato; los esqueletos vacíos se retiraron después de verificar rutas y consumidores.

- `backend_clinica/src/main/java/org/clinica/controller/DevelopmentAuthController.java` — @IfBuildProfile, @Path, @Consumes, @Produces
- `backend_clinica/src/test/java/org/clinica/RuntimeSecurityIT.java` — @QuarkusIntegrationTest, @Test
- `backend_clinica/src/test/java/org/clinica/service/FlywayCompatibilityTest.java` — @QuarkusTest, @Inject, @ConfigProperty, @ConfigProperty
- `backend_clinica/src/main/java/org/clinica/config/AuthExceptionMapper.java` — @Provider, @Override
- `backend_clinica/src/main/java/org/clinica/controller/AgendaHorarioController.java` — @Path, @Produces, @Consumes, @Inject
- `backend_clinica/src/main/java/org/clinica/entity/Analisis.java` — @code, @code, @code, @Entity
- `backend_clinica/src/main/java/org/clinica/entity/Notificacion.java` — @code, @code, @Entity, @Table
- `backend_clinica/src/test/java/org/clinica/integration/IsolatedIntegrationFixture.java` — @example
- `backend_clinica/src/test/java/org/clinica/integration/Phase12IntegrationFixture.java` — @example
- `backend_clinica/src/test/java/org/clinica/repository/GoogleAccountGatewayPersistenceTest.java` — @QuarkusTest, @Inject, @Test, @TestTransaction
- `backend_clinica/src/test/java/org/clinica/service/AgendaAdministrationTest.java` — @QuarkusTest, @Inject, @Inject, @Inject
- `backend_clinica/src/test/java/org/clinica/service/AgendaMigrationUpgradeTest.java` — @QuarkusTest, @Inject, @ConfigProperty, @ConfigProperty
- `backend_clinica/src/test/java/org/clinica/service/AuthServiceTest.java` — @BeforeEach, @Test, @CLINICA, @Test
- `backend_clinica/src/test/java/org/clinica/service/CheckinTest.java` — @QuarkusTest, @Inject, @Inject, @Inject
- `backend_clinica/src/test/java/org/clinica/service/DemoOtpDeliveryProviderTest.java` — @Test, @EXAMPLE, @example, @example
- `backend_clinica/src/test/java/org/clinica/service/EspecialidadAdministrationTest.java` — @QuarkusTest, @Inject, @Inject, @Inject
- `backend_clinica/src/test/java/org/clinica/service/GestionCitaTest.java` — @QuarkusTest, @Inject, @Inject, @Inject
- `backend_clinica/src/test/java/org/clinica/service/GoogleAuthServiceTest.java` — @BeforeEach, @Test, @example, @example
- `backend_clinica/src/test/java/org/clinica/service/GoogleContinuationStoreTest.java` — @Test, @example, @Test, @example
- `backend_clinica/src/test/java/org/clinica/service/GoogleOidcProviderTest.java` — @Test, @example, @Test, @Example
- `backend_clinica/src/test/java/org/clinica/service/PasswordRecoveryPersistenceTest.java` — @QuarkusTest, @TestProfile, @Inject, @Inject
- `backend_clinica/src/test/java/org/clinica/service/PasswordRecoveryServiceTest.java` — @BeforeEach, @Test, @example, @example
- `backend_clinica/src/test/java/org/clinica/service/PatientRegistrationServiceTest.java` — @BeforeEach, @Test, @clinica, @Test
- `backend_clinica/src/test/java/org/clinica/service/PersonalAuthorizationTest.java` — @QuarkusTest, @TestProfile, @Execution, @Inject
- `backend_clinica/src/test/java/org/clinica/service/RecepcionTest.java` — @QuarkusTest, @Inject, @Inject, @Inject
- `backend_clinica/src/test/java/org/clinica/service/SancionTest.java` — @QuarkusTest, @Inject, @Inject, @Inject
- `backend_clinica/src/test/java/org/clinica/service/SecretariaAdministrationTest.java` — @QuarkusTest, @Inject, @Inject, @Inject
- `backend_clinica/src/test/java/org/clinica/service/SmtpOtpDeliveryProviderTest.java` — @Test, @gmail, @gmail, @example
