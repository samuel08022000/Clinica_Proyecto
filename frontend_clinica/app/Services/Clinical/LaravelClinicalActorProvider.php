<?php

namespace App\Services\Clinical;

use App\Contracts\ClinicalActorProvider;
use App\Models\Usuario;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use LogicException;

final class LaravelClinicalActorProvider implements ClinicalActorProvider
{
    public function __construct(private AuthFactory $auth) {}

    public function authenticatedUserCode(): string
    {
        $user = $this->auth->guard('web')->user();
        if (! $user instanceof Usuario || $user->estado !== 'Activo') {
            throw new LogicException('No existe una identidad clínica autenticada y activa.');
        }

        return $user->cod_usu;
    }
}
