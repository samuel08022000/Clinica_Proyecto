package org.clinica.service;

import jakarta.enterprise.context.ApplicationScoped;
import jakarta.inject.Inject;
import jakarta.persistence.EntityManager;
import jakarta.transaction.Transactional;
import jakarta.ws.rs.core.Response;
import org.clinica.config.DbActorHelper;
import org.clinica.dto.agenda.AgendaDtos.*;
import org.clinica.entity.BloqueoAgenda;
import org.clinica.entity.Horario;
import org.clinica.entity.Medico;
import org.clinica.entity.Rol;
import org.clinica.entity.Usuario;
import org.clinica.repository.AgendaRepository;
import org.clinica.repository.EspecialidadRepository;

import java.math.BigDecimal;
import java.time.LocalDate;
import java.time.LocalTime;
import java.time.format.DateTimeParseException;
import java.util.List;

import static org.clinica.service.PersonalService.error;

@ApplicationScoped
public class AgendaHorarioService {
    @Inject AgendaRepository agenda;
    @Inject EspecialidadRepository especialidades;
    @Inject EntityManager em;
    @Inject DbActorHelper actorHelper;

    public List<HorarioRespuesta> horarios(String codMed) {
        exigirMedico(codMed, false);
        return agenda.listarHorariosPorMedico(codMed).stream().map(AgendaHorarioService::respuesta).toList();
    }

    @Transactional
    public HorarioRespuesta crearHorario(String codMed, HorarioSolicitud dto, String actor) {
        agenda.bloquearMedico(codMed);
        exigirMedico(codMed, true);
        Horario horario = new Horario();
        horario.codMed = codMed;
        aplicarHorario(horario, dto);
        actorHelper.fijarActor(actor);
        return respuesta(agenda.findById(agenda.insertarHorario(horario)));
    }

    @Transactional
    public HorarioRespuesta actualizarHorario(String codHor, HorarioSolicitud dto, String actor) {
        Horario horario = exigirHorario(codHor);
        agenda.bloquearMedico(horario.codMed);
        em.refresh(horario);
        exigirMedico(horario.codMed, true);
        actorHelper.fijarActor(actor);
        aplicarHorario(horario, dto);
        em.flush();
        return respuesta(horario);
    }

    @Transactional
    public HorarioRespuesta estadoHorario(String codHor, String estado, String actor) {
        PersonalService.validarEstado(estado);
        Horario horario = exigirHorario(codHor);
        agenda.bloquearMedico(horario.codMed);
        em.refresh(horario);
        exigirMedico(horario.codMed, true);
        actorHelper.fijarActor(actor);
        horario.estado = estado;
        em.flush();
        return respuesta(horario);
    }

    @Transactional
    public DuracionRespuesta actualizarDuracion(String codMed, DuracionSolicitud dto, String actor) {
        short duracion = entero(dto.duracionConsultaMin, "La duración es obligatoria y debe ser un entero.");
        PersonalService.validarDuracion(duracion);
        agenda.bloquearMedico(codMed);
        exigirMedico(codMed, true);
        actorHelper.fijarActor(actor);
        em.createNativeQuery("UPDATE public.medicos SET duracion_consulta_min = :dur WHERE cod_med = :med")
                .setParameter("dur", duracion).setParameter("med", codMed).executeUpdate();
        // Conserva el contrato del CRUD de médicos: alinea sus vínculos activos.
        em.createNativeQuery("""
                UPDATE public.medico_especialidades SET duracion_consulta_min = :dur
                WHERE cod_med = :med AND estado = 'Activo'
                """).setParameter("dur", duracion).setParameter("med", codMed).executeUpdate();
        return new DuracionRespuesta(codMed, duracion);
    }

    public List<BloqueoRespuesta> bloqueos(String codMed, String desde, String hasta) {
        exigirMedico(codMed, false);
        LocalDate inicio = fecha(desde, false);
        LocalDate fin = fecha(hasta, false);
        if (inicio != null && fin != null && inicio.isAfter(fin)) throw validacion("El rango de fechas es incorrecto.");
        return agenda.buscarBloqueos(codMed, inicio, fin).stream().map(this::respuesta).toList();
    }

    public BloqueoRespuesta bloqueo(String codBlo) {
        return respuesta(exigirBloqueo(codBlo));
    }

    @Transactional
    public BloqueoRespuesta crearBloqueo(String codMed, BloqueoSolicitud dto, String actor) {
        agenda.bloquearMedico(codMed);
        exigirMedico(codMed, true);
        BloqueoAgenda bloqueo = new BloqueoAgenda();
        bloqueo.codMed = codMed;
        bloqueo.codUsuAdmin = actor;
        bloqueo.fechaBloqueo = fecha(dto.fechaBloqueo, true);
        bloqueo.horaInicio = hora(dto.horaInicio, false);
        bloqueo.horaFin = hora(dto.horaFin, false);
        validarPar(bloqueo.horaInicio, bloqueo.horaFin);
        bloqueo.motivo = dto.motivo == null ? "" : dto.motivo.strip();
        if (bloqueo.motivo.isBlank() || bloqueo.motivo.codePointCount(0,bloqueo.motivo.length()) > 500) {
            throw validacion("El motivo debe tener entre 1 y 500 caracteres.");
        }
        actorHelper.fijarActor(actor);
        return bloqueo(agenda.insertarBloqueo(bloqueo));
    }

    public CitasAfectadas citasAfectadas(String codBlo) {
        BloqueoAgenda bloqueo = exigirBloqueo(codBlo);
        return new CitasAfectadas(codBlo, bloqueo.trazabilidadCompleta, agenda.citasAfectadas(codBlo));
    }

    public List<Cupo> disponibilidad(String codEsp, String codMed, String fecha) {
        if (codMed != null && !codMed.isBlank()) exigirMedico(codMed, false);
        if (codEsp != null && !codEsp.isBlank() && especialidades.findById(codEsp) == null) {
            throw error(Response.Status.NOT_FOUND, "NO_ENCONTRADO", "No existe la especialidad.");
        }
        return agenda.buscarCuposDisponibles(codEsp,codMed,fecha(fecha,false)).stream()
                .map(c -> new Cupo(c.codSlot,c.codMed,c.nombreMedico,c.codEsp,c.nombreEspecialidad,c.fecha,c.horaInicio,c.horaFin))
                .toList();
    }

    private Medico exigirMedico(String codMed, boolean activo) {
        Medico medico = em.find(Medico.class,codMed);
        if (medico == null) throw error(Response.Status.NOT_FOUND,"NO_ENCONTRADO","No existe el médico.");
        if (activo) {
            Usuario usuario = em.find(Usuario.class,medico.codUsu);
            Rol rol = usuario == null ? null : em.find(Rol.class,usuario.codRol);
            if (!"Activo".equals(medico.estado) || usuario == null || !"Activo".equals(usuario.estado)
                    || !"ROL003".equals(usuario.codRol) || rol == null || !"Activo".equals(rol.estado)) {
                throw error(Response.Status.CONFLICT,"MEDICO_NO_HABILITADO","El médico no está habilitado para configurar su agenda.");
            }
        }
        return medico;
    }

    private Horario exigirHorario(String codHor) {
        return agenda.findByIdOptional(codHor).orElseThrow(() ->
                error(Response.Status.NOT_FOUND,"NO_ENCONTRADO","No existe el horario."));
    }

    private BloqueoAgenda exigirBloqueo(String codBlo) {
        BloqueoAgenda bloqueo = em.find(BloqueoAgenda.class,codBlo);
        if (bloqueo == null) throw error(Response.Status.NOT_FOUND,"NO_ENCONTRADO","No existe el bloqueo.");
        return bloqueo;
    }

    private static void aplicarHorario(Horario horario, HorarioSolicitud dto) {
        short dia = entero(dto.diaSemana,"El día debe ser un entero de 1 a 7 (lunes a domingo).");
        if (dia < 1 || dia > 7) throw validacion("El día debe estar entre 1 y 7 (lunes a domingo).");
        horario.diaSemana = dia;
        horario.horaInicio = hora(dto.horaInicio,true);
        horario.horaFin = hora(dto.horaFin,true);
        if (!horario.horaInicio.isBefore(horario.horaFin)) throw validacion("El inicio debe ser anterior al fin.");
        horario.pausaInicio = hora(dto.pausaInicio,false);
        horario.pausaFin = hora(dto.pausaFin,false);
        validarPar(horario.pausaInicio,horario.pausaFin);
        if (horario.pausaInicio != null && (horario.pausaInicio.isBefore(horario.horaInicio)
                || horario.pausaFin.isAfter(horario.horaFin))) throw validacion("El descanso debe pertenecer a la jornada.");
    }

    private static void validarPar(LocalTime inicio, LocalTime fin) {
        if ((inicio == null) != (fin == null) || (inicio != null && !inicio.isBefore(fin))) {
            throw validacion("Las horas opcionales deben informarse juntas y el inicio debe preceder al fin.");
        }
    }

    private static short entero(BigDecimal valor, String mensaje) {
        if (valor == null) throw validacion(mensaje);
        try { return valor.shortValueExact(); }
        catch (ArithmeticException exception) { throw validacion(mensaje); }
    }

    private static LocalTime hora(String valor, boolean requerido) {
        if (valor == null && !requerido) return null;
        try {
            LocalTime hora = LocalTime.parse(valor == null ? "" : valor);
            if (hora.getNano() % 1000 != 0) throw validacion("La precisión máxima de las horas es de microsegundos.");
            return hora;
        } catch (DateTimeParseException exception) { throw validacion("La hora debe utilizar HH:mm o HH:mm:ss."); }
    }

    private static LocalDate fecha(String valor, boolean requerido) {
        if (valor == null && !requerido) return null;
        try { return LocalDate.parse(valor == null ? "" : valor); }
        catch (DateTimeParseException exception) { throw validacion("La fecha debe utilizar YYYY-MM-DD."); }
    }

    private static jakarta.ws.rs.WebApplicationException validacion(String mensaje) {
        return error(Response.Status.BAD_REQUEST,"VALIDACION",mensaje);
    }

    private static HorarioRespuesta respuesta(Horario h) {
        return new HorarioRespuesta(h.codHor,h.codMed,h.diaSemana,h.horaInicio,h.horaFin,h.pausaInicio,h.pausaFin,h.estado);
    }

    private BloqueoRespuesta respuesta(BloqueoAgenda b) {
        return new BloqueoRespuesta(b.codBlo,b.codMed,b.codUsuAdmin,b.fechaBloqueo,b.horaInicio,b.horaFin,b.motivo,
                b.creadoEn,agenda.contarCitasAfectadas(b.codBlo),b.trazabilidadCompleta);
    }
}
