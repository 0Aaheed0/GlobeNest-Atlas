<?php

namespace App\Models;

use Database\Factories\FundingOpportunityFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['user_id', 'title', 'provider', 'potential_amount', 'currency', 'deadline', 'status', 'source_url'])]
class FundingOpportunity extends Model
{
    /** @use HasFactory<FundingOpportunityFactory> */
    use HasFactory;

    public const STATUSES = ['researching', 'preparing', 'submitted', 'awarded', 'not_awarded'];

    protected function casts(): array
    {
        return [
            'potential_amount' => 'decimal:2',
            'deadline' => 'date',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
