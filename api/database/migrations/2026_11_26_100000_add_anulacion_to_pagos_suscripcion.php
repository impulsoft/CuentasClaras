<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Un pago de la suscripción registrado por error (valor equivocado, negocio equivocado) se anula
 * con un motivo; no se borra, para que el historial quede completo. El vencimiento se recalcula
 * con los pagos que siguen vigentes (Negocio::recalcularSuscripcion).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pagos_suscripcion', function (Blueprint $table) {
            $table->timestamp('anulado_en')->nullable()->after('registrado_por');
            $table->string('motivo_anulacion')->nullable()->after('anulado_en');
            $table->foreignId('anulado_por')->nullable()->after('motivo_anulacion')->constrained('users');
        });
    }

    public function down(): void
    {
        Schema::table('pagos_suscripcion', function (Blueprint $table) {
            $table->dropConstrainedForeignId('anulado_por');
            $table->dropColumn(['anulado_en', 'motivo_anulacion']);
        });
    }
};
