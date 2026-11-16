<?php

namespace App\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\QueryException;

final class PortalController extends Controller
{
    public function __construct(private readonly DatabaseManager $database) {}

    public function index(): View
    {
        $specialties = [];
        $specialtiesState = 'ready';
        $specialtiesMessage = null;

        try {
            $specialties = collect($this->database->connection('clinical')->select(<<<'SQL'
                SELECT cod_esp AS code,nombre_especialidad AS name,descripcion AS description,
                       duracion_consulta_min AS duration
                FROM especialidades
                WHERE estado='Activo'
                ORDER BY nombre_especialidad,cod_esp
                SQL))
                ->map(fn (object $item): array => (array) $item)
                ->all();
            if ($specialties === []) {
                $specialtiesState = 'empty';
                $specialtiesMessage = 'No hay especialidades activas disponibles en este momento.';
            }
        } catch (QueryException) {
            $specialtiesState = 'error';
            $specialtiesMessage = 'No fue posible consultar las especialidades. Intenta nuevamente.';
        }

        return view('welcome', [
            'clinic' => config('clinic'),
            'specialties' => $specialties,
            'specialtiesState' => $specialtiesState,
            'specialtiesMessage' => $specialtiesMessage,
        ]);
    }
}
