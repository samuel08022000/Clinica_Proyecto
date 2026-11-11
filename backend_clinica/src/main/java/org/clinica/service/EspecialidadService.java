package org.clinica.service;

import jakarta.enterprise.context.ApplicationScoped;
import jakarta.inject.Inject;
import jakarta.persistence.LockModeType;
import jakarta.transaction.Transactional;
import jakarta.ws.rs.core.Response;
import org.clinica.config.DbActorHelper;
import org.clinica.dto.personal.EspecialidadDetalleDTO;
import org.clinica.dto.personal.EspecialidadDetalleDTO.MedicoVinculado;
import org.clinica.dto.personal.EspecialidadSolicitudes.Actualizar;
import org.clinica.dto.personal.EspecialidadSolicitudes.Crear;
import org.clinica.entity.Especialidad;
import org.clinica.repository.EspecialidadRepository;
import org.clinica.repository.PersonalRepository;

import java.math.BigDecimal;
import java.util.List;

import static org.clinica.service.PersonalService.error;
import static org.clinica.service.PersonalService.vacioANull;
import static org.clinica.service.PersonalService.validarDuracion;
import static org.clinica.service.PersonalService.validarEstado;
import static org.clinica.service.PersonalService.validarFiltroEstado;

@ApplicationScoped
public class EspecialidadService {

    @Inject EspecialidadRepository especialidades;
    @Inject PersonalRepository personal;
    @Inject DbActorHelper actorHelper;

    public List<EspecialidadDetalleDTO> listar(String estado) {
        String filtro = "Todos".equals(vacioANull(estado)) ? null : validarFiltroEstado(estado);
        return especialidades.listarPorEstado(filtro).stream().map(EspecialidadService::detalle).toList();
    }

    public EspecialidadDetalleDTO obtener(String codEsp) {
        return detalle(exigirEspecialidad(codEsp, LockModeType.NONE));
    }

    public List<MedicoVinculado> listarMedicos(String codEsp) {
        exigirEspecialidad(codEsp, LockModeType.NONE);
        return personal.listarVinculosPorEspecialidad(codEsp);
    }

    @Transactional
    public EspecialidadDetalleDTO registrar(Crear dto, String actor) {
        String nombre = validarNombre(dto.nombreEspecialidad);
        short duracion = validarDuracionInicial(dto.duracionConsultaMin);
        String estado = dto.estado == null ? "Activo" : dto.estado;
        validarEstado(estado);
        especialidades.bloquearNombreNormalizado(nombre);
        verificarDuplicado(nombre, null);
        actorHelper.fijarActor(actor);

        String codEsp = especialidades.insertar(nombre, vacioANull(dto.descripcion), duracion, estado);
        return obtener(codEsp);
    }

    @Transactional
    public EspecialidadDetalleDTO actualizar(String codEsp, Actualizar dto, String actor) {
        String nombre = validarNombre(dto.nombreEspecialidad);
        especialidades.bloquearNombreNormalizado(nombre);
        Especialidad especialidad = exigirEspecialidad(codEsp, LockModeType.PESSIMISTIC_WRITE);
        verificarDuplicado(nombre, codEsp);
        actorHelper.fijarActor(actor);

        especialidad.nombreEspecialidad = nombre;
        especialidad.descripcion = vacioANull(dto.descripcion);
        return detalle(especialidad);
    }

    @Transactional
    public EspecialidadDetalleDTO cambiarEstado(String codEsp, String estado, String actor) {
        validarEstado(estado);
        Especialidad especialidad = exigirEspecialidad(codEsp, LockModeType.PESSIMISTIC_WRITE);
        actorHelper.fijarActor(actor);
        especialidad.estado = estado;
        return detalle(especialidad);
    }

    private Especialidad exigirEspecialidad(String codEsp, LockModeType bloqueo) {
        return especialidades.findByIdOptional(codEsp, bloqueo).orElseThrow(() ->
                error(Response.Status.NOT_FOUND, "NO_ENCONTRADO", "No existe la especialidad " + codEsp + "."));
    }

    private void verificarDuplicado(String nombre, String excluirCodEsp) {
        if (especialidades.existeNombre(nombre, excluirCodEsp)) {
            throw error(Response.Status.CONFLICT, "DUPLICADO", "Ya existe una especialidad con ese nombre.");
        }
    }

    private static String validarNombre(String valor) {
        String nombre = valor == null ? "" : valor.strip();
        if (nombre.codePoints().allMatch(c -> Character.isWhitespace(c) || Character.isSpaceChar(c))
                || nombre.codePointCount(0, nombre.length()) > 120) {
            throw error(Response.Status.BAD_REQUEST, "VALIDACION", "El nombre de la especialidad debe tener entre 1 y 120 caracteres.");
        }
        return nombre;
    }

    private static short validarDuracionInicial(BigDecimal valor) {
        if (valor == null) {
            throw error(Response.Status.BAD_REQUEST, "VALIDACION", "La duración de consulta es obligatoria.");
        }
        short duracion;
        try {
            duracion = valor.shortValueExact();
        } catch (ArithmeticException exception) {
            throw error(Response.Status.BAD_REQUEST, "VALIDACION", "La duración de consulta debe ser un número entero válido.");
        }
        validarDuracion(duracion);
        return duracion;
    }

    private static EspecialidadDetalleDTO detalle(Especialidad especialidad) {
        return new EspecialidadDetalleDTO(especialidad.codEsp, especialidad.nombreEspecialidad,
                especialidad.descripcion, especialidad.duracionConsultaMin, especialidad.estado);
    }
}
