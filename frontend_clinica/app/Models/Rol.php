<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class Rol extends Model
{
    protected $connection = 'clinical';

    protected $table = 'roles';

    protected $primaryKey = 'cod_rol';

    public $incrementing = false;

    public $timestamps = false;

    protected $keyType = 'string';

    protected $guarded = [];
}
