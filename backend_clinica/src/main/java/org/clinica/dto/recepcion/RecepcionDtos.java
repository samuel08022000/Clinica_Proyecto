package org.clinica.dto.recepcion;

import com.fasterxml.jackson.annotation.JsonProperty;
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
