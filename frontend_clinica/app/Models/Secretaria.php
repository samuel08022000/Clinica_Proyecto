<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class Secretaria extends Model
{
    protected $connection = 'clinical';

    protected $table = 'secretarias';

    protected $primaryKey = 'cod_sec';

    public $incrementing = false;

    public $timestamps = false;

    protected $keyType = 'string';

    protected $guarded = ['secuencial', 'cod_sec'];
}
