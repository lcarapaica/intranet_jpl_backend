<?php

namespace App\Dto;

use App\Entity\Product;

class ProductInput
{
    public ?int $id = null;
    public ?string $nombre = null;
    public ?string $categoria = null;
    public ?string $marca = null;
    public ?string $modelo = null;
    public ?string $caracteristicas = null;
    public ?string $color = null;
    public ?string $serial = null;
    public ?string $condicion = null;
    public ?string $locacion = null;
    public ?int $cantidad = null;
    public ?string $empresa = null;
    public ?string $registeredAt = null;

    // Populates DTO from request payload array
    public static function fromArray(array $data): self
    {
        $dto = new self();
        $dto->id = isset($data['id']) ? (int)$data['id'] : null;
        $dto->nombre = $data['nombre'] ?? null;
        $dto->categoria = $data['categoria'] ?? null;
        $dto->marca = $data['marca'] ?? null;
        $dto->modelo = $data['modelo'] ?? null;
        $dto->caracteristicas = $data['caracteristicas'] ?? null;
        $dto->color = $data['color'] ?? null;
        $dto->serial = $data['serial'] ?? null;
        $dto->condicion = $data['condicion'] ?? null;
        $dto->locacion = $data['locacion'] ?? null;
        $dto->cantidad = isset($data['cantidad']) ? (int)$data['cantidad'] : null;
        $dto->empresa = $data['empresa'] ?? null;
        $dto->registeredAt = $data['registeredAt'] ?? null;
        return $dto;
    }

    // Maps non-null values to Product Entity for persist or update
    public function updateEntity(Product $product, array $providedFields = []): void
    {
        if (in_array('nombre', $providedFields)) {
            $product->setNombre($this->nombre ?? '');
        }
        if (in_array('categoria', $providedFields)) {
            $product->setCategoria($this->categoria ?? '');
        }
        if (in_array('marca', $providedFields)) {
            $product->setMarca($this->marca ?? '');
        }
        if (in_array('modelo', $providedFields)) {
            $product->setModelo($this->modelo ?? '');
        }
        if (in_array('caracteristicas', $providedFields)) {
            $product->setCaracteristicas($this->caracteristicas);
        }
        if (in_array('color', $providedFields)) {
            $product->setColor($this->color);
        }
        if (in_array('serial', $providedFields)) {
            $product->setSerial($this->serial);
        }
        if (in_array('condicion', $providedFields)) {
            $product->setCondicion($this->condicion ?? '');
        }
        if (in_array('locacion', $providedFields)) {
            $product->setLocacion($this->locacion ?? '');
        }
        if (in_array('cantidad', $providedFields)) {
            $product->setCantidad($this->cantidad);
        }
        if (in_array('empresa', $providedFields)) {
            $product->setEmpresa($this->empresa);
        }
        if (in_array('registeredAt', $providedFields)) {
            $product->setRegisteredAt($this->registeredAt ? new \DateTime($this->registeredAt) : null);
        }
        if (in_array('id', $providedFields) && $this->id !== null) {
            $product->setId($this->id);
        }
    }
}
