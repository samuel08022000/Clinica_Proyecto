package org.clinica.service;

import io.quarkus.elytron.security.common.BcryptUtil;
import jakarta.enterprise.context.ApplicationScoped;
import jakarta.inject.Inject;
import jakarta.persistence.EntityManager;
import jakarta.persistence.LockModeType;
import jakarta.transaction.Transactional;
import jakarta.ws.rs.WebApplicationException;
import jakarta.ws.rs.core.Response;
import org.clinica.config.DbActorHelper;
import org.clinica.dto.personal.SecretariaRespuestaDTO;
import org.clinica.dto.personal.SecretariaSolicitudes.Actualizar;
import org.clinica.dto.personal.SecretariaSolicitudes.Crear;
import org.clinica.entity.Secretaria;
import org.clinica.entity.Usuario;
import org.clinica.repository.PersonalRepository;
import org.clinica.repository.UsuarioRepository;

import java.time.OffsetDateTime;
import java.time.ZoneOffset;
import java.util.List;
import java.util.Locale;

import static org.clinica.service.PersonalService.error;
import static org.clinica.service.PersonalService.vacioANull;
import static org.clinica.service.PersonalService.validarEstado;
import static org.clinica.service.PersonalService.validarFiltroEstado;
import static org.clinica.service.PersonalService.validarNombreYCorreo;

@ApplicationScoped
public class SecretariaService {

    private static final String ROL_SECRETARIA = "ROL002";

    @Inject DbActorHelper actorHelper;
    @Inject EntityManager em;
    @Inject UsuarioRepository usuarios;
    @Inject PersonalRepository personal;
    @Inject AuthService authService;

    public List<SecretariaRespuestaDTO> listar(String estado) {
        return personal.listarSecretarias(validarFiltroEstado(estado));
    }

    public SecretariaRespuestaDTO obtener(String codSec) {
        return personal.obtenerSecretaria(codSec).orElseThrow(() -> noEncontrada(codSec));
    }

    @Transactional
    public SecretariaRespuestaDTO registrar(Crear dto, String actor) {
        DatosPerfil datos = validarPerfil(dto);
        verificarCorreo(datos.email(), null);
        String hash = hashPassword(dto.password);
        actorHelper.fijarActor(actor);

        String codUsu = usuarios.insertarPersonal(datos.nombre(), datos.email(), hash, ROL_SECRETARIA);
        String codSec = (String) em.createNativeQuery("""
                INSERT INTO public.secretarias (cod_usu, ci, telefono)
                VALUES (:usuario, CAST(:ci AS varchar), CAST(:telefono AS varchar))
                RETURNING cod_sec
                """).setParameter("usuario", codUsu).setParameter("ci", datos.ci())
                .setParameter("telefono", datos.telefono()).getSingleResult();
        return obtener(codSec);
    }

    @Transactional
    public SecretariaRespuestaDTO actualizar(String codSec, Actualizar dto, String actor) {
        CuentaSecretaria cuenta = bloquearCuenta(codSec);
        DatosPerfil datos = validarPerfil(dto);
        Usuario usuario = cuenta.usuario();
        verificarCorreo(datos.email(), usuario.codUsu);
        actorHelper.fijarActor(actor);

        usuario.nombreCompleto = datos.nombre();
        usuario.email = datos.email();
        usuario.actualizadoEn = OffsetDateTime.now(ZoneOffset.UTC);
        cuenta.perfil().ci = datos.ci();
        cuenta.perfil().telefono = datos.telefono();
        return cuenta.respuesta();
    }

    @Transactional
    public SecretariaRespuestaDTO cambiarEstado(String codSec, String estado, String actor) {
        validarEstado(estado);
        CuentaSecretaria cuenta = bloquearCuenta(codSec);
        actorHelper.fijarActor(actor);

        cuenta.perfil().estado = estado;
        cuenta.usuario().estado = estado;
        cuenta.usuario().actualizadoEn = OffsetDateTime.now(ZoneOffset.UTC);
        if ("Inactivo".equals(estado)) {
            authService.revokeAllForUserAfterCommit(cuenta.usuario().codUsu);
        }
        return cuenta.respuesta();
    }

    @Transactional
    public void restablecerPassword(String codSec, String password, String actor) {
        String hash = hashPassword(password);
        CuentaSecretaria cuenta = bloquearCuenta(codSec);
        actorHelper.fijarActor(actor);

        cuenta.usuario().passwordHash = hash;
        cuenta.usuario().actualizadoEn = OffsetDateTime.now(ZoneOffset.UTC);
        authService.revokeAllForUserAfterCommit(cuenta.usuario().codUsu);
    }

    private CuentaSecretaria bloquearCuenta(String codSec) {
        Secretaria perfil = em.find(Secretaria.class, codSec, LockModeType.PESSIMISTIC_WRITE);
        if (perfil == null) {
            throw noEncontrada(codSec);
        }
        Usuario usuario = em.find(Usuario.class, perfil.codUsu, LockModeType.PESSIMISTIC_WRITE);
        if (usuario == null || !ROL_SECRETARIA.equals(usuario.codRol)) {
            throw noEncontrada(codSec);
        }
        return new CuentaSecretaria(perfil, usuario);
    }

    private void verificarCorreo(String email, String codUsu) {
        usuarios.findByEmail(email).filter(usuario -> !usuario.codUsu.equals(codUsu)).ifPresent(usuario -> {
            throw error(Response.Status.CONFLICT, "DUPLICADO", "Ya existe un usuario con ese correo.");
        });
    }

    private static DatosPerfil validarPerfil(Actualizar dto) {
        String nombre = vacioANull(dto.nombreCompleto);
        String email = dto.email == null ? null : dto.email.trim().toLowerCase(Locale.ROOT);
        String ci = vacioANull(dto.ci);
        String telefono = vacioANull(dto.telefono);
        validarNombreYCorreo(nombre, email);
        if (ci != null && ci.length() > 30) {
            throw error(Response.Status.BAD_REQUEST, "VALIDACION", "El CI no puede superar 30 caracteres.");
        }
        if (telefono != null && telefono.length() > 25) {
            throw error(Response.Status.BAD_REQUEST, "VALIDACION", "El teléfono no puede superar 25 caracteres.");
        }
        return new DatosPerfil(nombre, email, ci, telefono);
    }

    private static String hashPassword(String password) {
        try {
            PasswordRecoveryService.validatePassword(password);
        } catch (AuthException exception) {
            throw error(Response.Status.BAD_REQUEST, "VALIDACION", exception.getMessage());
        }
        return BcryptUtil.bcryptHash(password, 12);
    }

    private static WebApplicationException noEncontrada(String codSec) {
        return error(Response.Status.NOT_FOUND, "NO_ENCONTRADO", "No existe el perfil de secretaría " + codSec + ".");
    }

    private record DatosPerfil(String nombre, String email, String ci, String telefono) {
    }

    private record CuentaSecretaria(Secretaria perfil, Usuario usuario) {
        SecretariaRespuestaDTO respuesta() {
            return new SecretariaRespuestaDTO(perfil.codSec, usuario.codUsu, usuario.nombreCompleto, usuario.email,
                    perfil.ci, perfil.telefono, perfil.estado);
        }
    }
}
