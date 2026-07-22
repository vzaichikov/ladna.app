<?php

namespace App\Support\Telegram\Announcements;

use App\Enums\SystemRole;
use App\Models\User;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;
use InvalidArgumentException;

final readonly class StudioOwnerAnnouncementExecutionGuard
{
    public function __construct(
        private Application $application,
        private Request $request,
    ) {}

    /**
     * @return array{origin: 'codex_skill'|'platform_owner', platform_user_id: int|null}
     */
    public function authorize(): array
    {
        if (
            ! in_array(PHP_SAPI, ['cli', 'phpdbg'], true)
            || ! $this->application->runningInConsole()
            || $this->request->route() !== null
        ) {
            throw new InvalidArgumentException('Studio-owner announcements are available only from a trusted CLI process.');
        }

        $origin = trim((string) getenv('LADNA_OWNER_ANNOUNCEMENT_ORIGIN'));

        if ($origin === 'codex_skill') {
            return [
                'origin' => 'codex_skill',
                'platform_user_id' => null,
            ];
        }

        if ($origin !== 'platform_owner') {
            throw new InvalidArgumentException('Studio-owner announcements require the Codex skill or a verified platform owner.');
        }

        $platformUserId = filter_var(
            getenv('LADNA_OWNER_ANNOUNCEMENT_PLATFORM_USER_ID'),
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]],
        );

        if (! is_int($platformUserId)) {
            throw new InvalidArgumentException('A valid platform owner user ID is required.');
        }

        $isPlatformOwner = User::query()
            ->whereKey($platformUserId)
            ->where('system_role', SystemRole::PlatformAdmin->value)
            ->exists();

        if (! $isPlatformOwner) {
            throw new InvalidArgumentException('The configured platform owner user ID is not a platform administrator.');
        }

        return [
            'origin' => 'platform_owner',
            'platform_user_id' => $platformUserId,
        ];
    }
}
