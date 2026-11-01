package org.clinica.entity;

import jakarta.persistence.Column;
import jakarta.persistence.Embeddable;
import java.io.Serializable;
import java.util.Objects;

@Embeddable
public class MedicoEspecialidadId implements Serializable {

    @Column(name = "cod_med")
    public String codMed;

    @Column(name = "cod_esp")
    public String codEsp;

    public MedicoEspecialidadId() {}

    public MedicoEspecialidadId(String codMed, String codEsp) {
        this.codMed = codMed;
        this.codEsp = codEsp;
    }

    @Override
    public boolean equals(Object o) {
        if (this == o) return true;
        if (!(o instanceof MedicoEspecialidadId that)) return false;
        return Objects.equals(codMed, that.codMed) && Objects.equals(codEsp, that.codEsp);
    }

    @Override
    public int hashCode() {
        return Objects.hash(codMed, codEsp);
    }
}