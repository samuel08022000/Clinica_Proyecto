package org.clinica.scheduler;

import io.quarkus.scheduler.Scheduled;
import jakarta.enterprise.context.ApplicationScoped;
import jakarta.inject.Inject;
import jakarta.persistence.EntityManager;
import jakarta.transaction.Transactional;

@ApplicationScoped
public class ClinicaDaemonScheduler {

    @Inject
    EntityManager em;

    // 1. Desplaza la ventana móvil de 14 días a diario a medianoche (RF-13)
    @Scheduled(cron = "0 5 0 * * ?")
    @Transactional
    void generarSlotsDiarios() {
        em.createNativeQuery("SELECT public.generar_slots_agenda()").getSingleResult();
    }

    // 2. Cada 5 minutos marca como 'No Asistió' las citas vencidas sin check-in (RF-23)
    //    y desbloquea a los pacientes cuya sanción ya expiró (RF-24)
    @Scheduled(every = "5m")
    @Transactional
    void procesarInasistenciasYSanciones() {
        em.createNativeQuery("SELECT public.marcar_inasistencias_vencidas()").getSingleResult();
        em.createNativeQuery("SELECT public.desbloquear_sanciones_vencidas()").getSingleResult();
    }
}