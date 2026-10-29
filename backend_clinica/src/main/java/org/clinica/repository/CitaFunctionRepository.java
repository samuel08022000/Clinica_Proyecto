package org.clinica.repository;

import jakarta.enterprise.context.ApplicationScoped;
import jakarta.inject.Inject;
import jakarta.persistence.EntityManager;
import java.time.LocalDate;

@ApplicationScoped
public class CitaFunctionRepository {

    @Inject
    EntityManager em;

    // RF-01: Registrar Paciente (inserta en usuarios y pacientes en un solo paso)
    public String registrarPaciente(String nombre, String email, String passHash, String ci, LocalDate fechaNac, String tel, String ciudad) {
        return (String) em.createNativeQuery(
                "SELECT public.registrar_paciente(:nombre, :email, :passHash, :ci, :fechaNac, :tel, :ciudad)")
                .setParameter("nombre", nombre)
                .setParameter("email", email)
                .setParameter("passHash", passHash)
                .setParameter("ci", ci)
                .setParameter("fechaNac", fechaNac)
                .setParameter("tel", tel)
                .setParameter("ciudad", ciudad)
                .getSingleResult();
    }

    // RF-02: Generar código OTP (devuelve el código de 6 dígitos en texto claro una sola vez y guarda SHA-256)
    public String crearOtp(String codUsu) {
        return (String) em.createNativeQuery("SELECT public.crear_otp(:codUsu)")
                .setParameter("codUsu", codUsu)
                .getSingleResult();
    }

    // RF-02: Validar OTP ingresado por el paciente
    public Boolean validarOtp(String codUsu, String codigo) {
        return (Boolean) em.createNativeQuery("SELECT public.validar_otp(:codUsu, :codigo)")
                .setParameter("codUsu", codUsu)
                .setParameter("codigo", codigo)
                .getSingleResult();
    }

    // RF-16, RF-17, RF-18: Reserva atómica (Web, Ventanilla, Derivacion, Admin) + Generación automática de token_qr HMAC
    public String reservarCita(String codPac, String codSlot, String codActor, String canal, String codDer) {
        return (String) em.createNativeQuery(
                "SELECT public.reservar_cita(:codPac, :codSlot, :codActor, :canal, :codDer)")
                .setParameter("codPac", codPac)
                .setParameter("codSlot", codSlot)
                .setParameter("codActor", codActor)
                .setParameter("canal", canal)
                .setParameter("codDer", codDer)
                .getSingleResult();
    }

    // RF-19: Escaneo de QR y Check-in en recepción
    public String registrarCheckin(String tokenQr, String codSec) {
        return (String) em.createNativeQuery("SELECT public.registrar_checkin(:tokenQr, :codSec)")
                .setParameter("tokenQr", tokenQr)
                .setParameter("codSec", codSec)
                .getSingleResult();
    }

    // RF-20: Médico marca la cita como Atendida
    public Boolean marcarCitaAtendida(String codCit, String codUsuDoctor) {
        return (Boolean) em.createNativeQuery("SELECT public.marcar_cita_atendida(:codCit, :codUsuDoctor)")
                .setParameter("codCit", codCit)
                .setParameter("codUsuDoctor", codUsuDoctor)
                .getSingleResult();
    }

    // RF-22: Cancelar cita liberando el slot inmediatamente
    public Boolean cancelarCita(String codCit, String codActor, String motivo) {
        return (Boolean) em.createNativeQuery("SELECT public.cancelar_cita(:codCit, :codActor, :motivo)")
                .setParameter("codCit", codCit)
                .setParameter("codActor", codActor)
                .setParameter("motivo", motivo)
                .getSingleResult();
    }
}