package org.clinica.controller;

import jakarta.inject.Inject;
import jakarta.ws.rs.Consumes;
import jakarta.ws.rs.GET;
import jakarta.ws.rs.HeaderParam;
import jakarta.ws.rs.POST;
import jakarta.ws.rs.PUT;
import jakarta.ws.rs.Path;
import jakarta.ws.rs.PathParam;
import jakarta.ws.rs.Produces;
import jakarta.ws.rs.QueryParam;
import jakarta.ws.rs.core.HttpHeaders;
import jakarta.ws.rs.core.MediaType;
import jakarta.ws.rs.core.Response;
import org.clinica.dto.personal.ActualizarMedicoDTO;
import org.clinica.dto.personal.CambiarEstadoDTO;
import org.clinica.dto.personal.CrearMedicoDTO;
import org.clinica.dto.personal.EspecialidadOpcionDTO;
import org.clinica.dto.personal.MedicoRespuestaDTO;
import org.clinica.dto.personal.SecretariaRespuestaDTO;
import org.clinica.dto.personal.SecretariaSolicitudes;
import org.clinica.service.AuthException;
import org.clinica.service.AuthService;
import org.clinica.service.PersonalService;
import org.clinica.service.SecretariaService;

import java.util.List;
import java.util.Set;

import static org.clinica.config.BearerToken.bearerToken;

/** Gestión de personal reservada al Administrador (ROL004). */
@Path("/api/v1/personal")
@Produces(MediaType.APPLICATION_JSON)
@Consumes(MediaType.APPLICATION_JSON)
public class PersonalController {

    private static final Set<String> ROLES_PERMITIDOS = Set.of("ROL004");

    @Inject
    PersonalService service;

    @Inject
    SecretariaService secretariaService;

    @Inject
    AuthService authService;

    @GET
    @Path("/medicos")
    public List<MedicoRespuestaDTO> listar(@QueryParam("estado") String estado,
                                           @HeaderParam(HttpHeaders.AUTHORIZATION) String authorization) {
        exigirAdmin(authorization);
        return service.listar(estado);
    }

    @GET
    @Path("/medicos/{codMed}")
    public MedicoRespuestaDTO obtener(@PathParam("codMed") String codMed,
                                      @HeaderParam(HttpHeaders.AUTHORIZATION) String authorization) {
        exigirAdmin(authorization);
        return service.obtener(codMed);
    }

    @GET
    @Path("/especialidades")
    public List<EspecialidadOpcionDTO> especialidadesActivas(
            @HeaderParam(HttpHeaders.AUTHORIZATION) String authorization) {
        exigirAdmin(authorization);
        return service.listarEspecialidadesActivas();
    }

    @POST
    @Path("/medicos")
    public Response crear(CrearMedicoDTO dto,
                          @HeaderParam(HttpHeaders.AUTHORIZATION) String authorization) {
        String actor = exigirAdmin(authorization);
        exigirCuerpo(dto);
        return Response.status(Response.Status.CREATED).entity(service.registrarMedico(dto, actor)).build();
    }

    @PUT
    @Path("/medicos/{codMed}")
    public MedicoRespuestaDTO actualizar(@PathParam("codMed") String codMed,
                                         ActualizarMedicoDTO dto,
                                         @HeaderParam(HttpHeaders.AUTHORIZATION) String authorization) {
        String actor = exigirAdmin(authorization);
        exigirCuerpo(dto);
        return service.actualizarMedico(codMed, dto, actor);
    }

    /** Inactiva o reactiva (no se elimina nunca: RNF-06). */
    @PUT
    @Path("/medicos/{codMed}/estado")
    public MedicoRespuestaDTO cambiarEstado(@PathParam("codMed") String codMed,
                                            CambiarEstadoDTO dto,
                                            @HeaderParam(HttpHeaders.AUTHORIZATION) String authorization) {
        String actor = exigirAdmin(authorization);
        exigirCuerpo(dto);
        return service.cambiarEstado(codMed, dto.estado, actor);
    }

    @GET
    @Path("/secretarias")
    public List<SecretariaRespuestaDTO> listarSecretarias(@QueryParam("estado") String estado,
            @HeaderParam(HttpHeaders.AUTHORIZATION) String authorization) {
        exigirAdmin(authorization);
        return secretariaService.listar(estado);
    }

    @GET
    @Path("/secretarias/{codSec}")
    public SecretariaRespuestaDTO obtenerSecretaria(@PathParam("codSec") String codSec,
            @HeaderParam(HttpHeaders.AUTHORIZATION) String authorization) {
        exigirAdmin(authorization);
        return secretariaService.obtener(codSec);
    }

    @POST
    @Path("/secretarias")
    public Response crearSecretaria(SecretariaSolicitudes.Crear dto,
            @HeaderParam(HttpHeaders.AUTHORIZATION) String authorization) {
        String actor = exigirAdmin(authorization);
        exigirSolicitudSecretaria(dto);
        return Response.status(Response.Status.CREATED).entity(secretariaService.registrar(dto, actor)).build();
    }

    @PUT
    @Path("/secretarias/{codSec}")
    public SecretariaRespuestaDTO actualizarSecretaria(@PathParam("codSec") String codSec,
            SecretariaSolicitudes.Actualizar dto, @HeaderParam(HttpHeaders.AUTHORIZATION) String authorization) {
        String actor = exigirAdmin(authorization);
        exigirSolicitudSecretaria(dto);
        return secretariaService.actualizar(codSec, dto, actor);
    }

    @PUT
    @Path("/secretarias/{codSec}/estado")
    public SecretariaRespuestaDTO cambiarEstadoSecretaria(@PathParam("codSec") String codSec,
            SecretariaSolicitudes.CambiarEstado dto, @HeaderParam(HttpHeaders.AUTHORIZATION) String authorization) {
        String actor = exigirAdmin(authorization);
        exigirSolicitudSecretaria(dto);
        return secretariaService.cambiarEstado(codSec, dto.estado, actor);
    }

    @PUT
    @Path("/secretarias/{codSec}/password")
    public Response restablecerPasswordSecretaria(@PathParam("codSec") String codSec,
            SecretariaSolicitudes.RestablecerPassword dto,
            @HeaderParam(HttpHeaders.AUTHORIZATION) String authorization) {
        String actor = exigirAdmin(authorization);
        exigirSolicitudSecretaria(dto);
        secretariaService.restablecerPassword(codSec, dto.password, actor);
        return Response.noContent().build();
    }

    private String exigirAdmin(String authorization) {
        try {
            return authService.requireAnyRole(bearerToken(authorization), ROLES_PERMITIDOS)
                    .user().id();
        } catch (AuthException exception) {
            if ("FORBIDDEN".equals(exception.code())) {
                throw PersonalService.error(Response.Status.FORBIDDEN, "SIN_PERMISO",
                        "Solo el administrador puede gestionar cuentas de personal.");
            }
            throw exception;
        }
    }

    private static void exigirSolicitudSecretaria(SecretariaSolicitudes.Solicitud dto) {
        exigirCuerpo(dto);
        if (dto.tieneCamposNoPermitidos()) {
            throw PersonalService.error(Response.Status.BAD_REQUEST, "VALIDACION",
                    "La petición contiene campos no permitidos.");
        }
    }

    private static void exigirCuerpo(Object dto) {
        if (dto == null) {
            throw PersonalService.error(Response.Status.BAD_REQUEST, "VALIDACION", "El cuerpo de la petición es obligatorio.");
        }
    }
}
