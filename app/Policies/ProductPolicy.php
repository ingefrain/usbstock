<?php

namespace App\Policies;

use App\Commerce\CommerceAccess;
use App\Models\Product;
use App\Models\User;

class ProductPolicy
{
    public function view(User $user, Product $product): bool
    {
        return CommerceAccess::allows($user, 'products.view');
    }

    public function update(User $user, Product $product): bool
    {
        return $this->view($user, $product) && CommerceAccess::allows($user, 'products.manage');
    }
}
