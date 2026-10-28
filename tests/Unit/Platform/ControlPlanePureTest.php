<?php

use App\Platform\ControlPlane\ControlPlanePermission as P;
use App\Platform\Registry\StatusTransition;
use App\Platform\Registry\TenantStatusMessage;

it('allows exact, area-wildcard and global grants', function () {
    expect(P::grants(['control_plane.tenants.suspend'], P::TENANTS_SUSPEND))->toBeTrue();
    expect(P::grants(['control_plane.tenants.*'], P::TENANTS_REINSTATE))->toBeTrue();
    expect(P::grants(['control_plane.*'], P::TENANTS_VIEW))->toBeTrue();
});

it('refuses other areas, other actions, junk and wildcards as needs', function () {
    expect(P::grants(['control_plane.tenants.view'], P::TENANTS_SUSPEND))->toBeFalse();
    expect(P::grants(['control_plane.billing.*'], P::TENANTS_VIEW))->toBeFalse();
    expect(P::grants(['*', 'tenants.suspend', 'control_plane.tenantsX.*'], P::TENANTS_VIEW))->toBeFalse();
    expect(P::grants(['control_plane.*'], 'control_plane.*'))->toBeFalse();
    expect(P::grants([], P::TENANTS_VIEW))->toBeFalse();
});

it('validates grantable permission strings', function () {
    expect(P::isValidGrant('control_plane.*'))->toBeTrue();
    expect(P::isValidGrant('control_plane.tenants.*'))->toBeTrue();
    expect(P::isValidGrant('control_plane.tenants.suspend'))->toBeTrue();
    expect(P::isValidGrant('core.settings.manage'))->toBeFalse();
    expect(P::isValidGrant("control_plane.tenants.view\n"))->toBeFalse();
});

it('only allows legal status transitions and treats cancelled as final', function () {
    expect(StatusTransition::allowed('active', 'suspended'))->toBeTrue();
    expect(StatusTransition::allowed('suspended', 'active'))->toBeTrue();
    expect(StatusTransition::allowed('active', 'active'))->toBeFalse();
    expect(StatusTransition::allowed('cancelled', 'active'))->toBeFalse();
    expect(StatusTransition::allowed('cancelled', 'suspended'))->toBeFalse();
    expect(StatusTransition::allowed('bogus', 'active'))->toBeFalse();
});

it('builds status emails with the reason and a sanitised tenant name', function () {
    $evil = TenantStatusMessage::build("Acme\r\nBcc: x@evil.test", 'suspended', 'Unpaid invoices');
    expect($evil['subject'])->not->toContain("\n")->and($evil['subject'])->not->toContain("\r");

    $s = TenantStatusMessage::build('Acme', 'suspended', 'Unpaid invoices');
    expect($s['subject'])->toBe('Your Acme workspace has been suspended')->and($s['text'])->toContain('Unpaid invoices');

    expect(TenantStatusMessage::build('Acme', 'active', 'Paid')['subject'])->toBe('Your Acme workspace is active again');
    expect(TenantStatusMessage::build('Acme', 'cancelled', 'Requested')['subject'])->toBe('Your Acme subscription has been cancelled');
});
