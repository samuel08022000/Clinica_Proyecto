package org.clinica.dto.personal;

import com.fasterxml.jackson.annotation.JsonAnySetter;
import com.fasterxml.jackson.databind.JsonNode;

public abstract class SolicitudConCamposPermitidos {

    private boolean camposNoPermitidos;

    @JsonAnySetter
    public void marcarCampoNoPermitido(String nombre, JsonNode valor) {
        camposNoPermitidos = true;
    }

    public boolean tieneCamposNoPermitidos() {
        return camposNoPermitidos;
    }
}
