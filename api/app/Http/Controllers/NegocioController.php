<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class NegocioController extends Controller
{
    // Datos del negocio del dueño que inició sesión
    public function mostrar(Request $request)
    {
        $negocio = $request->user()->negocio;

        return $negocio->paraDueno() + ['telefono' => $negocio->telefono];
    }
}
