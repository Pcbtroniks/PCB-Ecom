<?php

namespace App\Services\Syscom\DTOs;

class ProductsPageDto
{
    public function __construct(
        public readonly int $cantidad,
        public readonly int $pagina,
        public readonly int $paginas,
        public readonly array $productos,
        public readonly bool $todo,
    ) {}

    /**
     * Construye un ProductsPageDto desde un arreglo de respuesta paginada de la API Syscom.
     *
     * @param  array  $data  Arreglo con claves `cantidad`, `pagina`, `paginas`, `productos` y `todo`.
     * @return self DTO con cada producto convertido a ProductDto.
     */
    public static function fromArray(array $data): self
    {
        return new self(
            cantidad: (int) ($data['cantidad'] ?? 0),
            pagina: (int) ($data['pagina'] ?? 1),
            paginas: (int) ($data['paginas'] ?? 1),
            productos: array_map(
                static fn (array $p) => ProductDto::fromArray($p),
                $data['productos'] ?? []
            ),
            todo: (bool) ($data['todo'] ?? true),
        );
    }

    /**
     * Serializa la página a un arreglo asociativo con la lista de productos como sub-arreglos.
     *
     * @return array Arreglo con metadatos de paginación y lista de productos.
     */
    public function toArray(): array
    {
        return [
            'cantidad' => $this->cantidad,
            'pagina' => $this->pagina,
            'paginas' => $this->paginas,
            'productos' => array_map(static fn (ProductDto $p) => $p->toArray(), $this->productos),
            'todo' => $this->todo,
        ];
    }
}
