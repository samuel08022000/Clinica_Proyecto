package org.clinica.config;

import jakarta.enterprise.context.ApplicationScoped;
import jakarta.inject.Inject;
import jakarta.persistence.EntityManager;
import jakarta.transaction.Transactional;

/**
 * Fija el actor autenticado en la sesión de PostgreSQL para que el trigger
 * {@code public.auditar_fila()} registre el autor en {@code public.bitacora}
 * (vía {@code public.actor_actual()}).
 *
 * <p>Se usa {@code set_config(..., true)} (is_local), por lo que el valor sólo vive
 * dentro de la transacción actual. Por eso el método exige una transacción activa:
 * invóquelo al inicio de cada método {@code @Transactional} del servicio.</p>
 */
@ApplicationScoped
public class DbActorHelper {

    @Inject
    EntityManager em;

    @Transactional(Transactional.TxType.MANDATORY)
    public void fijarActor(String codUsu) {
        if (codUsu != null && !codUsu.isBlank()) {
            em.createNativeQuery("SELECT set_config('app.cod_usu', :codUsu, true)")
              .setParameter("codUsu", codUsu)
              .getSingleResult();
        }
    }
}