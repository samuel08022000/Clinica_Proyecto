package org.clinica.service;

import io.quarkus.elytron.security.common.BcryptUtil;
import jakarta.enterprise.context.ApplicationScoped;
import jakarta.inject.Inject;
import jakarta.transaction.Transactional;
import org.clinica.config.DbActorHelper;
import org.clinica.dto.personal.CrearMedicoDTO;
import org.clinica.entity.*;

@ApplicationScoped
public class PersonalService {

    @Inject
    DbActorHelper actorHelper;

    @Transactional
    public Medico registrarMedico(CrearMedicoDTO dto, String codUsuAdmin) {
        // 1. Fijar el administrador en sesión de PostgreSQL para que quede en bitácora
        actorHelper.fijarActor(codUsuAdmin);

        // 2. Insertar en usuarios con rol ROL003
        Usuario u = new Usuario();
        u.nombreCompleto = dto.nombreCompleto;
        u.email = dto.email.trim().toLowerCase();
        u.passwordHash = BcryptUtil.bcryptHash(dto.password);
        u.codRol = "ROL003";
        u.persistAndFlush(); // Genera u.codUsu ('USU000001')

        // 3. Insertar en medicos
        Medico m = new Medico();
        m.codUsu = u.codUsu;
        m.ci = dto.ci;
        m.matriculaProfesional = dto.matriculaProfesional;
        m.resumenCurricular = dto.resumenCurricular;
        m.duracionConsultaMin = dto.duracionConsultaMin; // null o 20, 30, 45, 60
        m.persistAndFlush(); // Genera m.codMed ('MED000001')

        // 4. Vincular una o más especialidades en medico_especialidades (RF-07)
        for (String codEsp : dto.codigosEspecialidades) {
            MedicoEspecialidad me = new MedicoEspecialidad();
            me.id = new MedicoEspecialidadId(m.codMed, codEsp);
            me.duracionConsultaMin = dto.duracionConsultaMin;
            me.estado = "Activo";
            me.persist();
        }
        return m;
    }
}