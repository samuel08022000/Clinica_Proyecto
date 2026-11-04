package org.clinica.config;

import jakarta.ws.rs.core.CacheControl;
import jakarta.ws.rs.core.Response;
import jakarta.ws.rs.ext.ExceptionMapper;
import jakarta.ws.rs.ext.Provider;
import org.clinica.dto.AuthDtos.ErrorDetail;
import org.clinica.dto.AuthDtos.ErrorResponse;
import org.clinica.service.AuthException;

@Provider
public class AuthExceptionMapper implements ExceptionMapper<AuthException> {

    @Override
    public Response toResponse(AuthException exception) {
        CacheControl cacheControl = new CacheControl();
        cacheControl.setNoStore(true);

        Response.ResponseBuilder response = Response.status(exception.status())
                .cacheControl(cacheControl)
                .header("Pragma", "no-cache")
                .entity(new ErrorResponse(new ErrorDetail(exception.code(), exception.getMessage())));

        if (exception.retryAfterSeconds() != null) {
            response.header("Retry-After", exception.retryAfterSeconds());
        }
        return response.build();
    }
}
