package org.clinica.config;

import jakarta.enterprise.context.ApplicationScoped;
import jakarta.inject.Inject;
import org.clinica.entity.Paciente;
import org.clinica.repository.PacienteRepository;
import org.clinica.service.AuthException;
import org.clinica.service.AuthService;

import java.util.Set;

/**
 * Equivalente de {@link AccesoAdministrativo} para el portal del paciente.
 * El paciente se identifica SIEMPRE por el token, nunca por un parámetro de la petición.
 */
@ApplicationScoped
public class AccesoPaciente {
    @Inject AuthService authService;
    @Inject PacienteRepository pacientes;

    /** Identidad del paciente autenticado: usuario (actor en las funciones SQL) y perfil. */
    public record PacienteActual(String codUsu, String codPac) { }

    public PacienteActual exigirPaciente(String authorization) {
        String codUsu = authService
                .requireAnyRole(BearerToken.bearerToken(authorization), Set.of("ROL001"))
                .user().id();
        Paciente paciente = pacientes.findByCodUsu(codUsu).orElseThrow(() ->
                new AuthException(403, "PROFILE_UNAVAILABLE", "La cuenta no tiene un perfil de paciente."));
        return new PacienteActual(codUsu, paciente.codPac);
    }
}
