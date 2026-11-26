<?php

namespace App\Models;

use Database\Factories\ActivityNoteFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Something worth knowing about an activity every time it comes up, such as "get there before 9, parking fills up".
 * Unlike a visit's retrospective, it isn't tied to one date.
 */
#[Fillable(['activity_id', 'body'])]
class ActivityNote extends Model
{
    /** @use HasFactory<ActivityNoteFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<Activity, $this>
     */
    public function activity(): BelongsTo
    {
        return $this->belongsTo(Activity::class);
    }
}
