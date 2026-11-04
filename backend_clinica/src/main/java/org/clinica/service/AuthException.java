package org.clinica.service;

/** Error controlado de autenticacion que puede convertirse en una respuesta HTTP segura. */
public class AuthException extends RuntimeException {

    private final int status;
    private final String code;
    private final Long retryAfterSeconds;

    public AuthException(int status, String code, String message) {
        this(status, code, message, null);
    }

    public AuthException(int status, String code, String message, Long retryAfterSeconds) {
        super(message);
        this.status = status;
        this.code = code;
        this.retryAfterSeconds = retryAfterSeconds;
    }

    public int status() {
        return status;
    }

    public String code() {
        return code;
    }

    public Long retryAfterSeconds() {
        return retryAfterSeconds;
    }
}
