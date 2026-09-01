<?php

declare(strict_types=1);

namespace InOtherShops\Tests\Feature\Support;

use FilesystemIterator;
use Illuminate\Auth\GenericUser;
use Illuminate\Support\Facades\Gate;
use InOtherShops\Support\Filament\PackageResource;
use InOtherShops\Tests\Stubs\Filament\DefaultDenyStubResource;
use InOtherShops\Tests\Stubs\Filament\GrantingStockablePolicy;
use InOtherShops\Tests\Stubs\Filament\LenientStubResource;
use InOtherShops\Tests\Stubs\TestStockable;
use InOtherShops\Tests\Support\BootsFilament;
use InOtherShops\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * T-SEC2 — package Filament Resources must default-deny.
 *
 * Filament's stock behaviour ALLOWS an action when no policy exists for the
 * model. For a Resource shipped inside a distributed package, that turns a
 * consumer forgetting to write a policy into full admin exposure. The
 * {@see PackageResource} base flips this to deny. These tests pin the flip
 * itself (against a lenient control), prove a granting policy still opens
 * access, prove the consumer's `Gate::before` super-admin bypass survives, and
 * census that no shipped Resource sidesteps the base.
 */
final class PackageResourceAuthorizationTest extends TestCase
{
    use BootsFilament;

    #[Test]
    public function it_denies_a_package_resource_when_no_policy_grants_access(): void
    {
        $this->actingAs(new GenericUser(['id' => 1]));

        $this->assertFalse(DefaultDenyStubResource::canViewAny());
        $this->assertFalse(DefaultDenyStubResource::canAccess());
    }

    #[Test]
    public function it_flips_the_stock_lenient_allow_to_a_deny_for_the_same_policyless_model(): void
    {
        $this->actingAs(new GenericUser(['id' => 1]));

        // Same user, same policy-less model — the ONLY difference is the base
        // class. Stock Filament allows; the package base denies. If this
        // control ever fails, Filament changed its own default and the deny is
        // now redundant (or the base regressed).
        $this->assertTrue(LenientStubResource::canViewAny());
        $this->assertFalse(DefaultDenyStubResource::canViewAny());
    }

    #[Test]
    public function it_allows_a_package_resource_when_a_policy_grants_access(): void
    {
        Gate::policy(TestStockable::class, GrantingStockablePolicy::class);
        $this->actingAs(new GenericUser(['id' => 1]));

        $this->assertTrue(DefaultDenyStubResource::canViewAny());
    }

    #[Test]
    public function it_honours_a_gate_before_super_admin_bypass_without_a_policy(): void
    {
        // The consumer's owner tier grants access via Gate::before, not a
        // policy (in-other-worlds: `Gate::before(fn ($u) => $u->isSuperAdmin() ? true : null)`).
        // Default-deny must not lock the owner out of a policy-less resource.
        Gate::before(fn (): bool => true);
        $this->actingAs(new GenericUser(['id' => 1]));

        $this->assertTrue(DefaultDenyStubResource::canViewAny());
    }

    #[Test]
    public function every_package_filament_resource_extends_the_default_deny_base(): void
    {
        $src = dirname(__DIR__, 3).'/src';
        $resources = [];

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($src, FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            $path = str_replace('\\', '/', $file->getPathname());

            if (! str_ends_with($path, 'Resource.php')) {
                continue;
            }

            if (! str_contains($path, '/Filament/Resources/')) {
                continue;
            }

            $relative = substr($path, strlen($src) + 1, -4); // strip src/ and .php
            $resources[] = 'InOtherShops\\'.str_replace('/', '\\', $relative);
        }

        // Guard the guard: if the glob finds nothing the census is vacuous.
        $this->assertGreaterThanOrEqual(10, count($resources));

        foreach ($resources as $resource) {
            $this->assertTrue(
                is_subclass_of($resource, PackageResource::class),
                "{$resource} must extend PackageResource so it default-denies; it extends Filament's lenient Resource directly.",
            );
        }
    }
}
