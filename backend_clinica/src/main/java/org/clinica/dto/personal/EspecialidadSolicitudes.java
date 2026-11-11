package org.clinica.dto.personal;

import java.math.BigDecimal;

public final class EspecialidadSolicitudes {

    private EspecialidadSolicitudes() {
    }

    public static class Actualizar extends SolicitudConCamposPermitidos {
        public String nombreEspecialidad;
        public String descripcion;
    }

    public static class Crear extends Actualizar {
        public BigDecimal duracionConsultaMin;
        public String estado;
    }
}
