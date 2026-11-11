package org.clinica.repository;

import io.quarkus.hibernate.orm.panache.PanacheRepositoryBase;
import jakarta.enterprise.context.ApplicationScoped;
import org.clinica.entity.Paciente;
import org.clinica.dto.recepcion.RecepcionDtos.PacienteResumen;
import org.clinica.dto.recepcion.RecepcionDtos.PacienteDetalle;

import java.util.List;
import java.util.Optional;

@ApplicationScoped
public class PacienteRepository implements PanacheRepositoryBase<Paciente, String> {

    public List<PacienteResumen> buscarAdministrativos(String patron, String ciExacto, int limite) {
        return getEntityManager().createQuery("""
                SELECT p.codPac,u.nombreCompleto,p.ci,u.email,p.estadoCuenta
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
        return Optional.ofNullable(getEntityManager().createQuery("""
                SELECT p.codPac,u.nombreCompleto,p.ci,u.email,p.telefono,p.ciudadResidencia,
                    p.estadoCuenta,u.estado,p.emailVerificado,p.bloqueadoHasta,
                    CASE WHEN u.estado<>'Activo' THEN 'USUARIO_INACTIVO'
                         WHEN r.estado<>'Activo' THEN 'ROL_INACTIVO'
                         WHEN p.estadoCuenta='Bloqueado_Temporal' OR p.bloqueadoHasta>CURRENT_TIMESTAMP
                             THEN 'PACIENTE_BLOQUEADO'
                         WHEN p.estadoCuenta<>'Activo' THEN 'PACIENTE_INACTIVO'
                         WHEN p.emailVerificado=false THEN 'EMAIL_NO_VERIFICADO'
                         ELSE NULL END
                FROM Paciente p JOIN Usuario u ON u.codUsu=p.codUsu JOIN Rol r ON r.codRol=u.codRol
                WHERE p.codPac=:pac AND u.codRol='ROL001'
                """, PacienteDetalle.class).setParameter("pac",codPac).getSingleResultOrNull());
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
