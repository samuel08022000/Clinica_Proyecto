package org.clinica.repository;

import jakarta.enterprise.context.ApplicationScoped;
import jakarta.inject.Inject;
import jakarta.persistence.EntityManager;
import org.clinica.entity.Medico;
import org.clinica.entity.Paciente;
import org.clinica.entity.Rol;
import org.clinica.entity.Secretaria;
import org.clinica.entity.Usuario;

import java.time.OffsetDateTime;
import java.util.Optional;

/** Compone la identidad de login con el rol y el estado del perfil real. */
@ApplicationScoped
public class AuthAccountGateway {

    @Inject
    UsuarioRepository usuarioRepository;

    @Inject
    PacienteRepository pacienteRepository;

    @Inject
    PersonalRepository personalRepository;

    @Inject
    EntityManager entityManager;

    public Optional<AuthAccount> findByEmail(String email) {
        return usuarioRepository.findByEmail(email).map(this::toAccount);
    }

    public Optional<AuthAccount> findById(String codUsu) {
        return usuarioRepository.findByIdOptional(codUsu).map(this::toAccount);
    }

    private AuthAccount toAccount(Usuario usuario) {
        Rol rol = entityManager.find(Rol.class, usuario.codRol);
        String roleName = rol == null ? null : rol.nombreRol;
        String roleStatus = rol == null ? null : rol.estado;
        String profileStatus = null;
        OffsetDateTime blockedUntil = null;
        boolean profilePresent = true;
        Boolean phoneVerified = null;

        switch (usuario.codRol) {
            case "ROL001" -> {
                Optional<Paciente> paciente = pacienteRepository.findByCodUsu(usuario.codUsu);
                profilePresent = paciente.isPresent();
                if (paciente.isPresent()) {
                    profileStatus = paciente.get().estadoCuenta;
                    blockedUntil = paciente.get().bloqueadoHasta;
                    phoneVerified = paciente.get().telefonoVerificado;
                }
            }
            case "ROL002" -> {
                Optional<Secretaria> secretaria = personalRepository.findSecretariaByCodUsu(usuario.codUsu);
                profilePresent = secretaria.isPresent();
                profileStatus = secretaria.map(item -> item.estado).orElse(null);
            }
            case "ROL003" -> {
                Optional<Medico> medico = personalRepository.findMedicoByCodUsu(usuario.codUsu);
                profilePresent = medico.isPresent();
                profileStatus = medico.map(item -> item.estado).orElse(null);
            }
            case "ROL004" -> profileStatus = usuario.estado;
            default -> profilePresent = false;
        }

        return new AuthAccount(
                usuario.codUsu,
                usuario.nombreCompleto,
                usuario.email,
                usuario.passwordHash,
                usuario.codRol,
                roleName,
                usuario.estado,
                roleStatus,
                profilePresent,
                profileStatus,
                blockedUntil,
                phoneVerified);
    }

    public record AuthAccount(
            String id,
            String name,
            String email,
            String passwordHash,
            String roleCode,
            String roleName,
            String userStatus,
            String roleStatus,
            boolean profilePresent,
            String profileStatus,
            OffsetDateTime blockedUntil,
            Boolean phoneVerified) {
    }
}
