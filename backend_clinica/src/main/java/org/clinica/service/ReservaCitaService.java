package org.clinica.service;

import jakarta.enterprise.context.ApplicationScoped;
import jakarta.inject.Inject;
import org.clinica.entity.VistaAgendaDisponibilidad;
import org.clinica.repository.AgendaRepository;

import java.time.LocalDate;
import java.util.List;

@ApplicationScoped
public class ReservaCitaService {

    @Inject
    AgendaRepository agendaRepository;

    public List<VistaAgendaDisponibilidad> buscarCuposDisponibles(String codEsp, String codMed, LocalDate fecha) {
        return agendaRepository.buscarCuposDisponibles(codEsp, codMed, fecha);
    }
}