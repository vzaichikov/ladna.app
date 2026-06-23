<?php

return [
    'required' => 'Поле :attribute є обовʼязковим.',
    'confirmed' => 'Підтвердження поля :attribute не збігається.',
    'email' => 'Поле :attribute має бути коректною email-адресою.',
    'string' => 'Поле :attribute має бути текстом.',
    'unique' => 'Таке значення поля :attribute вже використовується.',
    'digits' => 'Поле :attribute має містити :digits цифр.',
    'max' => [
        'string' => 'Поле :attribute не може містити більше ніж :max символів.',
    ],
    'min' => [
        'string' => 'Поле :attribute має містити щонайменше :min символів.',
    ],
    'custom' => [
        'password' => [
            'confirmed' => 'Підтвердження пароля не збігається.',
            'min' => 'Пароль має містити щонайменше :min символів.',
        ],
        'owner_password' => [
            'min' => 'Пароль має містити щонайменше :min символів.',
        ],
        'user_password' => [
            'min' => 'Пароль має містити щонайменше :min символів.',
        ],
    ],
    'attributes' => [
        'cf-turnstile-response' => 'перевірка безпеки',
        'code' => 'код',
        'email' => 'email',
        'name' => 'імʼя та прізвище',
        'phone' => 'телефон',
        'password' => 'пароль',
        'password_confirmation' => 'підтвердження пароля',
        'owner_password' => 'пароль власника',
        'user_password' => 'пароль користувача',
    ],
];
