<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;

final class AdminPagination
{
    public static function paginate($query, int $maximum = 200): LengthAwarePaginator
    {
        $request = request();
        $options = array_values(array_filter([20, 50, 100, 200], fn ($size) => $size <= $maximum));
        $preference = 'admin_pagination.'.($request->route()?->getName() ?? 'listing');
        $input = $request->query('per_page', $request->session()->get($preference, 20));
        $perPage = is_scalar($input) && in_array((string) $input, array_map('strval', $options), true) ? (int) $input : 20;
        $request->session()->put($preference, $perPage);
        $query = $query instanceof Relation ? $query->getQuery() : $query;
        $countQuery = clone $query;
        $total = (int) ($countQuery instanceof Builder ? $countQuery->toBase() : $countQuery)->getCountForPagination();
        $last = max(1, (int) ceil($total / $perPage));
        $inputPage = $request->query('page', 1);
        $page = is_scalar($inputPage) && preg_match('/\A[0-9]+\z/', (string) $inputPage) ? min($last, max(1, (int) $inputPage)) : 1;
        $items = $query->forPage($page, $perPage)->get();
        $paginator = new LengthAwarePaginator($items, $total, $perPage, $page, ['path' => Paginator::resolveCurrentPath()]);
        $paginator->appends($request->query())->appends(['per_page' => $perPage]);
        $paginator->onEachSide(1);
        $paginator->withPath($request->url());
        // Render metadata is not a query parameter or an authorization bypass.
        $request->attributes->set('admin_pagination_options', $options);

        return $paginator;
    }
}
