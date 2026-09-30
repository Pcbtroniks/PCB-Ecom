<?php

namespace App\Services\Syscom\DTOs;

class ProductDto
{
    public function __construct(
        public readonly int $producto_id,
        public readonly string $titulo,
        public readonly string $modelo,
        public readonly string $marca,
        public readonly ?string $img_portada,
        public readonly int $total_existencia,
        public readonly string $nombre,
        public readonly ?string $etiqueta,
        public readonly array $categorias,
        public readonly ?string $garantia,
        public readonly PriceDto $precios,
    ) {}

    /**
     * Construye un ProductDto desde un arreglo asociativo de la API Syscom.
     *
     * @param  array  $data  Arreglo con claves del producto y `categorias` (lista de CategoryDto) y `precios`.
     * @return self DTO con todos los campos normalizados.
     */
    public static function fromArray(array $data): self
    {
        $categorias = array_map(
            static fn (array $c) => CategoryDto::fromArray($c),
            $data['categorias'] ?? []
        );

        return new self(
            producto_id: (int) $data['producto_id'],
            titulo: (string) ($data['titulo'] ?? ''),
            modelo: (string) ($data['modelo'] ?? ''),
            marca: (string) ($data['marca'] ?? ''),
            img_portada: $data['img_portada'] ?? null,
            total_existencia: (int) ($data['total_existencia'] ?? 0),
            nombre: (string) ($data['nombre'] ?? ''),
            etiqueta: $data['etiqueta'] ?? null,
            categorias: $categorias,
            garantia: $data['garantia'] ?? null,
            precios: PriceDto::fromArray($data['precios'] ?? []),
        );
    }

    /**
     * Serializa el DTO a un arreglo asociativo, incluyendo categorías y precios como sub-arreglos.
     *
     * @return array Arreglo completo del producto apto para JSON.
     */
    public function toArray(): array
    {
        return [
            'producto_id' => $this->producto_id,
            'titulo' => $this->titulo,
            'modelo' => $this->modelo,
            'marca' => $this->marca,
            'img_portada' => $this->img_portada,
            'total_existencia' => $this->total_existencia,
            'nombre' => $this->nombre,
            'etiqueta' => $this->etiqueta,
            'categorias' => array_map(static fn (CategoryDto $c) => $c->toArray(), $this->categorias),
            'garantia' => $this->garantia,
            'precios' => $this->precios->toArray(),
        ];
    }
}
