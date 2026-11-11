package org.clinica.repository;

import io.quarkus.hibernate.orm.panache.PanacheRepositoryBase;
import jakarta.enterprise.context.ApplicationScoped;
import org.clinica.entity.Paciente;
import org.clinica.dto.recepcion.RecepcionDtos.PacienteResumen;
import org.clinica.dto.recepcion.RecepcionDtos.PacienteDetalle;

import java.util.List;
import java.util.Optional;
import org.clinica.dto.sancion.SancionDtos.PaginaPacientes;

@ApplicationScoped
public class PacienteRepository implements PanacheRepositoryBase<Paciente, String> {
    private static final String ESTADO = "CAST(function('estado_reserva_paciente',p.codPac) AS String)";
    private static final String DETALLE = """
            SELECT p.codPac,u.nombreCompleto,p.ci,u.email,p.telefono,p.ciudadResidencia,
                %s,u.estado,p.emailVerificado,p.bloqueadoHasta,
                CASE WHEN u.estado<>'Activo' THEN 'USUARIO_INACTIVO'
                     WHEN r.estado<>'Activo' THEN 'ROL_INACTIVO'
                     WHEN %s='Bloqueado_Temporal' THEN 'PACIENTE_BLOQUEADO'
                     WHEN %s<>'Activo' THEN 'PACIENTE_INACTIVO'
                     WHEN p.emailVerificado=false THEN 'EMAIL_NO_VERIFICADO'
                     ELSE NULL END
            FROM Paciente p JOIN Usuario u ON u.codUsu=p.codUsu JOIN Rol r ON r.codRol=u.codRol
            WHERE u.codRol='ROL001'
            """.formatted(ESTADO, ESTADO, ESTADO);

    public String estadoReservaEfectivo(String codPac) {
        return (String) getEntityManager().createNativeQuery("SELECT public.estado_reserva_paciente(CAST(:pac AS text))")
                .setParameter("pac", codPac).getSingleResult();
    }

    public PaginaPacientes listarAdministrativos(String patron, String estado, int page, int size) {
        String hql = DETALLE;
        if (patron != null) hql += " AND (lower(u.nombreCompleto) LIKE :q ESCAPE '!' OR lower(u.email) LIKE :q ESCAPE '!' OR lower(p.ci) LIKE :q ESCAPE '!')";
        if (estado != null) hql += " AND " + ESTADO + "=:estado";
        var query = getEntityManager().createQuery(hql + " ORDER BY u.nombreCompleto,p.codPac", PacienteDetalle.class);
        if (patron != null) query.setParameter("q", patron);
        if (estado != null) query.setParameter("estado", estado);
        var filas = query.setFirstResult(page * size).setMaxResults(size + 1).getResultList();
        boolean hayMas = filas.size() > size;
        return new PaginaPacientes(List.copyOf(hayMas ? filas.subList(0, size) : filas), page, size, hayMas);
    }

    public List<PacienteResumen> buscarAdministrativos(String patron, String ciExacto, int limite) {
        return getEntityManager().createQuery("""
                SELECT p.codPac,u.nombreCompleto,p.ci,u.email,CAST(function('estado_reserva_paciente',p.codPac) AS String)
                FROM Paciente p JOIN Usuario u ON u.codUsu=p.codUsu
                WHERE u.codRol='ROL001' AND (
                    lower(u.nombreCompleto) LIKE :patron ESCAPE '!'
                    OR lower(u.email) LIKE :patron ESCAPE '!'
                    OR lower(p.ci) LIKE :patron ESCAPE '!'
                    OR p.codPac=:ciExacto)
                ORDER BY u.nombreCompleto,p.codPac
                """, PacienteResumen.class)
                .setParameter("patron",patron).setParameter("ciExacto",ciExacto)
                .setMaxResults(limite).getResultList();
    }

    public Optional<PacienteDetalle> consultarAdministrativo(String codPac) {
        return Optional.ofNullable(getEntityManager().createQuery(DETALLE + " AND p.codPac=:pac", PacienteDetalle.class)
                .setParameter("pac",codPac).getSingleResultOrNull());
    }

    public Optional<Paciente> findByCodUsu(String codUsu) {
        return find("codUsu", codUsu).firstResultOptional();
    }

    /**
     * Busca por CI usando exactamente la misma normalización que el índice único
     * {@code uq_pacientes_ci_normalizado} (ignora espacios, guiones, puntos y mayúsculas).
     */
    public Optional<Paciente> findByCi(String ci) {
        if (ci == null || ci.isBlank()) {
            return Optional.empty();
        }
        @SuppressWarnings("unchecked")
        List<Paciente> resultado = getEntityManager().createNativeQuery("""
                SELECT * FROM public.pacientes
                WHERE upper(regexp_replace(trim(ci), '[^[:alnum:]]', '', 'g'))
                    = upper(regexp_replace(trim(CAST(:ci AS text)), '[^[:alnum:]]', '', 'g'))
                """, Paciente.class)
                .setParameter("ci", ci)
                .setMaxResults(1)
                .getResultList();
        return resultado.stream().findFirst();
    }
}
