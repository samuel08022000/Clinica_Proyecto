package org.clinica.entity;

import io.quarkus.hibernate.orm.panache.PanacheEntityBase;
import jakarta.persistence.Column;
import jakarta.persistence.Entity;
import jakarta.persistence.Id;
import jakarta.persistence.Table;
import org.hibernate.annotations.Generated;
import org.hibernate.generator.EventType;

import java.time.OffsetDateTime;

/**
 * Tabla {@code public.especialidades}.
 * {@code duracion_consulta_min} sólo admite 20, 30, 45 o 60 (CHECK en BD).
 */
@Entity
@Table(name = "especialidades", schema = "public")
public class Especialidad extends PanacheEntityBase {

    @Id
    @Generated(event = EventType.INSERT)
    @Column(name = "cod_esp", insertable = false, updatable = false)
    public String codEsp;

    @Generated(event = EventType.INSERT)
    @Column(name = "secuencial", insertable = false, updatable = false)
    public Long secuencial;

    @Column(name = "nombre_especialidad", nullable = false, unique = true, length = 120)
    public String nombreEspecialidad;

    @Column(name = "descripcion")
    public String descripcion;

    @Column(name = "duracion_consulta_min", nullable = false)
    public Short duracionConsultaMin;

    @Column(name = "estado", nullable = false, length = 10)
    public String estado = "Activo";

    @Generated(event = EventType.INSERT)
    @Column(name = "creado_en", insertable = false, updatable = false)
    public OffsetDateTime creadoEn;
}
