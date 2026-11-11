package org.clinica.repository;

import io.quarkus.hibernate.orm.panache.PanacheRepositoryBase;
import io.quarkus.panache.common.Sort;
import jakarta.enterprise.context.ApplicationScoped;
import org.clinica.dto.personal.EspecialidadDetalleDTO.MedicoVinculado;
import org.clinica.dto.recepcion.RecepcionDtos.MedicoOpcion;
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

    public List<MedicoOpcion> listarMedicosReservables(String codEsp) {
        return getEntityManager().createQuery("""
                SELECT m.codMed,u.nombreCompleto,COALESCE(v.duracionConsultaMin,m.duracionConsultaMin,e.duracionConsultaMin)
                FROM MedicoEspecialidad v JOIN Medico m ON m.codMed=v.id.codMed
                JOIN Especialidad e ON e.codEsp=v.id.codEsp JOIN Usuario u ON u.codUsu=m.codUsu
                JOIN Rol r ON r.codRol=u.codRol
                WHERE e.codEsp=:esp AND e.estado='Activo' AND v.estado='Activo' AND m.estado='Activo'
                  AND u.estado='Activo' AND u.codRol='ROL003' AND r.estado='Activo'
                ORDER BY u.nombreCompleto,m.codMed
                """, MedicoOpcion.class).setParameter("esp",codEsp).getResultList();
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

    public List<MedicoVinculado> listarVinculosPorEspecialidad(String codEsp) {
        return getEntityManager().createQuery("""
                SELECT m.codMed, u.nombreCompleto, m.estado, v.estado, v.duracionConsultaMin
                FROM MedicoEspecialidad v
                JOIN Medico m ON m.codMed = v.id.codMed
                JOIN Usuario u ON u.codUsu = m.codUsu
                WHERE v.id.codEsp = :codEsp
                ORDER BY m.codMed
                """, MedicoVinculado.class).setParameter("codEsp", codEsp).getResultList();
    }
}
