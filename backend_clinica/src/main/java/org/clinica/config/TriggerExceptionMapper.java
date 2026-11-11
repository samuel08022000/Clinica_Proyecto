package org.clinica.config;

import jakarta.ws.rs.WebApplicationException;
import jakarta.ws.rs.core.MediaType;
import jakarta.ws.rs.core.Response;
import jakarta.ws.rs.ext.ExceptionMapper;
import jakarta.ws.rs.ext.Provider;
import org.jboss.logging.Logger;
import org.postgresql.util.PSQLException;

import java.sql.SQLException;
import java.util.Collections;
import java.util.IdentityHashMap;
import java.util.LinkedHashMap;
import java.util.Map;
import java.util.Set;

/**
 * Traduce los {@code RAISE EXCEPTION} de los triggers/funciones de PostgreSQL
 * (reglas de negocio) a respuestas HTTP 400 con un JSON uniforme:
 * <pre>{"ok": false, "error": "REGLA_NEGOCIO_BD", "mensaje": "..."}</pre>
 */
@Provider
public class TriggerExceptionMapper implements ExceptionMapper<Exception> {

    private static final Logger LOG = Logger.getLogger(TriggerExceptionMapper.class);

    @Override
    public Response toResponse(Exception exception) {
        // Respeta las respuestas JAX-RS explícitas (404, 401, 403, etc.).
        if (exception instanceof WebApplicationException wae) {
            return wae.getResponse();
        }

        PSQLException psql = buscarPSQLException(exception);
        if (psql != null) {
            if ("P4030".equals(psql.getSQLState())) {
                return json(Response.Status.FORBIDDEN, "RESERVA_NO_AUTORIZADA",
                        "El usuario o paciente no está habilitado para esta reserva.");
            }
            if ("P4040".equals(psql.getSQLState())) {
                return json(Response.Status.NOT_FOUND, "NO_ENCONTRADO", "No existe el recurso de la reserva.");
            }
            if ("P4000".equals(psql.getSQLState())) {
                return json(Response.Status.BAD_REQUEST, "VALIDACION", "El turno debe ser futuro y estar entre hoy y D+14.");
            }
            if ("P4090".equals(psql.getSQLState()) && psql.getServerErrorMessage() != null) {
                return json(Response.Status.CONFLICT, "CONFLICTO_AGENDA", psql.getServerErrorMessage().getMessage());
            }
            if ("40P01".equals(psql.getSQLState()) || "40001".equals(psql.getSQLState())) {
                return json(Response.Status.CONFLICT, "CONFLICTO_CONCURRENTE", "Otra operación modificó la agenda. Reintente la petición.");
            }
            if ("P0001".equals(psql.getSQLState()) && psql.getServerErrorMessage() != null) {
                // RAISE EXCEPTION comunica los rechazos de negocio definidos en las funciones de la clínica.
                return json(Response.Status.BAD_REQUEST, "REGLA_NEGOCIO_BD", psql.getServerErrorMessage().getMessage());
            }
            if ("23505".equals(psql.getSQLState())) {
                return json(Response.Status.CONFLICT, "DUPLICADO", "Ya existe un registro con esos datos.");
            }
        }

        LOG.error("Error no controlado en la API", exception);
        return json(Response.Status.INTERNAL_SERVER_ERROR, "ERROR_INTERNO", "Ocurrió un error interno inesperado.");
    }

    /** Recorre la cadena getCause() (y getNextException() de JDBC) buscando un PSQLException. */
    private static PSQLException buscarPSQLException(Throwable t) {
        Set<Throwable> visitados = Collections.newSetFromMap(new IdentityHashMap<>());
        Throwable actual = t;
        while (actual != null && visitados.add(actual)) {
            if (actual instanceof PSQLException psql) {
                return psql;
            }
            if (actual instanceof SQLException sql && sql.getNextException() instanceof PSQLException next) {
                return next;
            }
            actual = actual.getCause();
        }
        return null;
    }

    private static Response json(Response.Status status, String error, String mensaje) {
        Map<String, Object> cuerpo = new LinkedHashMap<>();
        cuerpo.put("ok", false);
        cuerpo.put("error", error);
        cuerpo.put("mensaje", mensaje);
        return Response.status(status).type(MediaType.APPLICATION_JSON).entity(cuerpo).build();
    }
}
