package org.clinica.service;

import jakarta.enterprise.context.ApplicationScoped;
import jakarta.inject.Inject;
import jakarta.persistence.EntityManager;
import jakarta.transaction.Transactional;
import jakarta.ws.rs.core.Response;
import org.clinica.config.DbActorHelper;
import org.clinica.dto.recepcion.RecepcionDtos.*;
import org.clinica.entity.Medico;
import org.clinica.repository.CitaFunctionRepository;
import org.clinica.repository.CitaRepository;

import java.time.LocalDate;
import java.time.format.DateTimeParseException;
import java.util.Set;

import static org.clinica.service.RecepcionService.validacion;
import static org.clinica.service.RecepcionService.validarIdentificador;
import static org.clinica.service.PersonalService.error;

@ApplicationScoped
public class GestionCitaService {
    private static final Set<String> ESTADOS = Set.of("Confirmada", "En Espera", "Atendida", "Cancelada",
            "No Asistió", "Afectada/Pendiente Reprogramación", "Reprogramada");
    @Inject CitaFunctionRepository funciones;
    @Inject CitaRepository citas;
    @Inject RecepcionService recepcion;
    @Inject DbActorHelper actorHelper;
    @Inject EntityManager em;

    @Transactional
    public Cancelacion cancelar(String codCit, CancelacionSolicitud solicitud, String actor) {
        validarIdentificador(codCit);
        if (solicitud == null || solicitud.tieneCamposNoPermitidos() || solicitud.motivo == null) {
            throw validacion("Debe proporcionar únicamente el motivo de cancelación.");
        }
        String motivo = solicitud.motivo.strip();
        if (motivo.isBlank() || motivo.codePointCount(0, motivo.length()) > 2000) {
            throw validacion("El motivo debe tener entre 1 y 2000 caracteres.");
        }
        actorHelper.fijarActor(actor);
        funciones.cancelarCita(codCit, actor, motivo);
        var detalle = detalle(codCit);
        return new Cancelacion(codCit, detalle.estado(), detalle.motivoCancelacion(),
                detalle.canceladoPorUsu(), detalle.canceladoEn());
    }

    @Transactional
    public Inasistencia registrarInasistencia(String codCit, InasistenciaSolicitud solicitud, String actor) {
        validarIdentificador(codCit);
        if (solicitud != null && solicitud.tieneCamposNoPermitidos()) {
            throw validacion("La inasistencia no acepta campos en el cuerpo.");
        }
        actorHelper.fijarActor(actor);
        funciones.registrarInasistencia(codCit, actor);
        var detalle = detalle(codCit);
        return new Inasistencia(codCit, detalle.estado(), detalle.codIncInasistencia(),
                detalle.inasistenciaPorUsu(), detalle.inasistenciaRegistradaEn(), detalle.motivoInasistencia());
    }

    public PaginaCitas listar(String fecha, String desde, String hasta, String estado, String codPac,
            String codMed, String pageTexto, String sizeTexto, boolean historial) {
        int page = entero(pageTexto);
        int size = entero(sizeTexto);
        if (page < 0 || page > 10000 || size < 1 || size > 50) {
            throw validacion("page debe estar entre 0 y 10000; size entre 1 y 50.");
        }
        if (estado != null && !ESTADOS.contains(estado)) throw validacion("Estado de cita inválido.");
        if (codPac != null) recepcion.paciente(codPac); // No exige habilitación para reservar: conserva históricos.
        if (codMed != null) {
            validarIdentificador(codMed);
            if (em.find(Medico.class, codMed) == null) {
                throw error(Response.Status.NOT_FOUND, "NO_ENCONTRADO", "No existe el médico.");
            }
        }
        if (fecha != null && (desde != null || hasta != null)) {
            throw validacion("Use fecha o el rango desde/hasta, sin combinarlos.");
        }
        LocalDate inicio = fecha == null ? fecha(desde) : fecha(fecha);
        LocalDate fin = fecha == null ? fecha(hasta) : inicio;
        if (!historial && inicio == null && fin == null) {
            inicio = (LocalDate) em.createNativeQuery("SELECT public.fecha_clinica()", LocalDate.class).getSingleResult();
            fin = inicio;
        }
        if (inicio != null && fin != null && inicio.isAfter(fin)) throw validacion("Rango de fechas inválido.");
        return citas.historial(codPac, codMed, inicio, fin, estado, page, size);
    }

    private CitaHistorial detalle(String codCit) {
        return citas.consultarDetalle(codCit).orElseThrow(() ->
                error(Response.Status.NOT_FOUND, "NO_ENCONTRADO", "No existe la cita."));
    }

    private static LocalDate fecha(String texto) {
        if (texto == null) return null;
        try {
            if (!texto.matches("[0-9]{4}-[0-9]{2}-[0-9]{2}")) throw new DateTimeParseException("Formato", texto, 0);
            LocalDate fecha = LocalDate.parse(texto);
            if (fecha.getYear() == 0) throw new DateTimeParseException("Año", texto, 0);
            return fecha;
        }
        catch (DateTimeParseException exception) { throw validacion("Use fechas con formato YYYY-MM-DD."); }
    }

    private static int entero(String texto) {
        try { return Integer.parseInt(texto); }
        catch (NumberFormatException exception) { throw validacion("page y size deben ser números enteros válidos."); }
    }
}
