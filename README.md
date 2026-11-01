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
[Laravel 12: frontend_clinica] (Puerto 8000)
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
       │  3. Conexión JDBC / Flyway (Puerto 5432)
       ▼
[PostgreSQL 15: Docker Container] (Puerto 5432)
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
2. En la raíz del proyecto, levanta PostgreSQL y pgAdmin:
   ```bash
   docker compose up -d
   ```
   * **PostgreSQL:** corriendo en `localhost:5432` (BD: `db_clinica`, Usuario: `admin_clinica`, Clave: `password_seguro_123`).
   * **pgAdmin 4 (Visor Web):** disponible en [http://localhost:5050](http://localhost:5050) (Login: `admin@clinica.bo` / Clave: `admin123`).

3. **Levantar el Backend (Quarkus) y generar la Base de Datos automáticamente:**
   ```bash
   cd backend_clinica
   ./mvnw quarkus:dev
   ```
   * Al arrancar Quarkus, **Flyway ejecutará automáticamente** `V1__control_citas_postgresql_completo.sql` y `V2__seed_usuarios_prueba.sql`.
   * No necesitas ejecutar ningún script SQL manualmente en DBeaver ni pgAdmin.
   * API REST disponible en: `http://localhost:8080` (Swagger/Dev UI en `http://localhost:8080/q/dev`).

4. **Levantar el Frontend (Laravel):**
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

### 🔄 ¿Cómo reiniciar la Base de Datos desde cero si hiciste pruebas sucias?
Si quieres borrar tus datos de prueba locales y que Quarkus regenere todas las tablas y los datos iniciales limpios:
```bash
docker compose down -v
docker compose up -d
```
*(Al volver a correr `./mvnw quarkus:dev`, Flyway reconstruirá todo en 2 segundos).*

---

## 🔑 3. Usuarios y Datos de Prueba Pre-cargados (`V2__seed`)

Al iniciar Quarkus por primera vez, ya tienes creados estos **4 usuarios oficiales**, además de **3 especialidades** (`Medicina General`, `Cardiología`, `Pediatría`), horarios de lunes a viernes y los **slots de agenda generados para los próximos 14 días**:

| Rol en el Sistema | Código Rol | Correo Electrónico | Contraseña de Prueba | Perfil Asociado |
| :--- | :---: | :--- | :---: | :--- |
| **Paciente** | `ROL001` | `samuel.paciente@unifranz.edu.bo` | *(Hash Bcrypt Seed)* | `PAC000001` (Teléfono verificado) |
| **Secretario(a)** | `ROL002` | `angela.secretaria@clinica.bo` | *(Hash Bcrypt Seed)* | `SEC000001` |
| **Doctor** | `ROL003` | `dilan.doctor@clinica.bo` | *(Hash Bcrypt Seed)* | `MED000001` (Vinculado a las 3 esp.) |
| **Administrador** | `ROL004` | `yesid.admin@clinica.bo` | *(Hash Bcrypt Seed)* | Superusuario Admin |

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