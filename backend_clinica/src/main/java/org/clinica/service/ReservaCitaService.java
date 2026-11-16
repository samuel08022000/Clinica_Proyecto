package org.clinica.service;

import jakarta.enterprise.context.ApplicationScoped;
import jakarta.inject.Inject;
import jakarta.persistence.EntityManager;
import jakarta.transaction.Transactional;
import jakarta.ws.rs.core.Response;
import org.clinica.config.DbActorHelper;
import org.clinica.dto.recepcion.RecepcionDtos.CitaRecepcion;
import org.clinica.dto.recepcion.RecepcionDtos.NuevaCita;
import org.clinica.entity.SlotAgenda;
import org.clinica.repository.CitaFunctionRepository;
import org.clinica.repository.CitaRepository;
import org.clinica.entity.VistaAgendaDisponibilidad;
import org.clinica.repository.AgendaRepository;

import java.time.LocalDate;
import java.util.List;

@ApplicationScoped
public class ReservaCitaService {

    @Inject
    AgendaRepository agendaRepository;

    @Inject RecepcionService recepcion;
    @Inject CitaFunctionRepository funciones;
    @Inject CitaRepository citas;
    @Inject DbActorHelper actorHelper;
    @Inject EntityManager em;
    @Inject org.clinica.repository.DerivacionRepository derivaciones;

    public List<VistaAgendaDisponibilidad> buscarCuposDisponibles(String codEsp, String codMed, LocalDate fecha) {
        return agendaRepository.buscarCuposDisponibles(codEsp, codMed, fecha);
    }

    @Transactional
    public CitaRecepcion reservarPresencial(NuevaCita solicitud, String actor) {
        String codPac = solicitud.codPac;
        if (solicitud.codDer == null) RecepcionService.validarIdentificador(codPac);
        RecepcionService.validarIdentificador(solicitud.codSlot);
        SlotAgenda slot = em.find(SlotAgenda.class, solicitud.codSlot);
        if (slot == null) {
            throw PersonalService.error(Response.Status.NOT_FOUND, "NO_ENCONTRADO", "No existe el turno.");
        }
        if (solicitud.codDer != null) {
            RecepcionService.validarIdentificador(solicitud.codDer);
            var derivacion = derivaciones.detalle(solicitud.codDer, null).orElseThrow(() ->
                    PersonalService.error(Response.Status.NOT_FOUND, "NO_ENCONTRADO", "No existe la derivación."));
            if (!"Pendiente".equals(derivacion.estado()) || derivacion.codCitHija() != null) {
                throw PersonalService.error(Response.Status.CONFLICT, "DERIVACION_NO_DISPONIBLE", "La derivación ya fue programada o no está habilitada.");
            }
            if ((codPac != null && !codPac.equals(derivacion.codPac()))
                    || !slot.codEsp.equals(derivacion.codEspDestino())
                    || (derivacion.codMedDestino() != null && !slot.codMed.equals(derivacion.codMedDestino()))) {
                throw PersonalService.error(Response.Status.CONFLICT, "DESTINO_INCOMPATIBLE", "El paciente o turno no corresponde a la derivación.");
            }
            codPac = derivacion.codPac();
        }
        RecepcionService.validarIdentificador(codPac);
        recepcion.exigirPacienteHabilitado(codPac);
        actorHelper.fijarActor(actor);
        String codCit = funciones.reservarCita(codPac, solicitud.codSlot, actor, "Ventanilla", solicitud.codDer);
        return consultarRecepcion(codCit);
    }

    public CitaRecepcion consultarRecepcion(String codCit) {
        RecepcionService.validarIdentificador(codCit);
        return citas.consultarRecepcion(codCit).orElseThrow(() ->
                PersonalService.error(Response.Status.NOT_FOUND, "NO_ENCONTRADO", "No existe la cita."));
    }
}
