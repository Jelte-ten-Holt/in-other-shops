<?php

declare(strict_types=1);

namespace InOtherShops\Tests\Feature\Support;

use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\CreateRecord;
use Filament\Resources\Pages\EditRecord;
use Filament\Resources\Pages\ListRecords;
use InOtherShops\Support\Filament\PackageEditRecord;
use InOtherShops\Support\Filament\PackageListRecords;
use InOtherShops\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use ReflectionMethod;
use Symfony\Component\Finder\Finder;

/**
 * Wiring cover for the WI-7 page base classes. The stub deletion's only
 * observable risk is a page silently losing its Create/Delete header
 * action, so: (1) the bases must produce exactly those actions, and
 * (2) every package List/Edit page must actually extend the bases — a new
 * page extending Filament's classes directly would quietly opt out of the
 * shared defaults.
 */
final class PackagePageBasesTest extends TestCase
{
    #[Test]
    public function list_base_ships_exactly_a_create_header_action(): void
    {
        $page = new class extends PackageListRecords {};

        $actions = (new ReflectionMethod($page, 'getHeaderActions'))->invoke($page);

        $this->assertCount(1, $actions);
        $this->assertInstanceOf(CreateAction::class, $actions[0]);
    }

    #[Test]
    public function edit_base_ships_exactly_a_delete_header_action(): void
    {
        $page = new class extends PackageEditRecord {};

        $actions = (new ReflectionMethod($page, 'getHeaderActions'))->invoke($page);

        $this->assertCount(1, $actions);
        $this->assertInstanceOf(DeleteAction::class, $actions[0]);
    }

    #[Test]
    public function every_package_resource_page_extends_the_matching_base(): void
    {
        $checked = 0;

        foreach (Finder::create()->files()->in(__DIR__.'/../../../src')->path('/Filament\/Resources\/.*\/Pages/')->name('*.php') as $file) {
            $class = $this->classFromFile($file->getRealPath());
            $reflection = new ReflectionClass($class);

            if ($reflection->isSubclassOf(ListRecords::class)) {
                $this->assertTrue(
                    $reflection->isSubclassOf(PackageListRecords::class),
                    "{$class} extends Filament's ListRecords directly — extend PackageListRecords."
                );
                $checked++;
            } elseif ($reflection->isSubclassOf(EditRecord::class)) {
                $this->assertTrue(
                    $reflection->isSubclassOf(PackageEditRecord::class),
                    "{$class} extends Filament's EditRecord directly — extend PackageEditRecord."
                );
                $checked++;
            } else {
                // Create pages deliberately have no package base (nothing to
                // share); anything else here would be a new page type.
                $this->assertTrue(
                    $reflection->isSubclassOf(CreateRecord::class),
                    "{$class} is neither a List/Edit/Create page — extend a known base."
                );
            }
        }

        $this->assertSame(20, $checked, 'List/Edit page census drifted — update this count deliberately.');
    }

    private function classFromFile(string $path): string
    {
        $source = (string) file_get_contents($path);

        preg_match('/^namespace (.+);$/m', $source, $ns);
        preg_match('/^(?:final )?class (\w+)/m', $source, $class);

        return $ns[1].'\\'.$class[1];
    }
}
