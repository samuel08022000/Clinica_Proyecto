package org.clinica.repository;

import io.quarkus.hibernate.orm.panache.PanacheRepositoryBase;
import io.quarkus.panache.common.Sort;
import jakarta.enterprise.context.ApplicationScoped;
import org.clinica.entity.Medico;
import org.clinica.entity.MedicoEspecialidad;
import org.clinica.entity.Secretaria;

import java.util.List;
import java.util.Optional;

/** Acceso a personal de la clínica: médicos, secretarías y vínculos médico-especialidad. */
@ApplicationScoped
public class PersonalRepository implements PanacheRepositoryBase<Medico, String> {

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

    // ----- Médico ↔ Especialidad -----

    public List<MedicoEspecialidad> listarEspecialidadesDeMedico(String codMed) {
        return MedicoEspecialidad.list("id.codMed", Sort.by("id.codEsp"), codMed);
    }
}
