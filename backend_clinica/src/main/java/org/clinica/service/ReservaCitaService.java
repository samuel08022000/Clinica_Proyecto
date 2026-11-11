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

    public List<VistaAgendaDisponibilidad> buscarCuposDisponibles(String codEsp, String codMed, LocalDate fecha) {
        return agendaRepository.buscarCuposDisponibles(codEsp, codMed, fecha);
    }

    @Transactional
    public CitaRecepcion reservarPresencial(NuevaCita solicitud, String actor) {
        RecepcionService.validarIdentificador(solicitud.codPac);
        RecepcionService.validarIdentificador(solicitud.codSlot);
        recepcion.exigirPacienteHabilitado(solicitud.codPac);
        if (em.find(SlotAgenda.class, solicitud.codSlot) == null) {
            throw PersonalService.error(Response.Status.NOT_FOUND, "NO_ENCONTRADO", "No existe el turno.");
        }
        actorHelper.fijarActor(actor);
        String codCit = funciones.reservarCita(solicitud.codPac, solicitud.codSlot, actor, "Ventanilla", null);
        return consultarRecepcion(codCit);
    }

    public CitaRecepcion consultarRecepcion(String codCit) {
        RecepcionService.validarIdentificador(codCit);
        return citas.consultarRecepcion(codCit).orElseThrow(() ->
                PersonalService.error(Response.Status.NOT_FOUND, "NO_ENCONTRADO", "No existe la cita."));
    }
}
