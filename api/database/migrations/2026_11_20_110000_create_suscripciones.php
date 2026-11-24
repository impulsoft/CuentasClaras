<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Fase 4: suscripciones, bloqueo y contraseñas.
 * - pagos_suscripcion: historial de pagos de cada negocio (RF-09, RN-12).
 * - negocios: inicio de la suscripción (RF-08), cuándo se bloqueó y cuándo se borraron sus datos (RN-34).
 * - users: cuándo se desactivó (RN-16) y desde cuándo valen las sesiones (RF-46, RF-47).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('negocios', function (Blueprint $table) {
            $table->date('suscripcion_inicio_el')->nullable()->after('estado');
            $table->timestamp('bloqueado_en')->nullable()->after('suscripcion_vence_el');
            $table->timestamp('datos_borrados_en')->nullable()->after('bloqueado_en');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('desactivado_en')->nullable()->after('activo');
            $table->timestamp('sesiones_validas_desde')->nullable()->after('debe_cambiar_contrasena');
        });

        Schema::create('pagos_suscripcion', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('negocio_id')->constrained('negocios');
            $table->date('fecha'); // el día en que pagó
            $table->unsignedBigInteger('valor');
            $table->date('vencia_el')->nullable(); // vencimiento antes del pago
            $table->date('vence_el'); // vencimiento nuevo (RN-12)
            $table->string('nota')->nullable();
            $table->foreignId('registrado_por')->nullable()->constrained('users');
            $table->timestamps();

            $table->index(['negocio_id', 'fecha']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pagos_suscripcion');
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['desactivado_en', 'sesiones_validas_desde']);
        });
        Schema::table('negocios', function (Blueprint $table) {
            $table->dropColumn(['suscripcion_inicio_el', 'bloqueado_en', 'datos_borrados_en']);
        });
    }
};
