<?php

namespace App\Models;

use Database\Factories\CustomEmojiFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

/**
 * @property string $id
 * @property string $team_id
 * @property string|null $created_by
 * @property string $name
 * @property string $path
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read string $url
 * @property-read Team $team
 * @property-read User|null $creator
 */
#[Fillable(['team_id', 'created_by', 'name', 'path'])]
class CustomEmoji extends Model
{
    /** @use HasFactory<CustomEmojiFactory> */
    use HasFactory, HasUuids;

    /**
     * The table backing the model. Set explicitly because Laravel's inflector
     * treats "emoji" as uncountable, so it would otherwise resolve the singular
     * `custom_emoji` and miss the `custom_emojis` table the migration creates.
     *
     * @var string
     */
    protected $table = 'custom_emojis';

    /**
     * The filesystem disk emoji images live on. Routed through Laravel's disk
     * abstraction so pointing this at an S3 bucket (either by switching the disk
     * or repointing the `public` disk's driver) keeps upload, URL, and deletion
     * working unchanged — the driver's `url()` returns the bucket URL.
     */
    public const string DISK = 'public';

    /**
     * Get the team this emoji belongs to.
     *
     * @return BelongsTo<Team, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * Get the member who uploaded this emoji, if they are still around.
     *
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * The public URL of the emoji image on the configured disk.
     *
     * @return Attribute<string, never>
     */
    protected function url(): Attribute
    {
        return Attribute::get(fn (): string => Storage::disk(self::DISK)->url($this->path));
    }
}
