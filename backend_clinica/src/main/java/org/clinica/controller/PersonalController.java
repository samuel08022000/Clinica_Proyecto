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
import org.clinica.dto.personal.EspecialidadDetalleDTO;
import org.clinica.dto.personal.EspecialidadDetalleDTO.MedicoVinculado;
import org.clinica.dto.personal.EspecialidadSolicitudes;
import org.clinica.dto.personal.MedicoRespuestaDTO;
import org.clinica.dto.personal.SecretariaRespuestaDTO;
import org.clinica.dto.personal.SecretariaSolicitudes;
import org.clinica.dto.personal.SolicitudConCamposPermitidos;
import org.clinica.service.AuthException;
import org.clinica.service.AuthService;
import org.clinica.service.EspecialidadService;
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
    EspecialidadService especialidadService;

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
    public Response listarEspecialidades(@QueryParam("estado") String estado,
            @HeaderParam(HttpHeaders.AUTHORIZATION) String authorization) {
        exigirAdmin(authorization);
        if (estado == null || estado.isBlank()) {
            return Response.ok(service.listarEspecialidadesActivas()).build();
        }
        return Response.ok(especialidadService.listar(estado)).build();
    }

    @GET
    @Path("/especialidades/{codEsp}")
    public EspecialidadDetalleDTO obtenerEspecialidad(@PathParam("codEsp") String codEsp,
            @HeaderParam(HttpHeaders.AUTHORIZATION) String authorization) {
        exigirAdmin(authorization);
        return especialidadService.obtener(codEsp);
    }

    @POST
    @Path("/especialidades")
    public Response crearEspecialidad(EspecialidadSolicitudes.Crear dto,
            @HeaderParam(HttpHeaders.AUTHORIZATION) String authorization) {
        String actor = exigirAdmin(authorization);
        exigirSolicitud(dto);
        return Response.status(Response.Status.CREATED).entity(especialidadService.registrar(dto, actor)).build();
    }

    @PUT
    @Path("/especialidades/{codEsp}")
    public EspecialidadDetalleDTO actualizarEspecialidad(@PathParam("codEsp") String codEsp,
            EspecialidadSolicitudes.Actualizar dto, @HeaderParam(HttpHeaders.AUTHORIZATION) String authorization) {
        String actor = exigirAdmin(authorization);
        exigirSolicitud(dto);
        return especialidadService.actualizar(codEsp, dto, actor);
    }

    @PUT
    @Path("/especialidades/{codEsp}/estado")
    public EspecialidadDetalleDTO cambiarEstadoEspecialidad(@PathParam("codEsp") String codEsp,
            CambiarEstadoDTO dto, @HeaderParam(HttpHeaders.AUTHORIZATION) String authorization) {
        String actor = exigirAdmin(authorization);
        exigirSolicitud(dto);
        return especialidadService.cambiarEstado(codEsp, dto.estado, actor);
    }

    @GET
    @Path("/especialidades/{codEsp}/medicos")
    public List<MedicoVinculado> listarMedicosVinculados(@PathParam("codEsp") String codEsp,
            @HeaderParam(HttpHeaders.AUTHORIZATION) String authorization) {
        exigirAdmin(authorization);
        return especialidadService.listarMedicos(codEsp);
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
        exigirSolicitud(dto);
        return Response.status(Response.Status.CREATED).entity(secretariaService.registrar(dto, actor)).build();
    }

    @PUT
    @Path("/secretarias/{codSec}")
    public SecretariaRespuestaDTO actualizarSecretaria(@PathParam("codSec") String codSec,
            SecretariaSolicitudes.Actualizar dto, @HeaderParam(HttpHeaders.AUTHORIZATION) String authorization) {
        String actor = exigirAdmin(authorization);
        exigirSolicitud(dto);
        return secretariaService.actualizar(codSec, dto, actor);
    }

    @PUT
    @Path("/secretarias/{codSec}/estado")
    public SecretariaRespuestaDTO cambiarEstadoSecretaria(@PathParam("codSec") String codSec,
            SecretariaSolicitudes.CambiarEstado dto, @HeaderParam(HttpHeaders.AUTHORIZATION) String authorization) {
        String actor = exigirAdmin(authorization);
        exigirSolicitud(dto);
        return secretariaService.cambiarEstado(codSec, dto.estado, actor);
    }

    @PUT
    @Path("/secretarias/{codSec}/password")
    public Response restablecerPasswordSecretaria(@PathParam("codSec") String codSec,
            SecretariaSolicitudes.RestablecerPassword dto,
            @HeaderParam(HttpHeaders.AUTHORIZATION) String authorization) {
        String actor = exigirAdmin(authorization);
        exigirSolicitud(dto);
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

    private static void exigirSolicitud(SolicitudConCamposPermitidos dto) {
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
