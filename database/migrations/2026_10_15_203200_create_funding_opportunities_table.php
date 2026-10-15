<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('funding_opportunities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('title', 120);
            $table->string('provider', 120);
            $table->decimal('potential_amount', 12, 2)->nullable();
            $table->char('currency', 3);
            $table->date('deadline')->nullable();
            $table->string('status', 24)->default('researching');
            $table->string('source_url', 2048)->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
            $table->index(['user_id', 'deadline']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('funding_opportunities');
    }
};
