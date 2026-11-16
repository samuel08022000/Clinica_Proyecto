/* ============================================================================
   MIGRACIÓN V2 — SEED DE USUARIOS, PERFILES Y AGENDA INICIAL (DESARROLLO)
   Idempotente: permite re-ejecución sin duplicar datos ni violar restricciones.
   ============================================================================ */

-- 1. USUARIOS INICIALES (Password común: password_seguro_123)
-- Hash bcrypt $2a$12$R9h/cIPz0gi.URNNX3kh2OPST9/PgBkqquzi.Ss7KIUgO2t0jWMUW
INSERT INTO public.usuarios (nombre_completo, email, password_hash, cod_rol, estado)
VALUES
  ('Samuel Paciente', 'samuel.paciente@unifranz.edu.bo', '$2a$12$R9h/cIPz0gi.URNNX3kh2OPST9/PgBkqquzi.Ss7KIUgO2t0jWMUW', 'ROL001', 'Activo'),
  ('Angela Secretaria', 'angela.secretaria@clinica.bo', '$2a$12$R9h/cIPz0gi.URNNX3kh2OPST9/PgBkqquzi.Ss7KIUgO2t0jWMUW', 'ROL002', 'Activo'),
  ('Dr. Dilan Doctor', 'dilan.doctor@clinica.bo', '$2a$12$R9h/cIPz0gi.URNNX3kh2OPST9/PgBkqquzi.Ss7KIUgO2t0jWMUW', 'ROL003', 'Activo'),
  ('Yesid Administrador', 'yesid.admin@clinica.bo', '$2a$12$R9h/cIPz0gi.URNNX3kh2OPST9/PgBkqquzi.Ss7KIUgO2t0jWMUW', 'ROL004', 'Activo')
ON CONFLICT (lower(trim(email))) DO NOTHING;

-- 2. PERFIL PACIENTE (ROL001)
INSERT INTO public.pacientes (cod_usu, ci, fecha_nacimiento, telefono, telefono_verificado, ciudad_residencia, estado_cuenta)
SELECT u.cod_usu, '12345678', '1995-05-15', '59170012345', true, 'Santa Cruz', 'Activo'
FROM public.usuarios u
WHERE lower(trim(u.email)) = 'samuel.paciente@unifranz.edu.bo'
  AND NOT EXISTS (SELECT 1 FROM public.pacientes p WHERE p.cod_usu = u.cod_usu);

-- 3. PERFIL SECRETARÍA (ROL002)
INSERT INTO public.secretarias (cod_usu, ci, telefono, estado)
SELECT u.cod_usu, '87654321', '59170098765', 'Activo'
FROM public.usuarios u
WHERE lower(trim(u.email)) = 'angela.secretaria@clinica.bo'
  AND NOT EXISTS (SELECT 1 FROM public.secretarias s WHERE s.cod_usu = u.cod_usu);

-- 4. PERFIL MÉDICO (ROL003)
INSERT INTO public.medicos (cod_usu, ci, matricula_profesional, resumen_curricular, duracion_consulta_min, estado)
SELECT u.cod_usu, '45678901', 'MP-BOL-99881', 'Especialista en Medicina Interna y Cardiología con 10 años de experiencia clínica.', 30, 'Activo'
FROM public.usuarios u
WHERE lower(trim(u.email)) = 'dilan.doctor@clinica.bo'
  AND NOT EXISTS (SELECT 1 FROM public.medicos m WHERE m.cod_usu = u.cod_usu);

-- 5. ESPECIALIDADES MÉDICAS
INSERT INTO public.especialidades (nombre_especialidad, duracion_consulta_min, descripcion, estado)
VALUES
  ('Medicina General', 30, 'Atención médica primaria y preventiva general', 'Activo'),
  ('Cardiología', 30, 'Diagnóstico, tratamiento y prevención de patologías cardiovasculares', 'Activo'),
  ('Pediatría', 20, 'Atención y cuidado médico pediátrico integral', 'Activo')
ON CONFLICT (nombre_especialidad) DO NOTHING;

-- 6. VÍNCULO MÉDICO - ESPECIALIDADES
INSERT INTO public.medico_especialidades (cod_med, cod_esp, duracion_consulta_min, estado)
SELECT m.cod_med, e.cod_esp, e.duracion_consulta_min, 'Activo'
FROM public.medicos m
CROSS JOIN public.especialidades e
JOIN public.usuarios u ON u.cod_usu = m.cod_usu
WHERE lower(trim(u.email)) = 'dilan.doctor@clinica.bo'
  AND e.nombre_especialidad IN ('Medicina General', 'Cardiología', 'Pediatría')
ON CONFLICT (cod_med, cod_esp) DO NOTHING;

-- 7. HORARIOS SEMANALES (Lunes a Viernes de 08:00 a 14:00 con Pausa 12:00 a 12:30)
INSERT INTO public.horarios (cod_med, dia_semana, hora_inicio, hora_fin, pausa_inicio, pausa_fin, estado)
SELECT m.cod_med, d.dia::smallint, '08:00'::time, '14:00'::time, '12:00'::time, '12:30'::time, 'Activo'
FROM public.medicos m
JOIN public.usuarios u ON u.cod_usu = m.cod_usu
CROSS JOIN generate_series(1, 5) AS d(dia)
WHERE lower(trim(u.email)) = 'dilan.doctor@clinica.bo'
  AND NOT EXISTS (
    SELECT 1 FROM public.horarios h
    WHERE h.cod_med = m.cod_med AND h.dia_semana = d.dia::smallint AND h.estado = 'Activo'
  );

-- 8. GENERACIÓN AUTOMÁTICA DE SLOTS EN LA VENTANA D..D+14
SELECT public.generar_slots_agenda();
