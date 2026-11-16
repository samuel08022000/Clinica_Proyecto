package org.clinica.dto.recepcion;

import com.fasterxml.jackson.annotation.JsonProperty;
import io.quarkus.runtime.annotations.RegisterForReflection;
import org.clinica.dto.personal.SolicitudConCamposPermitidos;

import java.time.LocalDate;
import java.time.LocalTime;
import java.time.OffsetDateTime;
import java.util.List;

public final class RecepcionDtos {
    private RecepcionDtos() { }

    public static class NuevaCita extends SolicitudConCamposPermitidos {
        public String codPac;
        public String codSlot;
        public String codDer;
    }

    public static class CheckinSolicitud extends SolicitudConCamposPermitidos {
        @JsonProperty(access = JsonProperty.Access.WRITE_ONLY)
        public String tokenQr;
    }

    public static class CancelacionSolicitud extends SolicitudConCamposPermitidos {
        public String motivo;
    }

    public static class InasistenciaSolicitud extends SolicitudConCamposPermitidos { }

    @RegisterForReflection
    public record PaginaCitas(List<CitaHistorial> citas, int page, int size, boolean hayMas) { }

    @RegisterForReflection
    public record CitaHistorial(String codCit, String codPac, String nombrePaciente, String codMed,
            String nombreMedico, String codEsp, String nombreEspecialidad, LocalDate fecha, LocalTime horaInicio,
            LocalTime horaFin, String estado, String canalReserva, String motivoCancelacion, String canceladoPorUsu,
            OffsetDateTime canceladoEn, OffsetDateTime horaLlegadaCheckin, String checkinPorUsu,
            OffsetDateTime fechaAtencion, String codIncInasistencia, OffsetDateTime inasistenciaRegistradaEn,
            String inasistenciaPorUsu, String motivoInasistencia) { }

    @RegisterForReflection
    public record Cancelacion(String codCit, String estado, String motivoCancelacion, String canceladoPorUsu,
            OffsetDateTime canceladoEn) {
        @JsonProperty public boolean cancelada() { return "Cancelada".equals(estado); }
    }

    @RegisterForReflection
    public record Inasistencia(String codCit, String estado, String codInc, String registradoPorUsu,
            OffsetDateTime fechaRegistro, String motivo) {
        @JsonProperty public boolean inasistenciaRegistrada() { return "No Asistió".equals(estado); }
    }

    @RegisterForReflection
    public record Acreditacion(String codCit, String nombrePaciente, LocalDate fecha, String estado,
            boolean qrValido, boolean admiteCheckin) { }

    @RegisterForReflection
    public record Asistencia(String codCit, String nombrePaciente, LocalDate fecha, String estado,
            OffsetDateTime horaLlegadaCheckin, String checkinPorUsu) {
        @JsonProperty
        public boolean checkinRegistrado() {
            return horaLlegadaCheckin != null && checkinPorUsu != null;
        }
    }

    public record PacienteResumen(String codPac, String nombreCompleto, String ci, String email, String estadoCuenta) { }

    public record PacientesEncontrados(List<PacienteResumen> pacientes) { }

    public record PacienteDetalle(String codPac, String nombreCompleto, String ci, String email, String telefono,
            String ciudadResidencia, String estadoCuenta, String estadoUsuario, Boolean emailVerificado,
            OffsetDateTime bloqueadoHasta, String motivoInhabilitacion) {
        @JsonProperty
        public boolean habilitadoParaReservar() {
            return motivoInhabilitacion == null;
        }
    }

    public record MedicoOpcion(String codMed, String nombreCompleto, Short duracionConsultaMin) { }

    public record CitaRecepcion(String codCit, String codSlot, String codPac, String nombrePaciente, String codMed,
            String nombreMedico, String codEsp, String nombreEspecialidad, LocalDate fecha, LocalTime horaInicio,
            LocalTime horaFin, String estado, String canalReserva, String codSec, boolean qrDisponible) { }
}
