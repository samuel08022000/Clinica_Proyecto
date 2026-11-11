package org.clinica.repository;

import io.quarkus.hibernate.orm.panache.PanacheRepositoryBase;
import jakarta.enterprise.context.ApplicationScoped;
import org.clinica.dto.recepcion.RecepcionDtos.CitaRecepcion;
import org.clinica.dto.recepcion.RecepcionDtos.Acreditacion;
import org.clinica.dto.recepcion.RecepcionDtos.Asistencia;
import org.clinica.entity.Cita;
import org.hibernate.query.NativeQuery;

import java.time.LocalDate;
import java.util.Optional;
import java.util.List;
import java.util.LinkedHashMap;
import java.util.Map;
import org.clinica.dto.recepcion.RecepcionDtos.CitaHistorial;
import org.clinica.dto.recepcion.RecepcionDtos.PaginaCitas;

/** Consultas administrativas. Las escrituras continúan en CitaFunctionRepository. */
@ApplicationScoped
public class CitaRepository implements PanacheRepositoryBase<Cita,String> {
    private static final String HISTORIAL = """
            SELECT h.codCit,h.codPac,u.nombreCompleto,h.codMed,h.nombreMedico,h.codEsp,h.nombreEspecialidad,
                h.fecha,h.horaInicio,h.horaFin,h.estado,h.canalReserva,c.motivoCancelacion,c.canceladoPorUsu,
                h.canceladoEn,h.horaLlegadaCheckin,c.checkinPorUsu,h.fechaAtencion,
                i.codInc,i.fechaRegistro,i.registradoPorUsu,i.motivo
            FROM VistaHistorialCitas h JOIN Cita c ON c.codCit=h.codCit
            JOIN Paciente p ON p.codPac=h.codPac JOIN Usuario u ON u.codUsu=p.codUsu
            LEFT JOIN IncidenciaSancion i ON i.codCit=h.codCit AND i.tipoIncidencia='INASISTENCIA_NO_SHOW'
                AND i.secuencial=(SELECT max(i2.secuencial) FROM IncidenciaSancion i2
                    WHERE i2.codCit=h.codCit AND i2.tipoIncidencia='INASISTENCIA_NO_SHOW')
            WHERE 1=1
            """;

    public Optional<CitaHistorial> consultarDetalle(String codCit) {
        return Optional.ofNullable(getEntityManager().createQuery(HISTORIAL + " AND h.codCit=:cit", CitaHistorial.class)
                .setParameter("cit", codCit).getSingleResultOrNull());
    }

    public PaginaCitas historial(String codPac, String codMed, LocalDate desde, LocalDate hasta,
            String estado, int page, int size) {
        StringBuilder hql = new StringBuilder(HISTORIAL);
        Map<String, Object> parametros = new LinkedHashMap<>();
        if (codPac != null) { hql.append(" AND h.codPac=:pac"); parametros.put("pac", codPac); }
        if (codMed != null) { hql.append(" AND h.codMed=:med"); parametros.put("med", codMed); }
        if (desde != null) { hql.append(" AND h.fecha>=:desde"); parametros.put("desde", desde); }
        if (hasta != null) { hql.append(" AND h.fecha<=:hasta"); parametros.put("hasta", hasta); }
        if (estado != null) { hql.append(" AND h.estado=:estado"); parametros.put("estado", estado); }
        hql.append(" ORDER BY h.fecha,h.horaInicio,h.codCit");
        var consulta = getEntityManager().createQuery(hql.toString(), CitaHistorial.class);
        parametros.forEach(consulta::setParameter);
        List<CitaHistorial> filas = consulta.setFirstResult(page * size).setMaxResults(size + 1).getResultList();
        boolean hayMas = filas.size() > size;
        return new PaginaCitas(List.copyOf(hayMas ? filas.subList(0, size) : filas), page, size, hayMas);
    }

    public Optional<Acreditacion> consultarAcreditacion(String codCit) {
        NativeQuery<?> consulta = getEntityManager().createNativeQuery("""
                SELECT c.cod_cit,u.nombre_completo,c.fecha,c.estado,
                    (q.cod_cit=c.cod_cit AND q.fecha=c.fecha) IS TRUE AS qr_valido,
                    ((q.cod_cit=c.cod_cit AND q.fecha=c.fecha) IS TRUE
                        AND public.cita_admite_checkin(c.cod_cit)) AS admite_checkin
                FROM public.citas c JOIN public.pacientes p ON p.cod_pac=c.cod_pac
                JOIN public.usuarios u ON u.cod_usu=p.cod_usu
                LEFT JOIN LATERAL public.verificar_token_qr(c.token_qr) q ON true
                WHERE c.cod_cit=:cit
                """).unwrap(NativeQuery.class);
        consulta.addScalar("cod_cit", String.class).addScalar("nombre_completo", String.class)
                .addScalar("fecha", LocalDate.class).addScalar("estado", String.class)
                .addScalar("qr_valido", Boolean.class).addScalar("admite_checkin", Boolean.class);
        Object[] fila = (Object[]) consulta.setParameter("cit", codCit).getSingleResultOrNull();
        if (fila == null) {
            return Optional.empty();
        }
        return Optional.of(new Acreditacion((String) fila[0], (String) fila[1], (LocalDate) fila[2],
                (String) fila[3], (Boolean) fila[4], (Boolean) fila[5]));
    }

    public Optional<Asistencia> consultarAsistencia(String codCit) {
        return Optional.ofNullable(getEntityManager().createQuery("""
                SELECT c.codCit,u.nombreCompleto,c.fecha,c.estado,c.horaLlegadaCheckin,c.checkinPorUsu
                FROM Cita c JOIN Paciente p ON p.codPac=c.codPac JOIN Usuario u ON u.codUsu=p.codUsu
                WHERE c.codCit=:cit
                """, Asistencia.class).setParameter("cit", codCit).getSingleResultOrNull());
    }

    public Optional<CitaRecepcion> consultarRecepcion(String codCit) {
        return Optional.ofNullable(getEntityManager().createQuery("""
                SELECT c.codCit,c.codSlot,c.codPac,up.nombreCompleto,c.codMed,um.nombreCompleto,
                    c.codEsp,e.nombreEspecialidad,c.fecha,c.horaInicio,c.horaFin,c.estado,c.canalReserva,
                    c.codSec,(c.tokenQr IS NOT NULL)
                FROM Cita c JOIN Paciente p ON p.codPac=c.codPac JOIN Usuario up ON up.codUsu=p.codUsu
                JOIN Medico m ON m.codMed=c.codMed JOIN Usuario um ON um.codUsu=m.codUsu
                JOIN Especialidad e ON e.codEsp=c.codEsp WHERE c.codCit=:cit
                """, CitaRecepcion.class).setParameter("cit",codCit).getSingleResultOrNull());
    }
}
