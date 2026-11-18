<?php

namespace App\Http\Controllers\Admin;

use App\Commerce\CommerceAccess;
use App\Commerce\QuoteDesigns;
use Illuminate\Http\Request;

final class QuoteDesignController
{
    private function access(): void
    {
        abort_unless(app()->environment(['local', 'testing']), 404);
        CommerceAccess::authorize('quotes.view');
        CommerceAccess::authorize('commerce.configure');
    }

    public function index()
    {
        $this->access();

        return response()->view('admin.commerce.pdf-designs', ['concepts' => QuoteDesigns::CONCEPTS, 'laboratory' => QuoteDesigns::LAB_CONCEPTS])->header('Cache-Control', 'no-store, private');
    }

    public function preview(Request $request, string $concept, QuoteDesigns $designs)
    {
        $this->access();
        $data = $request->validate(['items' => 'sometimes|integer|in:1,3,10,35', 'format' => 'sometimes|in:html,pdf']);
        $snapshot = $designs->fixture((int) ($data['items'] ?? 3));
        $pdf = ($data['format'] ?? 'html') === 'pdf';

        return response($pdf ? $designs->render($concept, $snapshot) : $designs->html($concept, $snapshot))
            ->header('Content-Type', $pdf ? 'application/pdf' : 'text/html; charset=UTF-8')
            ->header('Cache-Control', 'no-store, private')->header('X-Robots-Tag', 'noindex, nofollow')
            ->header('X-Content-Type-Options', 'nosniff');
    }
}
