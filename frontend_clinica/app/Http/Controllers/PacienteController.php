<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http; // Importante agregar esto

class PacienteController extends Controller
{
    public function index()
    {
        // Aquí le pedimos los datos a tu Quarkus (asumiendo que corre en el 8080)
        $response = Http::get('http://localhost:8080/pacientes');
        
        // Convertimos la respuesta a un arreglo de PHP
        $pacientes = $response->json();

        // Retornamos la vista enviando los datos
        return view('pacientes', compact('pacientes'));
    }
}