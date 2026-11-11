package org.clinica.dto.personal;

import com.fasterxml.jackson.annotation.JsonAnySetter;
import com.fasterxml.jackson.annotation.JsonProperty;
import com.fasterxml.jackson.databind.JsonNode;

public final class SecretariaSolicitudes {

    private SecretariaSolicitudes() {
    }

    public abstract static class Solicitud {
        private boolean camposNoPermitidos;

        @JsonAnySetter
        public void marcarCampoNoPermitido(String nombre, JsonNode valor) {
            camposNoPermitidos = true;
        }

        public boolean tieneCamposNoPermitidos() {
            return camposNoPermitidos;
        }
    }

    public static class Actualizar extends Solicitud {
        public String nombreCompleto;
        public String email;
        public String ci;
        public String telefono;
    }

    public static class Crear extends Actualizar {
        @JsonProperty(access = JsonProperty.Access.WRITE_ONLY)
        public String password;
    }

    public static class CambiarEstado extends Solicitud {
        public String estado;
    }

    public static class RestablecerPassword extends Solicitud {
        @JsonProperty(access = JsonProperty.Access.WRITE_ONLY)
        public String password;
    }
}
