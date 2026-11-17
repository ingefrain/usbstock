<?php

namespace App\Services\Documents;

use App\Commerce\CommerceAccess;
use App\Models\ApplicationBranding;
use App\Models\Catalog;
use App\Models\CatalogItem;
use App\Models\Document;
use App\Models\Location;
use App\Models\Organization;
use App\Models\Product;
use App\Models\Quote;
use App\Models\User;
use App\Policies\CatalogPolicy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;

/**
 * Default owner-level authorization. Derived projects may replace this service
 * to scope additional polymorphic owners through their own policies.
 */
class DocumentOwnerAccess
{
    public function allows(User $actor, Document $document): bool
    {
        $owner = $document->documentable;
        if ($owner instanceof Quote) {
            return CommerceAccess::allows($actor, 'quotes.view');
        }
        if ($owner instanceof Product) {
            return CommerceAccess::allows($actor, 'products.view');
        }
        if (! $owner instanceof Model) {
            return false;
        }

        if ($owner instanceof User) {
            return $actor->is($owner);
        }
        if ($owner instanceof ApplicationBranding) {
            return $actor->can('branding.manage') && (string) $owner->getKey() === '1';
        }

        $gate = Gate::forUser($actor);

        return $gate->getPolicyFor($owner) !== null && $gate->allows('view', $owner);
    }

    public function scopeVisibleTo(Builder $query, User $actor): Builder
    {
        return $query->where(function (Builder $builder) use ($actor): void {
            $builder->where(function (Builder $users) use ($actor): void {
                $users->where('documentable_type', (new User)->getMorphClass())
                    ->where('documentable_id', (string) $actor->getKey());
            });
            if ($actor->can('branding.manage')) {
                $builder->orWhere(function (Builder $branding): void {
                    $branding->where('documentable_type', (new ApplicationBranding)->getMorphClass())
                        ->where('documentable_id', '1');
                });
            }
            if ($actor->can('organizations.view')) {
                $builder->orWhere('documentable_type', (new Organization)->getMorphClass());
            }
            if ($actor->can('locations.view')) {
                $builder->orWhere('documentable_type', (new Location)->getMorphClass());
            }
            if (CommerceAccess::allows($actor, 'products.view')) {
                $builder->orWhere('documentable_type', (new Product)->getMorphClass());
            }
            if (CommerceAccess::allows($actor, 'quotes.view')) {
                $builder->orWhere('documentable_type', (new Quote)->getMorphClass());
            }
            if (CatalogPolicy::permits($actor, 'catalogs.view')) {
                $showOrganizations = CatalogPolicy::permits($actor, 'organizations.view');
                $builder->orWhereHasMorph('documentable', Catalog::class, function (Builder $catalog) use ($showOrganizations): void {
                    if (! $showOrganizations) {
                        $catalog->whereNull('organization_id');
                    }
                });
                if (CatalogPolicy::permits($actor, 'catalog_items.view')) {
                    $builder->orWhereHasMorph('documentable', CatalogItem::class, fn (Builder $items) => $items
                        ->whereHas('catalog', fn (Builder $catalog) => $catalog->when(! $showOrganizations, fn ($query) => $query->whereNull('organization_id'))));
                }
            }
        });
    }

    public function canManage(User $actor, Document $document): bool
    {
        $owner = $document->documentable;
        if ($owner instanceof Quote) {
            // Quote image mutations must take the quote lock and check its revision/status.
            return false;
        }
        if ($owner instanceof Product) {
            return $document->category !== Product::IMAGE_CATEGORY && CommerceAccess::allows($actor, 'products.view') && CommerceAccess::allows($actor, 'products.manage');
        }
        if ($owner instanceof Catalog || $owner instanceof CatalogItem) {
            return $document->category !== CatalogItem::IMAGE_CATEGORY && Gate::forUser($actor)->allows('update', $owner);
        }

        return $this->allows($actor, $document);
    }
}
