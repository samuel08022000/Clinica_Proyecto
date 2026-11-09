package org.clinica.dto;

import java.time.LocalDate;
import java.time.OffsetDateTime;

/** Contratos JSON del modulo de autenticacion. Nunca incluyen hashes de contrasena. */
public final class AuthDtos {

    private AuthDtos() {
    }

    public record LoginRequest(String email, String password) {
    }

    public record RegisterPatientRequest(
            String nombreCompleto,
            String email,
            String password,
            String ci,
            LocalDate fechaNacimiento,
            String telefono,
            String ciudadResidencia) {
    }

    public record OtpRequest(String email, String password) {
    }

    public record OtpVerificationRequest(String verificationToken, String code) {
    }

    public record OtpResendRequest(String verificationToken) {
    }

    public record RoleResponse(String code, String name, String slug) {
    }

    public record UserResponse(
            String id,
            String name,
            String email,
            RoleResponse role,
            String accountStatus) {
    }

    public record SessionResponse(
            String accessToken,
            String tokenType,
            OffsetDateTime expiresAt) {
    }

    public record LoginData(UserResponse user, SessionResponse session) {
    }

    public record GoogleAuthorizationRequest(
            String state,
            String nonce,
            String codeChallenge,
            String redirectUri) {
    }

    public record GoogleAuthorizationData(String authorizationUrl) {
    }

    public record GoogleCodeExchangeRequest(
            String code,
            String codeVerifier,
            String nonce,
            String redirectUri) {
    }

    public record GoogleExchangeData(
            String status,
            LoginData login,
            String continuationToken,
            OffsetDateTime continuationExpiresAt,
            String suggestedName,
            String email,
            boolean emailVerified,
            OtpChallengeData verification) {
    }

    public record GooglePatientRegistrationRequest(
            String continuationToken,
            String ci,
            LocalDate fechaNacimiento,
            String telefono,
            String ciudadResidencia) {
    }

    public record GoogleLinkRequest(String continuationToken) {
    }

    public record GoogleLinkData(boolean linked) {
    }

    public record PasswordRecoveryRequest(String email) {
    }

    public record PasswordRecoveryData(String message) {
    }

    public record PasswordResetRequest(
            String email,
            String token,
            String password) {
    }

    public record PasswordResetData(boolean reset, String nextAction) {
    }

    public record DemoPasswordResetRequest(String email) {
    }

    public record DemoPasswordResetData(String resetUrl, OffsetDateTime expiresAt) {
    }

    public record MeData(UserResponse user, OffsetDateTime expiresAt) {
    }

    public record LogoutData(boolean revoked) {
    }

    public record OtpChallengeData(
            String verificationToken,
            String maskedDestination,
            OffsetDateTime otpExpiresAt,
            OffsetDateTime resendAvailableAt,
            OffsetDateTime verificationExpiresAt,
            String deliveryMode,
            boolean deliveryConfirmed) {
    }

    public record RegistrationData(
            String patientId,
            String roleCode,
            boolean emailVerificationRequired,
            OtpChallengeData verification) {
    }

    public record OtpVerificationData(boolean verified, String nextAction) {
    }

    public record OtpStatusData(
            boolean verified,
            String maskedDestination,
            OffsetDateTime verificationExpiresAt) {
    }

    public record DemoOtpData(String code, OffsetDateTime expiresAt) {
    }

    public record DemoOtpRequest(String verificationToken, String destination) {
    }

    public record DataResponse<T>(T data) {
    }

    public record ErrorDetail(String code, String message) {
    }

    public record ErrorResponse(ErrorDetail error) {
    }
}
