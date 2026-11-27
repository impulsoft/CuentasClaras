<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Un negocio puede tener varias cuentas (el dueño y otras que crea el admin): se quita la regla de
 * una cuenta por negocio. Primero se crea un índice normal, porque en MySQL la llave foránea
 * necesita uno mientras se quita el único.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->index('negocio_id');
        });
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['negocio_id']);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->unique('negocio_id');
        });
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['negocio_id']);
        });
    }
};
