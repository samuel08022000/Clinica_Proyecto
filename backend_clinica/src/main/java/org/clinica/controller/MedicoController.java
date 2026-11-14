package org.clinica.controller;

import jakarta.inject.Inject;
import jakarta.ws.rs.Consumes;
import jakarta.ws.rs.DefaultValue;
import jakarta.ws.rs.GET;
import jakarta.ws.rs.HeaderParam;
import jakarta.ws.rs.POST;
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

@Path("/api/v1/medico")
@Produces(MediaType.APPLICATION_JSON)
@Consumes(MediaType.APPLICATION_JSON)
public class MedicoController {
    @Inject MedicoAtencionService atencion;
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
