<?php

namespace App\Contracts;

/** Debe resolver una identidad autenticada y revalidada por el servidor, nunca Request::input(). */
interface ClinicalActorProvider
{
    public function authenticatedUserCode(): string;
}
