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
        Schema::create('transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 20);
            $table->string('category', 60);
            $table->string('description', 160);
            $table->decimal('amount', 14, 2);
            $table->char('currency', 3);
            $table->date('occurred_on');
            $table->boolean('is_simulated')->default(false);
            $table->timestamps();

            $table->index(['user_id', 'occurred_on']);
            $table->index(['user_id', 'kind', 'category']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('transactions');
    }
};
