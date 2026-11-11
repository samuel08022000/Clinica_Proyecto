package org.clinica.controller;

import jakarta.inject.Inject;
import jakarta.ws.rs.Consumes;
import jakarta.ws.rs.GET;
import jakarta.ws.rs.HeaderParam;
import jakarta.ws.rs.POST;
import jakarta.ws.rs.Path;
import jakarta.ws.rs.PathParam;
import jakarta.ws.rs.Produces;
import jakarta.ws.rs.QueryParam;
import jakarta.ws.rs.core.HttpHeaders;
import jakarta.ws.rs.core.CacheControl;
import jakarta.ws.rs.core.MediaType;
import jakarta.ws.rs.core.Response;
import org.clinica.config.BearerToken;
import org.clinica.dto.agenda.AgendaDtos.Cupo;
import org.clinica.dto.personal.EspecialidadOpcionDTO;
import org.clinica.dto.recepcion.RecepcionDtos.CitaRecepcion;
import org.clinica.dto.recepcion.RecepcionDtos.MedicoOpcion;
import org.clinica.dto.recepcion.RecepcionDtos.NuevaCita;
import org.clinica.dto.recepcion.RecepcionDtos.CheckinSolicitud;
import org.clinica.dto.recepcion.RecepcionDtos.PacienteDetalle;
import org.clinica.dto.recepcion.RecepcionDtos.PacientesEncontrados;
import org.clinica.service.AuthService;
import org.clinica.service.AtencionCheckinService;
import org.clinica.service.PersonalService;
import org.clinica.service.RecepcionService;
import org.clinica.service.ReservaCitaService;

import java.util.List;
import java.util.Set;

@Path("/api/v1/secretaria")
@Produces(MediaType.APPLICATION_JSON)
@Consumes(MediaType.APPLICATION_JSON)
public class SecretariaController {
    @Inject AuthService authService;
    @Inject RecepcionService recepcion;
    @Inject ReservaCitaService reservas;
    @Inject AtencionCheckinService checkin;

    @GET
    @Path("/pacientes")
    public PacientesEncontrados pacientes(@QueryParam("q") String termino,
            @HeaderParam(HttpHeaders.AUTHORIZATION) String authorization) {
        exigirSecretaria(authorization);
        return recepcion.buscarPacientes(termino);
    }

    @GET
    @Path("/pacientes/{codPac}")
    public PacienteDetalle paciente(@PathParam("codPac") String codPac,
            @HeaderParam(HttpHeaders.AUTHORIZATION) String authorization) {
        exigirSecretaria(authorization);
        return recepcion.paciente(codPac);
    }

    @GET
    @Path("/especialidades")
    public List<EspecialidadOpcionDTO> especialidades(@HeaderParam(HttpHeaders.AUTHORIZATION) String authorization) {
        exigirSecretaria(authorization);
        return recepcion.especialidades();
    }

    @GET
    @Path("/medicos")
    public List<MedicoOpcion> medicos(@QueryParam("codEsp") String codEsp,
            @HeaderParam(HttpHeaders.AUTHORIZATION) String authorization) {
        exigirSecretaria(authorization);
        return recepcion.medicos(codEsp);
    }

    @GET
    @Path("/disponibilidad")
    public List<Cupo> disponibilidad(@QueryParam("codEsp") String codEsp, @QueryParam("codMed") String codMed,
            @QueryParam("fecha") String fecha, @QueryParam("codPac") String codPac,
            @HeaderParam(HttpHeaders.AUTHORIZATION) String authorization) {
        exigirSecretaria(authorization);
        return recepcion.disponibilidad(codEsp, codMed, fecha, codPac);
    }

    @POST
    @Path("/citas")
    public Response reservar(NuevaCita solicitud, @HeaderParam(HttpHeaders.AUTHORIZATION) String authorization) {
        String actor = exigirSecretaria(authorization);
        if (solicitud == null || solicitud.tieneCamposNoPermitidos()) {
            throw PersonalService.error(Response.Status.BAD_REQUEST, "VALIDACION",
                    "La reserva requiere únicamente codPac y codSlot.");
        }
        return Response.status(Response.Status.CREATED).entity(reservas.reservarPresencial(solicitud, actor)).build();
    }

    @GET
    @Path("/citas/{codCit}")
    public CitaRecepcion cita(@PathParam("codCit") String codCit,
            @HeaderParam(HttpHeaders.AUTHORIZATION) String authorization) {
        exigirSecretaria(authorization);
        return reservas.consultarRecepcion(codCit);
    }

    @GET
    @Path("/citas/{codCit}/acreditacion")
    public Response acreditacion(@PathParam("codCit") String codCit,
            @HeaderParam(HttpHeaders.AUTHORIZATION) String authorization) {
        exigirSecretaria(authorization);
        return respuestaPrivada(checkin.acreditacion(codCit));
    }

    @GET
    @Path("/citas/{codCit}/asistencia")
    public Response asistencia(@PathParam("codCit") String codCit,
            @HeaderParam(HttpHeaders.AUTHORIZATION) String authorization) {
        exigirSecretaria(authorization);
        return respuestaPrivada(checkin.asistencia(codCit));
    }

    @POST
    @Path("/checkin")
    public Response registrarLlegada(CheckinSolicitud solicitud,
            @HeaderParam(HttpHeaders.AUTHORIZATION) String authorization) {
        String actor = exigirSecretaria(authorization);
        return respuestaPrivada(checkin.registrar(solicitud, actor));
    }

    private static Response respuestaPrivada(Object entidad) {
        CacheControl cache = new CacheControl();
        cache.setNoStore(true);
        return Response.ok(entidad).cacheControl(cache).header("Pragma", "no-cache").build();
    }

    private String exigirSecretaria(String authorization) {
        return authService.requireAnyRole(BearerToken.bearerToken(authorization), Set.of("ROL002")).user().id();
    }
}
