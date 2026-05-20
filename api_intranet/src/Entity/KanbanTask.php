<?php

namespace App\Entity;

use App\Repository\KanbanTaskRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Annotation\Groups;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * @ORM\Entity(repositoryClass=KanbanTaskRepository::class)
 * @ORM\HasLifecycleCallbacks
 */
class KanbanTask
{
    const STATUS_BACKLOG = 'En espera';
    const STATUS_TODO = 'Por Hacer';
    const STATUS_IN_PROGRESS = 'En Progreso';
    const STATUS_COMPLETE = 'Completadas';

    const IMPORTANCE_LOW = 'baja';
    const IMPORTANCE_MEDIUM = 'mediana';
    const IMPORTANCE_HIGH = 'alta';

    /**
     * @ORM\Id
     * @ORM\GeneratedValue
     * @ORM\Column(type="integer")
     * @Groups({"kanban:read"})
     */
    private $id;

    /**
     * @ORM\Column(type="string", length=255)
     * @Assert\NotBlank(message="El título no puede estar vacío")
     * @Assert\Length(max=255, maxMessage="El título no puede superar los 255 caracteres")
     * @Groups({"kanban:read"})
     */
    private $title;

    /**
     * @ORM\Column(type="json")
     * @Groups({"kanban:read"})
     */
    private $category = [];

    /**
     * @ORM\Column(type="string", length=50)
     * @Assert\Choice(
     *     choices={KanbanTask::IMPORTANCE_LOW, KanbanTask::IMPORTANCE_MEDIUM, KanbanTask::IMPORTANCE_HIGH},
     *     message="Valor de importancia inválido"
     * )
     * @Groups({"kanban:read"})
     */
    private $importance;

    /**
     * @ORM\Column(type="string", length=50)
     * @Assert\Choice(
     *     choices={KanbanTask::STATUS_BACKLOG, KanbanTask::STATUS_TODO, KanbanTask::STATUS_IN_PROGRESS, KanbanTask::STATUS_COMPLETE},
     *     message="Valor de estado inválido"
     * )
     * @Groups({"kanban:read"})
     */
    private $status;

    /**
     * @ORM\Column(type="json", nullable=true)
     * @Groups({"kanban:read"})
     */
    private $subTasks = [];

    /**
     * @ORM\Column(type="text", nullable=true)
     * @Groups({"kanban:read"})
     */
    private $message;

    /**
     * @ORM\Column(type="datetime", nullable=true)
     * @Groups({"kanban:read"})
     */
    private $dueAt;

    /**
     * @ORM\Column(type="integer", options={"default": 0})
     * @Groups({"kanban:read"})
     */
    private $position = 0;

    /**
     * @ORM\Column(type="datetime")
     * @Groups({"kanban:read"})
     */
    private $createdAt;

    /**
     * @ORM\Column(type="datetime", nullable=true)
     * @Groups({"kanban:read"})
     */
    private $updatedAt;

    /**
     * @ORM\Column(type="datetime", nullable=true)
     * @Groups({"kanban:read"})
     */
    private $deletedAt;

    /**
     * @ORM\ManyToOne(targetEntity=User::class)
     * @ORM\JoinColumn(nullable=false)
     */
    private $owner;

    public function __construct()
    {
        $this->createdAt = new \DateTime();
        $this->status = self::STATUS_BACKLOG;
        $this->importance = self::IMPORTANCE_MEDIUM;
        $this->category = [];
        $this->position = 0;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTitle(): ?string
    {
        return $this->title;
    }

    public function setTitle(string $title): self
    {
        $this->title = $title;
        return $this;
    }

    public function getCategory(): ?array
    {
        return $this->category;
    }

    public function setCategory(array $category): self
    {
        $this->category = array_values(array_unique(array_filter(array_map('strval', $category))));
        return $this;
    }

    public function getImportance(): ?string
    {
        return $this->importance;
    }

    public function setImportance(string $importance): self
    {
        $this->importance = $importance;
        return $this;
    }

    public function getStatus(): ?string
    {
        return $this->status;
    }

    public function setStatus(string $status): self
    {
        $this->status = $status;
        return $this;
    }

    public function getSubTasks(): ?array
    {
        return $this->subTasks;
    }

    public function setSubTasks(?array $subTasks): self
    {
        $validated = [];
        if ($subTasks !== null) {
            foreach ($subTasks as $item) {
                if (is_array($item) && isset($item['title'])) {
                    $validated[] = [
                        'title' => (string)$item['title'],
                        'isCompleted' => isset($item['isCompleted']) ? (bool)$item['isCompleted'] : false
                    ];
                }
            }
        }
        $this->subTasks = $validated;
        return $this;
    }

    public function getMessage(): ?string
    {
        return $this->message;
    }

    public function setMessage(?string $message): self
    {
        $this->message = $message;
        return $this;
    }

    public function getDueAt(): ?\DateTimeInterface
    {
        return $this->dueAt;
    }

    public function setDueAt(?\DateTimeInterface $dueAt): self
    {
        $this->dueAt = $dueAt;
        return $this;
    }

    public function getPosition(): ?int
    {
        return $this->position;
    }

    public function setPosition(int $position): self
    {
        $this->position = $position;
        return $this;
    }

    public function getCreatedAt(): ?\DateTimeInterface
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeInterface $createdAt): self
    {
        $this->createdAt = $createdAt;
        return $this;
    }

    public function getUpdatedAt(): ?\DateTimeInterface
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(?\DateTimeInterface $updatedAt): self
    {
        $this->updatedAt = $updatedAt;
        return $this;
    }

    public function getOwner(): ?User
    {
        return $this->owner;
    }

    public function setOwner(?User $owner): self
    {
        $this->owner = $owner;
        return $this;
    }

    public function getDeletedAt(): ?\DateTimeInterface
    {
        return $this->deletedAt;
    }

    public function setDeletedAt(?\DateTimeInterface $deletedAt): self
    {
        $this->deletedAt = $deletedAt;
        return $this;
    }

    /**
     * @ORM\PreUpdate
     */
    public function updateTimestamp(): void
    {
        $this->updatedAt = new \DateTime();
    }
}
