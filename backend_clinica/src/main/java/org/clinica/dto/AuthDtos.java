package org.clinica.dto;

import java.time.OffsetDateTime;

/** Contratos JSON del modulo de autenticacion. Nunca incluyen hashes de contrasena. */
public final class AuthDtos {

    private AuthDtos() {
    }

    public record LoginRequest(String email, String password) {
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

    public record DataResponse<T>(T data) {
    }

    public record ErrorDetail(String code, String message) {
    }

    public record ErrorResponse(ErrorDetail error) {
    }
}
