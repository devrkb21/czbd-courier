<?php

return [
    'pathao' => [
        'users' => env('PATHAO_USERS'),
        'passwords' => env('PATHAO_PASSWORDS'),
    ],

    'redx' => [
        'phones' => env('REDX_PHONES'),
        'passwords' => env('REDX_PASSWORDS'),
    ],

    'steadfast' => [
        'users' => env('STEADFAST_USERS'),
        'passwords' => env('STEADFAST_PASSWORDS'),
    ],

    'paperfly' => [
        'users' => env('PAPERFLY_USERS'),
        'passwords' => env('PAPERFLY_PASSWORDS'),
    ],
    'carrybee' => [
        'phones' => env('CARRYBEE_PHONES'),
        'passwords' => env('CARRYBEE_PASSWORDS'),
    ],

    'proxy' => [
        'all' => env('COURIER_PROXY_ALL', 'no'),
        'pathao' => env('COURIER_PROXY_PATHAO', 'no'),
        'steadfast' => env('COURIER_PROXY_STEADFAST', 'no'),
        'redx' => env('COURIER_PROXY_REDX', 'no'),
        'paperfly' => env('COURIER_PROXY_PAPERFLY', 'no'),
        'carrybee' => env('COURIER_PROXY_CARRYBEE', 'no'),
        'address' => env('COURIER_PROXY_ADDRESS'),
    ],
];
