package org.clinica.repository;

import io.quarkus.hibernate.orm.panache.PanacheRepositoryBase;
import jakarta.enterprise.context.ApplicationScoped;
import org.clinica.entity.Analisis;
import org.clinica.dto.medico.SeguimientoDtos.AnalisisResumen;
import java.util.List;
import java.util.Optional;

@ApplicationScoped
public class AnalisisRepository implements PanacheRepositoryBase<Analisis, String> {
    public List<AnalisisResumen> listar(String codCit, String codMed) {
        // No se publican archivo_pdf_url ni rutas privadas sin un mecanismo autorizado de descarga.
        return getEntityManager().createQuery("""
                SELECT a.codAna,a.codCit,a.solicitadoPorUsu,a.tipoAnalisis,a.estado,a.resultado,
                    a.fechaRegistro,a.fechaEntregaEstimada,a.fechaEntregaReal
                FROM Analisis a JOIN Cita c ON c.codCit=a.codCit
                WHERE c.codCit=:cit AND c.codMed=:med ORDER BY a.fechaRegistro,a.codAna
                """, AnalisisResumen.class).setParameter("cit", codCit).setParameter("med", codMed).getResultList();
    }

    public Optional<Analisis> mismoTipo(String codCit, String tipo) {
        return find("codCit=?1 and lower(trim(tipoAnalisis))=lower(?2)", codCit, tipo).firstResultOptional();
    }

    public Optional<String> citaPropia(String codAna, String codMed) {
        return Optional.ofNullable(getEntityManager().createQuery("""
                SELECT a.codCit FROM Analisis a JOIN Cita c ON c.codCit=a.codCit
                WHERE a.codAna=:ana AND c.codMed=:med
                """, String.class).setParameter("ana", codAna).setParameter("med", codMed).getSingleResultOrNull());
    }

    public String insertar(String codCit, String actor, String tipo) {
        return (String) getEntityManager().createNativeQuery("""
                INSERT INTO public.analisis(cod_cit,solicitado_por_usu,tipo_analisis)
                VALUES (:cit,:actor,:tipo) RETURNING cod_ana
                """).setParameter("cit", codCit).setParameter("actor", actor).setParameter("tipo", tipo).getSingleResult();
    }

    public void resultado(String codAna, String estado, String resultado) {
        org.hibernate.query.NativeQuery<?> query = getEntityManager().createNativeQuery(
                "UPDATE public.analisis SET estado=:estado,resultado=:resultado WHERE cod_ana=:ana")
                .unwrap(org.hibernate.query.NativeQuery.class);
        query.setParameter("estado", estado).setParameter("resultado", resultado, String.class)
                .setParameter("ana", codAna).executeUpdate();
    }
}
