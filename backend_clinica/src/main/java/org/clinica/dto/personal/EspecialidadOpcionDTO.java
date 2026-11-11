package org.clinica.dto.personal;

import io.quarkus.runtime.annotations.RegisterForReflection;

/** Opción de especialidad activa para llenar el formulario de doctores. */
@RegisterForReflection
public record EspecialidadOpcionDTO(String codEsp, String nombreEspecialidad, Short duracionConsultaMin) {}
