<?php

namespace App\Http\Controllers\Administracion;

use App\Http\Requests\Administracion\PasswordRequest;

class SecretariaController extends PersonalController
{
    protected string $resource = 'secretarias';

    public function password(string $codigo)
    {
        return view('administracion.personal.password', ['record' => $this->api->administrative('GET', 'personal/secretarias/'.$codigo)]);
    }

    public function resetPassword(PasswordRequest $request, string $codigo)
    {
        return $this->save('PUT', 'personal/secretarias/'.$codigo.'/password', $request->safe()->only('password'),
            'administracion.secretarias.show', ['codigo' => $codigo]);
    }
}
