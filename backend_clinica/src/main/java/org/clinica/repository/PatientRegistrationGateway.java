package org.clinica.repository;

import jakarta.enterprise.context.ApplicationScoped;
import jakarta.inject.Inject;
import jakarta.transaction.Transactional;
import org.clinica.entity.Paciente;
import org.clinica.entity.Usuario;

import java.time.LocalDate;
import java.util.Optional;

/** Acceso transaccional al registro y a las funciones OTP ya definidas en V1. */
@ApplicationScoped
public class PatientRegistrationGateway {

    @Inject
    UsuarioRepository usuarioRepository;

    @Inject
    PacienteRepository pacienteRepository;

    @Inject
    CitaFunctionRepository functions;

    public boolean emailExists(String email) {
        return usuarioRepository.findByEmail(email).isPresent();
    }

    public boolean documentExists(String ci) {
        return pacienteRepository.findByCi(ci).isPresent();
    }

    public Optional<PatientAccount> findByEmail(String email) {
        return usuarioRepository.findByEmail(email).flatMap(this::toPatientAccount);
    }

    public Optional<PatientAccount> findByUserId(String codUsu) {
        return usuarioRepository.findByIdOptional(codUsu).flatMap(this::toPatientAccount);
    }

    @Transactional
    public PatientAccount register(
            String nombreCompleto,
            String email,
            String passwordHash,
            String ci,
            LocalDate fechaNacimiento,
            String telefono,
            String ciudadResidencia) {
        functions.registrarPaciente(
                nombreCompleto,
                email,
                passwordHash,
                ci,
                fechaNacimiento,
                telefono,
                ciudadResidencia);
        return findByEmail(email).orElseThrow(
                () -> new IllegalStateException("El registro no pudo recuperarse despues de ser creado."));
    }

    @Transactional
    public String createOtp(String codUsu) {
        return functions.crearOtpEmail(codUsu);
    }

    @Transactional
    public boolean verifyOtp(String codUsu, String code) {
        return Boolean.TRUE.equals(functions.validarOtpEmail(codUsu, code));
    }

    private Optional<PatientAccount> toPatientAccount(Usuario usuario) {
        if (!"ROL001".equals(usuario.codRol)) {
            return Optional.empty();
        }
        return pacienteRepository.findByCodUsu(usuario.codUsu).map(paciente -> map(usuario, paciente));
    }

    private static PatientAccount map(Usuario usuario, Paciente paciente) {
        return new PatientAccount(
                usuario.codUsu,
                paciente.codPac,
                usuario.nombreCompleto,
                usuario.email,
                usuario.passwordHash,
                usuario.codRol,
                usuario.estado,
                paciente.estadoCuenta,
                paciente.telefono,
                Boolean.TRUE.equals(paciente.emailVerificado));
    }

    public record PatientAccount(
            String userId,
            String patientId,
            String name,
            String email,
            String passwordHash,
            String roleCode,
            String userStatus,
            String profileStatus,
            String phone,
            boolean emailVerified) {
    }
}
