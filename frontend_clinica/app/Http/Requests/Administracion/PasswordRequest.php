<?php

namespace App\Http\Requests\Administracion;

class PasswordRequest extends AdminRequest
{
    public function rules(): array
    {
        return ['password' => $this->passwordRules(true, true), 'confirmacion' => ['accepted']];
    }
}
