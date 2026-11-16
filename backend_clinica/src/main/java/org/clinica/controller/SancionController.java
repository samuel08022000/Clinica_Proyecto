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
import jakarta.ws.rs.core.HttpHeaders;
import jakarta.ws.rs.core.MediaType;
import jakarta.ws.rs.core.Response;
import org.clinica.config.BearerToken;
import org.clinica.dto.sancion.SancionDtos.CrearSancion;
import org.clinica.service.AuthService;
import org.clinica.service.SancionService;

import java.net.URI;
import java.util.Set;

@Path("/api/v1/administracion/pacientes")
@Produces(MediaType.APPLICATION_JSON)
@Consumes(MediaType.APPLICATION_JSON)
public class SancionController {
    @Inject AuthService auth;
    @Inject SancionService service;

    @GET
    public Response pacientes(@QueryParam("q") String q, @QueryParam("estado") String estado,
            @QueryParam("page") @DefaultValue("0") String page, @QueryParam("size") @DefaultValue("20") String size,
            @HeaderParam(HttpHeaders.AUTHORIZATION) String authorization) {
        administrador(authorization);
        return privada(Response.ok(service.pacientes(q, estado, page, size)));
    }

    @GET @Path("/{codPac}")
    public Response paciente(@PathParam("codPac") String codPac,
            @HeaderParam(HttpHeaders.AUTHORIZATION) String authorization) {
        administrador(authorization);
        return privada(Response.ok(service.paciente(codPac)));
    }

    @GET @Path("/{codPac}/incidencias")
    public Response incidencias(@PathParam("codPac") String codPac, @QueryParam("tipo") String tipo,
            @QueryParam("page") @DefaultValue("0") String page, @QueryParam("size") @DefaultValue("20") String size,
            @HeaderParam(HttpHeaders.AUTHORIZATION) String authorization) {
        administrador(authorization);
        return privada(Response.ok(service.incidencias(codPac, tipo, page, size)));
    }

    @GET @Path("/{codPac}/sanciones")
    public Response sanciones(@PathParam("codPac") String codPac, @QueryParam("vigente") String vigente,
            @QueryParam("page") @DefaultValue("0") String page, @QueryParam("size") @DefaultValue("20") String size,
            @HeaderParam(HttpHeaders.AUTHORIZATION) String authorization) {
        administrador(authorization);
        return privada(Response.ok(service.sanciones(codPac, vigente, page, size)));
    }

    @GET @Path("/{codPac}/sanciones/{codInc}")
    public Response sancion(@PathParam("codPac") String codPac, @PathParam("codInc") String codInc,
            @HeaderParam(HttpHeaders.AUTHORIZATION) String authorization) {
        administrador(authorization);
        return privada(Response.ok(service.sancion(codPac, codInc)));
    }

    @POST @Path("/{codPac}/sanciones")
    public Response crear(@PathParam("codPac") String codPac, CrearSancion solicitud,
            @HeaderParam(HttpHeaders.AUTHORIZATION) String authorization) {
        var sancion = service.registrar(codPac, solicitud, administrador(authorization));
        return privada(Response.created(URI.create("/api/v1/administracion/pacientes/" + codPac + "/sanciones/" + sancion.codInc()))
                .entity(sancion));
    }

    private String administrador(String authorization) {
        return auth.requireAnyRole(BearerToken.bearerToken(authorization), Set.of("ROL004")).user().id();
    }

    private static Response privada(Response.ResponseBuilder response) {
        CacheControl cache = new CacheControl(); cache.setNoStore(true);
        return response.cacheControl(cache).header("Pragma", "no-cache").build();
    }
}
