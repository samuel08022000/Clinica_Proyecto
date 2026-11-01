package org.clinica.entity;

import io.quarkus.hibernate.orm.panache.PanacheEntityBase;
import jakarta.persistence.Column;
import jakarta.persistence.Entity;
import jakarta.persistence.Id;
import jakarta.persistence.Table;
import org.hibernate.annotations.Generated;
import org.hibernate.generator.EventType;

import java.time.LocalTime;
import java.time.OffsetDateTime;

/**
 * Tabla {@code public.horarios}: patrón semanal del médico.
 * {@code dia_semana} es ISO (lunes=1 ... domingo=7). El trigger
 * {@code trg_validar_horario_semanal} rechaza solapes y
 * {@code trg_slots_despues_horario} regenera los slots D..D+14.
 */
@Entity
@Table(name = "horarios", schema = "public")
public class Horario extends PanacheEntityBase {

    @Id
    @Generated(event = EventType.INSERT)
    @Column(name = "cod_hor", insertable = false, updatable = false)
    public String codHor;

    @Generated(event = EventType.INSERT)
    @Column(name = "secuencial", insertable = false, updatable = false)
    public Long secuencial;

    @Column(name = "cod_med", nullable = false)
    public String codMed;

    @Column(name = "dia_semana", nullable = false)
    public Short diaSemana;

    @Column(name = "hora_inicio", nullable = false)
    public LocalTime horaInicio;

    @Column(name = "hora_fin", nullable = false)
    public LocalTime horaFin;

    @Column(name = "pausa_inicio")
    public LocalTime pausaInicio;

    @Column(name = "pausa_fin")
    public LocalTime pausaFin;

    @Column(name = "estado", nullable = false, length = 10)
    public String estado = "Activo";

    @Generated(event = EventType.INSERT)
    @Column(name = "creado_en", insertable = false, updatable = false)
    public OffsetDateTime creadoEn;
}
