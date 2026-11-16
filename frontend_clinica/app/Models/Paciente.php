<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class Paciente extends Model
{
    protected $connection = 'clinical';

    protected $table = 'pacientes';

    protected $primaryKey = 'cod_pac';

    public $incrementing = false;

    public $timestamps = false;

    protected $keyType = 'string';

    protected $guarded = ['secuencial', 'cod_pac'];

    protected function casts(): array
    {
        return ['email_verificado' => 'boolean', 'bloqueado_hasta' => 'immutable_datetime'];
    }
}
