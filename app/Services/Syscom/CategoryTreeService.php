<?php

namespace App\Services\Syscom;

use Illuminate\Support\Facades\Cache;

class CategoryTreeService
{
    public function __construct(protected CategoriesService $categories) {}

    /**
     * Devuelve el árbol jerárquico completo de categorías (con `children`), cacheado por TTL.
     *
     * @return array Lista de nodos raíz; cada nodo incluye `children` y `children_ids`.
     */
    public function getTree(): array
    {
        $ttl = (int) config('syscom.cache.categories_ttl', 86400);
        $cacheKey = 'syscom:categories:tree:v2';

        return Cache::remember($cacheKey, $ttl, function (): array {
            $flat = $this->categories->getCategories();

            return $this->buildTree($flat);
        });
    }

    /**
     * Devuelve únicamente las categorías raíz del árbol (sin padre).
     *
     * @return array Nodos cuyo `parent_id` es null.
     */
    public function getRoots(): array
    {
        return array_values(array_filter(
            $this->getTree(),
            fn ($node) => ($node['parent_id'] ?? null) === null
        ));
    }

    /**
     * Devuelve la ruta jerárquica desde la raíz hasta la categoría indicada.
     *
     * @param  int  $categoryId  ID de la categoría destino.
     * @return array Lista ordenada de ancestros (incluye la categoría objetivo); vacía si no existe.
     */
    public function getPath(int $categoryId): array
    {
        $flat = $this->categories->getCategories();
        $byId = [];
        $position = -1;
        foreach ($flat as $i => $c) {
            $id = (int) $c['id'];
            $byId[$id] = $c;
            if ($id === $categoryId) {
                $position = $i;
            }
        }

        if ($position === -1) {
            return [];
        }

        $current = $byId[$categoryId];
        $path = [$current];

        while (true) {
            $currentNivel = (int) ($current['nivel'] ?? 1);
            if ($currentNivel <= 1) {
                break;
            }
            $parentNivel = $currentNivel - 1;
            $parent = null;
            for ($i = $position - 1; $i >= 0; $i--) {
                if ((int) ($flat[$i]['nivel'] ?? 1) === $parentNivel) {
                    $parent = $flat[$i];
                    break;
                }
            }
            if ($parent === null) {
                break;
            }
            array_unshift($path, $parent);
            $current = $parent;
        }

        return $path;
    }

    /**
     * Devuelve los hijos directos de una categoría en el árbol.
     *
     * @param  int  $categoryId  ID de la categoría padre.
     * @return array Subcategorías directas; lista vacía si no tiene hijos.
     */
    public function getChildren(int $categoryId): array
    {
        $all = $this->getTree();

        return array_values(array_filter($all, fn ($c) => (int) ($c['parent_id'] ?? 0) === $categoryId));
    }

    /**
     * Busca un nodo de categoría en el árbol por su ID.
     *
     * @param  int  $id  ID de la categoría.
     * @return array|null Nodo encontrado o null.
     */
    public function getCategory(int $id): ?array
    {
        foreach ($this->getTree() as $node) {
            if ((int) $node['id'] === $id) {
                return $node;
            }
        }

        return null;
    }

    /**
     * Construye la estructura jerárquica (con `children`) a partir de la lista plana de categorías.
     *
     * @param  array  $flat  Lista plana de categorías con `id`, `nombre` y `nivel`.
     * @return array Lista de nodos raíz del árbol con sus descendientes anidados.
     */
    protected function buildTree(array $flat): array
    {
        $nodes = [];
        $lastByLevel = [];

        foreach ($flat as $c) {
            $id = (int) $c['id'];
            $nivel = (int) ($c['nivel'] ?? 1);
            $parentId = $this->inferParent($id, $nivel, $flat, $lastByLevel);

            $nodes[$id] = [
                'id' => $id,
                'nombre' => (string) ($c['nombre'] ?? ''),
                'nivel' => $nivel,
                'parent_id' => $parentId,
                'children' => [],
                'children_ids' => [],
                'product_count' => 0,
            ];

            $lastByLevel[$nivel] = $id;
        }

        $tree = [];
        foreach ($nodes as $id => &$node) {
            $parentId = $node['parent_id'];
            if ($parentId !== null && isset($nodes[$parentId])) {
                $nodes[$parentId]['children'][] = &$node;
                $nodes[$parentId]['children_ids'][] = $id;
            } else {
                $tree[] = &$node;
            }
        }
        unset($node);

        return $tree;
    }

    /**
     * Infiere el ID de la categoría padre usando el último nodo visto en el nivel superior o coincidencia por prefijo de nombre.
     *
     * @param  int  $id  ID de la categoría actual.
     * @param  int  $nivel  Nivel de la categoría actual.
     * @param  array  $flat  Lista plana de categorías (referencia).
     * @param  array  $lastByLevel  Mapa de último ID visto por nivel (se actualiza por referencia).
     * @return int|null ID del padre inferido o null si es raíz o no se encuentra.
     */
    protected function inferParent(int $id, int $nivel, array $flat, array $lastByLevel): ?int
    {
        if ($nivel <= 1) {
            return null;
        }

        $parentNivel = $nivel - 1;
        if (isset($lastByLevel[$parentNivel])) {
            return (int) $lastByLevel[$parentNivel];
        }

        $current = null;
        foreach ($flat as $c) {
            if ((int) $c['id'] === $id) {
                $current = $c;
                break;
            }
        }
        if ($current === null) {
            return null;
        }

        $name = mb_strtolower(trim((string) ($current['nombre'] ?? '')));
        $bestMatch = null;
        $bestLen = -1;
        foreach ($flat as $candidate) {
            if ((int) $candidate['id'] === $id) {
                continue;
            }
            if ((int) ($candidate['nivel'] ?? 1) !== $parentNivel) {
                continue;
            }
            $candidateName = mb_strtolower(trim((string) ($candidate['nombre'] ?? '')));
            if ($candidateName !== '' && str_starts_with($name, $candidateName) && mb_strlen($candidateName) > $bestLen) {
                $bestMatch = (int) $candidate['id'];
                $bestLen = mb_strlen($candidateName);
            }
        }

        return $bestMatch;
    }

    /**
     * Variante de inferencia de padre basada solo en coincidencia de prefijo de nombre; actualmente sin uso externo.
     *
     * @param  int  $childId  ID de la categoría hija.
     * @param  array  $flat  Lista plana de categorías.
     * @param  array  $byId  Mapa de categorías indexado por ID.
     * @return int|null ID del padre inferido o null.
     */
    protected function findParentId(int $childId, array $flat, array $byId): ?int
    {
        $child = $byId[$childId] ?? null;
        if ($child === null) {
            return null;
        }
        $name = mb_strtolower(trim((string) ($child['nombre'] ?? '')));
        $bestMatch = null;
        $bestLen = -1;
        foreach ($flat as $candidate) {
            if ((int) $candidate['id'] === $childId) {
                continue;
            }
            $nivel = (int) ($candidate['nivel'] ?? 1);
            if ($nivel !== ((int) ($child['nivel'] ?? 1)) - 1) {
                continue;
            }
            $candidateName = mb_strtolower(trim((string) ($candidate['nombre'] ?? '')));
            if ($candidateName !== '' && str_starts_with($name, $candidateName) && mb_strlen($candidateName) > $bestLen) {
                $bestMatch = (int) $candidate['id'];
                $bestLen = mb_strlen($candidateName);
            }
        }

        return $bestMatch;
    }
}
