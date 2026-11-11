package org.clinica.config;

import jakarta.enterprise.context.ApplicationScoped;
import jakarta.inject.Inject;
import jakarta.ws.rs.core.Response;
import org.clinica.service.AuthException;
import org.clinica.service.AuthService;
import org.clinica.service.PersonalService;

import java.util.Set;

@ApplicationScoped
public class AccesoAdministrativo {
    @Inject AuthService authService;

    public String exigirAdmin(String authorization) {
        try {
            return authService.requireAnyRole(BearerToken.bearerToken(authorization), Set.of("ROL004")).user().id();
        } catch (AuthException exception) {
            if ("FORBIDDEN".equals(exception.code())) {
                throw PersonalService.error(Response.Status.FORBIDDEN, "SIN_PERMISO",
                        "Solo el administrador puede gestionar cuentas de personal.");
            }
            throw exception;
        }
    }
}
