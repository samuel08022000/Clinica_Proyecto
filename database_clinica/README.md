# `database_clinica/` — Esquema oficial PostgreSQL 16 del Sistema de Control de Citas Médicas

Esta carpeta centraliza como **única fuente de verdad** los recursos SQL históricos y activos del sistema clínico sobre **PostgreSQL 16.15** (`America/La_Paz`).

> **Importante:** los archivos SQL de `migration/` y `dev-migration/` conservan íntegros sus nombres, contenido y checksums originales compatibles con `flyway_schema_history`. **No editar ni reescribir migraciones ya aplicadas.**

---

## 1. Estructura de la carpeta

```text
database_clinica/
├── migration/
│   ├── B9__instalacion_sin_datos_demo.sql          # Instalación limpia acumulada (sin datos demo)
│   ├── V10__derivaciones_recepcion_enlazadas.sql   # Evolución vigente sobre B9 / V9
│   ├── R__verificacion_email_otp.sql               # Repetible: verificación OTP / email
│   ├── V1__control_citas_postgresql_completo.sql   # Histórico V1 (solo trazabilidad / bases heredadas)
│   ├── V2__seed_usuarios_prueba.sql                # Histórico V2 (datos demo originales; omitido en B9)
│   ├── V4__identidades_google.sql                  # Histórico V4
│   ├── V5__administracion_agendas_consistentes.sql # Histórico V5
│   ├── V6__reservas_recepcion_errores_y_habilitacion.sql
│   ├── V7__checkin_qr_validacion_y_concurrencia.sql
│   ├── V8__cancelaciones_inasistencias_recepcion.sql
│   ├── V9__sanciones_temporales_consistentes.sql
│   └── README.md                                   # Inventario técnico histórico Flyway
├── dev-migration/
│   ├── V3__corregir_credenciales_usuarios_demo.sql # Solo histórico de desarrollo antiguo; no usar en B9
│   └── README.md
├── .gitignore
└── README.md
```

Los scripts de privilegios incrementales para el rol de ejecución mínima (`clinica_app`) y la automatización PowerShell/PHP residen en `scripts/clinical/`:

- `scripts/clinical/runtime-permissions.sql`
- `scripts/clinical/phase2-permissions.sql`
- `scripts/clinical/phase3-permissions.sql`
- `scripts/clinical/phase4-permissions.sql`
- `scripts/clinical/ClinicalSchemaPure.php`, `Install-Schema.ps1`, `Verify-Database.ps1`, `Initialize-Environment.ps1`, `Start-Database.ps1`, `Stop-Database.ps1`

---

## 2. Arquitectura del esquema clínico (`B9 → V10 → R`)

### Orden oficial para bases nuevas

Para cualquier instalación nueva se ejecuta **exclusivamente**:

1. **`B9__instalacion_sin_datos_demo.sql`** *(SQL_BASELINE versión 9)* — crea toda la estructura completa sin datos demo ni cuentas predefinidas (`usuarios = 0`).
2. **`V10__derivaciones_recepcion_enlazadas.sql`** *(SQL versión 10)* — incorpora la programación enlazada de derivaciones en recepción (`reservar_cita` con `p_cod_der`, `reprogramar_cita`, validación de compatibilidad destino/paciente).
3. **`R__verificacion_email_otp.sql`** *(SQL repetible)* — incorpora columnas/reglas y funciones de OTP y verificación de correo en el registro de pacientes.

> **Advertencia crítica:** **nunca ejecutar `V1`–`V9` automáticamente sobre una base instalada con `B9`**, ni editar `B9`, `V10` o `R` después de aplicadas; el instalador PHP (`ClinicalSchemaPure.php`) valida los checksums CRC32 contra `public.flyway_schema_history`.

### Inventario verificado del esquema (`clinica_laravel`)

- **Tabla de control:** `public.flyway_schema_history` (`3` filas: `B9`, `V10`, `R`).
- **21 tablas clínicas de dominio:**
  - Identidad y seguridad: `roles`, `usuarios`, `pacientes`, `secretarias`, `medicos`, `usuarios_identidades_externas`, `verificaciones_otp`, `tokens_recuperacion`, `configuracion_seguridad`, `bitacora`, `notificaciones`.
  - Agenda y atención médica: `especialidades`, `medico_especialidades`, `horarios`, `bloqueos_agenda`, `bloqueos_citas_afectadas`, `slots_agenda`, `citas`, `derivaciones`, `incidencias_sanciones`, `analisis`.
- **2 vistas:** `v_agenda_disponibilidad`, `v_historial_citas`.
- **42 funciones clínicas SQL / PLpgSQL** *(excluyendo funciones de extensiones `pgcrypto` y `btree_gist`)*, **32 triggers**, **40 claves foráneas**, **80 índices**, **2 restricciones de exclusión** (`ex_citas_medico_sin_solape`, `ex_citas_paciente_sin_solape`) y clave HMAC `QR_HMAC` en `configuracion_seguridad`.

---

## 3. Diferencia entre bases de datos en el entorno

| Base de datos | Motor y puerto | Propósito | Estado / Uso |
|---|---|---|---|
| **Base habitual anterior** (`db_clinica` / contenedor habitual PG 15) | PostgreSQL 15 (`5433` según Compose heredado) | Datos previos conservados fuera de este flujo | **Protegida e intacta**: no se modifica ni se migra automáticamente; no asume importación a Laravel |
| **`clinica_laravel`** (`clinica-laravel-fase1-pg16`) | PostgreSQL 16.15 (`127.0.0.1:35443`) | **Base principal de la aplicación Laravel** (`clinical`) | Instalada con `B9 -> V10 -> R`, `0` usuarios demo |
| **`clinica_laravel_phase1_tests`** (`clinica-laravel-fase1-pg16`) | PostgreSQL 16.15 (`127.0.0.1:35443`) | **Base aislada para pruebas `tests/Postgres`** | Usada por `Invoke-Laravel.ps1 -TestDatabase` |

---

## 4. Instalación y verificación desde `database_clinica/migration/`

Desde la raíz del proyecto en Windows PowerShell:

```powershell
# 1. Generar secretos privados locales si aún no existen (solo la primera vez)
powershell -NoProfile -ExecutionPolicy Bypass -File scripts\clinical\Initialize-Environment.ps1

# 2. Levantar el contenedor PostgreSQL 16 aislado (compose.clinical.yml)
powershell -NoProfile -ExecutionPolicy Bypass -File scripts\clinical\Start-Database.ps1

# 3. Instalar B9 -> V10 -> R y aplicar permisos mínimos para clinica_app
powershell -NoProfile -ExecutionPolicy Bypass -File scripts\clinical\Install-Schema.ps1

# 4. Validar estructura, invariantes y checksums sin modificar datos
powershell -NoProfile -ExecutionPolicy Bypass -File scripts\clinical\Install-Schema.ps1 -ValidateOnly
powershell -NoProfile -ExecutionPolicy Bypass -File scripts\clinical\Verify-Database.ps1
```

Los scripts no utilizan Java ni Maven ni Quarkus, resuelven todas las rutas de forma relativa a la raíz del proyecto y operan con credenciales locales privadas en `.local/clinical-phase1/` (ignoradas por Git).

---

## 5. Cómo añadir futuras migraciones correctamente

1. Crear un nuevo archivo versionado correlativo en `database_clinica/migration/`, por ejemplo `V11__nombre_descriptivo.sql` (o actualizar `R__...` si aplica según política de repetibles).
2. Registrar el paso en el plan de `scripts/clinical/ClinicalSchemaPure.php` manteniendo intactos los pasos anteriores `B9 -> V10 -> R`.
3. Si la nueva versión introduce tablas, columnas o funciones que Laravel deba consultar o invocar bajo `clinica_app`, añadir un script incremental `phaseN-permissions.sql` en `scripts/clinical/` siguiendo el principio de mínimo privilegio (`GRANT` explícito y funciones `SECURITY DEFINER` con `SET search_path TO 'public', 'pg_temp'`).
4. Probar primero sobre `clinica_laravel_install_verify` descartable o `clinica_laravel_phase1_tests` y verificar con `scripts\clinical\Verify-Database.ps1`.
