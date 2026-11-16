package org.clinica.service;

import jakarta.enterprise.context.ApplicationScoped;
import jakarta.inject.Inject;
import jakarta.transaction.Transactional;
import jakarta.ws.rs.core.Response;
import org.clinica.config.DbActorHelper;
import org.clinica.dto.recepcion.RecepcionDtos.PacienteDetalle;
import org.clinica.dto.sancion.SancionDtos.CrearSancion;
import org.clinica.dto.sancion.SancionDtos.Incidencia;
import org.clinica.dto.sancion.SancionDtos.PaginaIncidencias;
import org.clinica.dto.sancion.SancionDtos.PaginaPacientes;
import org.clinica.repository.CitaFunctionRepository;
import org.clinica.repository.IncidenciaRepository;
import org.clinica.repository.PacienteRepository;

import java.util.Locale;
import java.util.Set;

import static org.clinica.service.RecepcionService.validacion;
import static org.clinica.service.RecepcionService.validarIdentificador;
import static org.clinica.service.PersonalService.error;

@ApplicationScoped
public class SancionService {
    private static final Set<String> TIPOS = Set.of("CANCELACION", "INASISTENCIA_NO_SHOW", "SANCION_BLOQUEO", "OTRA");
    @Inject PacienteRepository pacientes;
    @Inject IncidenciaRepository incidencias;
    @Inject RecepcionService recepcion;
    @Inject CitaFunctionRepository funciones;
    @Inject DbActorHelper actores;

    public PacienteDetalle paciente(String codPac) { return recepcion.paciente(codPac); }

    public PaginaPacientes pacientes(String q, String estado, String page, String size) {
        int pagina = pagina(page), tamano = tamano(size);
        if (estado != null && !Set.of("Activo", "Inactivo", "Bloqueado_Temporal").contains(estado)) {
            throw validacion("Estado administrativo inválido.");
        }
        String patron = null;
        if (q != null) {
            String texto = q.strip();
            if (texto.length() > 100 || texto.codePoints().filter(Character::isLetterOrDigit).count() < 3) {
                throw validacion("La búsqueda requiere de 3 a 100 caracteres y al menos 3 letras o números.");
            }
            patron = "%" + texto.toLowerCase(Locale.ROOT).replace("!", "!!").replace("%", "!%").replace("_", "!_") + "%";
        }
        return pacientes.listarAdministrativos(patron, estado, pagina, tamano);
    }

    public PaginaIncidencias incidencias(String codPac, String tipo, String page, String size) {
        int pagina = pagina(page), tamano = tamano(size);
        paciente(codPac);
        if (tipo != null && !TIPOS.contains(tipo)) throw validacion("Tipo de incidencia inválido.");
        return incidencias.listar(codPac, tipo, null, pagina, tamano);
    }

    public PaginaIncidencias sanciones(String codPac, String vigente, String page, String size) {
        int pagina = pagina(page), tamano = tamano(size);
        paciente(codPac);
        if (vigente != null && !Set.of("true", "false").contains(vigente)) throw validacion("vigente debe ser true o false.");
        return incidencias.listar(codPac, "SANCION_BLOQUEO", vigente == null ? null : Boolean.valueOf(vigente), pagina, tamano);
    }

    public Incidencia sancion(String codPac, String codInc) {
        paciente(codPac);
        validarIdentificador(codInc);
        return incidencias.sancion(codPac, codInc).orElseThrow(() ->
                error(Response.Status.NOT_FOUND, "NO_ENCONTRADO", "No existe esa sanción para el paciente."));
    }

    @Transactional
    public Incidencia registrar(String codPac, CrearSancion solicitud, String actor) {
        validarIdentificador(codPac);
        if (solicitud == null || solicitud.tieneCamposNoPermitidos() || solicitud.motivo == null
                || solicitud.dias() == null || solicitud.dias() < 1 || solicitud.dias() > 365) {
            throw validacion("Debe indicar únicamente motivo y dias, de 1 a 365.");
        }
        String motivo = solicitud.motivo.strip();
        if (motivo.isBlank() || motivo.codePointCount(0, motivo.length()) > 2000) {
            throw validacion("El motivo debe tener entre 1 y 2000 caracteres.");
        }
        paciente(codPac);
        actores.fijarActor(actor);
        String codInc = funciones.registrarSancion(codPac, actor, motivo, solicitud.dias());
        return sancion(codPac, codInc);
    }

    private static int entero(String valor) {
        try { return Integer.parseInt(valor); }
        catch (NumberFormatException exception) { throw validacion("page y size deben ser números enteros."); }
    }
    private static int pagina(String valor) {
        int page = entero(valor);
        if (page < 0 || page > 10000) throw validacion("page debe estar entre 0 y 10000.");
        return page;
    }
    private static int tamano(String valor) {
        int size = entero(valor);
        if (size < 1 || size > 50) throw validacion("size debe estar entre 1 y 50.");
        return size;
    }
}
