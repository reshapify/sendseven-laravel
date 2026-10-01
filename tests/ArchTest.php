<?php

declare(strict_types=1);

arch('source files declare strict types')
    ->expect('Reshapify\SendSeven\Laravel')
    ->toUseStrictTypes();

arch('classes are final')
    ->expect('Reshapify\SendSeven\Laravel')
    ->classes()
    ->toBeFinal();

arch('exceptions belong to the SDK hierarchy')
    ->expect('Reshapify\SendSeven\Laravel\Exceptions')
    ->toImplement(Reshapify\SendSeven\Exceptions\SendSevenException::class);

arch('no debugging calls')
    ->expect(['dd', 'dump', 'ray', 'var_dump'])
    ->not->toBeUsed();
