package org.clinica.repository;

import io.quarkus.hibernate.orm.panache.PanacheRepositoryBase;
import jakarta.enterprise.context.ApplicationScoped;
import org.clinica.entity.Paciente;

import java.util.List;
import java.util.Optional;

@ApplicationScoped
public class PacienteRepository implements PanacheRepositoryBase<Paciente, String> {

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
