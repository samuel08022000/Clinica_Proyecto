package org.clinica.service;

import jakarta.enterprise.context.ApplicationScoped;
import jakarta.inject.Inject;
import jakarta.transaction.Transactional;
import jakarta.ws.rs.core.Response;
import org.clinica.config.DbActorHelper;
import org.clinica.dto.medico.SeguimientoDtos.*;
import org.clinica.entity.Cita;
import org.clinica.entity.Medico;
import org.clinica.repository.*;
import java.util.Set;
import static org.clinica.service.RecepcionService.*;
import static org.clinica.service.PersonalService.error;

@ApplicationScoped
public class MedicoDerivacionService {
    @Inject MedicoAtencionService atencion;
    @Inject CitaRepository citas;
    @Inject CitaFunctionRepository funciones;
    @Inject DerivacionRepository derivaciones;
    @Inject RecepcionService recepcion;
    @Inject EspecialidadRepository especialidades;
    @Inject DbActorHelper actorHelper;

    @Transactional
    public DerivacionResumen crear(String authorization, String codCit, NuevaDerivacion solicitud) {
        Medico medico = atencion.medicoAutenticado(authorization);
        validarIdentificador(codCit);
        if (solicitud == null || solicitud.tieneCamposNoPermitidos()) throw validacion("Indique especialidad, motivo y médico destino opcional.");
        validarIdentificador(solicitud.codEspDestino);
        String motivo = texto(solicitud.motivo, 2000, "motivo");
        Cita cita = citas.bloquearCitaMedica(codCit, medico.codMed).orElseThrow(MedicoDerivacionService::noEncontrado);
        if (!"Atendida".equals(cita.estado)) throw error(Response.Status.CONFLICT, "DERIVACION_NO_PERMITIDA", "La cita origen debe estar atendida.");
        var especialidad = especialidades.findByIdOptional(solicitud.codEspDestino).orElseThrow(MedicoDerivacionService::noEncontrado);
        if (!"Activo".equals(especialidad.estado)) throw error(Response.Status.CONFLICT, "DESTINO_NO_HABILITADO", "La especialidad destino está inactiva.");
        if (solicitud.codMedDestino != null) {
            validarIdentificador(solicitud.codMedDestino);
            if (recepcion.medicos(solicitud.codEspDestino).stream().noneMatch(m -> m.codMed().equals(solicitud.codMedDestino))) {
                throw error(Response.Status.CONFLICT, "DESTINO_NO_HABILITADO", "El médico destino no está habilitado para la especialidad.");
            }
        }
        var previa = derivaciones.identica(codCit, solicitud.codEspDestino, solicitud.codMedDestino, motivo);
        if (previa.isPresent()) return derivaciones.detalle(previa.get().codDer, medico.codMed).orElseThrow(MedicoDerivacionService::noEncontrado);
        actorHelper.fijarActor(medico.codUsu);
        String codigo = funciones.crearDerivacion(codCit, medico.codUsu, solicitud.codEspDestino, motivo, solicitud.codMedDestino);
        return derivaciones.detalle(codigo, medico.codMed).orElseThrow(MedicoDerivacionService::noEncontrado);
    }

    public DerivacionResumen detalle(String authorization, String codDer) {
        Medico medico = atencion.medicoAutenticado(authorization);
        validarIdentificador(codDer);
        return derivaciones.detalle(codDer, medico.codMed).orElseThrow(MedicoDerivacionService::noEncontrado);
    }

    public PaginaDerivaciones propias(String authorization, String estado, String page, String size) {
        return listar(atencion.medicoAutenticado(authorization).codMed, estado, page, size);
    }

    public PaginaDerivaciones pendientesSecretaria(String estado, String page, String size) {
        return listar(null, estado == null ? "Pendiente" : estado, page, size);
    }

    private PaginaDerivaciones listar(String codMed, String estado, String page, String size) {
        if (estado != null && !Set.of("Pendiente", "Agendada", "Completada", "Cancelada").contains(estado)) throw validacion("Estado de derivación inválido.");
        int pagina = entero(page), limite = entero(size);
        if (pagina < 0 || pagina > 10000 || limite < 1 || limite > 50) throw validacion("page debe estar entre 0 y 10000; size entre 1 y 50.");
        return derivaciones.listar(codMed, estado, pagina, limite);
    }

    static String texto(String valor, int maximo, String campo) {
        String texto = valor == null ? "" : valor.strip();
        if (texto.isBlank() || texto.indexOf('\0') >= 0 || texto.codePointCount(0, texto.length()) > maximo) throw validacion("El campo " + campo + " requiere entre 1 y " + maximo + " caracteres.");
        return texto;
    }
    private static int entero(String valor) {
        try { return Integer.parseInt(valor); }
        catch (NumberFormatException e) { throw validacion("page y size deben ser números enteros válidos."); }
    }
    static jakarta.ws.rs.WebApplicationException noEncontrado() {
        return error(Response.Status.NOT_FOUND, "NO_ENCONTRADO", "No existe el recurso solicitado para este usuario.");
    }
}
