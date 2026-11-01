package org.clinica.entity;

import io.quarkus.hibernate.orm.panache.PanacheEntityBase;
import jakarta.persistence.*;
import org.hibernate.annotations.Generated;
import org.hibernate.generator.EventType;
import java.time.OffsetDateTime;

@Entity
@Table(name = "medicos", schema = "public")
public class Medico extends PanacheEntityBase {

    @Id
    @Generated(event = EventType.INSERT)
    @Column(name = "cod_med", insertable = false, updatable = false)
    public String codMed;

    @Generated(event = EventType.INSERT)
    @Column(name = "secuencial", insertable = false, updatable = false)
    public Long secuencial;

    @Column(name = "cod_usu", nullable = false, unique = true)
    public String codUsu;

    @Column(name = "ci", length = 30)
    public String ci;

    @Column(name = "matricula_profesional", nullable = false, unique = true, length = 80)
    public String matriculaProfesional;

    @Column(name = "resumen_curricular")
    public String resumenCurricular;

    @Column(name = "duracion_consulta_min")
    public Short duracionConsultaMin;

    @Column(name = "estado", nullable = false, length = 10)
    public String estado = "Activo";

    @Generated(event = EventType.INSERT)
    @Column(name = "creado_en", insertable = false, updatable = false)
    public OffsetDateTime creadoEn;
}