package org.clinica.entity;

import io.quarkus.hibernate.orm.panache.PanacheEntityBase;
import jakarta.persistence.Column;
import jakarta.persistence.Entity;
import jakarta.persistence.Id;
import jakarta.persistence.Table;

/** Tabla {@code public.roles}. PK de texto asignada manualmente (ROL001..ROL004). */
@Entity
@Table(name = "roles", schema = "public")
public class Rol extends PanacheEntityBase {

    @Id
    @Column(name = "cod_rol", length = 10)
    public String codRol;

    @Column(name = "nombre_rol", nullable = false, unique = true, length = 40)
    public String nombreRol;

    @Column(name = "descripcion")
    public String descripcion;

    @Column(name = "estado", nullable = false, length = 10)
    public String estado = "Activo";
}
