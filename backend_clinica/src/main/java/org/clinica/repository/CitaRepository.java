package org.clinica.repository;

import io.quarkus.hibernate.orm.panache.PanacheRepositoryBase;
import jakarta.enterprise.context.ApplicationScoped;
import org.clinica.dto.recepcion.RecepcionDtos.CitaRecepcion;
import org.clinica.entity.Cita;

import java.util.Optional;

/** Consultas administrativas. Las escrituras continúan en CitaFunctionRepository. */
@ApplicationScoped
public class CitaRepository implements PanacheRepositoryBase<Cita,String> {
    public Optional<CitaRecepcion> consultarRecepcion(String codCit) {
        return Optional.ofNullable(getEntityManager().createQuery("""
                SELECT c.codCit,c.codSlot,c.codPac,up.nombreCompleto,c.codMed,um.nombreCompleto,
                    c.codEsp,e.nombreEspecialidad,c.fecha,c.horaInicio,c.horaFin,c.estado,c.canalReserva,
                    c.codSec,(c.tokenQr IS NOT NULL)
                FROM Cita c JOIN Paciente p ON p.codPac=c.codPac JOIN Usuario up ON up.codUsu=p.codUsu
                JOIN Medico m ON m.codMed=c.codMed JOIN Usuario um ON um.codUsu=m.codUsu
                JOIN Especialidad e ON e.codEsp=c.codEsp WHERE c.codCit=:cit
                """, CitaRecepcion.class).setParameter("cit",codCit).getSingleResultOrNull());
    }
}
