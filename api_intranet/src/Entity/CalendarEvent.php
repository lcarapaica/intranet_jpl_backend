<?php

namespace App\Entity;

use App\Repository\CalendarEventRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Annotation\Groups;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * @ORM\Entity(repositoryClass=CalendarEventRepository::class)
 * @ORM\Table(name="calendar_event", indexes={
 *     @ORM\Index(name="idx_calendar_event_deleted_at", columns={"deleted_at"}),
 *     @ORM\Index(name="idx_calendar_event_date", columns={"date"})
 * })
 * @ORM\HasLifecycleCallbacks
 */
class CalendarEvent
{
    /**
     * @ORM\Id
     * @ORM\GeneratedValue
     * @ORM\Column(type="integer")
     * @Groups({"calendar:read"})
     */
    private $id;

    /**
     * @ORM\Column(type="string", length=255)
     * @Assert\NotBlank(message="El título no puede estar vacío")
     * @Assert\Length(max=255, maxMessage="El título no puede superar los 255 caracteres")
     * @Groups({"calendar:read"})
     */
    private $title;

    /**
     * @ORM\Column(type="text", nullable=true)
     * @Groups({"calendar:read"})
     */
    private $description;

    /**
     * @ORM\Column(type="string", length=255, nullable=true)
     * @Assert\Length(max=255, maxMessage="El lugar no puede superar los 255 caracteres")
     * @Groups({"calendar:read"})
     */
    private $place;


    /**
     * @ORM\Column(type="date")
     * @Assert\NotBlank(message="La fecha del evento es obligatoria")
     * @Groups({"calendar:read"})
     */
    private $date;

    /**
     * @ORM\Column(type="datetime", nullable=true)
     * @Groups({"calendar:read"})
     */
    private $startAt;

    /**
     * @ORM\Column(type="datetime", nullable=true)
     * @Groups({"calendar:read"})
     */
    private $endAt;

    /**
     * @ORM\Column(type="json")
     * @Groups({"calendar:read"})
     */
    private $tags = [];

    /**
     * @ORM\Column(type="boolean")
     * @Groups({"calendar:read"})
     */
    private $isCompanyWide = false;

    /**
     * @ORM\Column(type="string", length=255, nullable=true)
     * @Assert\Length(max=255, maxMessage="El cliente no puede superar los 255 caracteres")
     * @Groups({"calendar:read"})
     */
    private $cliente;

    /**
     * @ORM\Column(type="string", length=7, nullable=true)
     * @Assert\Regex(
     *     pattern="/^#([A-Fa-f0-9]{6}|[A-Fa-f0-9]{3})$/",
     *     message="El color debe tener un formato hexadecimal válido (ej: #FFFFFF)"
     * )
     * @Groups({"calendar:read"})
     */
    private $color;

    /**
     * @ORM\ManyToMany(targetEntity=User::class)
     * @ORM\JoinTable(name="calendar_event_user",
     *      joinColumns={@ORM\JoinColumn(name="calendar_event_id", referencedColumnName="id", onDelete="CASCADE")},
     *      inverseJoinColumns={@ORM\JoinColumn(name="user_id", referencedColumnName="id", onDelete="CASCADE")}
     * )
     * @Groups({"calendar:read"})
     */
    private $participants;

    /**
     * @ORM\ManyToOne(targetEntity=User::class)
     * @ORM\JoinColumn(nullable=true, onDelete="CASCADE")
     * @Groups({"calendar:read"})
     */
    private $owner;

    /**
     * @ORM\Column(type="datetime", nullable=true)
     * @Groups({"calendar:read"})
     */
    private $reminderAt;

    /**
     * @ORM\Column(type="datetime", nullable=true)
     * @Groups({"calendar:read"})
     */
    private $deletedAt;

    /**
     * @ORM\Column(type="datetime")
     * @Groups({"calendar:read"})
     */
    private $createdAt;

    /**
     * @ORM\Column(type="datetime")
     * @Groups({"calendar:read"})
     */
    private $updatedAt;

    public function __construct()
    {
        $this->createdAt = new \DateTime();
        $this->updatedAt = new \DateTime();
        $this->tags = [];
        $this->participants = new ArrayCollection();
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

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): self
    {
        $this->description = $description;
        return $this;
    }

    public function getPlace(): ?string
    {
        return $this->place;
    }

    public function setPlace(?string $place): self
    {
        $this->place = $place;
        return $this;
    }

    public function getDate(): ?\DateTimeInterface
    {
        return $this->date;
    }

    public function setDate(?\DateTimeInterface $date): self
    {
        $this->date = $date;
        return $this;
    }


    public function getStartAt(): ?\DateTimeInterface
    {
        return $this->startAt;
    }

    public function setStartAt(?\DateTimeInterface $startAt): self
    {
        $this->startAt = $startAt;
        return $this;
    }

    public function getEndAt(): ?\DateTimeInterface
    {
        return $this->endAt;
    }

    public function setEndAt(?\DateTimeInterface $endAt): self
    {
        $this->endAt = $endAt;
        return $this;
    }

    public function getTags(): array
    {
        return $this->tags ?: [];
    }

    public function setTags(array $tags): self
    {
        $this->tags = array_values(array_unique(array_filter(array_map('trim', $tags))));
        return $this;
    }

    public function getIsCompanyWide(): ?bool
    {
        return $this->isCompanyWide;
    }

    public function setIsCompanyWide(bool $isCompanyWide): self
    {
        $this->isCompanyWide = $isCompanyWide;
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

    public function getReminderAt(): ?\DateTimeInterface
    {
        return $this->reminderAt;
    }

    public function setReminderAt(?\DateTimeInterface $reminderAt): self
    {
        $this->reminderAt = $reminderAt;
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

    public function setUpdatedAt(\DateTimeInterface $updatedAt): self
    {
        $this->updatedAt = $updatedAt;
        return $this;
    }

    /**
     * @ORM\PreUpdate
     */
    public function updateTimestamp(): void
    {
        $this->updatedAt = new \DateTime();
    }

    public function getCliente(): ?string
    {
        return $this->cliente;
    }

    public function setCliente(?string $cliente): self
    {
        $this->cliente = $cliente;
        return $this;
    }

    public function getColor(): ?string
    {
        return $this->color;
    }

    public function setColor(?string $color): self
    {
        $this->color = $color;
        return $this;
    }

    /**
     * @return Collection|User[]
     */
    public function getParticipants(): Collection
    {
        return $this->participants ?: new ArrayCollection();
    }

    public function addParticipant(User $participant): self
    {
        if (!$this->getParticipants()->contains($participant)) {
            $this->participants[] = $participant;
        }
        return $this;
    }

    public function removeParticipant(User $participant): self
    {
        $this->getParticipants()->removeElement($participant);
        return $this;
    }

    public function isActive(): bool
    {
        return $this->deletedAt === null;
    }
}
