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
 * Tabla {@code public.analisis}. El trigger {@code trg_validar_analisis} exige que
 * {@code solicitado_por_usu} sea el médico de la cita y que ésta esté 'En Espera' o 'Atendida'.
 */
@Entity
@Table(name = "analisis", schema = "public")
public class Analisis extends PanacheEntityBase {

    @Id
    @Generated(event = EventType.INSERT)
    @Column(name = "cod_ana", insertable = false, updatable = false)
    public String codAna;

    @Generated(event = EventType.INSERT)
    @Column(name = "secuencial", insertable = false, updatable = false)
    public Long secuencial;

    @Column(name = "cod_cit", nullable = false)
    public String codCit;

    @Column(name = "solicitado_por_usu", nullable = false)
    public String solicitadoPorUsu;

    @Column(name = "tipo_analisis", nullable = false, length = 150)
    public String tipoAnalisis;

    @Column(name = "resultado")
    public String resultado;

    @Column(name = "archivo_pdf_url")
    public String archivoPdfUrl;

    /** Pendiente, En Proceso, Completado, Cancelado, Entregado. */
    @Column(name = "estado", nullable = false, length = 15)
    public String estado = "Pendiente";

    @Generated(event = EventType.INSERT)
    @Column(name = "fecha_registro", insertable = false, updatable = false)
    public OffsetDateTime fechaRegistro;

    @Column(name = "fecha_entrega_estimada")
    public OffsetDateTime fechaEntregaEstimada;

    @Column(name = "fecha_entrega_real")
    public OffsetDateTime fechaEntregaReal;

    @Generated(event = EventType.INSERT)
    @Column(name = "creado_en", insertable = false, updatable = false)
    public OffsetDateTime creadoEn;
}
