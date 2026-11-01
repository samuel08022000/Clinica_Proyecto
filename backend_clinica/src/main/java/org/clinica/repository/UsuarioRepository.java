package org.clinica.repository;

import io.quarkus.hibernate.orm.panache.PanacheRepositoryBase;
import io.quarkus.panache.common.Sort;
import jakarta.enterprise.context.ApplicationScoped;
import org.clinica.entity.Usuario;

import java.util.List;
import java.util.Optional;

@ApplicationScoped
public class UsuarioRepository implements PanacheRepositoryBase<Usuario, String> {

    /** Búsqueda case-insensitive, coherente con el índice único {@code lower(trim(email))}. */
    public Optional<Usuario> findByEmail(String email) {
        if (email == null || email.isBlank()) {
            return Optional.empty();
        }
        return find("lower(trim(email)) = ?1", email.trim().toLowerCase()).firstResultOptional();
    }

    public List<Usuario> listarPorRol(String codRol) {
        return list("codRol", Sort.by("nombreCompleto"), codRol);
    }
}
