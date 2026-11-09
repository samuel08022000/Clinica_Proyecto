package org.clinica.entity;

import io.quarkus.hibernate.orm.panache.PanacheEntityBase;
import jakarta.persistence.*;
import org.hibernate.annotations.Generated;
import org.hibernate.generator.EventType;
import java.time.OffsetDateTime;

@Entity
@Table(name = "usuarios", schema = "public")
public class Usuario extends PanacheEntityBase {

    @Id
    @Generated(event = EventType.INSERT)
    @Column(name = "cod_usu", insertable = false, updatable = false)
    public String codUsu;

    @Generated(event = EventType.INSERT)
    @Column(name = "secuencial", insertable = false, updatable = false)
    public Long secuencial;

    @Column(name = "nombre_completo", nullable = false, length = 150)
    public String nombreCompleto;

    @Column(name = "email", nullable = false, length = 254)
    public String email;

    @Column(name = "password_hash", length = 255)
    public String passwordHash;

    @Column(name = "cod_rol", nullable = false, length = 10)
    public String codRol;

    @Column(name = "estado", nullable = false, length = 12)
    public String estado = "Activo";

    @Generated(event = EventType.INSERT)
    @Column(name = "creado_en", insertable = false, updatable = false)
    public OffsetDateTime creadoEn;

    @Generated(event = EventType.INSERT)
    @Column(name = "actualizado_en", insertable = false)
    public OffsetDateTime actualizadoEn;
}
