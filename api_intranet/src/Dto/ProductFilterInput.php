<?php

namespace App\Dto;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Validator\Constraints as Assert;

class ProductFilterInput
{
    /**
     * @Assert\Type("string")
     */
    public string $search = '';

    /**
     * @Assert\GreaterThanOrEqual(1)
     */
    public int $page = 1;

    /**
     * @Assert\Choice({10, 25, 50, 100})
     */
    public int $limit = 25;

    /**
     * @Assert\Type("string")
     */
    public ?string $empresa = null;

    public bool $active = true;

    /**
     * @Assert\Choice({"id", "nombre", "categoria", "marca", "modelo", "color", "serial", "condicion", "locacion", "cantidad", "empresa", "registeredAt"})
     */
    public string $sort = 'id';

    /**
     * @Assert\Choice({"ASC", "DESC"})
     */
    public string $order = 'DESC';

    public bool $hasLogisticsAccess = true;

    // Extracts and validates URL query params into DTO instance
    public static function fromRequest(Request $request, bool $hasLogisticsAccess = true): self
    {
        $input = new self();
        $input->search = $request->query->get('search', '');
        $input->page = max(1, $request->query->getInt('page', 1));
        
        $limit = $request->query->getInt('limit', 25);
        $input->limit = in_array($limit, [10, 25, 50, 100]) ? $limit : 25;
        
        $input->empresa = $request->query->get('empresa');
        
        $sort = $request->query->get('sort', 'id');
        $allowedSorts = ['id', 'nombre', 'categoria', 'marca', 'modelo', 'color', 'serial', 'condicion', 'locacion', 'cantidad', 'empresa', 'registeredAt'];
        $input->sort = in_array($sort, $allowedSorts) ? $sort : 'id';
        
        $order = strtoupper($request->query->get('order', 'DESC'));
        $input->order = in_array($order, ['ASC', 'DESC']) ? $order : 'DESC';
        
        $input->active = $request->query->getBoolean('active', true);
        $input->hasLogisticsAccess = $hasLogisticsAccess;
        
        return $input;
    }

    // Converts the DTO instance to an array for the repository
    public function toArray(): array
    {
        return [
            'search'             => $this->search,
            'page'               => $this->page,
            'limit'              => $this->limit,
            'empresa'            => $this->empresa,
            'active'             => $this->active,
            'sort'               => $this->sort,
            'order'              => $this->order,
            'hasLogisticsAccess' => $this->hasLogisticsAccess,
        ];
    }
}
