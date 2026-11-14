package org.clinica.controller;

import jakarta.inject.Inject;
import jakarta.ws.rs.Consumes;
import jakarta.ws.rs.DefaultValue;
import jakarta.ws.rs.GET;
import jakarta.ws.rs.HeaderParam;
import jakarta.ws.rs.POST;
import jakarta.ws.rs.PATCH;
import jakarta.ws.rs.Path;
import jakarta.ws.rs.PathParam;
import jakarta.ws.rs.Produces;
import jakarta.ws.rs.QueryParam;
import jakarta.ws.rs.core.CacheControl;
import jakarta.ws.rs.core.Context;
import jakarta.ws.rs.core.HttpHeaders;
import jakarta.ws.rs.core.MediaType;
import jakarta.ws.rs.core.Response;
import jakarta.ws.rs.core.UriInfo;
import org.clinica.dto.medico.MedicoDtos.AtenderSolicitud;
import org.clinica.service.MedicoAtencionService;
import org.clinica.service.MedicoDerivacionService;
import org.clinica.service.MedicoAnalisisService;
import org.clinica.service.RecepcionService;
import org.clinica.dto.medico.SeguimientoDtos.*;

@Path("/api/v1/medico")
@Produces(MediaType.APPLICATION_JSON)
@Consumes(MediaType.APPLICATION_JSON)
public class MedicoController {
    @Inject MedicoAtencionService atencion;
    @Inject MedicoDerivacionService derivaciones;
    @Inject MedicoAnalisisService analisis;
    @Inject RecepcionService recepcion;
    @Context UriInfo uriInfo;

    @GET
    @Path("/agenda")
    public Response agenda(@QueryParam("fecha") String fecha,
            @HeaderParam(HttpHeaders.AUTHORIZATION) String authorization) {
        return privada(atencion.agenda(authorization, fechaSuministrada("fecha", fecha)));
    }

    @GET
    @Path("/citas/{codCit}")
    public Response detalle(@PathParam("codCit") String codCit,
            @HeaderParam(HttpHeaders.AUTHORIZATION) String authorization) {
        return privada(atencion.detalle(authorization, codCit));
    }

    @POST
    @Path("/citas/{codCit}/atender")
    public Response atender(@PathParam("codCit") String codCit, AtenderSolicitud solicitud,
            @HeaderParam(HttpHeaders.AUTHORIZATION) String authorization) {
        return privada(atencion.atender(authorization, codCit, solicitud));
    }

    @GET
    @Path("/historial")
    public Response historial(@QueryParam("desde") String desde, @QueryParam("hasta") String hasta,
            @QueryParam("page") @DefaultValue("0") String page,
            @QueryParam("size") @DefaultValue("20") String size,
            @HeaderParam(HttpHeaders.AUTHORIZATION) String authorization) {
        return privada(atencion.historial(authorization, fechaSuministrada("desde", desde),
                fechaSuministrada("hasta", hasta), page, size));
    }

    @POST
    @Path("/citas/{codCit}/derivaciones")
    public Response derivar(@PathParam("codCit") String codCit, NuevaDerivacion solicitud,
            @HeaderParam(HttpHeaders.AUTHORIZATION) String authorization) {
        return privadaCreada(derivaciones.crear(authorization, codCit, solicitud));
    }

    @GET
    @Path("/derivaciones")
    public Response derivaciones(@QueryParam("estado") String estado,
            @QueryParam("page") @DefaultValue("0") String page, @QueryParam("size") @DefaultValue("20") String size,
            @HeaderParam(HttpHeaders.AUTHORIZATION) String authorization) {
        return privada(derivaciones.propias(authorization, estado, page, size));
    }

    @GET
    @Path("/derivaciones/{codDer}")
    public Response derivacion(@PathParam("codDer") String codDer,
            @HeaderParam(HttpHeaders.AUTHORIZATION) String authorization) {
        return privada(derivaciones.detalle(authorization, codDer));
    }

    @POST
    @Path("/citas/{codCit}/analisis")
    public Response solicitarAnalisis(@PathParam("codCit") String codCit, NuevoAnalisis solicitud,
            @HeaderParam(HttpHeaders.AUTHORIZATION) String authorization) {
        return privadaCreada(analisis.solicitar(authorization, codCit, solicitud));
    }

    @GET
    @Path("/citas/{codCit}/analisis")
    public Response analisis(@PathParam("codCit") String codCit,
            @HeaderParam(HttpHeaders.AUTHORIZATION) String authorization) {
        return privada(analisis.listar(authorization, codCit));
    }

    @PATCH
    @Path("/analisis/{codAna}")
    public Response resultado(@PathParam("codAna") String codAna, ResultadoAnalisis solicitud,
            @HeaderParam(HttpHeaders.AUTHORIZATION) String authorization) {
        return privada(analisis.registrarResultado(authorization, codAna, solicitud));
    }

    @GET
    @Path("/especialidades")
    public Response especialidades(@HeaderParam(HttpHeaders.AUTHORIZATION) String authorization) {
        atencion.medicoAutenticado(authorization);
        return privada(recepcion.especialidades());
    }

    @GET
    @Path("/medicos")
    public Response medicos(@QueryParam("codEsp") String codEsp,
            @HeaderParam(HttpHeaders.AUTHORIZATION) String authorization) {
        atencion.medicoAutenticado(authorization);
        return privada(recepcion.medicos(codEsp));
    }

    private static Response privadaCreada(Object entidad) {
        return Response.fromResponse(privada(entidad)).status(Response.Status.CREATED).build();
    }

    private String fechaSuministrada(String nombre, String valor) {
        // REST convierte un query param vacío en null. Diferencia fecha= de un filtro omitido.
        return valor == null && uriInfo.getQueryParameters().containsKey(nombre) ? "" : valor;
    }

    private static Response privada(Object entidad) {
        CacheControl cache = new CacheControl();
        cache.setNoStore(true);
        return Response.ok(entidad).cacheControl(cache).header("Pragma", "no-cache").build();
    }
}
