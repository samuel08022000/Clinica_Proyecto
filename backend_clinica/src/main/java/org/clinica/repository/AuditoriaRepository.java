package org.clinica.repository;

import io.quarkus.hibernate.orm.panache.PanacheRepositoryBase;
import io.quarkus.panache.common.Sort;
import jakarta.enterprise.context.ApplicationScoped;
import org.clinica.entity.Bitacora;
import org.clinica.entity.IncidenciaSancion;
import org.clinica.entity.VistaHistorialCitas;

import java.util.List;

/** Consultas de solo lectura: historial de citas, incidencias/sanciones y bitácora. */
@ApplicationScoped
public class AuditoriaRepository implements PanacheRepositoryBase<Bitacora, String> {

    private static final Sort ORDEN_HISTORIAL = Sort.descending("fecha", "horaInicio");

    // ----- Historial de citas (v_historial_citas) -----

    public List<VistaHistorialCitas> historialPorPaciente(String codPac) {
        return VistaHistorialCitas.list("codPac", ORDEN_HISTORIAL, codPac);
    }

    public List<VistaHistorialCitas> historialPorMedico(String codMed) {
        return VistaHistorialCitas.list("codMed", ORDEN_HISTORIAL, codMed);
    }

    // ----- Incidencias y sanciones -----

    public List<IncidenciaSancion> listarIncidenciasPorPaciente(String codPac) {
        return IncidenciaSancion.list("codPac", Sort.descending("fechaRegistro", "secuencial"), codPac);
    }

    // ----- Bitácora -----

    /** Últimos {@code limite} registros de auditoría (más recientes primero). */
    public List<Bitacora> listarUltimos(int limite) {
        return findAll(Sort.descending("registradoEn", "secuencial"))
                .page(0, Math.max(1, limite))
                .list();
    }

    public List<Bitacora> listarPorTabla(String nombreTabla, int limite) {
        return find("nombreTabla", Sort.descending("registradoEn", "secuencial"), nombreTabla)
                .page(0, Math.max(1, limite))
                .list();
    }

    public List<Bitacora> listarPorUsuario(String codUsu, int limite) {
        return find("codUsu", Sort.descending("registradoEn", "secuencial"), codUsu)
                .page(0, Math.max(1, limite))
                .list();
    }
}
