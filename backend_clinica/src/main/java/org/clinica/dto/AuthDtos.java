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

    public record MeData(UserResponse user, OffsetDateTime expiresAt) {
    }

    public record LogoutData(boolean revoked) {
    }

    public record OtpChallengeData(
            String verificationToken,
            String maskedDestination,
            OffsetDateTime otpExpiresAt,
            OffsetDateTime resendAvailableAt,
            OffsetDateTime verificationExpiresAt) {
    }

    public record RegistrationData(
            String patientId,
            String roleCode,
            boolean phoneVerificationRequired,
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
