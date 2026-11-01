package org.clinica.entity;

import io.quarkus.hibernate.orm.panache.PanacheEntityBase;
import jakarta.persistence.Column;
import jakarta.persistence.Entity;
import jakarta.persistence.Id;
import jakarta.persistence.Table;
import org.hibernate.annotations.Generated;
import org.hibernate.annotations.Immutable;
import org.hibernate.generator.EventType;

import java.time.OffsetDateTime;

/**
 * Tabla {@code public.incidencias_sanciones}: historial inmutable
 * (el trigger {@code trg_incidencias_inmutables} prohíbe UPDATE/DELETE).
 * Para sancionar use {@code CitaFunctionRepository.registrarSancion(...)}.
 */
@Entity
@Immutable
@Table(name = "incidencias_sanciones", schema = "public")
public class IncidenciaSancion extends PanacheEntityBase {

    @Id
    @Generated(event = EventType.INSERT)
    @Column(name = "cod_inc", insertable = false, updatable = false)
    public String codInc;

    @Generated(event = EventType.INSERT)
    @Column(name = "secuencial", insertable = false, updatable = false)
    public Long secuencial;

    @Column(name = "cod_pac", nullable = false)
    public String codPac;

    @Column(name = "cod_cit")
    public String codCit;

    @Column(name = "registrado_por_usu")
    public String registradoPorUsu;

    /** CANCELACION, INASISTENCIA_NO_SHOW, SANCION_BLOQUEO, OTRA. */
    @Column(name = "tipo_incidencia", nullable = false, length = 30)
    public String tipoIncidencia;

    @Column(name = "motivo", nullable = false)
    public String motivo;

    @Column(name = "fecha_fin_sancion")
    public OffsetDateTime fechaFinSancion;

    @Generated(event = EventType.INSERT)
    @Column(name = "fecha_registro", insertable = false, updatable = false)
    public OffsetDateTime fechaRegistro;
}
