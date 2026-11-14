package org.clinica.service;

import jakarta.enterprise.context.ApplicationScoped;
import jakarta.inject.Inject;
import jakarta.transaction.Transactional;
import jakarta.ws.rs.core.Response;
import org.clinica.config.BearerToken;
import org.clinica.config.DbActorHelper;
import org.clinica.dto.medico.MedicoDtos.AtenderSolicitud;
import org.clinica.dto.medico.MedicoDtos.CitaMedica;
import org.clinica.dto.medico.MedicoDtos.HistorialMedico;
import org.clinica.entity.Medico;
import org.clinica.repository.CitaFunctionRepository;
import org.clinica.repository.CitaRepository;
import org.clinica.repository.PersonalRepository;

import java.time.LocalDate;
import java.time.format.DateTimeParseException;
import java.util.List;
import java.util.Set;

import static org.clinica.service.PersonalService.error;
import static org.clinica.service.RecepcionService.validacion;
import static org.clinica.service.RecepcionService.validarIdentificador;

@ApplicationScoped
public class MedicoAtencionService {
    @Inject AuthService auth;
    @Inject PersonalRepository personal;
    @Inject CitaRepository citas;
    @Inject CitaFunctionRepository funciones;
    @Inject DbActorHelper actorHelper;

    public List<CitaMedica> agenda(String authorization, String fechaTexto) {
        Medico medico = medicoAutenticado(authorization);
        LocalDate dia = fecha(fechaTexto);
        return citas.agendaMedica(medico.codMed, dia == null ? citas.fechaClinica() : dia);
    }

    public CitaMedica detalle(String authorization, String codCit) {
        Medico medico = medicoAutenticado(authorization);
        validarIdentificador(codCit);
        return detallePropio(medico.codMed, codCit);
    }

    @Transactional
    public CitaMedica atender(String authorization, String codCit, AtenderSolicitud solicitud) {
        Medico medico = medicoAutenticado(authorization);
        validarIdentificador(codCit);
        if (solicitud != null && solicitud.tieneCamposNoPermitidos()) {
            throw validacion("El cierre de atención no acepta campos en el cuerpo.");
        }
        // El bloqueo se mantiene hasta el commit e incluye la validación del check-in.
        // La función existente vuelve a validar propietario/estado bajo el mismo bloqueo.
        var cita = citas.bloquearCitaMedica(codCit, medico.codMed).orElseThrow(MedicoAtencionService::noEncontrada);
        if (!"En Espera".equals(cita.estado) || cita.horaLlegadaCheckin == null || cita.checkinPorUsu == null
                || cita.fechaAtencion != null || cita.atendidaPorUsu != null) {
            throw error(Response.Status.CONFLICT, "ATENCION_NO_PERMITIDA",
                    "Solo puede atender una cita en espera con check-in registrado y sin atención previa.");
        }
        actorHelper.fijarActor(medico.codUsu);
        funciones.marcarCitaAtendida(codCit, medico.codUsu);
        // Proyección nueva: no devuelve la entidad cargada antes de ejecutar la función SQL.
        return detallePropio(medico.codMed, codCit);
    }

    public HistorialMedico historial(String authorization, String desde, String hasta, String pageTexto, String sizeTexto) {
        Medico medico = medicoAutenticado(authorization);
        int page = entero(pageTexto), size = entero(sizeTexto);
        if (page < 0 || page > 10000 || size < 1 || size > 50) {
            throw validacion("page debe estar entre 0 y 10000; size entre 1 y 50.");
        }
        LocalDate inicio = fecha(desde), fin = fecha(hasta);
        if (inicio != null && fin != null && inicio.isAfter(fin)) throw validacion("Rango de fechas inválido.");
        return citas.historialMedico(medico.codMed, inicio, fin, page, size);
    }

    public Medico medicoAutenticado(String authorization) {
        String usuario = auth.requireAnyRole(BearerToken.bearerToken(authorization), Set.of("ROL003")).user().id();
        // AuthService revalida además usuario, rol y perfil activos en cada solicitud.
        return personal.findMedicoByCodUsu(usuario).filter(m -> "Activo".equals(m.estado)).orElseThrow(() ->
                error(Response.Status.FORBIDDEN, "MEDICO_NO_HABILITADO", "El perfil médico no está habilitado."));
    }

    private CitaMedica detallePropio(String codMed, String codCit) {
        return citas.consultarCitaMedica(codCit, codMed).orElseThrow(MedicoAtencionService::noEncontrada);
    }

    private static jakarta.ws.rs.WebApplicationException noEncontrada() {
        // Misma respuesta para un identificador ajeno o inexistente; no revela citas de otros médicos.
        return error(Response.Status.NOT_FOUND, "NO_ENCONTRADO", "No existe la cita para el médico autenticado.");
    }

    private static LocalDate fecha(String texto) {
        if (texto == null) return null;
        try {
            if (!texto.matches("[0-9]{4}-[0-9]{2}-[0-9]{2}")) throw new DateTimeParseException("Formato", texto, 0);
            LocalDate valor = LocalDate.parse(texto);
            if (valor.getYear() == 0) throw new DateTimeParseException("Año", texto, 0);
            return valor;
        } catch (DateTimeParseException exception) {
            throw validacion("Use fechas con formato YYYY-MM-DD.");
        }
    }

    private static int entero(String texto) {
        try { return Integer.parseInt(texto); }
        catch (NumberFormatException exception) { throw validacion("page y size deben ser números enteros válidos."); }
    }
}
