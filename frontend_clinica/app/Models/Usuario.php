<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

final class Usuario extends Authenticatable
{
    use Notifiable;

    protected $connection = 'clinical';

    protected $table = 'usuarios';

    protected $primaryKey = 'cod_usu';

    public $incrementing = false;

    public $timestamps = false;

    protected $keyType = 'string';

    protected $guarded = ['secuencial', 'cod_usu'];

    protected $hidden = ['password_hash'];

    protected function casts(): array
    {
        return [
            'creado_en' => 'immutable_datetime',
            'actualizado_en' => 'immutable_datetime',
        ];
    }

    public function getAuthPasswordName(): string
    {
        return 'password_hash';
    }

    public function getAuthPassword(): ?string
    {
        return $this->password_hash;
    }

    public function getRememberTokenName(): string
    {
        return '';
    }

    public function rol(): BelongsTo
    {
        return $this->belongsTo(Rol::class, 'cod_rol', 'cod_rol');
    }

    public function paciente(): HasOne
    {
        return $this->hasOne(Paciente::class, 'cod_usu', 'cod_usu');
    }

    public function secretaria(): HasOne
    {
        return $this->hasOne(Secretaria::class, 'cod_usu', 'cod_usu');
    }

    public function medico(): HasOne
    {
        return $this->hasOne(Medico::class, 'cod_usu', 'cod_usu');
    }
}
