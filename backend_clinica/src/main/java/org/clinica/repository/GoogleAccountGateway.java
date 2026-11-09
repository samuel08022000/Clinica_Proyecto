package org.clinica.repository;

import jakarta.enterprise.context.ApplicationScoped;
import jakarta.inject.Inject;
import jakarta.persistence.EntityManager;
import jakarta.transaction.Transactional;
import org.clinica.entity.Paciente;
import org.clinica.entity.Usuario;
import org.clinica.entity.UsuarioIdentidadExterna;
import org.clinica.repository.AuthAccountGateway.AuthAccount;

import java.time.LocalDate;
import java.time.OffsetDateTime;
import java.time.ZoneOffset;
import java.util.Optional;

/** Persistencia transaccional de identidades Google y pacientes federados. */
@ApplicationScoped
public class GoogleAccountGateway {

    private static final String PROVIDER = "google";

    @Inject
    EntityManager entityManager;

    @Inject
    UsuarioRepository usuarioRepository;

    @Inject
    AuthAccountGateway accountGateway;

    @Inject
    PatientRegistrationGateway registrationGateway;

    public Optional<LinkedGoogleIdentity> findBySubject(String subject) {
        if (subject == null || subject.isBlank()) {
            return Optional.empty();
        }
        return entityManager.createQuery("""
                        select identity from UsuarioIdentidadExterna identity
                        where identity.proveedor = :provider
                          and identity.identificadorExterno = :subject
                        """, UsuarioIdentidadExterna.class)
                .setParameter("provider", PROVIDER)
                .setParameter("subject", subject)
                .getResultStream()
                .findFirst()
                .map(this::map);
    }

    public Optional<AuthAccount> findAccountByEmail(String email) {
        return accountGateway.findByEmail(email);
    }

    public Optional<AuthAccount> findAccountById(String userId) {
        return accountGateway.findById(userId);
    }

    public boolean documentExists(String document) {
        return registrationGateway.documentExists(document);
    }

    @Transactional
    public void link(String userId, GoogleIdentity identity) {
        UsuarioIdentidadExterna external = new UsuarioIdentidadExterna();
        external.codUsu = userId;
        external.proveedor = PROVIDER;
        external.identificadorExterno = identity.subject();
        external.emailProveedor = identity.email();
        external.emailVerificado = identity.emailVerified();
        entityManager.persist(external);
        entityManager.flush();
    }

    @Transactional
    public void updateProviderMetadata(LinkedGoogleIdentity linked, GoogleIdentity identity) {
        UsuarioIdentidadExterna external = entityManager.find(
                UsuarioIdentidadExterna.class,
                linked.identityId());
        if (external == null) {
            return;
        }
        external.emailProveedor = identity.email();
        external.emailVerificado = identity.emailVerified();
        external.actualizadoEn = OffsetDateTime.now(ZoneOffset.UTC);
    }

    @Transactional
    public String registerPatient(GoogleIdentity identity, GooglePatientDetails details) {
        Usuario user = new Usuario();
        user.nombreCompleto = identity.name();
        user.email = identity.email();
        user.passwordHash = null;
        user.codRol = "ROL001";
        user.estado = "Activo";
        entityManager.persist(user);
        entityManager.flush();

        Paciente patient = new Paciente();
        patient.codUsu = user.codUsu;
        patient.ci = details.document();
        patient.fechaNacimiento = details.birthDate();
        patient.telefono = details.phone();
        patient.telefonoVerificado = false;
        patient.emailVerificado = identity.emailVerified();
        patient.emailVerificadoEn = identity.emailVerified()
                ? OffsetDateTime.now(ZoneOffset.UTC)
                : null;
        patient.ciudadResidencia = details.city();
        patient.estadoCuenta = "Activo";
        entityManager.persist(patient);
        entityManager.flush();

        link(user.codUsu, identity);
        return user.codUsu;
    }

    private LinkedGoogleIdentity map(UsuarioIdentidadExterna identity) {
        return new LinkedGoogleIdentity(
                identity.codIdentidad,
                identity.codUsu,
                identity.identificadorExterno,
                identity.emailProveedor,
                Boolean.TRUE.equals(identity.emailVerificado));
    }

    public record LinkedGoogleIdentity(
            String identityId,
            String userId,
            String subject,
            String providerEmail,
            boolean emailVerified) {
    }

    public record GoogleIdentity(String subject, String email, String name, boolean emailVerified) {
    }

    public record GooglePatientDetails(
            String document,
            LocalDate birthDate,
            String phone,
            String city) {
    }
}
