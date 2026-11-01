package org.clinica.entity;

import io.quarkus.hibernate.orm.panache.PanacheEntityBase;
import jakarta.persistence.*;
import org.hibernate.annotations.Generated;
import org.hibernate.generator.EventType;
import java.time.LocalDate;
import java.time.OffsetDateTime;

@Entity
@Table(name = "pacientes", schema = "public")
public class Paciente extends PanacheEntityBase {

    @Id
    @Generated(event = EventType.INSERT)
    @Column(name = "cod_pac", insertable = false, updatable = false)
    public String codPac;

    @Generated(event = EventType.INSERT)
    @Column(name = "secuencial", insertable = false, updatable = false)
    public Long secuencial;

    @Column(name = "cod_usu", nullable = false, unique = true)
    public String codUsu;

    @Column(name = "ci", nullable = false, length = 30)
    public String ci;

    @Column(name = "fecha_nacimiento", nullable = false)
    public LocalDate fechaNacimiento;

    @Column(name = "telefono", nullable = false, length = 25)
    public String telefono;

    @Column(name = "telefono_verificado", nullable = false)
    public Boolean telefonoVerificado = false;

    @Column(name = "ciudad_residencia", nullable = false, length = 100)
    public String ciudadResidencia;

    @Column(name = "estado_cuenta", nullable = false, length = 24)
    public String estadoCuenta = "Activo";

    @Column(name = "bloqueado_hasta")
    public OffsetDateTime bloqueadoHasta;

    @Generated(event = EventType.INSERT)
    @Column(name = "creado_en", insertable = false, updatable = false)
    public OffsetDateTime creadoEn;
}