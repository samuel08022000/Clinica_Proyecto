package org.clinica.service;

import jakarta.enterprise.context.ApplicationScoped;
import jakarta.inject.Inject;
import jakarta.transaction.Transactional;
import jakarta.ws.rs.core.Response;
import org.clinica.config.DbActorHelper;
import org.clinica.dto.recepcion.RecepcionDtos.Acreditacion;
import org.clinica.dto.recepcion.RecepcionDtos.Asistencia;
import org.clinica.dto.recepcion.RecepcionDtos.CheckinSolicitud;
import org.clinica.repository.CitaFunctionRepository;
import org.clinica.repository.CitaRepository;
import org.clinica.repository.PersonalRepository;

import static org.clinica.service.PersonalService.error;

@ApplicationScoped
public class AtencionCheckinService {
    @Inject CitaRepository citas;
    @Inject CitaFunctionRepository funciones;
    @Inject PersonalRepository personal;
    @Inject DbActorHelper actorHelper;

    public Acreditacion acreditacion(String codCit) {
        RecepcionService.validarIdentificador(codCit);
        return citas.consultarAcreditacion(codCit).orElseThrow(() ->
                error(Response.Status.NOT_FOUND, "NO_ENCONTRADO", "No existe la cita."));
    }

    public Asistencia asistencia(String codCit) {
        RecepcionService.validarIdentificador(codCit);
        return citas.consultarAsistencia(codCit).orElseThrow(() ->
                error(Response.Status.NOT_FOUND, "NO_ENCONTRADO", "No existe la cita."));
    }

    @Transactional
    public Asistencia registrar(CheckinSolicitud solicitud, String actor) {
        if (solicitud == null || solicitud.tieneCamposNoPermitidos() || solicitud.tokenQr == null
                || solicitud.tokenQr.isBlank() || solicitud.tokenQr.length() > 4096) {
            throw error(Response.Status.BAD_REQUEST, "QR_INVALIDO", "Debe proporcionar únicamente un token QR válido.");
        }
        var secretaria = personal.findSecretariaByCodUsu(actor).orElseThrow(() ->
                error(Response.Status.FORBIDDEN, "SECRETARIA_NO_HABILITADA", "El perfil de secretaría no está habilitado."));
        actorHelper.fijarActor(actor);
        String codCit = funciones.registrarCheckin(solicitud.tokenQr, secretaria.codSec);
        return asistencia(codCit);
    }
}
