package org.clinica.entity;

import io.quarkus.hibernate.orm.panache.PanacheEntityBase;
import jakarta.persistence.Column;
import jakarta.persistence.Entity;
import jakarta.persistence.Id;
import jakarta.persistence.Table;
import org.hibernate.annotations.Generated;
import org.hibernate.generator.EventType;

import java.time.LocalDate;
import java.time.LocalTime;
import java.time.OffsetDateTime;

/**
 * Tabla {@code public.bloqueos_agenda}. Si {@code horaInicio/horaFin} son null el
 * bloqueo es de día completo. Al insertar, el trigger {@code trg_procesar_bloqueo_agenda}
 * marca las citas confirmadas afectadas como 'Afectada/Pendiente Reprogramación'.
 */
@Entity
@Table(name = "bloqueos_agenda", schema = "public")
public class BloqueoAgenda extends PanacheEntityBase {

    @Id
    @Generated(event = EventType.INSERT)
    @Column(name = "cod_blo", insertable = false, updatable = false)
    public String codBlo;

    @Generated(event = EventType.INSERT)
    @Column(name = "secuencial", insertable = false, updatable = false)
    public Long secuencial;

    @Column(name = "cod_med", nullable = false)
    public String codMed;

    @Column(name = "cod_usu_admin", nullable = false)
    public String codUsuAdmin;

    @Column(name = "fecha_bloqueo", nullable = false)
    public LocalDate fechaBloqueo;

    @Column(name = "hora_inicio")
    public LocalTime horaInicio;

    @Column(name = "hora_fin")
    public LocalTime horaFin;

    @Column(name = "motivo", nullable = false, length = 500)
    public String motivo;

    @Column(name = "trazabilidad_completa", insertable = false, updatable = false)
    public boolean trazabilidadCompleta;

    @Generated(event = EventType.INSERT)
    @Column(name = "creado_en", insertable = false, updatable = false)
    public OffsetDateTime creadoEn;
}
