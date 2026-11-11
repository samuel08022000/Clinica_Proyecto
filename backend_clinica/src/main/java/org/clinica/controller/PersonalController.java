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
import org.clinica.service.AuthException;
import org.clinica.service.AuthService;
import org.clinica.service.PersonalService;

import java.util.List;
import java.util.Set;

import static org.clinica.config.BearerToken.bearerToken;

/** Gestión de doctores reservada al Administrador (ROL004). */
@Path("/api/v1/personal")
@Produces(MediaType.APPLICATION_JSON)
@Consumes(MediaType.APPLICATION_JSON)
public class PersonalController {

    private static final Set<String> ROLES_PERMITIDOS = Set.of("ROL004");

    @Inject
    PersonalService service;

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

    private String exigirAdmin(String authorization) {
        try {
            return authService.requireAnyRole(bearerToken(authorization), ROLES_PERMITIDOS)
                    .user().id();
        } catch (AuthException exception) {
            if ("FORBIDDEN".equals(exception.code())) {
                throw PersonalService.error(Response.Status.FORBIDDEN, "SIN_PERMISO",
                        "Solo el administrador puede gestionar doctores.");
            }
            throw exception;
        }
    }

    private static void exigirCuerpo(Object dto) {
        if (dto == null) {
            throw PersonalService.error(Response.Status.BAD_REQUEST, "VALIDACION", "El cuerpo de la petición es obligatorio.");
        }
    }
}
