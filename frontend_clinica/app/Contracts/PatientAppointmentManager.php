<?php

namespace App\Contracts;

interface PatientAppointmentManager
{
    public function list(array $filters = []): array;

    public function detail(string $appointmentCode): array;

    public function reserve(string $slotCode): array;

    public function cancel(string $appointmentCode, string $reason): array;

    public function reschedule(string $appointmentCode, string $newSlotCode, string $reason): array;
}
