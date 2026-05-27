<?php

namespace App\Dto;

use App\Entity\News;

class NewsInput
{
    public ?string $title = null;
    public ?string $body = null;
    public ?string $category = null;

    public static function fromArray(array $data): self
    {
        $dto = new self();
        $dto->title = $data['title'] ?? null;
        $dto->body = $data['body'] ?? null;
        $dto->category = $data['category'] ?? null;
        return $dto;
    }

    public function updateEntity(News $news, array $providedFields = []): void
    {
        if (in_array('title', $providedFields)) {
            $news->setTitle($this->title ?? '');
        }
        if (in_array('body', $providedFields)) {
            $news->setBody($this->body ?? '');
        }
        if (in_array('category', $providedFields)) {
            $news->setCategory($this->category);
        }
    }
}
