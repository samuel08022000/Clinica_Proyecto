package org.clinica.dto.personal;

import java.util.List;

/** Respuesta pública de un doctor (nunca expone password_hash). */
public record MedicoRespuestaDTO(
        String codMed,
        String codUsu,
        String nombreCompleto,
        String email,
        String ci,
        String matriculaProfesional,
        String resumenCurricular,
        Short duracionConsultaMin,
        String estado,
        List<EspecialidadVinculoDTO> especialidades) {

    public record EspecialidadVinculoDTO(
            String codEsp,
            String nombreEspecialidad,
            Short duracionConsultaMin,
            String estado) {}
}
