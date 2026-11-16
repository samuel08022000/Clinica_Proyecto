package org.clinica.repository;

import io.quarkus.hibernate.orm.panache.PanacheRepositoryBase;
import jakarta.enterprise.context.ApplicationScoped;
import org.clinica.dto.sancion.SancionDtos.Incidencia;
import org.clinica.dto.sancion.SancionDtos.PaginaIncidencias;
import org.clinica.entity.IncidenciaSancion;

import java.util.List;
import java.util.Optional;

@ApplicationScoped
public class IncidenciaRepository implements PanacheRepositoryBase<IncidenciaSancion, String> {
    private static final String PROYECCION = """
            SELECT i.codInc,i.codPac,i.codCit,i.tipoIncidencia,i.motivo,i.registradoPorUsu,
                i.fechaRegistro,i.fechaFinSancion,
                CASE WHEN i.tipoIncidencia='SANCION_BLOQUEO' AND i.fechaFinSancion>CURRENT_TIMESTAMP
                    THEN true ELSE false END
            FROM IncidenciaSancion i WHERE i.codPac=:pac
            """;

    public Optional<Incidencia> sancion(String codPac, String codInc) {
        return Optional.ofNullable(getEntityManager().createQuery(PROYECCION +
                " AND i.codInc=:inc AND i.tipoIncidencia='SANCION_BLOQUEO'", Incidencia.class)
                .setParameter("pac", codPac).setParameter("inc", codInc).getSingleResultOrNull());
    }

    public PaginaIncidencias listar(String codPac, String tipo, Boolean vigente, int page, int size) {
        String hql = PROYECCION;
        if (tipo != null) hql += " AND i.tipoIncidencia=:tipo";
        if (vigente != null) hql += vigente
                ? " AND i.fechaFinSancion>CURRENT_TIMESTAMP" : " AND i.fechaFinSancion<=CURRENT_TIMESTAMP";
        hql += " ORDER BY i.fechaRegistro DESC,i.secuencial DESC";
        var query = getEntityManager().createQuery(hql, Incidencia.class).setParameter("pac", codPac);
        if (tipo != null) query.setParameter("tipo", tipo);
        List<Incidencia> filas = query.setFirstResult(page * size).setMaxResults(size + 1).getResultList();
        boolean hayMas = filas.size() > size;
        return new PaginaIncidencias(List.copyOf(hayMas ? filas.subList(0, size) : filas), page, size, hayMas);
    }
}
