package org.clinica.repository;

import io.quarkus.hibernate.orm.panache.PanacheRepositoryBase;
import io.quarkus.panache.common.Sort;
import jakarta.enterprise.context.ApplicationScoped;
import org.clinica.entity.Especialidad;

import java.util.List;
import java.util.Optional;

@ApplicationScoped
public class EspecialidadRepository implements PanacheRepositoryBase<Especialidad, String> {

    public List<Especialidad> listarActivas() {
        return list("estado", Sort.by("nombreEspecialidad"), "Activo");
    }

    /** Búsqueda case-insensitive por nombre (la columna es UNIQUE). */
    public Optional<Especialidad> findByNombre(String nombre) {
        if (nombre == null || nombre.isBlank()) {
            return Optional.empty();
        }
        return find("lower(nombreEspecialidad) = ?1", nombre.trim().toLowerCase()).firstResultOptional();
    }
}
