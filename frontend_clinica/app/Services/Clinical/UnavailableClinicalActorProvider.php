<?php

namespace App\Services\Clinical;

use App\Contracts\ClinicalActorProvider;
use LogicException;

final class UnavailableClinicalActorProvider implements ClinicalActorProvider
{
    public function authenticatedUserCode(): string
    {
        throw new LogicException('La identidad clínica local aún no está habilitada.');
    }
}
