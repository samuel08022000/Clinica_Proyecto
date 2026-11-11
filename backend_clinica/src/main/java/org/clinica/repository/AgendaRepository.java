package org.clinica.repository;

import io.quarkus.hibernate.orm.panache.PanacheRepositoryBase;
import io.quarkus.panache.common.Parameters;
import io.quarkus.panache.common.Sort;
import jakarta.enterprise.context.ApplicationScoped;
import org.clinica.entity.BloqueoAgenda;
import org.clinica.entity.Horario;
import org.clinica.entity.VistaAgendaDisponibilidad;
import org.clinica.dto.agenda.AgendaDtos.CitaAdministrativa;

import java.time.LocalDate;
import java.util.HashMap;
import java.util.List;
import java.util.Map;
import jakarta.persistence.TypedQuery;

/** Horarios semanales, bloqueos y consulta de cupos sobre {@code v_agenda_disponibilidad}. */
@ApplicationScoped
public class AgendaRepository implements PanacheRepositoryBase<Horario, String> {

    public void bloquearMedico(String codMed) {
        getEntityManager().createNativeQuery("SELECT 1 FROM public.bloquear_agenda_medico(:med)")
                .setParameter("med", codMed).getSingleResult();
    }

    public String insertarHorario(Horario horario) {
        return (String) getEntityManager().createNativeQuery("""
                INSERT INTO public.horarios (cod_med,dia_semana,hora_inicio,hora_fin,pausa_inicio,pausa_fin)
                VALUES (:med,:dia,:inicio,:fin,CAST(:pausaInicio AS time),CAST(:pausaFin AS time)) RETURNING cod_hor
                """).setParameter("med", horario.codMed).setParameter("dia", horario.diaSemana)
                .setParameter("inicio", horario.horaInicio).setParameter("fin", horario.horaFin)
                .setParameter("pausaInicio", horario.pausaInicio).setParameter("pausaFin", horario.pausaFin).getSingleResult();
    }

    public String insertarBloqueo(BloqueoAgenda bloqueo) {
        return (String) getEntityManager().createNativeQuery("""
                INSERT INTO public.bloqueos_agenda (cod_med,cod_usu_admin,fecha_bloqueo,hora_inicio,hora_fin,motivo)
                VALUES (:med,:actor,:fecha,CAST(:inicio AS time),CAST(:fin AS time),:motivo) RETURNING cod_blo
                """).setParameter("med", bloqueo.codMed).setParameter("actor", bloqueo.codUsuAdmin)
                .setParameter("fecha", bloqueo.fechaBloqueo).setParameter("inicio", bloqueo.horaInicio)
                .setParameter("fin", bloqueo.horaFin).setParameter("motivo", bloqueo.motivo).getSingleResult();
    }

    public long contarCitasAfectadas(String codBlo) {
        return ((Number) getEntityManager().createNativeQuery(
                "SELECT count(*) FROM public.bloqueos_citas_afectadas WHERE cod_blo = :blo")
                .setParameter("blo", codBlo).getSingleResult()).longValue();
    }

    public List<CitaAdministrativa> citasAfectadas(String codBlo) {
        @SuppressWarnings("unchecked")
        List<String> codigos = getEntityManager().createNativeQuery(
                "SELECT cod_cit FROM public.bloqueos_citas_afectadas WHERE cod_blo = :blo")
                .setParameter("blo", codBlo).getResultList();
        if (codigos.isEmpty()) return List.of();
        return getEntityManager().createQuery("""
                SELECT c.codCit,c.codPac,c.codMed,c.codEsp,c.fecha,c.horaInicio,c.horaFin,c.estado
                FROM Cita c WHERE c.codCit IN :codigos ORDER BY c.fecha,c.horaInicio,c.codCit
                """, CitaAdministrativa.class).setParameter("codigos", codigos).getResultList();
    }

    public List<BloqueoAgenda> buscarBloqueos(String codMed, LocalDate desde, LocalDate hasta) {
        StringBuilder jpql = new StringBuilder("FROM BloqueoAgenda WHERE codMed = :med");
        Map<String, Object> filtros = new HashMap<>();
        filtros.put("med", codMed);
        if (desde != null) {
            jpql.append(" AND fechaBloqueo >= :desde");
            filtros.put("desde", desde);
        }
        if (hasta != null) {
            jpql.append(" AND fechaBloqueo <= :hasta");
            filtros.put("hasta", hasta);
        }
        jpql.append(" ORDER BY fechaBloqueo DESC,horaInicio,codBlo");
        var consulta = getEntityManager().createQuery(jpql.toString(), BloqueoAgenda.class);
        filtros.forEach(consulta::setParameter);
        return consulta.getResultList();
    }

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
