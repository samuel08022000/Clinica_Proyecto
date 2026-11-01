package org.clinica.repository;

import io.quarkus.hibernate.orm.panache.PanacheRepositoryBase;
import io.quarkus.panache.common.Parameters;
import io.quarkus.panache.common.Sort;
import jakarta.enterprise.context.ApplicationScoped;
import org.clinica.entity.BloqueoAgenda;
import org.clinica.entity.Horario;
import org.clinica.entity.VistaAgendaDisponibilidad;

import java.time.LocalDate;
import java.util.HashMap;
import java.util.List;
import java.util.Map;
import jakarta.persistence.TypedQuery;

/** Horarios semanales, bloqueos y consulta de cupos sobre {@code v_agenda_disponibilidad}. */
@ApplicationScoped
public class AgendaRepository implements PanacheRepositoryBase<Horario, String> {

    // ----- Horarios -----

    public List<Horario> listarHorariosPorMedico(String codMed) {
        return list("codMed", Sort.by("diaSemana").and("horaInicio"), codMed);
    }

    public List<Horario> listarHorariosActivosPorMedico(String codMed) {
        return list("codMed = ?1 and estado = 'Activo'", Sort.by("diaSemana").and("horaInicio"), codMed);
    }

    // ----- Bloqueos -----

    public List<BloqueoAgenda> listarBloqueosPorMedico(String codMed) {
        return BloqueoAgenda.list("codMed", Sort.descending("fechaBloqueo").and("horaInicio"), codMed);
    }

    public List<BloqueoAgenda> listarBloqueosPorMedicoYFecha(String codMed, LocalDate fecha) {
        return BloqueoAgenda.list("codMed = ?1 and fechaBloqueo = ?2", Sort.by("horaInicio"), codMed, fecha);
    }

    // ----- Cupos disponibles -----

    /**
     * Cupos con {@code estado_disponibilidad = 'Disponible'}. Cualquier filtro en {@code null}
     * se ignora (p. ej. sólo {@code codEsp} para ver todos los médicos de una especialidad).
     */
    public List<VistaAgendaDisponibilidad> buscarCuposDisponibles(String codEsp, String codMed, LocalDate fecha) {
        StringBuilder query = new StringBuilder("estadoDisponibilidad = 'Disponible'");
        Parameters params = new Parameters();
        if (codEsp != null && !codEsp.isBlank()) {
            query.append(" and codEsp = :codEsp");
            params.and("codEsp", codEsp);
        }
        if (codMed != null && !codMed.isBlank()) {
            query.append(" and codMed = :codMed");
            params.and("codMed", codMed);
        }
        if (fecha != null) {
            query.append(" and fecha = :fecha");
            params.and("fecha", fecha);
        }
        return VistaAgendaDisponibilidad.list(query.toString(),
                Sort.by("fecha").and("horaInicio").and("nombreMedico"), params.map());
    }

    /** Fechas (D..D+14) que tienen al menos un cupo disponible, útil para pintar el calendario. */
    public List<LocalDate> listarFechasConCupo(String codEsp, String codMed) {
        StringBuilder jpql = new StringBuilder(
                "select distinct v.fecha from VistaAgendaDisponibilidad v where v.estadoDisponibilidad = 'Disponible'");
        Map<String, Object> filtros = new HashMap<>();
        if (codEsp != null && !codEsp.isBlank()) {
            jpql.append(" and v.codEsp = :codEsp");
            filtros.put("codEsp", codEsp);
        }
        if (codMed != null && !codMed.isBlank()) {
            jpql.append(" and v.codMed = :codMed");
            filtros.put("codMed", codMed);
        }
        jpql.append(" order by v.fecha");
        TypedQuery<LocalDate> query = getEntityManager().createQuery(jpql.toString(), LocalDate.class);
        filtros.forEach(query::setParameter);
        return query.getResultList();
    }
}
