<?php

namespace App\Dav\Services;

use App\Api\Users\Models\User;
use App\Dav\Backend\AppCalendarsPDO;
use App\Dav\DTOs\CalendarDTO;
use App\Dav\Helpers\DavHelper;
use Illuminate\Support\Collection;

class CalendarSharing
{
    private readonly AppCalendarsPDO $backend;

    public function __construct(AppCalendarsPDO $backend)
    {
        $this->backend = $backend;
    }

    public function listInvites(User $user): Collection
    {
        $principalUri = DavHelper::getPrincipalUri($user);
        $calendars = $this->backend->listCalendarsCustom($principalUri);

        return collect($calendars)->filter(function (CalendarDTO $calendar) {
            return $calendar->inviteStatus === 'pending';
        });
    }

    /**
     * Invite a user to a calendar (read-write access by default)
     */
    public function inviteUser(CalendarDTO $calendar, User $owner, User $recipient): void
    {
        $this->backend->shareCalendarWithUserCustom(
            $owner,
            $recipient,
            $calendar,
            true
        );
    }

    /**
     * Accept the user's pending invite and return its calendar id; null if
     * they have none with this token
     */
    public function acceptInvite(User $user, string $shareToken): ?int
    {
        return $this->backend->acceptShare(DavHelper::getPrincipalUri($user), $shareToken);
    }

    /**
     * Decline the user's pending invite; false if they have none with this token
     */
    public function declineInvite(User $user, string $shareToken): bool
    {
        return $this->backend->declineShare(DavHelper::getPrincipalUri($user), $shareToken);
    }

    /**
     * Revoke a share from a recipient
     */
    public function revokeShare(int $calendarId, User $recipient): void
    {
        $this->backend->unshareCalendar(
            $calendarId,
            $recipient->email
        );
    }

    /**
     * List accepted shares for a user
     */
    public function listAcceptedShares(User $user): Collection
    {
        $principalUri = DavHelper::getPrincipalUri($user);
        $calendars = $this->backend->listCalendarsCustom($principalUri);

        return collect($calendars)->filter(function (CalendarDTO $calendar) {
            return $calendar->inviteStatus === 'accepted';
        });
    }

    /**
     * User ids of the owner and the accepted sharees of a calendar
     *
     * @return array<int, string>
     */
    public function listMemberIds(int $calendarId): array
    {
        return $this->backend->getCalendarMemberIds($calendarId);
    }

    public function ownerId(int $calendarId): ?string
    {
        return $this->backend->getCalendarOwnerId($calendarId);
    }

    /**
     * List users a calendar is shared with
     */
    public function listSharees(CalendarDTO $calendar): array
    {
        return $this->backend->getCalendarSharees($calendar->calendarId);
    }
}
