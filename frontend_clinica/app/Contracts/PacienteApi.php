<?php

namespace App\Contracts;

use App\Exceptions\PacienteApiException;

/**
 * Contrato del módulo paciente. Hoy hay dos implementaciones:
 *  - MockPacienteApi    (datos simulados en sesión, para avanzar sin backend)
 *  - QuarkusPacienteApi (llama a /api/v1/paciente/... cuando exista)
 *
 * Todas las formas de datos (arrays) están documentadas en PACIENTE_API_CONTRATO.md.
 *
 * @throws PacienteApiException en cualquier error funcional o de conexión
 */
interface PacienteApi
{
    /** @return list<array{codEsp:string,nombre:string}> */
    public function especialidades(): array;

    /** @return list<array{codMed:string,nombre:string,codEsp:string,nombreEspecialidad:string}> */
    public function medicos(?string $codEsp = null): array;

    /** @return list<array{codSlot:string,codMed:string,nombreMedico:string,codEsp:string,nombreEspecialidad:string,fecha:string,horaInicio:string,horaFin:string}> */
    public function disponibilidad(string $codEsp, ?string $codMed, string $fecha): array;

    /** @return list<array{codCit:string,codMed:string,nombreMedico:string,codEsp:string,nombreEspecialidad:string,fecha:string,horaInicio:string,horaFin:string,estado:string}> */
    public function citas(): array;

    /** Reserva el cupo; la cita nace en estado "Confirmada". */
    public function crearCita(string $codSlot): array;

    public function cancelarCita(string $codCit): void;

    /** @return array{nombre:string,email:string,ci:string,fechaNacimiento:string,telefono:string,ciudadResidencia:string} */
    public function perfil(): array;

    /** Solo se pueden editar telefono y ciudadResidencia. */
    public function actualizarPerfil(array $datos): array;
}
