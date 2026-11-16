<?php

namespace App\Contracts;

interface PatientAvailabilityProvider
{
    public function specialties(): array;

    public function doctors(string $specialtyCode): array;

    public function slots(array $filters): array;
}
