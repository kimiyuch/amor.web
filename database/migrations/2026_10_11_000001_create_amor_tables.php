<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settings', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->json('value')->nullable();
        });

        Schema::create('shift_codes', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('title')->nullable();
            $table->string('start', 5)->nullable(); // HH:MM
            $table->string('end', 5)->nullable();
            $table->boolean('skip')->default(false);
            $table->timestamps();
        });

        // One photographed roster and what Claude read from it.
        Schema::create('scans', function (Blueprint $table) {
            $table->id();
            $table->string('status')->default('queued'); // queued, reading, read, failed, saved
            $table->string('status_text')->nullable();
            $table->string('person');
            $table->string('photo_path')->nullable(); // deleted once read
            $table->string('strip_path')->nullable(); // crop of the person's row
            $table->json('result')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();
        });

        Schema::create('shifts', function (Blueprint $table) {
            $table->id();
            $table->date('date')->unique();
            $table->string('code');
            $table->foreignId('scan_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
        });

        $codes = ['1', '1T', '1E', '1P', 'M1', 'M3', '6A', '8B', '9A', '9B', '2S', '3F', 'S1/BD', 'B', 'HO', 'EI', 'DI'];
        DB::table('shift_codes')->insert(array_map(
            fn ($code) => ['code' => $code, 'created_at' => now(), 'updated_at' => now()],
            $codes,
        ));
    }

    public function down(): void
    {
        Schema::dropIfExists('shifts');
        Schema::dropIfExists('scans');
        Schema::dropIfExists('shift_codes');
        Schema::dropIfExists('settings');
    }
};
