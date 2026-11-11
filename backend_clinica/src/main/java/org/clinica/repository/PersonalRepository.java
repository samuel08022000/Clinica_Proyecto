package org.clinica.repository;

import io.quarkus.hibernate.orm.panache.PanacheRepositoryBase;
import io.quarkus.panache.common.Sort;
import jakarta.enterprise.context.ApplicationScoped;
import org.clinica.dto.personal.SecretariaRespuestaDTO;
import org.clinica.entity.Medico;
import org.clinica.entity.MedicoEspecialidad;
import org.clinica.entity.Secretaria;

import java.util.List;
import java.util.Optional;

/** Acceso a personal de la clínica: médicos, secretarías y vínculos médico-especialidad. */
@ApplicationScoped
public class PersonalRepository implements PanacheRepositoryBase<Medico, String> {

    private static final String SELECT_SECRETARIAS = """
            SELECT new org.clinica.dto.personal.SecretariaRespuestaDTO(
                s.codSec, u.codUsu, u.nombreCompleto, u.email, s.ci, s.telefono, s.estado)
            FROM Secretaria s JOIN Usuario u ON u.codUsu = s.codUsu
            WHERE u.codRol = 'ROL002'
            """;

    // ----- Médicos -----

    public Optional<Medico> findMedicoByCodUsu(String codUsu) {
        return find("codUsu", codUsu).firstResultOptional();
    }

    public List<Medico> listarMedicosActivos() {
        return list("estado", Sort.by("codMed"), "Activo");
    }

    /** Médicos activos con un vínculo activo a la especialidad indicada. */
    public List<Medico> listarMedicosActivosPorEspecialidad(String codEsp) {
        return list("""
                estado = 'Activo' and codMed in (
                    select me.id.codMed from MedicoEspecialidad me
                    where me.id.codEsp = ?1 and me.estado = 'Activo')
                """, Sort.by("codMed"), codEsp);
    }

    // ----- Secretarías -----

    public Optional<Secretaria> findSecretariaByCodUsu(String codUsu) {
        return Secretaria.find("codUsu", codUsu).firstResultOptional();
    }

    public List<Secretaria> listarSecretariasActivas() {
        return Secretaria.list("estado", Sort.by("codSec"), "Activo");
    }

    public List<SecretariaRespuestaDTO> listarSecretarias(String estado) {
        String filtro = estado == null ? "" : " AND s.estado = :estado";
        var consulta = getEntityManager().createQuery(SELECT_SECRETARIAS + filtro + " ORDER BY s.codSec",
                SecretariaRespuestaDTO.class);
        if (estado != null) {
            consulta.setParameter("estado", estado);
        }
        return consulta.getResultList();
    }

    public Optional<SecretariaRespuestaDTO> obtenerSecretaria(String codSec) {
        return Optional.ofNullable(getEntityManager().createQuery(SELECT_SECRETARIAS + " AND s.codSec = :codSec",
                        SecretariaRespuestaDTO.class)
                .setParameter("codSec", codSec).getSingleResultOrNull());
    }

    // ----- Médico ↔ Especialidad -----

    public List<MedicoEspecialidad> listarEspecialidadesDeMedico(String codMed) {
        return MedicoEspecialidad.list("id.codMed", Sort.by("id.codEsp"), codMed);
    }
}
