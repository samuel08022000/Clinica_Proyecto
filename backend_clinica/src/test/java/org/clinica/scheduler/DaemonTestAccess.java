package org.clinica.scheduler;

/** Permite invocar la tarea real sin activar el temporizador durante las pruebas. */
public final class DaemonTestAccess {
    private DaemonTestAccess() { }
    public static void ejecutar(ClinicaDaemonScheduler scheduler) {
        scheduler.procesarInasistenciasYSanciones();
    }
}
