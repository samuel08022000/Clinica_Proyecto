<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class Medico extends Model
{
    protected $connection = 'clinical';

    protected $table = 'medicos';

    protected $primaryKey = 'cod_med';

    public $incrementing = false;

    public $timestamps = false;

    protected $keyType = 'string';

    protected $guarded = ['secuencial', 'cod_med'];
}
