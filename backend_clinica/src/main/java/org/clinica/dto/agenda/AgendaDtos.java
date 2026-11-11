package org.clinica.dto.agenda;

import org.clinica.dto.personal.SolicitudConCamposPermitidos;

import java.math.BigDecimal;
import java.time.LocalDate;
import java.time.LocalTime;
import java.time.OffsetDateTime;
import java.util.List;

public final class AgendaDtos {
    private AgendaDtos() { }

    public static class HorarioSolicitud extends SolicitudConCamposPermitidos {
        public BigDecimal diaSemana;
        public String horaInicio;
        public String horaFin;
        public String pausaInicio;
        public String pausaFin;
    }

    public static class BloqueoSolicitud extends SolicitudConCamposPermitidos {
        public String fechaBloqueo;
        public String horaInicio;
        public String horaFin;
        public String motivo;
    }

    public static class DuracionSolicitud extends SolicitudConCamposPermitidos {
        public BigDecimal duracionConsultaMin;
    }

    public record HorarioRespuesta(String codHor, String codMed, Short diaSemana, LocalTime horaInicio,
            LocalTime horaFin, LocalTime pausaInicio, LocalTime pausaFin, String estado) { }

    public record BloqueoRespuesta(String codBlo, String codMed, String codUsuAdmin, LocalDate fechaBloqueo,
            LocalTime horaInicio, LocalTime horaFin, String motivo, OffsetDateTime creadoEn,
            long cantidadCitasAfectadas, boolean trazabilidadCompleta) { }

    public record CitaAdministrativa(String codCit, String codPac, String codMed, String codEsp, LocalDate fecha,
            LocalTime horaInicio, LocalTime horaFin, String estado) { }

    public record CitasAfectadas(String codBlo, boolean trazabilidadCompleta, List<CitaAdministrativa> citas) { }

    public record Cupo(String codSlot, String codMed, String nombreMedico, String codEsp,
            String nombreEspecialidad, LocalDate fecha, LocalTime horaInicio, LocalTime horaFin) { }

    public record DuracionRespuesta(String codMed, Short duracionConsultaMin) { }
}
