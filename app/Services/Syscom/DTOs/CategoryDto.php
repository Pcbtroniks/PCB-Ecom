<?php

namespace App\Services\Syscom\DTOs;

class CategoryDto
{
    public function __construct(
        public readonly int $id,
        public readonly int $nivel,
        public readonly string $nombre,
    ) {}

    /**
     * Construye un CategoryDto desde un arreglo asociativo de la API Syscom.
     *
     * @param  array  $data  Arreglo con claves `id`, `nivel` (opcional) y `nombre`.
     * @return self DTO con los valores normalizados.
     */
    public static function fromArray(array $data): self
    {
        return new self(
            id: (int) $data['id'],
            nivel: (int) ($data['nivel'] ?? 1),
            nombre: (string) $data['nombre'],
        );
    }

    /**
     * Serializa el DTO a un arreglo asociativo.
     *
     * @return array Arreglo con `id`, `nivel` y `nombre`.
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'nivel' => $this->nivel,
            'nombre' => $this->nombre,
        ];
    }
}
