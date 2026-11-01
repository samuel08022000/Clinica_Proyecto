package org.clinica.entity;

import io.quarkus.hibernate.orm.panache.PanacheEntityBase;
import jakarta.persistence.*;

@Entity
@Table(name = "medico_especialidades", schema = "public")
public class MedicoEspecialidad extends PanacheEntityBase {

    @EmbeddedId
    public MedicoEspecialidadId id;

    @Column(name = "duracion_consulta_min")
    public Short duracionConsultaMin;

    @Column(name = "estado", nullable = false, length = 10)
    public String estado = "Activo";
}