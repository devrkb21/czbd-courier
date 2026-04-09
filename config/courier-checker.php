<?php

return [
    'pathao' => [
        'user' => env('PATHAO_USER'),
        'password' => env('PATHAO_PASSWORD'),
        'users' => env('PATHAO_USERS'),
        'passwords' => env('PATHAO_PASSWORDS'),
    ],

    'redx' => [
        'phone' => env('REDX_PHONE'),
        'password' => env('REDX_PASSWORD'),
        'phones' => env('REDX_PHONES'),
        'passwords' => env('REDX_PASSWORDS'),
    ],

    'steadfast' => [
        'user' => env('STEADFAST_USER'),
        'password' => env('STEADFAST_PASSWORD'),
        'users' => env('STEADFAST_USERS'),
        'passwords' => env('STEADFAST_PASSWORDS'),
    ],

    'paperfly' => [
        'user' => env('PAPERFLY_USER'),
        'password' => env('PAPERFLY_PASSWORD'),
        'users' => env('PAPERFLY_USERS'),
        'passwords' => env('PAPERFLY_PASSWORDS'),
    ],
    'carrybee' => [
        'phone' => env('CARRYBEE_PHONE'),
        'password' => env('CARRYBEE_PASSWORD'),
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
