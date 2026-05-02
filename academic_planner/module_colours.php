<?php
function getModuleColour($module_id) {
    $colours = [
        "var(--module-colour-1)",
        "var(--module-colour-2)",
        "var(--module-colour-3)",
        "var(--module-colour-4)",
        "var(--module-colour-5)",
        "var(--module-colour-6)",
        "var(--module-colour-7)",
        "var(--module-colour-8)",
        "var(--module-colour-9)",
        "var(--module-colour-10)"
    ];

    $index = ($module_id - 1) % count($colours);
    return $colours[$index];
}
