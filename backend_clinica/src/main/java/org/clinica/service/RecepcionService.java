package org.clinica.service;

import jakarta.enterprise.context.ApplicationScoped;
import jakarta.inject.Inject;
import jakarta.persistence.EntityManager;
import jakarta.ws.rs.WebApplicationException;
import jakarta.ws.rs.core.Response;
import org.clinica.dto.agenda.AgendaDtos.Cupo;
import org.clinica.dto.personal.EspecialidadOpcionDTO;
import org.clinica.dto.recepcion.RecepcionDtos.MedicoOpcion;
import org.clinica.dto.recepcion.RecepcionDtos.PacienteDetalle;
import org.clinica.dto.recepcion.RecepcionDtos.PacientesEncontrados;
import org.clinica.repository.AgendaRepository;
import org.clinica.repository.EspecialidadRepository;
import org.clinica.repository.PacienteRepository;
import org.clinica.repository.PersonalRepository;
import org.clinica.entity.Medico;

import java.time.LocalDate;
import java.time.format.DateTimeParseException;
import java.util.List;
import java.util.Locale;

import static org.clinica.service.PersonalService.error;

@ApplicationScoped
public class RecepcionService {
    private static final int LIMITE_PACIENTES = 20;

    @Inject PacienteRepository pacientes;
    @Inject PersonalRepository personal;
    @Inject EspecialidadRepository especialidades;
    @Inject AgendaRepository agenda;
    @Inject EntityManager em;

    public PacientesEncontrados buscarPacientes(String termino) {
        String texto = termino == null ? "" : termino.strip();
        if (texto.length() > 100 || texto.codePoints().filter(Character::isLetterOrDigit).count() < 3) {
            throw validacion("La búsqueda requiere entre 3 y 100 caracteres y al menos 3 letras o números.");
        }
        String patron = "%" + texto.toLowerCase(Locale.ROOT).replace("!", "!!")
                .replace("%", "!%").replace("_", "!_") + "%";
        String ciExacto = pacientes.findByCi(texto).map(p -> p.codPac).orElse("");
        var resultados = pacientes.buscarAdministrativos(patron, ciExacto, LIMITE_PACIENTES + 1);
        if (resultados.size() > LIMITE_PACIENTES) {
            throw validacion("La búsqueda supera 20 pacientes. Precise el documento, nombre o correo.");
        }
        return new PacientesEncontrados(resultados);
    }

    public PacienteDetalle paciente(String codPac) {
        validarIdentificador(codPac);
        return pacientes.consultarAdministrativo(codPac).orElseThrow(() ->
                error(Response.Status.NOT_FOUND, "NO_ENCONTRADO", "No existe el paciente."));
    }

    public void exigirPacienteHabilitado(String codPac) {
        PacienteDetalle paciente = paciente(codPac);
        if (!paciente.habilitadoParaReservar()) {
            throw error(Response.Status.FORBIDDEN, paciente.motivoInhabilitacion(),
                    "El paciente no está habilitado para nuevas reservas.");
        }
    }

    public List<EspecialidadOpcionDTO> especialidades() {
        return especialidades.listarActivas().stream()
                .map(e -> new EspecialidadOpcionDTO(e.codEsp, e.nombreEspecialidad, e.duracionConsultaMin)).toList();
    }

    public List<MedicoOpcion> medicos(String codEsp) {
        exigirEspecialidad(codEsp);
        return personal.listarMedicosReservables(codEsp);
    }

    public List<Cupo> disponibilidad(String codEsp, String codMed, String fecha, String codPac) {
        exigirEspecialidad(codEsp);
        if (codMed != null) {
            validarIdentificador(codMed);
            if (em.find(Medico.class, codMed) == null) {
                throw error(Response.Status.NOT_FOUND, "NO_ENCONTRADO", "No existe el médico.");
            }
        }
        LocalDate dia;
        try {
            dia = LocalDate.parse(fecha == null ? "" : fecha);
        } catch (DateTimeParseException exception) {
            throw validacion("Debe indicar la fecha con el formato YYYY-MM-DD.");
        }
        LocalDate hoy = (LocalDate) em.createNativeQuery("SELECT public.fecha_clinica()", LocalDate.class).getSingleResult();
        if (dia.isBefore(hoy) || dia.isAfter(hoy.plusDays(14))) {
            throw validacion("La fecha debe estar entre hoy y D+14 según la zona clínica.");
        }
        if (codPac != null) {
            exigirPacienteHabilitado(codPac);
        }
        return agenda.buscarCuposDisponibles(codEsp, codMed, dia, codPac).stream()
                .map(c -> new Cupo(c.codSlot, c.codMed, c.nombreMedico, c.codEsp, c.nombreEspecialidad,
                        c.fecha, c.horaInicio, c.horaFin)).toList();
    }

    private void exigirEspecialidad(String codEsp) {
        validarIdentificador(codEsp);
        if (especialidades.findById(codEsp) == null) {
            throw error(Response.Status.NOT_FOUND, "NO_ENCONTRADO", "No existe la especialidad.");
        }
    }

    static void validarIdentificador(String codigo) {
        if (codigo == null || codigo.isBlank() || codigo.length() > 64 || !codigo.matches("[A-Za-z0-9]+")) {
            throw validacion("Debe indicar un identificador válido.");
        }
    }

    static WebApplicationException validacion(String mensaje) {
        return error(Response.Status.BAD_REQUEST, "VALIDACION", mensaje);
    }
}
