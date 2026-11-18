<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Blade;
use Symfony\Component\Finder\Finder;
use Tests\TestCase;

class AdminBladeSyntaxTest extends TestCase
{
    public function test_administrative_templates_generate_valid_php(): void
    {
        $files = Finder::create()->files()->name('*.blade.php')->in([
            resource_path('views/admin'),
            resource_path('views/components/admin'),
            resource_path('views/layouts'),
        ]);

        foreach ($files as $file) {
            try {
                token_get_all(Blade::compileString($file->getContents()), TOKEN_PARSE);
            } catch (\ParseError $error) {
                $this->fail($file->getRelativePathname().': '.$error->getMessage());
            }
            $this->addToAssertionCount(1);
        }
    }
}
