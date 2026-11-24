<?php

namespace App\Dav\Helpers;

use Sabre\VObject\Component\VEvent;

class VEventHelper
{
    // All-day slots are floating dates, so only the calendar day counts; timed slots are compared as instants.
    public static function isSameSlot(\DateTimeInterface $date, \DateTimeInterface $slot, bool $isAllDay): bool
    {
        return $isAllDay
            ? $date->format('Ymd') === $slot->format('Ymd')
            : $date->getTimestamp() === $slot->getTimestamp();
    }

    // All-day values are floating dates; a timed one is written in $tz, which can differ from the date's own zone
    // (an occurrence keeps its series' zone while the request's dates are in the user's).
    public static function addDateProperty(VEvent $event, string $name, \DateTimeInterface $date, bool $isAllDay, \DateTimeZone $tz): void
    {
        if ($isAllDay) {
            $event->add($name, $date->format('Ymd'), ['VALUE' => 'DATE']);

            return;
        }

        $dateInTz = \DateTime::createFromInterface($date)->setTimezone($tz);
        $event->add($name, $dateInTz->format('Ymd\THis'), ['TZID' => $tz->getName()]);
    }

    // DURATION goes too: a new DTEND next to an old DURATION is invalid.
    public static function removeDates(VEvent $event): void
    {
        $event->remove('DTSTART');
        $event->remove('DTEND');
        $event->remove('DURATION');
    }

    public static function setDescriptionAndLocation(VEvent $event, ?string $description, ?string $location): void
    {
        foreach (['DESCRIPTION' => $description, 'LOCATION' => $location] as $name => $value) {
            if ($value) {
                $event->{$name} = $value;
            } else {
                $event->remove($name);
            }
        }
    }
}
