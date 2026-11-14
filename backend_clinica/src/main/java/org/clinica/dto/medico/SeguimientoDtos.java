package org.clinica.dto.medico;

import io.quarkus.runtime.annotations.RegisterForReflection;
import org.clinica.dto.personal.SolicitudConCamposPermitidos;
import java.time.OffsetDateTime;
import java.util.List;

public final class SeguimientoDtos {
    private SeguimientoDtos() { }

    public static class NuevaDerivacion extends SolicitudConCamposPermitidos {
        public String codEspDestino;
        public String codMedDestino;
        public String motivo;
    }
    public static class NuevoAnalisis extends SolicitudConCamposPermitidos {
        public String tipoAnalisis;
    }
    public static class ResultadoAnalisis extends SolicitudConCamposPermitidos {
        public String estado;
        public String resultado;
    }

    /** Vista administrativa compartida: deliberadamente no contiene el motivo clínico. */
    @RegisterForReflection
    public record DerivacionResumen(String codDer, String codCitOrigen, String codPac, String nombrePaciente,
            String codMedEmisor, String nombreMedicoEmisor, String codEspDestino, String nombreEspecialidad,
            String codMedDestino, String nombreMedicoDestino, String estado, OffsetDateTime fechaDerivacion,
            String codCitHija) { }

    @RegisterForReflection
    public record PaginaDerivaciones(List<DerivacionResumen> derivaciones, int page, int size, boolean hayMas) { }

    @RegisterForReflection
    public record AnalisisResumen(String codAna, String codCit, String solicitadoPorUsu, String tipoAnalisis,
            String estado, String resultado, OffsetDateTime fechaRegistro,
            OffsetDateTime fechaEntregaEstimada, OffsetDateTime fechaEntregaReal) { }
}
