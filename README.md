# 🏥 Sistema de Control de Citas Médicas — UNIFRANZ 2026

Plataforma web para la gestión centralizada de agendas médicas, reserva atómica de citas dentro de una ventana móvil de 14 días, validación de asistencia por código QR firmado (HMAC-SHA256), derivaciones interconsulta y control de ausentismo (No-Show).

### 👥 Equipo de Desarrollo
* **Samuel Freddy Alcón Tinta**
* **Angela Victoria Maqui Quispe**
* **Dilan Jhessmar Frontanilla Centellas**
* **Yesid Freddy Hualpino Mamani**

---

## 🏗️ 1. Arquitectura MVC Distribuida del Proyecto

El proyecto separa estrictamente el **Frontend Web** del **Backend API / Base de Datos** para evitar conflictos en Git y proteger la integridad transaccional:

```text
[Navegador Web]
       │  1. Petición HTTP (GET / POST desde formulario Blade)
       ▼
[Laravel 13: frontend_clinica] (Puerto 8000)
  ├── routes/web.php               -> Define la ruta web y aplica el middleware CheckRolSession
  ├── app/Http/Controllers/        -> Controlador Web recibe Request y llama a QuarkusApiClient
  ├── app/Services/QuarkusApiClient-> Cliente HTTP que envía JSON a http://localhost:8080/api/v1
  └── resources/views/             -> Vistas Blade organizadas por módulo
       │  2. Petición REST JSON (Puerto 8080)
       ▼
[Quarkus 3 (Java 21): backend_clinica] (Puerto 8080)
  ├── org.clinica.controller       -> Endpoints REST (@Path("/api/v1/..."))
  ├── org.clinica.service          -> Lógica de negocio (@Transactional) + DbActorHelper
  ├── org.clinica.repository       -> Repositorios Panache + CitaFunctionRepository (Funciones SQL)
  └── org.clinica.entity           -> 17 Entidades JPA mapeadas al esquema public
       │  3. Conexión JDBC / Flyway (host 5433 → contenedor 5432)
       ▼
[PostgreSQL 15: Docker Container] (host 5433, interno 5432)
  ├── V1__control_citas_postgresql_completo.sql -> 18 Tablas, 2 Vistas, Triggers y Funciones PL/pgSQL
  └── V2__seed_usuarios_prueba.sql              -> 4 Usuarios por Rol, Especialidades, Horarios y Slots
```

> ⚠️ **REGLA DE ORO PARA EL EQUIPO:**
> **Laravel NUNCA se conecta directamente a PostgreSQL ni usa `php artisan migrate`.**
> Toda la base de datos se crea, migra y consulta exclusivamente a través de **Quarkus (`backend_clinica`)** usando **Flyway**.

---

## 🐳 2. Cómo Levantar la Base de Datos y los Contenedores (Docker)

### Opción A: Modo Desarrollo Recomendado (BD en Docker + Quarkus/Laravel en tu terminal)
1. Asegúrate de tener **Docker Desktop** abierto.
2. Copia `.env.docker.example` como `.env` en la raíz y define credenciales locales
   propias. Este archivo está ignorado por Git y no debe compartirse.
3. Comprueba que el puerto elegido esté libre y levanta solo PostgreSQL:
   ```powershell
   Get-NetTCPConnection -State Listen -LocalPort 5433 -ErrorAction SilentlyContinue
   docker compose up -d postgres_db
   ```
   PostgreSQL queda en `127.0.0.1:5433` por defecto. Compose no fija nombres
   globales de contenedor, por lo que cada clon usa nombres asociados a su proyecto.
   Si `5433` ya está ocupado por otra instancia, no levantes un segundo contenedor:
   reutiliza la instancia autorizada o define otro `POSTGRES_HOST_PORT` en `.env` y
   actualiza la URL JDBC local de Quarkus con el mismo puerto.
   Los puertos de pgAdmin, Quarkus y Laravel también son configurables mediante
   `PGADMIN_HOST_PORT`, `QUARKUS_HOST_PORT` y `LARAVEL_HOST_PORT`; todos se publican
   únicamente en `127.0.0.1` para evitar colisiones o exposición en la red local.

4. **Configurar y levantar el Backend (Quarkus):**
   ```powershell
   cd backend_clinica
   Copy-Item .env.example .env
   # Completa QUARKUS_DATASOURCE_USERNAME y QUARKUS_DATASOURCE_PASSWORD localmente.
   ./scripts/dev/prepare-demo-credentials.ps1
   ./mvnw quarkus:dev
   ```
   * Comparte la contraseña demo únicamente por el gestor de secretos del equipo.
   * El script genera una V3 local ignorada por Git, con BCrypt costo 12, sin imprimir
     la contraseña ni el hash.
   * En perfil `dev`, Flyway ejecuta V1/V2 desde el classpath y la V3 desde
     `backend_clinica/db/dev-migration`. Fuera de `dev`, esa ubicación no se carga.
   * La V3 valida la base `db_clinica`, el marcador `development` y los cuatro
     usuarios/roles/perfiles antes de actualizar; si falta alguno, no actualiza nada.
   * API REST disponible en: `http://localhost:8080` (Swagger/Dev UI en `http://localhost:8080/q/dev`).

5. **Levantar el Frontend (Laravel):**
   Abre otra terminal:
   ```bash
   cd frontend_clinica
   composer install
   cp .env.example .env
   php artisan key:generate
   ```
   Asegúrate de que en tu archivo `frontend_clinica/.env` estén estas dos variables:
   ```env
   SESSION_DRIVER=file
   QUARKUS_API_URL=http://localhost:8080/api/v1
   ```
   Arranca el servidor web:
   ```bash
   php artisan serve --port=8000
   ```
   * Sistema web disponible en: [http://localhost:8000](http://localhost:8000).

---

### Opción B: Levantar TODO en Contenedores Docker (BD + Quarkus + Laravel juntos)
Si deseas encender la base de datos, el backend Quarkus y el frontend Laravel dentro de contenedores Docker de una sola vez:
```bash
docker compose --profile fullstack up -d
```

> No uses `docker compose down -v` ni `flyway clean` en bases compartidas: eliminan
> datos. La Fase 2A no requiere recrear PostgreSQL.

---

## 🔑 3. Usuarios y Datos de Prueba Pre-cargados (`V2__seed`)

Al iniciar Quarkus por primera vez, ya tienes creados estos **4 usuarios oficiales**, además de **3 especialidades** (`Medicina General`, `Cardiología`, `Pediatría`), horarios de lunes a viernes y los **slots de agenda generados para los próximos 14 días**:

| Rol en el Sistema | Código Rol | Correo Electrónico | Perfil Asociado |
| :--- | :---: | :--- | :--- |
| **Paciente** | `ROL001` | `samuel.paciente@unifranz.edu.bo` | `PAC000001` (Teléfono verificado) |
| **Secretario(a)** | `ROL002` | `angela.secretaria@clinica.bo` | `SEC000001` |
| **Doctor** | `ROL003` | `dilan.doctor@clinica.bo` | `MED000001` (Vinculado a las 3 esp.) |
| **Administrador** | `ROL004` | `yesid.admin@clinica.bo` | Superusuario Admin |

La contraseña común no se almacena en Git. Una vez iniciados Quarkus (`8080`) y
Laravel (`8000`), verifica las cuatro cuentas desde la raíz del repositorio:

```powershell
./backend_clinica/scripts/dev/verify-demo-logins.ps1
```

El script pide la contraseña en modo enmascarado y ejecuta la prueba real sin
persistirla. Si Quarkus se inició deliberadamente en otro puerto local, indícalo:

```powershell
./backend_clinica/scripts/dev/verify-demo-logins.ps1 -QuarkusApiUrl http://127.0.0.1:8081/api/v1
```

---

## 📧 Registro de pacientes y OTP por correo (Fase 2B)

El registro conserva `registrar_paciente` y añade una verificación de correo independiente
de `telefono_verificado`. La migración repetible
`R__verificacion_email_otp.sql` incorpora `email_verificado`, `email_verificado_en`,
`crear_otp_email` y `validar_otp_email`. Se eligió una migración repetible para no
ocupar ni bloquear la V3 local de credenciales demo. Los pacientes ya existentes se
marcan como verificados al incorporar la columna; los nuevos comienzan pendientes.

Para desarrollo local, configure en `backend_clinica/.env`:

```env
OTP_DELIVERY_PROVIDER=demo
OTP_DEMO_ACCESS_KEY=<clave-local-aleatoria-de-al-menos-24-caracteres>
```

El archivo `.env` está ignorado. No comparta esa clave por Git, capturas o registros.
Para probar el proveedor DEMO use la opción A (Quarkus ejecutado directamente en la
máquina local). El perfil `fullstack` no publica la clave DEMO dentro del contenedor
ni relaja la restricción de acceso loopback.
El proveedor DEMO solo funciona con el perfil Quarkus `dev` o `test`, desde loopback,
y conserva temporalmente el código en memoria. La interfaz indica expresamente que no
se envió un correo real. Para leer el código después de enviar
el formulario de registro:

```powershell
./backend_clinica/scripts/dev/read-demo-otp.ps1
```

El script solicita el correo y la clave de acceso sin guardar esta última. El código
se muestra únicamente en esa consola local. Nunca redirija su salida a un archivo o log.

Flujo para probarlo:

1. Inicie PostgreSQL autorizado, Quarkus en `8080` y Laravel en `8000`.
2. Abra `http://127.0.0.1:8000/registro` y cree un paciente ficticio.
3. Consulte el OTP con el script local e ingréselo en `/verificar-correo`.
4. Confirme que el login se rechaza antes de verificar y funciona después.

Controles implementados: BCrypt costo 12, OTP SHA-256 de seis dígitos, vigencia de
cinco minutos, tres intentos, espera de 60 segundos entre reenvíos, máximo de cinco
emisiones por hora, invalidación del código previo y bloqueo de reutilización mediante
`FOR UPDATE` en PostgreSQL. El teléfono no es único porque el esquema permite que más
de un paciente comparta un número; correo y CI sí tienen índices únicos.

El proveedor SMTP con Gmail ya está preparado, pero permanece inactivo hasta configurar
credenciales válidas exclusivamente en `backend_clinica/.env`. Consulte la guía
[Configurar Gmail SMTP](backend_clinica/docs/gmail-smtp.md). Nunca use el modo DEMO en
producción ni presente su código local como un correo enviado.

---

## 🛠️ 4. Guía de Trabajo para el Equipo: ¿Dónde pongo mi código?

La capa de Base de Datos (`entity/`, `repository/`, `config/` y `db/migration/`) **YA ESTÁ 100% CREADA Y MAPEADA**. Nadie debe modificar `V1__control_citas_postgresql_completo.sql`.

Cada integrante debe trabajar siguiendo estos **4 pasos** para su módulo asignado:

### PASO 1: Usar los Repositorios en tu `Service` de Quarkus (`backend_clinica/src/main/java/org/clinica/service/`)
En tu clase `*Service.java`, **no escribas SQL manual**. Inyecta los repositorios que ya están listos:

1. **`@Inject CitaFunctionRepository citaFnRepo;`** (Contiene todas las funciones PL/pgSQL del motor):
   * `citaFnRepo.registrarPaciente(nombre, email, passHash, ci, fechaNac, tel, ciudad)`
   * `citaFnRepo.crearOtp(codUsu)` y `citaFnRepo.validarOtp(codUsu, codigo)`
   * `citaFnRepo.crearTokenRecuperacion(email)` y `citaFnRepo.restablecerContrasena(email, token, nuevoPassHash)`
   * `citaFnRepo.reservarCita(codPac, codSlot, codActor, canal, codDer)`
   * `citaFnRepo.cancelarCita(codCit, codActor, motivo)`
   * `citaFnRepo.reprogramarCita(codCit, codSlotNuevo, codActor, motivo)`
   * `citaFnRepo.registrarCheckin(tokenQr, codSec)`
   * `citaFnRepo.marcarCitaAtendida(codCit, codUsuDoctor)`
   * `citaFnRepo.crearDerivacion(codCitOrigen, codUsuDoctor, codEspDestino, motivo, codMedDestino)`
   * `citaFnRepo.registrarSancion(codPac, codAdmin, motivo, dias)`

2. **Repositorios Panache de Consulta y Catálogos:**
   * `@Inject UsuarioRepository usuarioRepo;`
   * `@Inject PacienteRepository pacienteRepo;`
   * `@Inject PersonalRepository personalRepo;`
   * `@Inject EspecialidadRepository especialidadRepo;`
   * `@Inject AgendaRepository agendaRepo;` (Ya filtra `VistaAgendaDisponibilidad` para la ventana de 14 días).
   * `@Inject AuditoriaRepository auditoriaRepo;` (Consulta `VistaHistorialCitas`, `IncidenciaSancion` y `Bitacora`).

3. **Auditoría Automática en Bitácora (`@Inject DbActorHelper actorHelper;`):**
   Cuando hagas un `INSERT` o `UPDATE` directo en catálogos (`especialidades`, `horarios`, `bloqueos_agenda`, `medicos`), llama en la primera línea de tu método `@Transactional` a:
   ```java
   actorHelper.fijarActor(codUsuActor);
   ```
   Con eso el trigger de PostgreSQL sabrá quién hizo el cambio y lo guardará en `bitacora`.

---

### PASO 2: Exponer el Endpoint JSON en tu `Controller` de Quarkus (`backend_clinica/src/main/java/org/clinica/controller/`)
En tu controlador de Quarkus, define la ruta base `/api/v1/<tu-modulo>` y llama a tu `Service`.
> 💡 **Manejo de Errores Automático:** Si un trigger o función de PostgreSQL lanza un `RAISE EXCEPTION` (por ejemplo: *"El paciente tiene una sanción activa"*, *"El horario se superpone"*, *"QR mal formado"*), la clase `TriggerExceptionMapper.java` lo captura automáticamente y le responde a Laravel un **HTTP 400** con:
> `{"ok": false, "error": "REGLA_NEGOCIO_BD", "mensaje": "Texto exacto de PostgreSQL"}`

Ejemplo en `ReservaCitaController.java` (Quarkus):
```java
@Path("/api/v1/reservas")
@Produces(MediaType.APPLICATION_JSON)
@Consumes(MediaType.APPLICATION_JSON)
public class ReservaCitaController {

    @Inject
    ReservaCitaService reservaService;

    @GET
    @Path("/disponibilidad")
    public List<VistaAgendaDisponibilidad> consultarDisponibilidad(
            @QueryParam("codEsp") String codEsp,
            @QueryParam("codMed") String codMed,
            @QueryParam("fecha") LocalDate fecha) {
        return reservaService.buscarCuposDisponibles(codEsp, codMed, fecha);
    }
}
```

---

### PASO 3: Conectar el Controlador de Laravel (`frontend_clinica/app/Http/Controllers/`)
En Laravel, cada controlador recibe los datos del formulario web, llama a Quarkus usando `QuarkusApiClient` y retorna la vista Blade:

```php
<?php

namespace App\Http\Controllers;

use App\Services\QuarkusApiClient;
use Illuminate\Http\Request;

class ReservaCitaController extends Controller
{
    public function __construct(private QuarkusApiClient $api) {}

    public function index(Request $request)
    {
        $response = $this->api->get('/reservas/disponibilidad', [
            'codEsp' => $request->query('cod_esp'),
            'codMed' => $request->query('cod_med'),
            'fecha'  => $request->query('fecha'),
        ]);

        return view('reservas.disponibles', [
            'cupos' => $response->successful() ? $response->json() : []
        ]);
    }

    public function store(Request $request)
    {
        $response = $this->api->post('/reservas/citas', [
            'codPac'   => session('cod_pac'),
            'codSlot'  => $request->input('cod_slot'),
            'codActor' => session('cod_usu'),
            'canal'    => 'Web',
        ]);

        // Si PostgreSQL rechazó la operación, mostramos el mensaje exacto en la vista
        if ($response->failed()) {
            return back()->withErrors([
                'bd_error' => $response->json('mensaje') ?? 'Error al procesar la solicitud.'
            ])->withInput();
        }

        return redirect()->route('reservas.comprobante', ['id' => $response->json('codCit')])
                         ->with('success', 'Cita reservada exitosamente.');
    }
}
```

---

### PASO 4: Crear las Vistas Blade y Rutas Web en Laravel (`frontend_clinica/resources/views/` y `routes/web.php`)
Cada módulo tiene su propia subcarpeta en `frontend_clinica/resources/views/` y su controlador asignado:

| Módulo / Sprint | Controlador Quarkus (`/api/v1/...`) | Controlador Laravel (`app/Http/Controllers/`) | Carpeta de Vistas Blade (`resources/views/`) | Prefijo de Ruta en `routes/web.php` |
| :--- | :--- | :--- | :--- | :--- |
| **1. Autenticación y Roles** | `AuthController.java` (`/api/v1/auth`) | `AuthController.php` | `resources/views/auth/` | `/login`, `/registro`, `/otp` |
| **2A. Gestión de Personal** | `PersonalController.java` (`/api/v1/personal`) | `PersonalController.php` | `resources/views/personal/` | `/admin/personal` |
| **2B. Especialidades** | `EspecialidadController.java` (`/api/v1/especialidades`) | `EspecialidadController.php` | `resources/views/especialidades/` | `/admin/especialidades` |
| **3. Agendas y Horarios** | `AgendaHorarioController.java` (`/api/v1/agendas`) | `AgendaHorarioController.php` | `resources/views/agendas/` | `/admin/agendas` |
| **4. Reservas y Citas** | `ReservaCitaController.java` (`/api/v1/reservas`) | `ReservaCitaController.php` | `resources/views/reservas/` | `/reservas`, `/recepcion/reservas` |
| **5. Check-in y Atención** | `AtencionCheckinController.java` (`/api/v1/atencion`) | `AtencionCheckinController.php` | `resources/views/atencion/` | `/recepcion/checkin`, `/doctor/agenda` |
| **6. Incidencias y Alertas** | `IncidenciaNotificacionController.java` (`/api/v1/incidencias`) | `IncidenciaNotificacionController.php` | `resources/views/incidencias/` | `/historial`, `/admin/sanciones` |
