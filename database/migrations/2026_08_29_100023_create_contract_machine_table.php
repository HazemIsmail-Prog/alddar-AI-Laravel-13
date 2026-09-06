<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contract_machine', function (Blueprint $table) {
            $table->foreignId('contract_id')->constrained()->cascadeOnDelete();
            $table->foreignId('machine_id')->constrained('ac_machines')->cascadeOnDelete();
            $table->primary(['contract_id', 'machine_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contract_machine');
    }
};
