package org.clinica.dto.medico;

import com.fasterxml.jackson.annotation.JsonProperty;
import io.quarkus.runtime.annotations.RegisterForReflection;
import org.clinica.dto.personal.SolicitudConCamposPermitidos;

import java.time.LocalDate;
import java.time.LocalTime;
import java.time.OffsetDateTime;
import java.util.List;

public final class MedicoDtos {
    private MedicoDtos() { }

    /** El responsable y la fecha de atención se obtienen exclusivamente en el servidor. */
    public static class AtenderSolicitud extends SolicitudConCamposPermitidos { }

    @RegisterForReflection
    public record CitaMedica(String codCit, String codPac, String nombrePaciente, String codEsp,
            String nombreEspecialidad, LocalDate fecha, LocalTime horaInicio, LocalTime horaFin,
            String estado, String canalReserva, OffsetDateTime horaLlegadaCheckin,
            String checkinPorUsu, OffsetDateTime fechaAtencion, String atendidaPorUsu) {
        @JsonProperty
        public boolean checkinRegistrado() {
            return horaLlegadaCheckin != null && checkinPorUsu != null;
        }
    }

    @RegisterForReflection
    public record HistorialMedico(List<CitaMedica> citas, int page, int size, boolean hayMas) { }
}
