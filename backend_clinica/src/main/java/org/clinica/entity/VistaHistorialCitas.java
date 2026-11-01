package org.clinica.entity;

import io.quarkus.hibernate.orm.panache.PanacheEntityBase;
import jakarta.persistence.Column;
import jakarta.persistence.Entity;
import jakarta.persistence.Id;
import jakarta.persistence.Table;
import org.hibernate.annotations.Immutable;

import java.time.LocalDate;
import java.time.LocalTime;
import java.time.OffsetDateTime;

/** Vista {@code public.v_historial_citas} (solo lectura). */
@Entity
@Immutable
@Table(name = "v_historial_citas", schema = "public")
public class VistaHistorialCitas extends PanacheEntityBase {

    @Id
    @Column(name = "cod_cit")
    public String codCit;

    @Column(name = "cod_pac")
    public String codPac;

    @Column(name = "fecha")
    public LocalDate fecha;

    @Column(name = "hora_inicio")
    public LocalTime horaInicio;

    @Column(name = "hora_fin")
    public LocalTime horaFin;

    @Column(name = "estado")
    public String estado;

    @Column(name = "canal_reserva")
    public String canalReserva;

    @Column(name = "cod_esp")
    public String codEsp;

    @Column(name = "nombre_especialidad")
    public String nombreEspecialidad;

    @Column(name = "cod_med")
    public String codMed;

    @Column(name = "nombre_medico")
    public String nombreMedico;

    @Column(name = "creado_en")
    public OffsetDateTime creadoEn;

    @Column(name = "cancelado_en")
    public OffsetDateTime canceladoEn;

    @Column(name = "hora_llegada_checkin")
    public OffsetDateTime horaLlegadaCheckin;

    @Column(name = "fecha_atencion")
    public OffsetDateTime fechaAtencion;
}
