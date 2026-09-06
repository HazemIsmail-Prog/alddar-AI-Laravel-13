<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contracts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->foreignId('location_id')->constrained('client_locations');
            $table->foreignId('department_id')->constrained();
            $table->string('type'); // warranty, annual
            $table->boolean('includes_spare_parts')->default(false);
            $table->boolean('includes_compressor_warranty')->default(false);
            $table->date('compressor_warranty_start')->nullable();
            $table->date('compressor_warranty_end')->nullable();
            $table->date('start_date');
            $table->date('end_date');
            $table->unsignedInteger('total_amount')->default(0);
            $table->unsignedInteger('payment_count')->default(0);
            $table->unsignedInteger('planned_visits')->default(0);
            $table->string('status')->default('active');
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contracts');
    }
};
