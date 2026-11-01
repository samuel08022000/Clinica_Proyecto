package org.clinica.entity;

import io.quarkus.hibernate.orm.panache.PanacheEntityBase;
import jakarta.persistence.Column;
import jakarta.persistence.Entity;
import jakarta.persistence.Id;
import jakarta.persistence.Table;
import org.hibernate.annotations.Generated;
import org.hibernate.generator.EventType;

import java.time.OffsetDateTime;

/** Tabla {@code public.secretarias}. */
@Entity
@Table(name = "secretarias", schema = "public")
public class Secretaria extends PanacheEntityBase {

    @Id
    @Generated(event = EventType.INSERT)
    @Column(name = "cod_sec", insertable = false, updatable = false)
    public String codSec;

    @Generated(event = EventType.INSERT)
    @Column(name = "secuencial", insertable = false, updatable = false)
    public Long secuencial;

    @Column(name = "cod_usu", nullable = false, unique = true)
    public String codUsu;

    @Column(name = "ci", length = 30)
    public String ci;

    @Column(name = "telefono", length = 25)
    public String telefono;

    @Column(name = "estado", nullable = false, length = 10)
    public String estado = "Activo";

    @Generated(event = EventType.INSERT)
    @Column(name = "creado_en", insertable = false, updatable = false)
    public OffsetDateTime creadoEn;
}
