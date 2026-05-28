<?php

namespace App\Dto;

use App\Entity\CalendarEvent;

class CalendarEventInput
{
    public ?string $title = null;
    public ?string $description = null;
    public ?string $place = null;
    public ?string $date = null;
    public ?string $startAt = null;
    public ?string $endAt = null;
    public ?array $tags = null;
    public ?bool $isCompanyWide = null;
    public ?string $reminderAt = null;

    public static function fromArray(array $data): self
    {
        $dto = new self();
        $dto->title = isset($data['title']) && is_scalar($data['title']) ? (string)$data['title'] : null;
        $dto->description = isset($data['description']) && is_scalar($data['description']) ? (string)$data['description'] : null;
        $dto->place = isset($data['place']) && is_scalar($data['place']) ? (string)$data['place'] : null;
        $dto->date = isset($data['date']) && is_scalar($data['date']) ? (string)$data['date'] : null;
        $dto->startAt = isset($data['startAt']) && is_scalar($data['startAt']) ? (string)$data['startAt'] : null;
        $dto->endAt = isset($data['endAt']) && is_scalar($data['endAt']) ? (string)$data['endAt'] : null;
        $dto->tags = isset($data['tags']) && is_array($data['tags']) ? $data['tags'] : null;
        $dto->isCompanyWide = isset($data['isCompanyWide']) ? (bool)$data['isCompanyWide'] : null;
        $dto->reminderAt = isset($data['reminderAt']) && is_scalar($data['reminderAt']) ? (string)$data['reminderAt'] : null;
        return $dto;
    }

    public function updateEntity(CalendarEvent $event, array $providedFields = []): void
    {
        if (in_array('title', $providedFields)) {
            $event->setTitle($this->title ?? '');
        }
        if (in_array('description', $providedFields)) {
            $event->setDescription($this->description);
        }
        if (in_array('place', $providedFields)) {
            $event->setPlace($this->place);
        }
        if (in_array('date', $providedFields)) {
            if ($this->date === null || trim($this->date) === '') {
                $event->setDate(null);
            } else {
                $event->setDate(new \DateTime($this->date));
            }
        }
        if (in_array('startAt', $providedFields)) {
            if ($this->startAt === null) {
                $event->setStartAt(null);
            } else {
                $start = new \DateTime($this->startAt);
                if ($event->getDate()) {
                    $timeStart = new \DateTime($event->getDate()->format('Y-m-d') . ' ' . $start->format('H:i:s'));
                    $event->setStartAt($timeStart);
                } else {
                    $event->setStartAt($start);
                }
            }
        }
        if (in_array('endAt', $providedFields)) {
            if ($this->endAt === null) {
                $event->setEndAt(null);
            } else {
                $end = new \DateTime($this->endAt);
                if ($event->getDate()) {
                    $timeEnd = new \DateTime($event->getDate()->format('Y-m-d') . ' ' . $end->format('H:i:s'));
                    $event->setEndAt($timeEnd);
                } else {
                    $event->setEndAt($end);
                }
            }
        }
        if (in_array('tags', $providedFields) && $this->tags !== null) {
            $event->setTags($this->tags);
        }
        if (in_array('isCompanyWide', $providedFields) && $this->isCompanyWide !== null) {
            $event->setIsCompanyWide($this->isCompanyWide);
        }
        if (in_array('reminderAt', $providedFields)) {
            $event->setReminderAt($this->reminderAt ? new \DateTime($this->reminderAt) : null);
        }
    }
}
