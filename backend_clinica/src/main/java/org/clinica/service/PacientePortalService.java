package org.clinica.service;

import io.quarkus.panache.common.Sort;
import jakarta.enterprise.context.ApplicationScoped;
import jakarta.inject.Inject;
import jakarta.persistence.EntityManager;
import jakarta.persistence.PersistenceException;
import jakarta.transaction.Transactional;
import jakarta.ws.rs.WebApplicationException;
import jakarta.ws.rs.core.Response;
import org.clinica.config.AccesoPaciente.PacienteActual;
import org.clinica.config.DbActorHelper;
import org.clinica.dto.agenda.AgendaDtos.Cupo;
import org.clinica.dto.paciente.PacienteDtos.*;
import org.clinica.entity.Paciente;
import org.clinica.entity.Usuario;
import org.clinica.entity.VistaHistorialCitas;
import org.clinica.repository.AgendaRepository;
import org.clinica.repository.CitaFunctionRepository;
import org.clinica.repository.PacienteRepository;
import org.jboss.logging.Logger;

import java.time.Duration;
import java.time.LocalDate;
import java.time.ZoneId;
import java.time.ZonedDateTime;
import java.time.format.DateTimeParseException;
import java.util.List;
import java.util.Locale;
import java.util.Set;
import java.util.regex.Pattern;

import static org.clinica.service.PersonalService.error;

/**
 * Lógica del portal del paciente. La reserva y la cancelación delegan en las funciones SQL
 * {@code reservar_cita} y {@code cancelar_cita} (vía {@link CitaFunctionRepository}), que ya
 * aplican permisos, sanciones, ventana D..D+14 y anti-solape con bloqueo por médico.
 * Lo único que se agrega aquí es la regla de 24 h para cancelar, que la base no aplica.
 */
@ApplicationScoped
public class PacientePortalService {
    private static final Logger LOG = Logger.getLogger(PacientePortalService.class);

    /** Misma zona que usa la base de datos (fecha_clinica / ahora_clinica). */
    private static final ZoneId ZONA = ZoneId.of("America/La_Paz");
    private static final int DIAS_VENTANA = 14;
    private static final Duration ANTICIPACION_CANCELAR = Duration.ofHours(24);
    private static final Set<String> ESTADOS_CANCELABLES = Set.of("Confirmada", "Afectada/Pendiente Reprogramación");
    private static final String MOTIVO_CANCELACION = "Cancelada por el paciente";
    private static final Pattern CODIGO = Pattern.compile("^[A-Za-z0-9_-]{3,40}$");
    private static final Pattern TELEFONO = Pattern.compile("^\\+?[0-9 ()-]{8,25}$");

    @Inject EntityManager em;
    @Inject AgendaRepository agenda;
    @Inject CitaFunctionRepository citaFunciones;
    @Inject PacienteRepository pacientes;
    @Inject DbActorHelper actorHelper;

    // ---------------------------------------------------------------- catálogo

    public List<EspecialidadItem> especialidades() {
        @SuppressWarnings("unchecked")
        List<Object[]> filas = em.createNativeQuery("""
                SELECT cod_esp, nombre_especialidad FROM public.especialidades
                WHERE estado = 'Activo' ORDER BY nombre_especialidad
                """).getResultList();
        return filas.stream().map(f -> new EspecialidadItem((String) f[0], (String) f[1])).toList();
    }

    public List<MedicoItem> medicos(String codEsp) {
        boolean filtrar = codEsp != null && !codEsp.isBlank();
        String sql = """
                SELECT m.cod_med, u.nombre_completo, e.cod_esp, e.nombre_especialidad
                FROM public.medicos m
                JOIN public.usuarios u ON u.cod_usu = m.cod_usu AND u.estado = 'Activo'
                JOIN public.medico_especialidades me ON me.cod_med = m.cod_med AND me.estado = 'Activo'
                JOIN public.especialidades e ON e.cod_esp = me.cod_esp AND e.estado = 'Activo'
                WHERE m.estado = 'Activo'
                """ + (filtrar ? " AND e.cod_esp = :codEsp" : "")
                + " ORDER BY u.nombre_completo, e.nombre_especialidad";
        var consulta = em.createNativeQuery(sql);
        if (filtrar) consulta.setParameter("codEsp", codEsp.strip());
        @SuppressWarnings("unchecked")
        List<Object[]> filas = consulta.getResultList();
        return filas.stream()
                .map(f -> new MedicoItem((String) f[0], (String) f[1], (String) f[2], (String) f[3]))
                .toList();
    }

    /** Cupos libres de un día. Fuera de la ventana hoy..D+14 devuelve lista vacía. */
    public List<Cupo> disponibilidad(String codEsp, String codMed, String fechaTexto) {
        if (codEsp == null || codEsp.isBlank()) {
            throw error(Response.Status.BAD_REQUEST, "VALIDACION", "La especialidad es obligatoria.");
        }
        LocalDate fecha = parsearFecha(fechaTexto);
        LocalDate hoy = LocalDate.now(ZONA);
        if (fecha.isBefore(hoy) || fecha.isAfter(hoy.plusDays(DIAS_VENTANA))) {
            return List.of();
        }
        return agenda.buscarCuposDisponibles(codEsp.strip(), codMed, fecha).stream()
                .map(c -> new Cupo(c.codSlot, c.codMed, c.nombreMedico, c.codEsp, c.nombreEspecialidad,
                        c.fecha, c.horaInicio, c.horaFin))
                .toList();
    }

    // ------------------------------------------------------------------- citas

    public List<CitaPaciente> citas(PacienteActual paciente) {
        List<VistaHistorialCitas> filas = VistaHistorialCitas.list("codPac",
                Sort.by("fecha", Sort.Direction.Descending).and("horaInicio", Sort.Direction.Descending),
                paciente.codPac());
        return filas.stream().map(PacientePortalService::aDto).toList();
    }

    @Transactional
    public CitaPaciente crearCita(PacienteActual paciente, String codSlot) {
        if (codSlot == null || codSlot.isBlank() || codSlot.length() > 40) {
            throw error(Response.Status.BAD_REQUEST, "VALIDACION", "Debes elegir un horario.");
        }
        String codCit;
        try {
            // Canal 'Web': la función exige que el actor sea el dueño de la cuenta del paciente.
            codCit = citaFunciones.reservarCita(paciente.codPac(), codSlot.strip(), paciente.codUsu(), "Web", null);
        } catch (PersistenceException e) {
            throw traducir(e);
        }
        VistaHistorialCitas cita = VistaHistorialCitas.findById(codCit);
        if (cita == null) {
            throw error(Response.Status.INTERNAL_SERVER_ERROR, "ERROR_INTERNO", "La cita se creó pero no pudo leerse.");
        }
        return aDto(cita);
    }

    @Transactional
    public void cancelarCita(PacienteActual paciente, String codCit) {
        if (codCit == null || !CODIGO.matcher(codCit).matches()) throw citaNoEncontrada();
        VistaHistorialCitas cita = VistaHistorialCitas.findById(codCit);
        // Si no es del paciente se responde igual que si no existiera (no revelar existencia).
        if (cita == null || !paciente.codPac().equals(cita.codPac)) throw citaNoEncontrada();

        if (!ESTADOS_CANCELABLES.contains(cita.estado)) {
            throw error(Response.Status.CONFLICT, "CITA_NO_CANCELABLE", "Esta cita ya no se puede cancelar.");
        }
        // Regla de negocio: hasta 24 h antes. La función SQL no la aplica.
        ZonedDateTime inicio = ZonedDateTime.of(cita.fecha, cita.horaInicio, ZONA);
        if (ZonedDateTime.now(ZONA).plus(ANTICIPACION_CANCELAR).isAfter(inicio)) {
            throw error(Response.Status.CONFLICT, "CANCELACION_FUERA_DE_PLAZO",
                    "Solo se puede cancelar hasta 24 horas antes de la cita.");
        }
        try {
            citaFunciones.cancelarCita(codCit, paciente.codUsu(), MOTIVO_CANCELACION);
        } catch (PersistenceException e) {
            throw traducir(e);
        }
    }

    // ------------------------------------------------------------------ perfil

    public PerfilRespuesta perfil(PacienteActual paciente) {
        Paciente p = pacientes.findById(paciente.codPac());
        Usuario u = em.find(Usuario.class, paciente.codUsu());
        if (p == null || u == null) {
            throw error(Response.Status.NOT_FOUND, "NO_ENCONTRADO", "No existe el perfil del paciente.");
        }
        return new PerfilRespuesta(u.nombreCompleto, u.email, p.ci, p.fechaNacimiento, p.telefono, p.ciudadResidencia);
    }

    @Transactional
    public PerfilRespuesta actualizarPerfil(PacienteActual paciente, PerfilSolicitud dto) {
        String telefono = dto.telefono == null ? "" : dto.telefono.strip();
        String ciudad = dto.ciudadResidencia == null ? "" : dto.ciudadResidencia.strip();
        if (!TELEFONO.matcher(telefono).matches()) {
            throw error(Response.Status.BAD_REQUEST, "VALIDACION", "El teléfono no tiene un formato válido.");
        }
        if (ciudad.codePointCount(0, ciudad.length()) < 3 || ciudad.codePointCount(0, ciudad.length()) > 100) {
            throw error(Response.Status.BAD_REQUEST, "VALIDACION", "La ciudad debe tener entre 3 y 100 caracteres.");
        }

        Paciente p = pacientes.findById(paciente.codPac());
        if (p == null) throw error(Response.Status.NOT_FOUND, "NO_ENCONTRADO", "No existe el perfil del paciente.");

        actorHelper.fijarActor(paciente.codUsu());
        if (!telefono.equals(p.telefono)) {
            p.telefono = telefono;
            p.telefonoVerificado = false; // el nuevo número aún no fue verificado
        }
        p.ciudadResidencia = ciudad;
        try {
            em.flush();
        } catch (PersistenceException e) {
            LOG.warnf("No se pudo actualizar el perfil de %s: %s", paciente.codPac(), mensajeRaiz(e));
            throw error(Response.Status.BAD_REQUEST, "VALIDACION", "No fue posible actualizar tus datos.");
        }
        return perfil(paciente);
    }

    // ----------------------------------------------------------------- helpers

    private static CitaPaciente aDto(VistaHistorialCitas c) {
        return new CitaPaciente(c.codCit, c.codMed, c.nombreMedico, c.codEsp, c.nombreEspecialidad,
                c.fecha, c.horaInicio, c.horaFin, c.estado);
    }

    private static LocalDate parsearFecha(String valor) {
        try {
            return LocalDate.parse(valor == null ? "" : valor.strip());
        } catch (DateTimeParseException e) {
            throw error(Response.Status.BAD_REQUEST, "FECHA_INVALIDA", "La fecha debe utilizar YYYY-MM-DD.");
        }
    }

    private static WebApplicationException citaNoEncontrada() {
        return error(Response.Status.NOT_FOUND, "CITA_NO_ENCONTRADA", "No existe la cita.");
    }

    /**
     * Traduce los RAISE EXCEPTION de PostgreSQL a códigos estables que el frontend entiende.
     * Los mensajes internos no se devuelven al paciente; solo se registran en el log.
     */
    private static WebApplicationException traducir(PersistenceException e) {
        String mensaje = mensajeRaiz(e);
        String m = mensaje.toLowerCase(Locale.ROOT);
        if (m.contains("ya no está disponible") || m.contains("se solapó") || m.contains("slot ya pasó")
                || m.contains("slot no encontrado") || m.contains("bloqueado por una excepción")
                || m.contains("ya no corresponde")) {
            return error(Response.Status.CONFLICT, "SLOT_NO_DISPONIBLE", "El horario ya no está disponible.");
        }
        if (m.contains("sanción activa")) {
            return error(Response.Status.CONFLICT, "CUENTA_SANCIONADA",
                    "La cuenta tiene una sanción activa y no puede reservar por ahora.");
        }
        if (m.contains("solo se admiten reservas")) {
            return error(Response.Status.BAD_REQUEST, "FECHA_INVALIDA", "La fecha está fuera de la ventana de reserva.");
        }
        if (m.contains("no puede cancelarse") || m.contains("no se puede cancelar")) {
            return error(Response.Status.CONFLICT, "CITA_NO_CANCELABLE", "La cita ya no se puede cancelar.");
        }
        LOG.warnf("Error de base de datos no mapeado: %s", mensaje);
        return error(Response.Status.BAD_REQUEST, "VALIDACION", "No fue posible completar la operación.");
    }

    private static String mensajeRaiz(Throwable e) {
        Throwable t = e;
        while (t.getCause() != null && t.getCause() != t) t = t.getCause();
        return t.getMessage() == null ? "" : t.getMessage();
    }
}
