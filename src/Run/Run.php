<?php

namespace Ernestdefoe\Phrasebook\Run;

use Carbon\Carbon;
use Flarum\Database\AbstractModel;

/**
 * One translation run: a locale, a provider and model, a budget, and the
 * strings it was given (Item rows).
 *
 * Status: queued → running ⇄ waiting (backing off after a rate limit) →
 * review (every string translated or given up on); or paused (budget
 * reached, or an error only the admin can fix), cancelled.
 *
 * @property int $id
 * @property string $locale
 * @property string $provider
 * @property string $model
 * @property string $status
 * @property string $budget
 * @property string $spent
 * @property int $input_tokens
 * @property int $output_tokens
 * @property int $characters
 * @property int $calls
 * @property int $failures
 * @property Carbon|null $next_attempt_at
 * @property Carbon|null $locked_until
 * @property string|null $provider_state
 * @property string|null $error
 * @property string|null $message
 * @property string $tone
 * @property string|null $notes
 * @property string|null $glossary
 * @property int|null $user_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $finished_at
 */
class Run extends AbstractModel
{
    public const ACTIVE = ['queued', 'running', 'waiting'];

    protected $table = 'phrasebook_runs';

    public $timestamps = true;

    protected $casts = [
        'next_attempt_at' => 'datetime',
        'locked_until' => 'datetime',
        'finished_at' => 'datetime',
        'input_tokens' => 'integer',
        'output_tokens' => 'integer',
        'characters' => 'integer',
        'calls' => 'integer',
        'failures' => 'integer',
    ];

    public function active(): bool
    {
        return in_array($this->status, self::ACTIVE, true);
    }

    /**
     * @return array<string, mixed>
     */
    public function state(): array
    {
        $state = json_decode((string) $this->provider_state, true);

        return is_array($state) ? $state : [];
    }
}
