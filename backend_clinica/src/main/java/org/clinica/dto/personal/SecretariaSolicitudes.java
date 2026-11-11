package org.clinica.dto.personal;

import com.fasterxml.jackson.annotation.JsonProperty;

public final class SecretariaSolicitudes {

    private SecretariaSolicitudes() {
    }

    public static class Actualizar extends SolicitudConCamposPermitidos {
        public String nombreCompleto;
        public String email;
        public String ci;
        public String telefono;
    }

    public static class Crear extends Actualizar {
        @JsonProperty(access = JsonProperty.Access.WRITE_ONLY)
        public String password;
    }

    public static class CambiarEstado extends SolicitudConCamposPermitidos {
        public String estado;
    }

    public static class RestablecerPassword extends SolicitudConCamposPermitidos {
        @JsonProperty(access = JsonProperty.Access.WRITE_ONLY)
        public String password;
    }
}
