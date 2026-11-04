package org.clinica.dto.personal;

import java.util.List;

/** Datos editables de un doctor. {@code password} y {@code codigosEspecialidades} son opcionales. */
public class ActualizarMedicoDTO {
    public String nombreCompleto;
    public String email;
    public String password;                 // null/vacío = no cambiar
    public String ci;
    public String matriculaProfesional;
    public String resumenCurricular;
    public Short duracionConsultaMin;
    public List<String> codigosEspecialidades; // null = no tocar los vínculos
}
