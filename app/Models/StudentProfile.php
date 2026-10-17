<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['user_id', 'destination_country', 'home_currency', 'monthly_budget', 'monthly_income', 'financial_goals', 'memory_consent'])]
class StudentProfile extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'monthly_budget' => 'decimal:2',
            'monthly_income' => 'decimal:2',
            'memory_consent' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
