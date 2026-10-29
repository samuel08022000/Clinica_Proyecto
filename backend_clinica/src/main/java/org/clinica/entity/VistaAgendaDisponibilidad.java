package org.clinica.entity;

import io.quarkus.hibernate.orm.panache.PanacheEntityBase;
import jakarta.persistence.*;
import org.hibernate.annotations.Immutable;
import java.time.LocalDate;
import java.time.LocalTime;

@Entity
@Immutable
@Table(name = "v_agenda_disponibilidad", schema = "public")
public class VistaAgendaDisponibilidad extends PanacheEntityBase {

    @Id
    @Column(name = "cod_slot")
    public String codSlot;

    @Column(name = "cod_med")
    public String codMed;

    @Column(name = "nombre_medico")
    public String nombreMedico;

    @Column(name = "cod_esp")
    public String codEsp;

    @Column(name = "nombre_especialidad")
    public String nombreEspecialidad;

    @Column(name = "fecha")
    public LocalDate fecha;

    @Column(name = "hora_inicio")
    public LocalTime horaInicio;

    @Column(name = "hora_fin")
    public LocalTime horaFin;

    @Column(name = "estado_disponibilidad")
    public String estadoDisponibilidad;
}