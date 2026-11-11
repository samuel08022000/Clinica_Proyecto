package org.clinica.repository;

import io.quarkus.hibernate.orm.panache.PanacheRepositoryBase;
import io.quarkus.panache.common.Sort;
import jakarta.enterprise.context.ApplicationScoped;
import org.clinica.entity.Especialidad;

import java.util.List;
import java.util.Map;
import java.util.Optional;

@ApplicationScoped
public class EspecialidadRepository implements PanacheRepositoryBase<Especialidad, String> {

    private static final String NOMBRE_NORMALIZADO = "lower(trim(nombreEspecialidad)) = lower(:nombre)";
    private static final Sort POR_NOMBRE = Sort.by("nombreEspecialidad");

    public List<Especialidad> listarActivas() {
        return listarPorEstado("Activo");
    }

    public List<Especialidad> listarPorEstado(String estado) {
        return estado == null ? listAll(POR_NOMBRE) : list("estado", POR_NOMBRE, estado);
    }

    /** Búsqueda case-insensitive por nombre (la columna es UNIQUE). */
    public Optional<Especialidad> findByNombre(String nombre) {
        if (nombre == null || nombre.isBlank()) {
            return Optional.empty();
        }
        return find(NOMBRE_NORMALIZADO, Map.of("nombre", nombre.strip())).firstResultOptional();
    }

    public boolean existeNombre(String nombre, String excluirCodEsp) {
        if (excluirCodEsp == null) {
            return findByNombre(nombre).isPresent();
        }
        return count(NOMBRE_NORMALIZADO + " AND codEsp <> :excluir",
                Map.of("nombre", nombre, "excluir", excluirCodEsp)) > 0;
    }

    /** Serializa altas y cambios de nombre equivalentes hasta confirmar o revertir la transacción. */
    public void bloquearNombreNormalizado(String nombre) {
        getEntityManager().createNativeQuery("""
                SELECT 1 FROM pg_advisory_xact_lock(hashtextextended('especialidades:' || lower(:nombre), 0))
                """).setParameter("nombre", nombre).getSingleResult();
    }

    public String insertar(String nombre, String descripcion, Short duracion, String estado) {
        return (String) getEntityManager().createNativeQuery("""
                INSERT INTO public.especialidades (nombre_especialidad, descripcion, duracion_consulta_min, estado)
                VALUES (:nombre, CAST(:descripcion AS text), :duracion, :estado)
                RETURNING cod_esp
                """).setParameter("nombre", nombre).setParameter("descripcion", descripcion)
                .setParameter("duracion", duracion).setParameter("estado", estado).getSingleResult();
    }
}
