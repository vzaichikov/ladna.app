<?php

return [
    'required' => 'Поле :attribute є обовʼязковим.',
    'accepted' => 'Поле :attribute має бути прийняте.',
    'confirmed' => 'Підтвердження поля :attribute не збігається.',
    'email' => 'Поле :attribute має бути коректною email-адресою.',
    'string' => 'Поле :attribute має бути текстом.',
    'unique' => 'Таке значення поля :attribute вже використовується.',
    'reserved_public_slug' => 'Ця публічна адреса зарезервована для системних сторінок Ladna.',
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
        'studio_rules_accepted' => [
            'accepted' => 'Погодьтеся з правилами студії, щоб продовжити.',
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
        'studio_rules_accepted' => 'правила студії',
        'user_password' => 'пароль користувача',
    ],
];
