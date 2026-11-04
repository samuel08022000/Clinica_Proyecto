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
import jakarta.ws.rs.core.MediaType;
import jakarta.ws.rs.core.Response;
import org.clinica.dto.personal.ActualizarMedicoDTO;
import org.clinica.dto.personal.CambiarEstadoDTO;
import org.clinica.dto.personal.CrearMedicoDTO;
import org.clinica.dto.personal.EspecialidadOpcionDTO;
import org.clinica.dto.personal.MedicoRespuestaDTO;
import org.clinica.service.PersonalService;

import java.util.List;

/**
 * Módulo 2A — Gestión de doctores. Solo el Administrador (ROL004).
 * Laravel envía el actor en los headers X-Actor-Cod-Usu y X-Actor-Rol (ver QuarkusApiClient).
 */
@Path("/api/v1/personal")
@Produces(MediaType.APPLICATION_JSON)
@Consumes(MediaType.APPLICATION_JSON)
public class PersonalController {

    private static final String ROL_ADMIN = "ROL004";

    @Inject
    PersonalService service;

    @GET
    @Path("/medicos")
    public List<MedicoRespuestaDTO> listar(@QueryParam("estado") String estado,
                                           @HeaderParam("X-Actor-Rol") String rol) {
        exigirAdmin(rol);
        return service.listar(estado);
    }

    @GET
    @Path("/medicos/{codMed}")
    public MedicoRespuestaDTO obtener(@PathParam("codMed") String codMed,
                                      @HeaderParam("X-Actor-Rol") String rol) {
        exigirAdmin(rol);
        return service.obtener(codMed);
    }

    @GET
    @Path("/especialidades")
    public List<EspecialidadOpcionDTO> especialidadesActivas(@HeaderParam("X-Actor-Rol") String rol) {
        exigirAdmin(rol);
        return service.listarEspecialidadesActivas();
    }

    @POST
    @Path("/medicos")
    public Response crear(CrearMedicoDTO dto,
                          @HeaderParam("X-Actor-Cod-Usu") String actor,
                          @HeaderParam("X-Actor-Rol") String rol) {
        exigirAdmin(rol);
        exigirActor(actor);
        exigirCuerpo(dto);
        return Response.status(Response.Status.CREATED).entity(service.registrarMedico(dto, actor)).build();
    }

    @PUT
    @Path("/medicos/{codMed}")
    public MedicoRespuestaDTO actualizar(@PathParam("codMed") String codMed,
                                         ActualizarMedicoDTO dto,
                                         @HeaderParam("X-Actor-Cod-Usu") String actor,
                                         @HeaderParam("X-Actor-Rol") String rol) {
        exigirAdmin(rol);
        exigirActor(actor);
        exigirCuerpo(dto);
        return service.actualizarMedico(codMed, dto, actor);
    }

    /** Inactiva o reactiva (no se elimina nunca: RNF-06). */
    @PUT
    @Path("/medicos/{codMed}/estado")
    public MedicoRespuestaDTO cambiarEstado(@PathParam("codMed") String codMed,
                                            CambiarEstadoDTO dto,
                                            @HeaderParam("X-Actor-Cod-Usu") String actor,
                                            @HeaderParam("X-Actor-Rol") String rol) {
        exigirAdmin(rol);
        exigirActor(actor);
        exigirCuerpo(dto);
        return service.cambiarEstado(codMed, dto.estado, actor);
    }

    private static void exigirAdmin(String rol) {
        if (!ROL_ADMIN.equals(rol)) {
            throw PersonalService.error(Response.Status.FORBIDDEN, "SIN_PERMISO",
                    "Solo el administrador puede gestionar doctores.");
        }
    }

    /** En escrituras el actor es obligatorio: queda registrado en la bitácora. */
    private static void exigirActor(String actor) {
        if (actor == null || actor.isBlank()) {
            throw PersonalService.error(Response.Status.FORBIDDEN, "SIN_PERMISO",
                    "Falta el usuario que realiza la operación.");
        }
    }

    private static void exigirCuerpo(Object dto) {
        if (dto == null) {
            throw PersonalService.error(Response.Status.BAD_REQUEST, "VALIDACION", "El cuerpo de la petición es obligatorio.");
        }
    }
}
