<?php

namespace App\Models;

use Database\Factories\ActivityPhotoFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/**
 * A photo from an activity, usually from one visit. The file is private and is shown through short-lived links.
 */
#[Fillable(['activity_id', 'event_id', 'path', 'caption'])]
class ActivityPhoto extends Model
{
    /** @use HasFactory<ActivityPhotoFactory> */
    use HasFactory;

    /**
     * The file goes with the photo.
     */
    protected static function booted(): void
    {
        static::deleted(fn (ActivityPhoto $photo) => Storage::disk(static::disk())->delete($photo->path));
    }

    /**
     * The disk photos are kept on.
     */
    public static function disk(): string
    {
        return config('sunshine.photos.disk');
    }

    /**
     * @return BelongsTo<Activity, $this>
     */
    public function activity(): BelongsTo
    {
        return $this->belongsTo(Activity::class);
    }

    /**
     * The visit it was taken on.
     *
     * @return BelongsTo<Event, $this>
     */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    /**
     * A link to the full photo that works for half an hour.
     */
    public function url(): string
    {
        return Storage::disk(static::disk())->temporaryUrl($this->path, now()->addMinutes(30));
    }
}
