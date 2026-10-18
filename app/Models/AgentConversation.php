<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['user_id', 'title', 'task_context'])]
class AgentConversation extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'task_context' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(AgentMessage::class, 'conversation_id');
    }

    public function pendingActions(): HasMany
    {
        return $this->hasMany(AgentPendingAction::class, 'conversation_id');
    }
}
