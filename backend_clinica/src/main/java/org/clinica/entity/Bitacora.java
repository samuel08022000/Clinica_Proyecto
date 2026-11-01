package org.clinica.entity;

import io.quarkus.hibernate.orm.panache.PanacheEntityBase;
import jakarta.persistence.Column;
import jakarta.persistence.Entity;
import jakarta.persistence.Id;
import jakarta.persistence.Table;
import org.hibernate.annotations.Generated;
import org.hibernate.annotations.Immutable;
import org.hibernate.annotations.JdbcTypeCode;
import org.hibernate.generator.EventType;
import org.hibernate.type.SqlTypes;

import java.time.OffsetDateTime;
import java.util.Map;

/**
 * Tabla {@code public.bitacora}: auditoría inmutable alimentada por el trigger
 * {@code public.auditar_fila()} ({@code trg_bitacora_inmutable} prohíbe UPDATE/DELETE).
 * Solo lectura desde la aplicación.
 */
@Entity
@Immutable
@Table(name = "bitacora", schema = "public")
public class Bitacora extends PanacheEntityBase {

    @Id
    @Generated(event = EventType.INSERT)
    @Column(name = "cod_bit", insertable = false, updatable = false)
    public String codBit;

    @Generated(event = EventType.INSERT)
    @Column(name = "secuencial", insertable = false, updatable = false)
    public Long secuencial;

    @Column(name = "cod_usu")
    public String codUsu;

    @Column(name = "nombre_tabla", nullable = false, length = 100)
    public String nombreTabla;

    /** INSERT, UPDATE, DELETE. */
    @Column(name = "accion", nullable = false, length = 30)
    public String accion;

    @Column(name = "clave_registro")
    public String claveRegistro;

    @Column(name = "detalles")
    public String detalles;

    @JdbcTypeCode(SqlTypes.JSON)
    @Column(name = "datos_anteriores", columnDefinition = "jsonb")
    public Map<String, Object> datosAnteriores;

    @JdbcTypeCode(SqlTypes.JSON)
    @Column(name = "datos_nuevos", columnDefinition = "jsonb")
    public Map<String, Object> datosNuevos;

    @Generated(event = EventType.INSERT)
    @Column(name = "registrado_en", insertable = false, updatable = false)
    public OffsetDateTime registradoEn;
}
