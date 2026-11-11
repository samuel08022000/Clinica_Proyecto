package org.clinica.dto.personal;

public record EspecialidadDetalleDTO(
        String codEsp,
        String nombreEspecialidad,
        String descripcion,
        Short duracionConsultaMin,
        String estado) {

    public record MedicoVinculado(
            String codMed,
            String nombreCompleto,
            String estadoMedico,
            String estadoVinculo,
            Short duracionConsultaMin) {
    }
}
