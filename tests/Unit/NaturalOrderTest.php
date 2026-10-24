<?php

use App\Support\NaturalOrder;

it('orders codes the way a farmer reads them', function () {
    $codes = ['B10', 'b2', 'B1', 'A100', 'A20', 'A20X', '7', '12', '007a'];
    usort($codes, [NaturalOrder::class, 'compare']);

    expect($codes)->toBe(['7', '007a', '12', 'A20', 'A20X', 'A100', 'B1', 'b2', 'B10']);
});
