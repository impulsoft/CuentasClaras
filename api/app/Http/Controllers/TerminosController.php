<?php

namespace App\Http\Controllers;

use App\Http\Resources\UsuarioResource;
use Illuminate\Http\Request;

class TerminosController extends Controller
{
    // RF-03, RNF-10: se guarda la fecha en que el dueño los aceptó
    public function aceptar(Request $request)
    {
        $request->validate([
            'acepto' => ['accepted'],
        ], [
            'acepto.accepted' => 'Debe marcar que acepta los términos y condiciones.',
        ]);

        $usuario = $request->user();
        $usuario->terminos_aceptados_en ??= now();
        $usuario->save();

        return new UsuarioResource($usuario->load('negocio'));
    }
}
