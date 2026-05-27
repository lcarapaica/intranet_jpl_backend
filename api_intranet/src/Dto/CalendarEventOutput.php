<?php

namespace App\Dto;

use App\Entity\CalendarEvent;

class CalendarEventOutput
{
    /**
     * Converts a CalendarEvent entity into a clean, sanitized associative array for API responses.
     */
    public static function fromEntity(CalendarEvent $event): array
    {
        return [
            'id'            => $event->getId(),
            'title'         => $event->getTitle(),
            'description'   => $event->getDescription(),
            'startAt'       => $event->getStartAt() ? $event->getStartAt()->format('Y-m-d H:i:s') : null,
            'endAt'         => $event->getEndAt() ? $event->getEndAt()->format('Y-m-d H:i:s') : null,
            'tags'          => $event->getTags(),
            'isCompanyWide' => $event->getIsCompanyWide(),
            'isActive'      => $event->isActive(),
            'reminderAt'    => $event->getReminderAt() ? $event->getReminderAt()->format('Y-m-d H:i:s') : null,
            'owner'         => $event->getOwner() ? [
                'id'   => $event->getOwner()->getId(),
                'name' => $event->getOwner()->getDisplayName()
            ] : null
        ];
    }
}
