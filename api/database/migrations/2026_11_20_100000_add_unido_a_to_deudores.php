<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * RN-32: si dos celulares crean sin internet el mismo deudor (mismo teléfono), al sincronizar
 * se unen en uno. El repetido queda vacío y apunta al que se conserva, para que los fiados y
 * abonos que lleguen con su id se pasen al deudor correcto.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deudores', function (Blueprint $table) {
            $table->uuid('unido_a')->nullable()->after('anonimizado_en');
        });
    }

    public function down(): void
    {
        Schema::table('deudores', function (Blueprint $table) {
            $table->dropColumn('unido_a');
        });
    }
};
