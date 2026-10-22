<?php

/*
| CoreModuleDependencyRegistry, as code: each module's HARD prerequisites. This file is the source of
| truth; `php artisan modules:sync-dependencies` validates it and writes it to the module_dependencies
| table that ModuleManager reads. Run it on every deploy.
|
| Rules: only Core may have an empty list; every other module must list Core; no cycles.
| A module depending on another non-Core module is allowed only when this file declares it; it is
| validated and applied atomically.
*/
return [
    'Core' => [],
    'Financial' => ['Core'],
    'Support' => ['Core'],
];
