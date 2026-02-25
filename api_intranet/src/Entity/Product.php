<?php

namespace App\Entity;

use App\Repository\ProductRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * @ORM\Entity(repositoryClass=ProductRepository::class)
 */
class Product
{
    /**
     * @ORM\Id
     * @ORM\GeneratedValue
     * @ORM\Column(type="integer")
     */
    private $id;

    /**
     * @ORM\Column(type="string", length=255)
     * @Assert\NotBlank(message="El nombre es obligatorio")
     */
    private $nombre;

    /**
     * @ORM\Column(type="string", length=255)
     */
    private $categoria;

    /**
     * @ORM\Column(type="string", length=255)
     */
    private $marca;

    /**
     * @ORM\Column(type="string", length=255)
     */
    private $modelo;

    /**
     * @ORM\Column(type="text", nullable=true)
     */
    private $caracteristicas;

    /**
     * @ORM\Column(type="string", length=100, nullable=true)
     */
    private $color;

    /**
     * @ORM\Column(type="string", length=150, nullable=true)
     */
    private $serial;

    /**
     * @ORM\Column(type="string", length=50)
     * @Assert\NotBlank(message="La condición es obligatoria")
     */
    private $condicion;

    /**
     * @ORM\Column(type="string", length=255, nullable=true)
     */
    private $locacion;

    /**
     * @ORM\Column(type="datetime", nullable=true)
     */
    private $deletedAt;

    // --- GETTERS Y SETTERS ---

    public function getId(): ?int { return $this->id; }

    public function getNombre(): ?string { return $this->nombre; }
    public function setNombre(string $nombre): self { $this->nombre = $nombre; return $this; }

    public function getCategoria(): ?string { return $this->categoria; }
    public function setCategoria(string $categoria): self { $this->categoria = $categoria; return $this; }

    public function getMarca(): ?string { return $this->marca; }
    public function setMarca(string $marca): self { $this->marca = $marca; return $this; }

    public function getModelo(): ?string { return $this->modelo; }
    public function setModelo(string $modelo): self { $this->modelo = $modelo; return $this; }

    public function getCaracteristicas(): ?string { return $this->caracteristicas; }
    public function setCaracteristicas(?string $caracteristicas): self { $this->caracteristicas = $caracteristicas; return $this; }

    public function getColor(): ?string { return $this->color; }
    public function setColor(?string $color): self { $this->color = $color; return $this; }

    public function getSerial(): ?string { return $this->serial; }
    public function setSerial(?string $serial): self { $this->serial = $serial; return $this; }

    public function getCondicion(): ?string { return $this->condicion; }
    public function setCondicion(string $condicion): self { 
        $this->condicion = $condicion; 
        return $this; 
    }

    public function getLocacion(): ?string { return $this->locacion; }
    public function setLocacion(?string $locacion): self { $this->locacion = $locacion; return $this; }

    public function getDeletedAt(): ?\DateTimeInterface { return $this->deletedAt; }
    public function setDeletedAt(?\DateTimeInterface $deletedAt): self { $this->deletedAt = $deletedAt; return $this; }
}
