<?php

declare(strict_types=1);

use RvWaarloos\RvMail\RvMail;

it('resolves the singleton', function () {
    expect(app(RvMail::class))->toBeInstanceOf(RvMail::class);
});

it('returns the same instance from the container', function () {
    expect(app(RvMail::class))->toBe(app(RvMail::class));
});

it('merges the package config', function () {
    expect(config('rv-mail.placeholder'))->toBe('default');
});
