<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Selling
    |--------------------------------------------------------------------------
    |
    | Off: the site is a gallery only. Prices and the Buy button are hidden,
    | checkout is refused, and the Sales page leaves the admin menu. Off by
    | default, so a fresh deploy can never take orders by accident.
    |
    */

    'enabled' => (bool) env('SHOP_ENABLED', false),

];
