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
import org.clinica.dto.AuthDtos.DemoOtpRequest;
import org.clinica.dto.AuthDtos.LoginRequest;
import org.clinica.dto.AuthDtos.LogoutData;
import org.clinica.dto.AuthDtos.OtpRequest;
import org.clinica.dto.AuthDtos.OtpResendRequest;
import org.clinica.dto.AuthDtos.OtpVerificationRequest;
import org.clinica.dto.AuthDtos.RegisterPatientRequest;
import org.clinica.service.ConfiguredOtpDeliveryProvider;
import org.clinica.service.AuthService;
import org.clinica.service.AuthException;
import org.clinica.service.PatientRegistrationService;

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
    PatientRegistrationService registrationService;

    @Inject
    ConfiguredOtpDeliveryProvider otpDeliveryProvider;

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

    @POST
    @Path("/register")
    public Response register(RegisterPatientRequest request) {
        return noStore(Response.status(Response.Status.CREATED)
                .entity(new DataResponse<>(registrationService.register(request))))
                .build();
    }

    @POST
    @Path("/otp/request")
    public Response requestOtp(OtpRequest request) {
        return noStore(Response.accepted(new DataResponse<>(
                registrationService.requestOtp(request, remoteAddress())))).build();
    }

    @POST
    @Path("/otp/verify")
    public Response verifyOtp(OtpVerificationRequest request) {
        String token = request == null ? null : request.verificationToken();
        String code = request == null ? null : request.code();
        return noStore(Response.ok(new DataResponse<>(registrationService.verify(token, code)))).build();
    }

    @POST
    @Path("/otp/resend")
    public Response resendOtp(OtpResendRequest request) {
        String token = request == null ? null : request.verificationToken();
        return noStore(Response.accepted(new DataResponse<>(registrationService.resend(token)))).build();
    }

    @POST
    @Path("/otp/status")
    public Response otpStatus(OtpResendRequest request) {
        String token = request == null ? null : request.verificationToken();
        return noStore(Response.ok(new DataResponse<>(registrationService.status(token)))).build();
    }

    @POST
    @Path("/otp/demo-code")
    public Response demoOtpCode(
            DemoOtpRequest request,
            @HeaderParam("X-Demo-Otp-Key") String accessKey) {
        String verificationToken = request == null ? null : request.verificationToken();
        String destination = request == null ? null : request.destination();
        var demoCode = destination == null || destination.isBlank()
                ? otpDeliveryProvider.readDemoCode(verificationToken, accessKey, remoteAddress())
                : otpDeliveryProvider.readDemoCodeForDestination(destination, accessKey, remoteAddress());
        return demoCode
                .map(data -> noStore(Response.ok(new DataResponse<>(data))).build())
                .orElseThrow(() -> new AuthException(404, "DEMO_OTP_NOT_AVAILABLE", "Codigo demo no disponible."));
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
