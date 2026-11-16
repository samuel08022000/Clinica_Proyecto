package org.clinica.service;

import jakarta.enterprise.context.ApplicationScoped;
import jakarta.inject.Inject;
import jakarta.persistence.LockModeType;
import jakarta.transaction.Transactional;
import jakarta.ws.rs.core.Response;
import org.clinica.config.DbActorHelper;
import org.clinica.dto.medico.SeguimientoDtos.*;
import org.clinica.entity.Cita;
import org.clinica.entity.Medico;
import org.clinica.repository.AnalisisRepository;
import org.clinica.repository.CitaRepository;
import java.util.List;
import java.util.Set;
import static org.clinica.service.RecepcionService.*;
import static org.clinica.service.PersonalService.error;
import static org.clinica.service.MedicoDerivacionService.*;

@ApplicationScoped
public class MedicoAnalisisService {
    @Inject MedicoAtencionService atencion;
    @Inject CitaRepository citas;
    @Inject AnalisisRepository analisis;
    @Inject DbActorHelper actorHelper;

    @Transactional
    public AnalisisResumen solicitar(String authorization, String codCit, NuevoAnalisis solicitud) {
        Medico medico = atencion.medicoAutenticado(authorization);
        validarIdentificador(codCit);
        if (solicitud == null || solicitud.tieneCamposNoPermitidos()) throw validacion("Indique únicamente tipoAnalisis.");
        String tipo = texto(solicitud.tipoAnalisis, 150, "tipoAnalisis");
        Cita cita = citas.bloquearCitaMedica(codCit, medico.codMed).orElseThrow(MedicoDerivacionService::noEncontrado);
        exigirEmision(cita);
        var previo = analisis.mismoTipo(codCit, tipo);
        if (previo.isPresent()) return resumen(codCit, medico.codMed, previo.get().codAna);
        actorHelper.fijarActor(medico.codUsu);
        String codAna = analisis.insertar(codCit, medico.codUsu, tipo);
        return resumen(codCit, medico.codMed, codAna);
    }

    public List<AnalisisResumen> listar(String authorization, String codCit) {
        Medico medico = atencion.medicoAutenticado(authorization);
        validarIdentificador(codCit);
        citas.consultarCitaMedica(codCit, medico.codMed).orElseThrow(MedicoDerivacionService::noEncontrado);
        return analisis.listar(codCit, medico.codMed);
    }

    @Transactional
    public AnalisisResumen registrarResultado(String authorization, String codAna, ResultadoAnalisis solicitud) {
        Medico medico = atencion.medicoAutenticado(authorization);
        validarIdentificador(codAna);
        if (solicitud == null || solicitud.tieneCamposNoPermitidos() || solicitud.estado == null
                || !Set.of("En Proceso", "Completado").contains(solicitud.estado)) throw validacion("Indique estado En Proceso o Completado y resultado únicamente al completar.");
        String resultado = "Completado".equals(solicitud.estado) ? texto(solicitud.resultado, 20000, "resultado") : null;
        if (resultado == null && solicitud.resultado != null) throw validacion("El resultado se registra al completar el análisis.");
        String codCit = analisis.citaPropia(codAna, medico.codMed).orElseThrow(MedicoDerivacionService::noEncontrado);
        // Mantiene el mismo orden de bloqueo cita -> análisis que solicitudes/cancelaciones.
        Cita cita = citas.bloquearCitaMedica(codCit, medico.codMed).orElseThrow(MedicoDerivacionService::noEncontrado);
        exigirEmision(cita);
        var estudio = analisis.find("codAna", codAna).withLock(LockModeType.PESSIMISTIC_WRITE).firstResultOptional()
                .orElseThrow(MedicoDerivacionService::noEncontrado);
        if (!medico.codUsu.equals(estudio.solicitadoPorUsu)) throw noEncontrado();
        if (!Set.of("Pendiente", "En Proceso").contains(estudio.estado) || estudio.resultado != null
                || estudio.archivoPdfUrl != null || estudio.fechaEntregaReal != null
                || ("En Proceso".equals(estudio.estado) && "En Proceso".equals(solicitud.estado))) {
            throw error(Response.Status.CONFLICT, "ANALISIS_NO_MODIFICABLE", "El análisis no admite esa transición o ya tiene resultados registrados.");
        }
        actorHelper.fijarActor(medico.codUsu);
        analisis.resultado(codAna, solicitud.estado, resultado);
        return resumen(codCit, medico.codMed, codAna);
    }

    private AnalisisResumen resumen(String codCit, String codMed, String codAna) {
        return analisis.listar(codCit, codMed).stream().filter(a -> a.codAna().equals(codAna)).findFirst().orElseThrow(MedicoDerivacionService::noEncontrado);
    }
    private static void exigirEmision(Cita cita) {
        if (!Set.of("En Espera", "Atendida").contains(cita.estado)) throw error(Response.Status.CONFLICT,
                "ANALISIS_NO_PERMITIDO", "La cita debe estar en espera o atendida.");
    }
}
