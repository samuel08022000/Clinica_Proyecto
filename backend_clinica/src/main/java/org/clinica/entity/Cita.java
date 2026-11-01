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
 * Tabla {@code public.citas}.
 *
 * <p><b>Para crear, cancelar, reprogramar, hacer check-in o cerrar citas use
 * {@code CitaFunctionRepository}</b>: las funciones de BD aplican permisos por rol,
 * bloqueo de filas y la exclusión anti-solape.</p>
 *
 * <p>El trigger {@code trg_validar_cita} (BEFORE INSERT/UPDATE) sobrescribe siempre
 * {@code cod_med}, {@code cod_esp}, {@code fecha}, {@code hora_inicio}, {@code hora_fin}
 * y {@code actualizado_en} a partir del slot; por eso se releen tras cada escritura.
 * {@code token_qr} lo genera {@code trg_procesar_evento_cita} y es de solo lectura.</p>
 */
@Entity
@Table(name = "citas", schema = "public")
public class Cita extends PanacheEntityBase {

    @Id
    @Generated(event = EventType.INSERT)
    @Column(name = "cod_cit", insertable = false, updatable = false)
    public String codCit;

    @Generated(event = EventType.INSERT)
    @Column(name = "secuencial", insertable = false, updatable = false)
    public Long secuencial;

    @Column(name = "cod_pac", nullable = false)
    public String codPac;

    @Column(name = "cod_slot", nullable = false)
    public String codSlot;

    @Generated(event = {EventType.INSERT, EventType.UPDATE}, writable = true)
    @Column(name = "cod_med", nullable = false)
    public String codMed;

    @Generated(event = {EventType.INSERT, EventType.UPDATE}, writable = true)
    @Column(name = "cod_esp", nullable = false)
    public String codEsp;

    @Generated(event = {EventType.INSERT, EventType.UPDATE}, writable = true)
    @Column(name = "fecha", nullable = false)
    public LocalDate fecha;

    @Generated(event = {EventType.INSERT, EventType.UPDATE}, writable = true)
    @Column(name = "hora_inicio", nullable = false)
    public LocalTime horaInicio;

    @Generated(event = {EventType.INSERT, EventType.UPDATE}, writable = true)
    @Column(name = "hora_fin", nullable = false)
    public LocalTime horaFin;

    /** Confirmada, En Espera, Atendida, Cancelada, No Asistió, Afectada/Pendiente Reprogramación, Reprogramada. */
    @Column(name = "estado", nullable = false, length = 40)
    public String estado = "Confirmada";

    /** Web, Ventanilla, Derivacion, Admin. */
    @Column(name = "canal_reserva", nullable = false, length = 15)
    public String canalReserva = "Web";

    @Column(name = "cod_sec")
    public String codSec;

    @Column(name = "creado_por_usu")
    public String creadoPorUsu;

    @Column(name = "cancelado_por_usu")
    public String canceladoPorUsu;

    @Column(name = "motivo_cancelacion")
    public String motivoCancelacion;

    @Column(name = "motivo_reprogramacion")
    public String motivoReprogramacion;

    @Column(name = "cancelado_en")
    public OffsetDateTime canceladoEn;

    @Column(name = "token_qr", unique = true, insertable = false, updatable = false)
    public String tokenQr;

    @Column(name = "hora_llegada_checkin")
    public OffsetDateTime horaLlegadaCheckin;

    @Column(name = "checkin_por_usu")
    public String checkinPorUsu;

    @Column(name = "fecha_atencion")
    public OffsetDateTime fechaAtencion;

    @Column(name = "atendida_por_usu")
    public String atendidaPorUsu;

    @Column(name = "cod_cit_padre")
    public String codCitPadre;

    @Generated(event = EventType.INSERT)
    @Column(name = "creado_en", insertable = false, updatable = false)
    public OffsetDateTime creadoEn;

    @Generated(event = {EventType.INSERT, EventType.UPDATE})
    @Column(name = "actualizado_en", insertable = false, updatable = false)
    public OffsetDateTime actualizadoEn;
}
