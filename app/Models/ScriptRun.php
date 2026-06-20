<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ScriptRun extends Model
{
    protected $fillable = [
        'filename',
        'status',
        'output',
        'error',
        'duration_ms',
        'ran_at',
        'approval_status',
        'moved_to',
        'approved_at',
    ];

    protected $casts = [
        'ran_at' => 'datetime',
        'approved_at' => 'datetime',
        'duration_ms' => 'integer',
    ];

    public function succeeded(): bool
    {
        return $this->status === 'success';
    }

    public function isPendingApproval(): bool
    {
        return $this->approval_status === 'pending';
    }
}
