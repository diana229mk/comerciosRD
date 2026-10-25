<?php

return [
    'enabled' => (bool) env('ECF_ENABLED', false),

    // Flip only in a reviewed release after XAdES and DGII certification tests pass.
    'validation_approved' => false,
];
