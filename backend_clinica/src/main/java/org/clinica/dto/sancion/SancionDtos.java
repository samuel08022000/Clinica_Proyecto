package org.clinica.dto.sancion;

import com.fasterxml.jackson.annotation.JsonSetter;
import com.fasterxml.jackson.databind.JsonNode;
import io.quarkus.runtime.annotations.RegisterForReflection;
import org.clinica.dto.personal.SolicitudConCamposPermitidos;
import org.clinica.dto.recepcion.RecepcionDtos.PacienteDetalle;

import java.time.OffsetDateTime;
import java.util.List;

public final class SancionDtos {
    private SancionDtos() { }

    public static class CrearSancion extends SolicitudConCamposPermitidos {
        public String motivo;
        private Integer dias;
        @JsonSetter("dias")
        public void leerDias(JsonNode valor) {
            dias = valor != null && valor.isIntegralNumber() && valor.canConvertToInt() ? valor.intValue() : null;
        }
        public Integer dias() { return dias; }
    }

    @RegisterForReflection
    public record Incidencia(String codInc, String codPac, String codCit, String tipoIncidencia, String motivo,
            String registradoPorUsu, OffsetDateTime fechaRegistro, OffsetDateTime fechaFinSancion, boolean vigente) { }

    @RegisterForReflection
    public record PaginaIncidencias(List<Incidencia> incidencias, int page, int size, boolean hayMas) { }

    @RegisterForReflection
    public record PaginaPacientes(List<PacienteDetalle> pacientes, int page, int size, boolean hayMas) { }
}
