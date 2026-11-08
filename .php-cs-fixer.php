<?php

declare(strict_types=1);

use PSBits\Qa\Quality\CsFixerFactory;

return CsFixerFactory::create([
    'Resources',
    'src',
    'tests',
]);
