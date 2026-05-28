<?php

namespace App\Entity;

use App\Repository\NewsRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Annotation\Groups;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * @ORM\Entity(repositoryClass=NewsRepository::class)
 * @ORM\Table(name="news", indexes={
 *     @ORM\Index(name="idx_news_deleted_at", columns={"deleted_at"})
 * })
 * @ORM\HasLifecycleCallbacks
 */
class News
{
    /**
     * @ORM\Id
     * @ORM\GeneratedValue
     * @ORM\Column(type="integer")
     * @Groups({"news:read"})
     */
    private $id;

    /**
     * @ORM\Column(type="string", length=255)
     * @Assert\NotBlank(message="El título no puede estar vacío")
     * @Assert\Length(max=255, maxMessage="El título no puede superar los 255 caracteres")
     * @Groups({"news:read"})
     */
    private $title;

    /**
     * @ORM\Column(type="text")
     * @Assert\NotBlank(message="El cuerpo de la noticia no puede estar vacío")
     * @Groups({"news:read"})
     */
    private $body;

    /**
     * @ORM\Column(type="string", length=100, nullable=true)
     * @Assert\Length(max=100, maxMessage="La categoría no puede superar los 100 caracteres")
     * @Groups({"news:read"})
     */
    private $category;

    /**
     * @ORM\Column(type="datetime")
     * @Groups({"news:read"})
     */
    private $postedAt;

    /**
     * @ORM\Column(type="datetime", nullable=true)
     * @Groups({"news:read"})
     */
    private $updatedAt;

    /**
     * @ORM\Column(type="datetime", nullable=true)
     * @Groups({"news:read"})
     */
    private $deletedAt;

    /**
     * @ORM\ManyToOne(targetEntity=User::class)
     * @ORM\JoinColumn(nullable=false)
     * @Groups({"news:read"})
     */
    private $author;

    public function __construct()
    {
        $this->postedAt = new \DateTime();
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

    public function getBody(): ?string
    {
        return $this->body;
    }

    public function setBody(string $body): self
    {
        $this->body = $body;
        return $this;
    }

    public function getCategory(): ?string
    {
        return $this->category;
    }

    public function setCategory(?string $category): self
    {
        $this->category = $category;
        return $this;
    }

    public function getPostedAt(): ?\DateTimeInterface
    {
        return $this->postedAt;
    }

    public function setPostedAt(\DateTimeInterface $postedAt): self
    {
        $this->postedAt = $postedAt;
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

    public function getDeletedAt(): ?\DateTimeInterface
    {
        return $this->deletedAt;
    }

    public function setDeletedAt(?\DateTimeInterface $deletedAt): self
    {
        $this->deletedAt = $deletedAt;
        return $this;
    }

    public function getAuthor(): ?User
    {
        return $this->author;
    }

    public function setAuthor(?User $author): self
    {
        $this->author = $author;
        return $this;
    }

    /**
     * @ORM\PreUpdate
     */
    public function updateTimestamp(): void
    {
        $this->updatedAt = new \DateTime();
    }

    public function isActive(): bool
    {
        return $this->deletedAt === null;
    }
}
