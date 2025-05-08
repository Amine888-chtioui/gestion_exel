// database/migrations/2023_05_08_create_production_stops_table.php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('production_stops', function (Blueprint $table) {
            $table->id();
            $table->date('from_date');
            $table->date('to_date');
            $table->string('mo_key')->nullable(); // Machine/Maintenance Object key
            $table->string('ws_key')->nullable(); // Workstation key
            $table->string('stop_t')->nullable(); // Stop type
            $table->string('wo_key')->nullable(); // Work order key
            $table->string('wo_name')->nullable(); // Work order name
            $table->string('code1_key')->nullable(); // Code1 (category) - Mechanical, Electrical, etc.
            $table->string('code2_key')->nullable(); // Code2 (reason) - Wear, Breakage, etc.
            $table->string('code3_key')->nullable(); // Code3 (component) - specific part
            $table->string('machine_name')->nullable(); // ALPHA 63, ALPHA 19, etc.
            $table->float('stop_duration')->nullable(); // Duration of the stop
            $table->string('machine_group')->nullable(); // Komax Alpha 355, etc.
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('production_stops');
    }
};