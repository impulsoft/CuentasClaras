<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Cada negocio suscrito; toda su información cuelga de aquí (RNF-08)
        Schema::create('negocios', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('nombre');
            $table->string('telefono', 20)->nullable();
            $table->string('estado', 20)->default('activo'); // activo | bloqueado (RF-12)
            $table->date('suscripcion_vence_el')->nullable();
            $table->timestamps();
        });

        // Admin (sin negocio) y dueños (un negocio cada uno, RN-09)
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('negocio_id')->nullable()->unique()->constrained('negocios');
            $table->string('rol', 10); // admin | dueno
            $table->string('nombre');
            $table->string('usuario', 50)->unique();
            $table->string('telefono', 20)->nullable();
            $table->string('password');
            $table->boolean('activo')->default(true); // RN-16
            $table->boolean('debe_cambiar_contrasena')->default(false); // RF-46
            $table->timestamp('terminos_aceptados_en')->nullable(); // RF-03, RNF-10
            $table->rememberToken();
            $table->timestamps();
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sessions');
        Schema::dropIfExists('users');
        Schema::dropIfExists('negocios');
    }
};
