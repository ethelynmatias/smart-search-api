<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Logs Page Access Token
    |--------------------------------------------------------------------------
    |
    | Random string required in the /logs URL to view the logs page.
    | Requests without the correct token receive a 404.
    |
    */

    'access_token' => env('LOGS_ACCESS_TOKEN'),

];
