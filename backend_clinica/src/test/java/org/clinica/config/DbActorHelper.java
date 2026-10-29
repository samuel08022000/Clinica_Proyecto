package org.clinica.config;

import jakarta.enterprise.context.ApplicationScoped;
import jakarta.inject.Inject;
import jakarta.persistence.EntityManager;

@ApplicationScoped
public class DbActorHelper {

    @Inject
    EntityManager em;

    public void fijarActor(String codUsu) {
        if (codUsu != null && !codUsu.isBlank()) {
            em.createNativeQuery("SELECT set_config('app.cod_usu', :codUsu, true)")
              .setParameter("codUsu", codUsu)
              .getSingleResult();
        }
    }
}