<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('journal_entries', function (Blueprint $table) {
            $table->id();
            $table->date('date');
            $table->string('description')->nullable();
            $table->string('source_type');
            $table->unsignedBigInteger('source_id');
            $table->string('event_key');
            $table->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();
            $table->unique(['source_type', 'source_id', 'event_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('journal_entries');
    }
};
