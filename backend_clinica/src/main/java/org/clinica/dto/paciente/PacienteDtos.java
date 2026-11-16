package org.clinica.dto.paciente;

import org.clinica.dto.personal.SolicitudConCamposPermitidos;

import java.time.LocalDate;
import java.time.LocalTime;

/** DTOs del portal del paciente (/api/v1/paciente). */
public final class PacienteDtos {
    private PacienteDtos() { }

    public record EspecialidadItem(String codEsp, String nombre) { }

    public record MedicoItem(String codMed, String nombre, String codEsp, String nombreEspecialidad) { }

    public record CitaPaciente(String codCit, String codMed, String nombreMedico, String codEsp,
            String nombreEspecialidad, LocalDate fecha, LocalTime horaInicio, LocalTime horaFin, String estado) { }

    public record PerfilRespuesta(String nombre, String email, String ci, LocalDate fechaNacimiento,
            String telefono, String ciudadResidencia) { }

    /** Cuerpo de POST /paciente/citas. No hay motivo de consulta: la tabla citas no tiene esa columna. */
    public static class CrearCitaSolicitud extends SolicitudConCamposPermitidos {
        public String codSlot;
    }

    /** Cuerpo de PUT /paciente/perfil. Solo estos dos campos son editables. */
    public static class PerfilSolicitud extends SolicitudConCamposPermitidos {
        public String telefono;
        public String ciudadResidencia;
    }
}
