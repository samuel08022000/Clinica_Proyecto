<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // Las identidades clínicas se administran directamente en PostgreSQL desde Laravel.
        // No crear usuarios de demostración ni contraseñas predeterminadas.
    }
}
