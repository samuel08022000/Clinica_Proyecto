package org.clinica.controller;

import io.quarkus.arc.profile.IfBuildProfile;
import io.vertx.ext.web.RoutingContext;
import jakarta.inject.Inject;
import jakarta.ws.rs.Consumes;
import jakarta.ws.rs.HeaderParam;
import jakarta.ws.rs.POST;
import jakarta.ws.rs.Path;
import jakarta.ws.rs.Produces;
import jakarta.ws.rs.core.MediaType;
import jakarta.ws.rs.core.Response;
import org.clinica.dto.AuthDtos.DataResponse;
import org.clinica.dto.AuthDtos.DemoOtpRequest;
import org.clinica.dto.AuthDtos.DemoPasswordResetRequest;
import org.clinica.service.AuthException;
import org.clinica.service.ConfiguredOtpDeliveryProvider;

/** Herramienta opt-in: ausente del artefacto habitual; proveedor, clave y loopback obligatorios. */
@IfBuildProfile(anyOf = {"dev", "test", "integration"})
@Path("/api/v1/auth")
@Consumes(MediaType.APPLICATION_JSON)
@Produces(MediaType.APPLICATION_JSON)
public class DevelopmentAuthController {
    @Inject ConfiguredOtpDeliveryProvider delivery;
    @Inject RoutingContext context;

    @POST
    @Path("/otp/demo-code")
    public Response otp(DemoOtpRequest request, @HeaderParam("X-Demo-Otp-Key") String key) {
        String destination = request == null ? null : request.destination();
        String verification = request == null ? null : request.verificationToken();
        var code = destination == null || destination.isBlank()
                ? delivery.readDemoCode(verification, key, remoteAddress())
                : delivery.readDemoCodeForDestination(destination, key, remoteAddress());
        return code.map(data -> privateResponse(data))
                .orElseThrow(() -> new AuthException(404, "DEMO_OTP_NOT_AVAILABLE", "Codigo demo no disponible."));
    }

    @POST
    @Path("/password/demo-link")
    public Response reset(DemoPasswordResetRequest request, @HeaderParam("X-Demo-Otp-Key") String key) {
        return delivery.readDemoPasswordReset(request == null ? null : request.email(), key, remoteAddress())
                .map(data -> privateResponse(data))
                .orElseThrow(() -> new AuthException(404, "DEMO_RESET_NOT_AVAILABLE", "Enlace demo no disponible."));
    }

    private String remoteAddress() {
        return context.request().remoteAddress().hostAddress();
    }

    private static Response privateResponse(Object data) {
        return Response.ok(new DataResponse<>(data)).header("Cache-Control", "no-store")
                .header("Pragma", "no-cache").build();
    }
}
