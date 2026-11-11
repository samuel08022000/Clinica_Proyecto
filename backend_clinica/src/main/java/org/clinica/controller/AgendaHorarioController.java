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
import org.clinica.config.AccesoAdministrativo;
import org.clinica.dto.agenda.AgendaDtos.*;
import org.clinica.dto.personal.CambiarEstadoDTO;
import org.clinica.dto.personal.SolicitudConCamposPermitidos;
import org.clinica.service.AgendaHorarioService;
import org.clinica.service.PersonalService;

import java.util.List;

@Path("/api/v1/administracion")
@Produces(MediaType.APPLICATION_JSON)
@Consumes(MediaType.APPLICATION_JSON)
public class AgendaHorarioController {
    @Inject AccesoAdministrativo acceso;
    @Inject AgendaHorarioService service;

    @GET @Path("/medicos/{codMed}/horarios")
    public List<HorarioRespuesta> horarios(@PathParam("codMed") String codMed,
            @HeaderParam(HttpHeaders.AUTHORIZATION) String authorization) {
        acceso.exigirAdmin(authorization);
        return service.horarios(codMed);
    }

    @POST @Path("/medicos/{codMed}/horarios")
    public Response crearHorario(@PathParam("codMed") String codMed, HorarioSolicitud dto,
            @HeaderParam(HttpHeaders.AUTHORIZATION) String authorization) {
        String actor = acceso.exigirAdmin(authorization);
        validarSolicitud(dto);
        return Response.status(201).entity(service.crearHorario(codMed,dto,actor)).build();
    }

    @PUT @Path("/horarios/{codHor}")
    public HorarioRespuesta actualizarHorario(@PathParam("codHor") String codHor, HorarioSolicitud dto,
            @HeaderParam(HttpHeaders.AUTHORIZATION) String authorization) {
        String actor = acceso.exigirAdmin(authorization);
        validarSolicitud(dto);
        return service.actualizarHorario(codHor,dto,actor);
    }

    @PUT @Path("/horarios/{codHor}/estado")
    public HorarioRespuesta estadoHorario(@PathParam("codHor") String codHor, CambiarEstadoDTO dto,
            @HeaderParam(HttpHeaders.AUTHORIZATION) String authorization) {
        String actor = acceso.exigirAdmin(authorization);
        validarSolicitud(dto);
        return service.estadoHorario(codHor,dto.estado,actor);
    }

    @PUT @Path("/medicos/{codMed}/duracion")
    public DuracionRespuesta duracion(@PathParam("codMed") String codMed, DuracionSolicitud dto,
            @HeaderParam(HttpHeaders.AUTHORIZATION) String authorization) {
        String actor = acceso.exigirAdmin(authorization);
        validarSolicitud(dto);
        return service.actualizarDuracion(codMed,dto,actor);
    }

    @GET @Path("/medicos/{codMed}/bloqueos")
    public List<BloqueoRespuesta> bloqueos(@PathParam("codMed") String codMed, @QueryParam("desde") String desde,
            @QueryParam("hasta") String hasta, @HeaderParam(HttpHeaders.AUTHORIZATION) String authorization) {
        acceso.exigirAdmin(authorization);
        return service.bloqueos(codMed,desde,hasta);
    }

    @POST @Path("/medicos/{codMed}/bloqueos")
    public Response crearBloqueo(@PathParam("codMed") String codMed, BloqueoSolicitud dto,
            @HeaderParam(HttpHeaders.AUTHORIZATION) String authorization) {
        String actor = acceso.exigirAdmin(authorization);
        validarSolicitud(dto);
        return Response.status(201).entity(service.crearBloqueo(codMed,dto,actor)).build();
    }

    @GET @Path("/bloqueos/{codBlo}")
    public BloqueoRespuesta bloqueo(@PathParam("codBlo") String codBlo,
            @HeaderParam(HttpHeaders.AUTHORIZATION) String authorization) {
        acceso.exigirAdmin(authorization);
        return service.bloqueo(codBlo);
    }

    @GET @Path("/bloqueos/{codBlo}/citas-afectadas")
    public CitasAfectadas citas(@PathParam("codBlo") String codBlo,
            @HeaderParam(HttpHeaders.AUTHORIZATION) String authorization) {
        acceso.exigirAdmin(authorization);
        return service.citasAfectadas(codBlo);
    }

    @GET @Path("/disponibilidad")
    public List<Cupo> disponibilidad(@QueryParam("codEsp") String codEsp, @QueryParam("codMed") String codMed,
            @QueryParam("fecha") String fecha, @HeaderParam(HttpHeaders.AUTHORIZATION) String authorization) {
        acceso.exigirAdmin(authorization);
        return service.disponibilidad(codEsp,codMed,fecha);
    }

    private static void validarSolicitud(SolicitudConCamposPermitidos dto) {
        if (dto == null || dto.tieneCamposNoPermitidos()) {
            throw PersonalService.error(Response.Status.BAD_REQUEST,"VALIDACION",
                    "Se requiere un cuerpo JSON con los campos permitidos.");
        }
    }
}
