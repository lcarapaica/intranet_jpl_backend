<?php

namespace App\Dto;

use App\Entity\CalendarEvent;

class CalendarEventOutput
{
    /**
     * Converts a CalendarEvent entity into a clean, sanitized associative array for API responses.
     */
    public static function fromEntity(CalendarEvent $event, bool $includeDeletedAt = false): array
    {
        $data = [
            'id'            => $event->getId(),
            'title'         => $event->getTitle(),
            'description'   => $event->getDescription(),
            'place'         => $event->getPlace(),
            'date'          => $event->getDate() ? $event->getDate()->format('Y-m-d') : null,
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

        if ($includeDeletedAt) {
            $data['deletedAt'] = $event->getDeletedAt() ? $event->getDeletedAt()->format('Y-m-d H:i:s') : null;
        }

        return $data;
    }
}
