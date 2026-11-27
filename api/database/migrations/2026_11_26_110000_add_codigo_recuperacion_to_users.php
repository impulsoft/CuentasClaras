<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Código de recuperación del admin (se guarda cifrado, como una contraseña) y cuándo lo creó
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('codigo_recuperacion')->nullable()->after('password');
            $table->timestamp('codigo_recuperacion_en')->nullable()->after('codigo_recuperacion');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['codigo_recuperacion', 'codigo_recuperacion_en']);
        });
    }
};
