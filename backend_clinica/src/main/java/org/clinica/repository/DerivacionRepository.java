package org.clinica.repository;

import io.quarkus.hibernate.orm.panache.PanacheRepositoryBase;
import jakarta.enterprise.context.ApplicationScoped;
import org.clinica.entity.Derivacion;
import org.clinica.dto.medico.SeguimientoDtos.DerivacionResumen;
import org.clinica.dto.medico.SeguimientoDtos.PaginaDerivaciones;
import java.util.List;
import java.util.Optional;

@ApplicationScoped
public class DerivacionRepository implements PanacheRepositoryBase<Derivacion, String> {
    private static final String RESUMEN = """
            SELECT d.codDer,d.codCitOrigen,c.codPac,up.nombreCompleto,d.codMedEmisor,ue.nombreCompleto,
                d.codEspDestino,e.nombreEspecialidad,d.codMedDestino,ud.nombreCompleto,d.estado,
                d.fechaDerivacion,d.codCitHija
            FROM Derivacion d JOIN Cita c ON c.codCit=d.codCitOrigen
            JOIN Paciente p ON p.codPac=c.codPac JOIN Usuario up ON up.codUsu=p.codUsu
            JOIN Medico me ON me.codMed=d.codMedEmisor JOIN Usuario ue ON ue.codUsu=me.codUsu
            JOIN Especialidad e ON e.codEsp=d.codEspDestino
            LEFT JOIN Medico md ON md.codMed=d.codMedDestino LEFT JOIN Usuario ud ON ud.codUsu=md.codUsu
            WHERE 1=1
            """;

    public Optional<DerivacionResumen> detalle(String codDer, String codMedEmisor) {
        var query = getEntityManager().createQuery(RESUMEN + " AND d.codDer=:der"
                + (codMedEmisor == null ? "" : " AND d.codMedEmisor=:med"), DerivacionResumen.class)
                .setParameter("der", codDer);
        if (codMedEmisor != null) query.setParameter("med", codMedEmisor);
        return Optional.ofNullable(query.getSingleResultOrNull());
    }

    public PaginaDerivaciones listar(String codMedEmisor, String estado, int page, int size) {
        var query = getEntityManager().createQuery(RESUMEN
                + (codMedEmisor == null ? "" : " AND d.codMedEmisor=:med")
                + (estado == null ? "" : " AND d.estado=:estado")
                + " ORDER BY d.fechaDerivacion DESC,d.codDer DESC", DerivacionResumen.class);
        if (codMedEmisor != null) query.setParameter("med", codMedEmisor);
        if (estado != null) query.setParameter("estado", estado);
        List<DerivacionResumen> rows = query.setFirstResult(page * size).setMaxResults(size + 1).getResultList();
        boolean more = rows.size() > size;
        return new PaginaDerivaciones(List.copyOf(more ? rows.subList(0, size) : rows), page, size, more);
    }

    /** Invocar bajo el bloqueo de la cita origen para serializar reintentos. */
    public Optional<Derivacion> identica(String cita, String especialidad, String medico, String motivo) {
        return find("codCitOrigen=?1 and codEspDestino=?2 and "
                + (medico == null ? "codMedDestino is null" : "codMedDestino=?4")
                + " and motivoDerivacion=?3", medico == null
                ? new Object[]{cita, especialidad, motivo} : new Object[]{cita, especialidad, motivo, medico})
                .firstResultOptional();
    }
}
