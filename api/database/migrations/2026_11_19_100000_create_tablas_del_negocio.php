<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Tablas que el celular crea sin internet y luego sincroniza: los id (UUID) los
 * genera el dispositivo. Los valores van en pesos colombianos enteros (RN-06).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cierres', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('negocio_id')->constrained('negocios');
            $table->date('fecha');
            $table->string('tipo', 10)->default('normal'); // normal | historico (RN-35)
            $table->boolean('no_abrio')->default(false); // RN-31
            $table->unsignedBigInteger('base')->nullable();
            $table->unsignedBigInteger('efectivo')->nullable();
            $table->unsignedBigInteger('salida_caja')->default(0); // RN-38
            $table->unsignedBigInteger('total_historico')->nullable(); // RN-35
            $table->unsignedBigInteger('nequi_historico')->nullable();
            $table->timestamp('modificado_en')->nullable(); // RF-20, RN-30
            $table->timestamps();

            $table->unique(['negocio_id', 'fecha']); // RN-01
        });

        // Pagos digitales del día: Nequi u "otro pago" (el efectivo sale del cierre)
        Schema::create('pagos', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('negocio_id')->constrained('negocios');
            $table->date('fecha'); // RN-04: la del comprobante
            $table->time('hora')->nullable();
            $table->string('medio', 10); // nequi | otro (RNF-14)
            $table->string('origen', 12); // foto | pantallazo | manual
            $table->string('estado', 12)->default('confirmado'); // por_leer | por_confirmar | confirmado
            $table->unsignedBigInteger('valor')->nullable(); // vacío mientras la foto no se ha leído
            $table->string('nombre_pagador')->nullable();
            $table->string('referencia', 40)->nullable(); // RN-05 (se valida sin contar los anulados)
            $table->string('foto_ruta')->nullable();
            $table->date('foto_borrar_el')->nullable(); // RN-43
            $table->timestamp('anulado_en')->nullable(); // RN-36
            $table->string('motivo_anulacion')->nullable();
            $table->timestamps();

            $table->index(['negocio_id', 'fecha']);
            $table->index(['negocio_id', 'referencia']);
        });

        Schema::create('deudores', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('negocio_id')->constrained('negocios');
            $table->string('nombre')->nullable(); // vacío si se anonimizó (RN-42)
            $table->string('telefono', 20)->nullable();
            $table->timestamp('autorizacion_en')->nullable(); // RN-41
            $table->timestamp('anonimizado_en')->nullable();
            $table->timestamps();

            $table->unique(['negocio_id', 'telefono']); // RN-32
        });

        Schema::create('fiados', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('negocio_id')->constrained('negocios');
            $table->foreignUuid('deudor_id')->constrained('deudores');
            $table->date('fecha');
            $table->string('descripcion');
            $table->unsignedBigInteger('valor');
            $table->date('fecha_pago'); // RN-24
            $table->timestamp('anulado_en')->nullable(); // RN-36
            $table->string('motivo_anulacion')->nullable();
            $table->timestamps();
        });

        Schema::create('abonos', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('negocio_id')->constrained('negocios');
            $table->foreignUuid('deudor_id')->constrained('deudores');
            $table->foreignUuid('pago_id')->nullable()->constrained('pagos'); // abono que llegó por Nequi (RN-39)
            $table->date('fecha');
            $table->unsignedBigInteger('valor');
            $table->string('nota')->nullable();
            $table->timestamp('anulado_en')->nullable(); // RN-36
            $table->string('motivo_anulacion')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('abonos');
        Schema::dropIfExists('fiados');
        Schema::dropIfExists('deudores');
        Schema::dropIfExists('pagos');
        Schema::dropIfExists('cierres');
    }
};
