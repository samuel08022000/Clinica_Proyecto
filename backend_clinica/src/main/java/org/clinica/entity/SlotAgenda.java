package org.clinica.entity;

import io.quarkus.hibernate.orm.panache.PanacheEntityBase;
import jakarta.persistence.Column;
import jakarta.persistence.Entity;
import jakarta.persistence.Id;
import jakarta.persistence.Table;
import org.hibernate.annotations.Generated;
import org.hibernate.generator.EventType;

import java.time.LocalDate;
import java.time.LocalDateTime;
import java.time.LocalTime;
import java.time.OffsetDateTime;

/**
 * Tabla {@code public.slots_agenda}. Normalmente la pobla
 * {@code public.generar_slots_agenda()}; no es necesario insertar desde Java.
 *
 * <p>{@code fecha}, {@code hora_inicio} y {@code hora_fin} son columnas
 * {@code GENERATED ALWAYS AS (...) STORED} derivadas de {@code inicio_at}/{@code fin_at}:
 * son de solo lectura y se releen tras INSERT/UPDATE.</p>
 */
@Entity
@Table(name = "slots_agenda", schema = "public")
public class SlotAgenda extends PanacheEntityBase {

    @Id
    @Generated(event = EventType.INSERT)
    @Column(name = "cod_slot", insertable = false, updatable = false)
    public String codSlot;

    @Generated(event = EventType.INSERT)
    @Column(name = "secuencial", insertable = false, updatable = false)
    public Long secuencial;

    @Column(name = "cod_hor", nullable = false)
    public String codHor;

    @Column(name = "cod_med", nullable = false)
    public String codMed;

    @Column(name = "cod_esp", nullable = false)
    public String codEsp;

    /** {@code timestamp} sin zona: hora local de la clínica (America/La_Paz). */
    @Column(name = "inicio_at", nullable = false)
    public LocalDateTime inicioAt;

    /** {@code timestamp} sin zona: hora local de la clínica (America/La_Paz). */
    @Column(name = "fin_at", nullable = false)
    public LocalDateTime finAt;

    @Generated(event = {EventType.INSERT, EventType.UPDATE})
    @Column(name = "fecha", insertable = false, updatable = false)
    public LocalDate fecha;

    @Generated(event = {EventType.INSERT, EventType.UPDATE})
    @Column(name = "hora_inicio", insertable = false, updatable = false)
    public LocalTime horaInicio;

    @Generated(event = {EventType.INSERT, EventType.UPDATE})
    @Column(name = "hora_fin", insertable = false, updatable = false)
    public LocalTime horaFin;

    @Generated(event = EventType.INSERT)
    @Column(name = "creado_en", insertable = false, updatable = false)
    public OffsetDateTime creadoEn;
}
