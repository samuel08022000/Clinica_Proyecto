package org.clinica.service;

import io.quarkus.elytron.security.common.BcryptUtil;
import jakarta.enterprise.context.ApplicationScoped;
import jakarta.inject.Inject;
import jakarta.persistence.EntityManager;
import jakarta.persistence.Query;
import jakarta.transaction.Transactional;
import jakarta.ws.rs.WebApplicationException;
import jakarta.ws.rs.core.MediaType;
import jakarta.ws.rs.core.Response;
import org.clinica.config.DbActorHelper;
import org.clinica.dto.personal.ActualizarMedicoDTO;
import org.clinica.dto.personal.CrearMedicoDTO;
import org.clinica.dto.personal.EspecialidadOpcionDTO;
import org.clinica.dto.personal.MedicoRespuestaDTO;
import org.clinica.dto.personal.MedicoRespuestaDTO.EspecialidadVinculoDTO;
import org.clinica.entity.Especialidad;
import org.clinica.repository.EspecialidadRepository;
import org.clinica.repository.AgendaRepository;
import org.clinica.repository.UsuarioRepository;

import java.util.ArrayList;
import java.util.LinkedHashMap;
import java.util.LinkedHashSet;
import java.util.List;
import java.util.Map;
import java.util.Set;
import java.util.regex.Pattern;

/**
 * Módulo 2A — Gestión de doctores (HU-05 y HU-07).
 * Los doctores nunca se eliminan: se inactivan (estado = 'Inactivo') para conservar la trazabilidad (RNF-06).
 * Las escrituras usan SQL nativo con RETURNING porque los códigos (USU/MED) los genera PostgreSQL.
 */
@ApplicationScoped
public class PersonalService {

    private static final String ROL_DOCTOR = "ROL003";
    private static final Set<Short> DURACIONES = Set.of((short) 20, (short) 30, (short) 45, (short) 60);
    private static final Pattern EMAIL = Pattern.compile("^[^@\\s]+@[^@\\s]+\\.[^@\\s]+$");

    private static final String SELECT_MEDICOS = """
            SELECT m.cod_med, m.cod_usu, u.nombre_completo, u.email, m.ci,
                   m.matricula_profesional, m.resumen_curricular, m.duracion_consulta_min, m.estado
            FROM public.medicos m
            JOIN public.usuarios u ON u.cod_usu = m.cod_usu
            """;

    @Inject DbActorHelper actorHelper;
    @Inject EntityManager em;
    @Inject UsuarioRepository usuarioRepo;
    @Inject EspecialidadRepository especialidadRepo;
    @Inject AuthService authService;
    @Inject AgendaRepository agendaRepo;

    // =====================================================================
    // Consultas
    // =====================================================================

    public List<MedicoRespuestaDTO> listar(String estado) {
        String filtro = validarFiltroEstado(estado);
        String sql = SELECT_MEDICOS + (filtro != null ? " WHERE m.estado = :estado" : "") + " ORDER BY m.cod_med";
        Query q = em.createNativeQuery(sql);
        if (filtro != null) {
            q.setParameter("estado", filtro);
        }
        @SuppressWarnings("unchecked")
        List<Object[]> filas = q.getResultList();
        return armar(filas);
    }

    public MedicoRespuestaDTO obtener(String codMed) {
        Query q = em.createNativeQuery(SELECT_MEDICOS + " WHERE m.cod_med = :cod");
        q.setParameter("cod", codMed);
        @SuppressWarnings("unchecked")
        List<Object[]> filas = q.getResultList();
        if (filas.isEmpty()) {
            throw error(Response.Status.NOT_FOUND, "NO_ENCONTRADO", "No existe el doctor " + codMed + ".");
        }
        return armar(filas).get(0);
    }

    public List<EspecialidadOpcionDTO> listarEspecialidadesActivas() {
        return especialidadRepo.listarActivas().stream()
                .map(e -> new EspecialidadOpcionDTO(e.codEsp, e.nombreEspecialidad, e.duracionConsultaMin))
                .toList();
    }

    // =====================================================================
    // Escrituras
    // =====================================================================

    @Transactional
    public MedicoRespuestaDTO registrarMedico(CrearMedicoDTO dto, String codUsuAdmin) {
        actorHelper.fijarActor(codUsuAdmin); // queda registrado en bitácora

        String nombre = limpiar(dto.nombreCompleto);
        String email = dto.email == null ? null : dto.email.trim().toLowerCase();
        String ci = vacioANull(dto.ci);
        String matricula = limpiar(dto.matriculaProfesional);
        String resumen = vacioANull(dto.resumenCurricular);

        validarDatos(nombre, email, ci, matricula, dto.duracionConsultaMin);
        if (dto.password == null || dto.password.length() < 8) {
            throw error(Response.Status.BAD_REQUEST, "VALIDACION", "La contraseña debe tener al menos 8 caracteres.");
        }
        Set<String> especialidades = validarEspecialidades(dto.codigosEspecialidades, Set.of());
        verificarUnicos(email, ci, matricula, null, null);

        // 1. Usuario con rol Doctor
        String codUsu = usuarioRepo.insertarPersonal(nombre, email, BcryptUtil.bcryptHash(dto.password), ROL_DOCTOR);

        // 2. Perfil médico
        Query qMed = em.createNativeQuery("""
                INSERT INTO public.medicos (cod_usu, ci, matricula_profesional, resumen_curricular, duracion_consulta_min, estado)
                VALUES (:usu, CAST(:ci AS varchar), :mat, CAST(:res AS text), CAST(:dur AS smallint), 'Activo')
                RETURNING cod_med
                """);
        qMed.setParameter("usu", codUsu);
        qMed.setParameter("ci", ci);
        qMed.setParameter("mat", matricula);
        qMed.setParameter("res", resumen);
        qMed.setParameter("dur", dto.duracionConsultaMin);
        String codMed = (String) qMed.getSingleResult();

        // 3. Vínculos con especialidades (RF-07)
        for (String codEsp : especialidades) {
            upsertVinculo(codMed, codEsp, dto.duracionConsultaMin);
        }
        return obtener(codMed);
    }

    @Transactional
    public MedicoRespuestaDTO actualizarMedico(String codMed, ActualizarMedicoDTO dto, String codUsuAdmin) {
        agendaRepo.bloquearMedico(codMed);
        actorHelper.fijarActor(codUsuAdmin);
        MedicoRespuestaDTO actual = obtener(codMed); // 404 si no existe

        String nombre = limpiar(dto.nombreCompleto);
        String email = dto.email == null ? null : dto.email.trim().toLowerCase();
        String ci = vacioANull(dto.ci);
        String matricula = limpiar(dto.matriculaProfesional);
        String resumen = vacioANull(dto.resumenCurricular);

        validarDatos(nombre, email, ci, matricula, dto.duracionConsultaMin);
        boolean cambiaPassword = dto.password != null && !dto.password.isBlank();
        if (cambiaPassword && dto.password.length() < 8) {
            throw error(Response.Status.BAD_REQUEST, "VALIDACION", "La contraseña debe tener al menos 8 caracteres.");
        }
        Set<String> vigentes = new LinkedHashSet<>();
        for (EspecialidadVinculoDTO vinculo : actual.especialidades()) {
            if ("Activo".equals(vinculo.estado())) {
                vigentes.add(vinculo.codEsp());
            }
        }
        Set<String> especialidades = dto.codigosEspecialidades == null ? null
                : validarEspecialidades(dto.codigosEspecialidades, vigentes);
        verificarUnicos(email, ci, matricula, actual.codUsu(), codMed);

        // Usuario
        Query qUsu = em.createNativeQuery("""
                UPDATE public.usuarios
                   SET nombre_completo = :nombre, email = :email,
                       password_hash = CASE WHEN :cambia THEN :hash ELSE password_hash END,
                       actualizado_en = now()
                 WHERE cod_usu = :usu
                """);
        qUsu.setParameter("nombre", nombre);
        qUsu.setParameter("email", email);
        qUsu.setParameter("cambia", cambiaPassword);
        qUsu.setParameter("hash", cambiaPassword ? BcryptUtil.bcryptHash(dto.password) : "");
        qUsu.setParameter("usu", actual.codUsu());
        qUsu.executeUpdate();
        if (cambiaPassword) {
            authService.revokeAllForUserAfterCommit(actual.codUsu());
        }

        // Perfil médico
        Query qMed = em.createNativeQuery("""
                UPDATE public.medicos
                   SET ci = CAST(:ci AS varchar), matricula_profesional = :mat,
                       resumen_curricular = CAST(:res AS text), duracion_consulta_min = CAST(:dur AS smallint)
                 WHERE cod_med = :med
                """);
        qMed.setParameter("ci", ci);
        qMed.setParameter("mat", matricula);
        qMed.setParameter("res", resumen);
        qMed.setParameter("dur", dto.duracionConsultaMin);
        qMed.setParameter("med", codMed);
        qMed.executeUpdate();

        // Si cambió la duración del doctor, se alinea en sus vínculos activos
        boolean cambioDuracion = !java.util.Objects.equals(actual.duracionConsultaMin(), dto.duracionConsultaMin);
        if (cambioDuracion) {
            Query qDur = em.createNativeQuery("""
                    UPDATE public.medico_especialidades
                       SET duracion_consulta_min = CAST(:dur AS smallint)
                     WHERE cod_med = :med AND estado = 'Activo'
                    """);
            qDur.setParameter("dur", dto.duracionConsultaMin);
            qDur.setParameter("med", codMed);
            qDur.executeUpdate();
        }

        // Sincroniza especialidades: las nuevas se activan, las quitadas se inactivan (no se borran)
        if (especialidades != null) {
            for (String codEsp : especialidades) {
                if (!vigentes.contains(codEsp)) {
                    upsertVinculo(codMed, codEsp, dto.duracionConsultaMin);
                }
            }
            for (String codEsp : vigentes) {
                if (!especialidades.contains(codEsp)) {
                    Query qOff = em.createNativeQuery("""
                            UPDATE public.medico_especialidades SET estado = 'Inactivo'
                             WHERE cod_med = :med AND cod_esp = :esp
                            """);
                    qOff.setParameter("med", codMed);
                    qOff.setParameter("esp", codEsp);
                    qOff.executeUpdate();
                }
            }
        }
        return obtener(codMed);
    }

    /** Inactiva o reactiva al doctor y su usuario (el login debe rechazar usuarios Inactivos). */
    @Transactional
    public MedicoRespuestaDTO cambiarEstado(String codMed, String estado, String codUsuAdmin) {
        agendaRepo.bloquearMedico(codMed);
        actorHelper.fijarActor(codUsuAdmin);
        validarEstado(estado);
        MedicoRespuestaDTO actual = obtener(codMed);

        Query qMed = em.createNativeQuery("UPDATE public.medicos SET estado = :est WHERE cod_med = :med");
        qMed.setParameter("est", estado);
        qMed.setParameter("med", codMed);
        qMed.executeUpdate();

        Query qUsu = em.createNativeQuery("UPDATE public.usuarios SET estado = :est, actualizado_en = now() WHERE cod_usu = :usu");
        qUsu.setParameter("est", estado);
        qUsu.setParameter("usu", actual.codUsu());
        qUsu.executeUpdate();
        if ("Inactivo".equals(estado)) {
            authService.revokeAllForUserAfterCommit(actual.codUsu());
        }

        return obtener(codMed);
    }

    // =====================================================================
    // Utilidades internas
    // =====================================================================

    public static WebApplicationException error(Response.Status status, String codigo, String mensaje) {
        Map<String, Object> cuerpo = new LinkedHashMap<>();
        cuerpo.put("ok", false);
        cuerpo.put("error", codigo);
        cuerpo.put("mensaje", mensaje);
        return new WebApplicationException(
                Response.status(status).type(MediaType.APPLICATION_JSON).entity(cuerpo).build());
    }

    private void upsertVinculo(String codMed, String codEsp, Short duracion) {
        Query q = em.createNativeQuery("""
                INSERT INTO public.medico_especialidades (cod_med, cod_esp, duracion_consulta_min, estado)
                VALUES (:med, :esp, CAST(:dur AS smallint), 'Activo')
                ON CONFLICT (cod_med, cod_esp)
                DO UPDATE SET estado = 'Activo', duracion_consulta_min = EXCLUDED.duracion_consulta_min
                """);
        q.setParameter("med", codMed);
        q.setParameter("esp", codEsp);
        q.setParameter("dur", duracion);
        q.executeUpdate();
    }

    static void validarNombreYCorreo(String nombre, String email) {
        if (nombre == null || nombre.length() < 3 || nombre.length() > 150) {
            throw error(Response.Status.BAD_REQUEST, "VALIDACION", "El nombre completo debe tener entre 3 y 150 caracteres.");
        }
        if (email == null || email.length() > 254 || !EMAIL.matcher(email).matches()) {
            throw error(Response.Status.BAD_REQUEST, "VALIDACION", "El correo electrónico no es válido.");
        }
    }

    static String validarFiltroEstado(String estado) {
        String filtro = vacioANull(estado);
        if (filtro != null && !"Activo".equals(filtro) && !"Inactivo".equals(filtro)) {
            throw error(Response.Status.BAD_REQUEST, "VALIDACION", "El filtro de estado debe ser Activo o Inactivo.");
        }
        return filtro;
    }

    static void validarEstado(String estado) {
        if (!"Activo".equals(estado) && !"Inactivo".equals(estado)) {
            throw error(Response.Status.BAD_REQUEST, "VALIDACION", "El estado debe ser Activo o Inactivo.");
        }
    }

    private void validarDatos(String nombre, String email, String ci, String matricula, Short duracion) {
        validarNombreYCorreo(nombre, email);
        if (matricula == null || matricula.isEmpty() || matricula.length() > 80) {
            throw error(Response.Status.BAD_REQUEST, "VALIDACION", "La matrícula profesional es obligatoria (máximo 80 caracteres).");
        }
        if (ci != null && ci.length() > 30) {
            throw error(Response.Status.BAD_REQUEST, "VALIDACION", "El CI no puede superar 30 caracteres.");
        }
        validarDuracion(duracion);
    }

    static void validarDuracion(Short duracion) {
        if (duracion != null && !DURACIONES.contains(duracion)) {
            throw error(Response.Status.BAD_REQUEST, "VALIDACION", "La duración de consulta debe ser 20, 30, 45 o 60 minutos.");
        }
    }

    private Set<String> validarEspecialidades(List<String> codigos, Set<String> vinculosVigentes) {
        if (codigos == null || codigos.isEmpty()) {
            throw error(Response.Status.BAD_REQUEST, "VALIDACION", "Debe seleccionar al menos una especialidad.");
        }
        Set<String> resultado = new LinkedHashSet<>();
        for (String c : codigos) {
            if (c == null || c.isBlank()) continue;
            String cod = c.trim();
            Especialidad e = especialidadRepo.findById(cod);
            if (e == null) {
                throw error(Response.Status.BAD_REQUEST, "VALIDACION", "La especialidad " + cod + " no existe.");
            }
            if (!"Activo".equals(e.estado) && !vinculosVigentes.contains(cod)) {
                throw error(Response.Status.BAD_REQUEST, "VALIDACION", "La especialidad " + e.nombreEspecialidad + " está inactiva.");
            }
            resultado.add(cod);
        }
        if (resultado.isEmpty()) {
            throw error(Response.Status.BAD_REQUEST, "VALIDACION", "Debe seleccionar al menos una especialidad.");
        }
        return resultado;
    }

    /** Evita mostrar errores crudos de PostgreSQL por duplicados. */
    private void verificarUnicos(String email, String ci, String matricula, String codUsuPropio, String codMedPropio) {
        usuarioRepo.findByEmail(email).ifPresent(u -> {
            if (!u.codUsu.equals(codUsuPropio)) {
                throw error(Response.Status.CONFLICT, "DUPLICADO", "Ya existe un usuario con el correo " + email + ".");
            }
        });
        if (cuenta("lower(trim(matricula_profesional)) = lower(:v)", matricula, codMedPropio) > 0) {
            throw error(Response.Status.CONFLICT, "DUPLICADO", "Ya existe un doctor con la matrícula " + matricula + ".");
        }
        if (ci != null && cuenta("ci IS NOT NULL AND upper(regexp_replace(trim(ci), '[^A-Za-z0-9]', '', 'g')) "
                + "= upper(regexp_replace(trim(:v), '[^A-Za-z0-9]', '', 'g'))", ci, codMedPropio) > 0) {
            throw error(Response.Status.CONFLICT, "DUPLICADO", "Ya existe un doctor con el CI " + ci + ".");
        }
    }

    private long cuenta(String condicion, String valor, String excluirCodMed) {
        String sql = "SELECT count(*) FROM public.medicos WHERE " + condicion
                + (excluirCodMed != null ? " AND cod_med <> :excluir" : "");
        Query q = em.createNativeQuery(sql);
        q.setParameter("v", valor);
        if (excluirCodMed != null) q.setParameter("excluir", excluirCodMed);
        return ((Number) q.getSingleResult()).longValue();
    }

    private List<MedicoRespuestaDTO> armar(List<Object[]> filas) {
        if (filas.isEmpty()) return List.of();

        List<String> codigos = new ArrayList<>();
        for (Object[] f : filas) codigos.add((String) f[0]);

        Query qEsp = em.createNativeQuery("""
                SELECT me.cod_med, me.cod_esp, e.nombre_especialidad, me.duracion_consulta_min, me.estado
                FROM public.medico_especialidades me
                JOIN public.especialidades e ON e.cod_esp = me.cod_esp
                WHERE me.cod_med IN (:codigos)
                ORDER BY me.cod_esp
                """);
        qEsp.setParameter("codigos", codigos);
        @SuppressWarnings("unchecked")
        List<Object[]> vinculos = qEsp.getResultList();

        Map<String, List<EspecialidadVinculoDTO>> porMedico = new LinkedHashMap<>();
        for (Object[] v : vinculos) {
            porMedico.computeIfAbsent((String) v[0], k -> new ArrayList<>())
                    .add(new EspecialidadVinculoDTO((String) v[1], (String) v[2], corto(v[3]), (String) v[4]));
        }

        List<MedicoRespuestaDTO> salida = new ArrayList<>();
        for (Object[] f : filas) {
            String codMed = (String) f[0];
            salida.add(new MedicoRespuestaDTO(codMed, (String) f[1], (String) f[2], (String) f[3], (String) f[4],
                    (String) f[5], (String) f[6], corto(f[7]), (String) f[8],
                    porMedico.getOrDefault(codMed, List.of())));
        }
        return salida;
    }

    private static Short corto(Object o) {
        return o == null ? null : ((Number) o).shortValue();
    }

    private static String limpiar(String s) {
        return s == null ? null : s.trim();
    }

    static String vacioANull(String s) {
        return (s == null || s.isBlank()) ? null : s.trim();
    }
}
