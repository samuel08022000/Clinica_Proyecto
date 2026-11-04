package org.clinica.controller;

import io.vertx.ext.web.RoutingContext;
import jakarta.inject.Inject;
import jakarta.ws.rs.Consumes;
import jakarta.ws.rs.GET;
import jakarta.ws.rs.HeaderParam;
import jakarta.ws.rs.POST;
import jakarta.ws.rs.Path;
import jakarta.ws.rs.Produces;
import jakarta.ws.rs.QueryParam;
import jakarta.ws.rs.core.CacheControl;
import jakarta.ws.rs.core.MediaType;
import jakarta.ws.rs.core.Response;
import org.clinica.dto.AuthDtos.DataResponse;
import org.clinica.dto.AuthDtos.LoginRequest;
import org.clinica.dto.AuthDtos.LogoutData;
import org.clinica.service.AuthService;
import org.clinica.service.AuthException;

import java.util.Arrays;
import java.util.Locale;
import java.util.Set;
import java.util.stream.Collectors;

@Path("/api/v1/auth")
@Consumes(MediaType.APPLICATION_JSON)
@Produces(MediaType.APPLICATION_JSON)
public class AuthController {

    @Inject
    AuthService authService;

    @Inject
    RoutingContext routingContext;

    @POST
    @Path("/login")
    public Response login(LoginRequest request) {
        String email = request == null ? null : request.email();
        String password = request == null ? null : request.password();
        return noStore(Response.ok(new DataResponse<>(
                authService.login(email, password, remoteAddress())))).build();
    }

    @GET
    @Path("/me")
    public Response me(@HeaderParam("Authorization") String authorization) {
        return noStore(Response.ok(new DataResponse<>(authService.me(bearerToken(authorization))))).build();
    }

    @GET
    @Path("/authorize")
    public Response authorize(
            @HeaderParam("Authorization") String authorization,
            @QueryParam("roles") String roles) {
        Set<String> allowedRoles = roles == null
                ? Set.of()
                : Arrays.stream(roles.split(","))
                        .map(String::trim)
                        .map(value -> value.toUpperCase(Locale.ROOT))
                        .filter(value -> !value.isBlank())
                        .collect(Collectors.toUnmodifiableSet());

        if (allowedRoles.isEmpty()) {
            throw new AuthException(422, "VALIDATION_ERROR", "Se requiere al menos un rol para autorizar.");
        }

        return noStore(Response.ok(new DataResponse<>(
                authService.requireAnyRole(bearerToken(authorization), allowedRoles)))).build();
    }

    @POST
    @Path("/logout")
    public Response logout(@HeaderParam("Authorization") String authorization) {
        boolean revoked = authService.logout(bearerToken(authorization));
        return noStore(Response.ok(new DataResponse<>(new LogoutData(revoked)))).build();
    }

    private String remoteAddress() {
        if (routingContext == null || routingContext.request() == null
                || routingContext.request().remoteAddress() == null) {
            return "unknown";
        }
        return routingContext.request().remoteAddress().hostAddress();
    }

    private static String bearerToken(String authorization) {
        if (authorization == null || !authorization.regionMatches(true, 0, "Bearer ", 0, 7)) {
            return null;
        }
        return authorization.substring(7).trim();
    }

    private static Response.ResponseBuilder noStore(Response.ResponseBuilder builder) {
        CacheControl cacheControl = new CacheControl();
        cacheControl.setNoStore(true);
        return builder.cacheControl(cacheControl).header("Pragma", "no-cache");
    }
}
