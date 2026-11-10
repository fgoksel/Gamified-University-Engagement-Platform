<?php

namespace App\Policies;

use App\Models\User;
use App\Services\CategoryList;

/**
 * Who may change the category list (Task #116). The answer lives in CategoryList, so the page,
 * its actions and the menu share one rule.
 */
class CategoryPolicy
{
    public function __construct(private CategoryList $categories) {}

    public function manage(User $user): bool
    {
        return $this->categories->canManage($user);
    }
}
