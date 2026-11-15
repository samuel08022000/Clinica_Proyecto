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
import org.clinica.config.AccesoPaciente;
import org.clinica.dto.agenda.AgendaDtos.Cupo;
import org.clinica.dto.paciente.PacienteDtos.*;
import org.clinica.dto.personal.SolicitudConCamposPermitidos;
import org.clinica.service.PacientePortalService;
import org.clinica.service.PersonalService;

import java.util.List;

/** Portal del paciente (ROL001). Todos los datos salen del token; nunca de un parámetro. */
@Path("/api/v1/paciente")
@Produces(MediaType.APPLICATION_JSON)
@Consumes(MediaType.APPLICATION_JSON)
public class PacientePortalController {

    @Inject AccesoPaciente acceso;
    @Inject PacientePortalService service;

    @GET @Path("/especialidades")
    public List<EspecialidadItem> especialidades(@HeaderParam(HttpHeaders.AUTHORIZATION) String authorization) {
        acceso.exigirPaciente(authorization);
        return service.especialidades();
    }

    @GET @Path("/medicos")
    public List<MedicoItem> medicos(@QueryParam("codEsp") String codEsp,
            @HeaderParam(HttpHeaders.AUTHORIZATION) String authorization) {
        acceso.exigirPaciente(authorization);
        return service.medicos(codEsp);
    }

    @GET @Path("/disponibilidad")
    public List<Cupo> disponibilidad(@QueryParam("codEsp") String codEsp, @QueryParam("codMed") String codMed,
            @QueryParam("fecha") String fecha, @HeaderParam(HttpHeaders.AUTHORIZATION) String authorization) {
        acceso.exigirPaciente(authorization);
        return service.disponibilidad(codEsp, codMed, fecha);
    }

    @GET @Path("/citas")
    public List<CitaPaciente> citas(@HeaderParam(HttpHeaders.AUTHORIZATION) String authorization) {
        return service.citas(acceso.exigirPaciente(authorization));
    }

    @POST @Path("/citas")
    public Response crearCita(CrearCitaSolicitud dto, @HeaderParam(HttpHeaders.AUTHORIZATION) String authorization) {
        var paciente = acceso.exigirPaciente(authorization);
        validarSolicitud(dto);
        return Response.status(Response.Status.CREATED).entity(service.crearCita(paciente, dto.codSlot)).build();
    }

    @PUT @Path("/citas/{codCit}/cancelar")
    public Response cancelarCita(@PathParam("codCit") String codCit,
            @HeaderParam(HttpHeaders.AUTHORIZATION) String authorization) {
        service.cancelarCita(acceso.exigirPaciente(authorization), codCit);
        return Response.noContent().build();
    }

    @GET @Path("/perfil")
    public PerfilRespuesta perfil(@HeaderParam(HttpHeaders.AUTHORIZATION) String authorization) {
        return service.perfil(acceso.exigirPaciente(authorization));
    }

    @PUT @Path("/perfil")
    public PerfilRespuesta actualizarPerfil(PerfilSolicitud dto,
            @HeaderParam(HttpHeaders.AUTHORIZATION) String authorization) {
        var paciente = acceso.exigirPaciente(authorization);
        validarSolicitud(dto);
        return service.actualizarPerfil(paciente, dto);
    }

    private static void validarSolicitud(SolicitudConCamposPermitidos dto) {
        if (dto == null || dto.tieneCamposNoPermitidos()) {
            throw PersonalService.error(Response.Status.BAD_REQUEST, "VALIDACION",
                    "Se requiere un cuerpo JSON con los campos permitidos.");
        }
    }
}
