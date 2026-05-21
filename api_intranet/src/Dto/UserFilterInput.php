<?php

namespace App\Dto;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Validator\Constraints as Assert;

class UserFilterInput
{
    /**
     * @Assert\Type("string")
     */
    public string $search = '';

    /**
     * @Assert\Type("string")
     */
    public ?string $role = null;

    /**
     * @Assert\GreaterThanOrEqual(1)
     */
    public int $page = 1;

    /**
     * @Assert\Choice({10, 25, 50, 100})
     */
    public int $limit = 25;

    /**
     * @Assert\Choice({"id", "email", "name", "surname"})
     */
    public string $sort = 'id';

    /**
     * @Assert\Choice({"ASC", "DESC"})
     */
    public string $order = 'DESC';

    public bool $active = true;

    public bool $hasAdminAccess = true;

    // Extracts and validates URL into DTO instance
    public static function fromRequest(Request $request, bool $hasAdminAccess = true): self
    {
 
        $input = new self();
        $input->search = $request->query->get('search', '');
        $input->role = $request->query->get('role');

        $input->page = max(1, $request->query->getInt('page', 1));
   
        $limit = $request->query->getInt('limit', 25);
        $input->limit = in_array($limit, [10, 25, 50, 100]) ? $limit : 25;
     
        $sort = $request->query->get('sort', 'id');
        $input->sort = in_array($sort, ['id', 'email', 'name', 'surname']) ? $sort : 'id';
      
        $order = strtoupper($request->query->get('order', 'DESC'));
        $input->order = in_array($order, ['ASC', 'DESC']) ? $order : 'DESC';

        $input->active = $request->query->getBoolean('active', true);
        $input->hasAdminAccess = $hasAdminAccess;
        
        return $input; //returns object instance
    }

    // Converts the DTO instance to an array
    public function toArray(): array
    {
        return [
            'search'         => $this->search,
            'role'           => $this->role,
            'page'           => $this->page,
            'limit'          => $this->limit,
            'sort'           => $this->sort,
            'order'          => $this->order,
            'active'         => $this->active,
            'hasAdminAccess' => $this->hasAdminAccess,
        ];
    }
}