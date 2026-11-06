package org.clinica.repository;

import jakarta.enterprise.context.ApplicationScoped;
import jakarta.inject.Inject;
import jakarta.persistence.EntityManager;
import jakarta.transaction.Transactional;
import org.hibernate.query.NativeQuery;

import java.time.LocalDate;

/**
 * Envoltorio Java de las funciones de negocio {@code SECURITY DEFINER} de PostgreSQL.
 *
 * <p>Las tablas privadas {@code verificaciones_otp}, {@code tokens_recuperacion} y
 * {@code configuracion_seguridad} (REVOKE ALL FROM PUBLIC) sólo se tocan a través de
 * estas funciones; no existen entidades JPA para ellas.</p>
 *
 * <p>Todos los métodos son {@code @Transactional} (REQUIRED): se unen a la transacción
 * del servicio si existe, o abren una propia. Los {@code RAISE EXCEPTION} de la BD llegan
 * como {@code PersistenceException} y {@code TriggerExceptionMapper} los traduce a HTTP 400.</p>
 *
 * <p>Se usan {@code CAST(:p AS tipo)} + binding tipado para que PostgreSQL resuelva la
 * firma exacta de cada función incluso cuando un parámetro opcional llega en {@code null}.</p>
 */
@ApplicationScoped
@Transactional
public class CitaFunctionRepository {

    @Inject
    EntityManager em;

    private NativeQuery<?> consulta(String sql) {
        return em.createNativeQuery(sql).unwrap(NativeQuery.class);
    }

    private static Integer aEntero(Object valor) {
        return valor == null ? null : ((Number) valor).intValue();
    }

    // ---------------------------------------------------------------------
    // Registro, OTP y recuperación de contraseña (RF-01, RF-02, RF-03)
    // ---------------------------------------------------------------------

    /** RF-01: crea usuario (ROL001) + perfil paciente. Devuelve {@code cod_pac}. */
    public String registrarPaciente(String nombre, String email, String passHash, String ci,
                                    LocalDate fechaNac, String tel, String ciudad) {
        return (String) consulta("""
                SELECT public.registrar_paciente(
                    CAST(:nombre AS text), CAST(:email AS text), CAST(:passHash AS text),
                    CAST(:ci AS text), CAST(:fechaNac AS date), CAST(:tel AS text), CAST(:ciudad AS text))
                """)
                .setParameter("nombre", nombre, String.class)
                .setParameter("email", email, String.class)
                .setParameter("passHash", passHash, String.class)
                .setParameter("ci", ci, String.class)
                .setParameter("fechaNac", fechaNac, LocalDate.class)
                .setParameter("tel", tel, String.class)
                .setParameter("ciudad", ciudad, String.class)
                .getSingleResult();
    }

    /** RF-02: genera OTP de 6 dígitos. Devuelve el código en claro UNA sola vez (enviar por SMS). */
    public String crearOtp(String codUsu) {
        return (String) consulta("SELECT public.crear_otp(CAST(:codUsu AS text))")
                .setParameter("codUsu", codUsu, String.class)
                .getSingleResult();
    }

    /** RF-02: valida el OTP; si es correcto marca {@code telefono_verificado = true}. */
    public Boolean validarOtp(String codUsu, String codigo) {
        return (Boolean) consulta("SELECT public.validar_otp(CAST(:codUsu AS text), CAST(:codigo AS text))")
                .setParameter("codUsu", codUsu, String.class)
                .setParameter("codigo", codigo, String.class)
                .getSingleResult();
    }

    /** Genera el OTP de verificacion de correo y lo devuelve una sola vez al backend. */
    public String crearOtpEmail(String codUsu) {
        return (String) consulta("SELECT public.crear_otp_email(CAST(:codUsu AS text))")
                .setParameter("codUsu", codUsu, String.class)
                .getSingleResult();
    }

    /** Valida el OTP de correo y marca email_verificado sin reutilizar telefono_verificado. */
    public Boolean validarOtpEmail(String codUsu, String codigo) {
        return (Boolean) consulta("SELECT public.validar_otp_email(CAST(:codUsu AS text), CAST(:codigo AS text))")
                .setParameter("codUsu", codUsu, String.class)
                .setParameter("codigo", codigo, String.class)
                .getSingleResult();
    }

    /** RF-03: devuelve el token de recuperación en claro, o {@code null} si el email no existe (respuesta neutra). */
    public String crearTokenRecuperacion(String email) {
        return (String) consulta("SELECT public.crear_token_recuperacion(CAST(:email AS text))")
                .setParameter("email", email, String.class)
                .getSingleResult();
    }

    /** RF-03: cambia la contraseña si el token es válido. {@code nuevoPassHash} debe ser bcrypt/Argon2. */
    public Boolean restablecerContrasena(String email, String token, String nuevoPassHash) {
        return (Boolean) consulta("""
                SELECT public.restablecer_contrasena(
                    CAST(:email AS text), CAST(:token AS text), CAST(:nuevoPassHash AS text))
                """)
                .setParameter("email", email, String.class)
                .setParameter("token", token, String.class)
                .setParameter("nuevoPassHash", nuevoPassHash, String.class)
                .getSingleResult();
    }

    // ---------------------------------------------------------------------
    // Agenda (RF-08 a RF-13)
    // ---------------------------------------------------------------------

    /** Genera slots para la ventana por defecto D..D+14. Devuelve cuántos se insertaron. */
    public Integer generarSlotsAgenda() {
        return generarSlotsAgenda(null, null);
    }

    /**
     * Genera slots entre {@code desde} y {@code hasta} (ambos dentro de D..D+14).
     * Un parámetro {@code null} toma el valor por defecto de la función SQL.
     */
    public Integer generarSlotsAgenda(LocalDate desde, LocalDate hasta) {
        return aEntero(consulta("""
                SELECT public.generar_slots_agenda(
                    COALESCE(CAST(:desde AS date), public.fecha_clinica()),
                    COALESCE(CAST(:hasta AS date), public.fecha_clinica() + 14))
                """)
                .setParameter("desde", desde, LocalDate.class)
                .setParameter("hasta", hasta, LocalDate.class)
                .getSingleResult());
    }

    // ---------------------------------------------------------------------
    // Ciclo de vida de la cita (RF-16 a RF-25)
    // ---------------------------------------------------------------------

    /**
     * RF-16/17/18: reserva atómica. {@code canal}: Web | Ventanilla | Derivacion | Admin
     * (null = 'Web'). Si {@code codDer} no es null la BD fuerza canal 'Derivacion'.
     * Devuelve {@code cod_cit}; el token QR lo genera el trigger.
     */
    public String reservarCita(String codPac, String codSlot, String codActor, String canal, String codDer) {
        return (String) consulta("""
                SELECT public.reservar_cita(
                    CAST(:codPac AS text), CAST(:codSlot AS text), CAST(:codActor AS text),
                    COALESCE(CAST(:canal AS text), 'Web'), CAST(:codDer AS text))
                """)
                .setParameter("codPac", codPac, String.class)
                .setParameter("codSlot", codSlot, String.class)
                .setParameter("codActor", codActor, String.class)
                .setParameter("canal", canal, String.class)
                .setParameter("codDer", codDer, String.class)
                .getSingleResult();
    }

    /** RF-22: cancela y libera el slot. Sólo paciente propietario, secretaría o admin. */
    public Boolean cancelarCita(String codCit, String codActor, String motivo) {
        return (Boolean) consulta("""
                SELECT public.cancelar_cita(CAST(:codCit AS text), CAST(:codActor AS text), CAST(:motivo AS text))
                """)
                .setParameter("codCit", codCit, String.class)
                .setParameter("codActor", codActor, String.class)
                .setParameter("motivo", motivo, String.class)
                .getSingleResult();
    }

    /** RF-21: marca la cita como 'Reprogramada' y crea la nueva. Devuelve el {@code cod_cit} nuevo. */
    public String reprogramarCita(String codCit, String codSlotNuevo, String codActor, String motivo) {
        return (String) consulta("""
                SELECT public.reprogramar_cita(
                    CAST(:codCit AS text), CAST(:codSlotNuevo AS text), CAST(:codActor AS text), CAST(:motivo AS text))
                """)
                .setParameter("codCit", codCit, String.class)
                .setParameter("codSlotNuevo", codSlotNuevo, String.class)
                .setParameter("codActor", codActor, String.class)
                .setParameter("motivo", motivo, String.class)
                .getSingleResult();
    }

    /** RF-19: valida la firma HMAC del QR y pasa la cita a 'En Espera'. Devuelve {@code cod_cit}. */
    public String registrarCheckin(String tokenQr, String codSec) {
        return (String) consulta("SELECT public.registrar_checkin(CAST(:tokenQr AS text), CAST(:codSec AS text))")
                .setParameter("tokenQr", tokenQr, String.class)
                .setParameter("codSec", codSec, String.class)
                .getSingleResult();
    }

    /** RF-20: el médico responsable cierra la cita ('En Espera' → 'Atendida'). */
    public Boolean marcarCitaAtendida(String codCit, String codUsuDoctor) {
        return (Boolean) consulta("""
                SELECT public.marcar_cita_atendida(CAST(:codCit AS text), CAST(:codUsuDoctor AS text))
                """)
                .setParameter("codCit", codCit, String.class)
                .setParameter("codUsuDoctor", codUsuDoctor, String.class)
                .getSingleResult();
    }

    /** RF-25: crea una derivación desde una cita atendida. {@code codMedDestino} es opcional. Devuelve {@code cod_der}. */
    public String crearDerivacion(String codCitOrigen, String codUsuDoctor, String codEspDestino,
                                  String motivo, String codMedDestino) {
        return (String) consulta("""
                SELECT public.crear_derivacion(
                    CAST(:codCitOrigen AS text), CAST(:codUsuDoctor AS text), CAST(:codEspDestino AS text),
                    CAST(:motivo AS text), CAST(:codMedDestino AS text))
                """)
                .setParameter("codCitOrigen", codCitOrigen, String.class)
                .setParameter("codUsuDoctor", codUsuDoctor, String.class)
                .setParameter("codEspDestino", codEspDestino, String.class)
                .setParameter("motivo", motivo, String.class)
                .setParameter("codMedDestino", codMedDestino, String.class)
                .getSingleResult();
    }

    /** RF-24: sanción de bloqueo (1..365 días). Sólo administradores. Devuelve {@code cod_inc}. */
    public String registrarSancion(String codPac, String codAdmin, String motivo, Integer dias) {
        return (String) consulta("""
                SELECT public.registrar_sancion(
                    CAST(:codPac AS text), CAST(:codAdmin AS text), CAST(:motivo AS text), CAST(:dias AS integer))
                """)
                .setParameter("codPac", codPac, String.class)
                .setParameter("codAdmin", codAdmin, String.class)
                .setParameter("motivo", motivo, String.class)
                .setParameter("dias", dias, Integer.class)
                .getSingleResult();
    }

    // ---------------------------------------------------------------------
    // Procesos programados (RF-23, RF-24)
    // ---------------------------------------------------------------------

    /** RF-23: marca 'No Asistió' las citas confirmadas vencidas sin check-in. Devuelve filas afectadas. */
    public Integer marcarInasistenciasVencidas() {
        return aEntero(consulta("SELECT public.marcar_inasistencias_vencidas()").getSingleResult());
    }

    /** RF-24: reactiva pacientes cuya sanción expiró. Devuelve filas afectadas. */
    public Integer desbloquearSancionesVencidas() {
        return aEntero(consulta("SELECT public.desbloquear_sanciones_vencidas()").getSingleResult());
    }
}
