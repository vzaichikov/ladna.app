<?php

namespace App\Support\Ai;

use App\Enums\StudioAiDisposition;

class OpenAiStudioResponseSchema
{
    /**
     * @return array<string, mixed>
     */
    public function format(): array
    {
        return [
            'type' => 'json_schema',
            'name' => 'ladna_studio_assistant_v1',
            'description' => 'A validated Ladna studio assistant answer or confirmation-required action proposal.',
            'strict' => true,
            'schema' => [
                'type' => 'object',
                'additionalProperties' => false,
                'properties' => [
                    'disposition' => [
                        'type' => 'string',
                        'enum' => array_map(
                            fn (StudioAiDisposition $disposition): string => $disposition->value,
                            StudioAiDisposition::cases(),
                        ),
                    ],
                    'answer' => [
                        'type' => ['string', 'null'],
                    ],
                    'follow_up_actions' => [
                        'type' => 'array',
                        'items' => ['type' => 'string'],
                        'maxItems' => 3,
                    ],
                    'action' => [
                        'anyOf' => [
                            ['type' => 'null'],
                            [
                                'type' => 'object',
                                'additionalProperties' => false,
                                'properties' => [
                                    'customer_id' => ['type' => ['integer', 'null']],
                                    'scheduled_class_id' => ['type' => ['integer', 'null']],
                                    'customer_query' => ['type' => ['string', 'null']],
                                    'trainer_query' => ['type' => ['string', 'null']],
                                    'date' => ['type' => ['string', 'null']],
                                    'booking_id' => ['type' => ['integer', 'null']],
                                    'option_number' => ['type' => ['integer', 'null']],
                                    'option_label' => ['type' => ['string', 'null']],
                                    'use_actor_trainer' => ['type' => 'boolean'],
                                ],
                                'required' => [
                                    'customer_id',
                                    'scheduled_class_id',
                                    'customer_query',
                                    'trainer_query',
                                    'date',
                                    'booking_id',
                                    'option_number',
                                    'option_label',
                                    'use_actor_trainer',
                                ],
                            ],
                        ],
                    ],
                    'calendar_reference' => [
                        'anyOf' => [
                            ['type' => 'null'],
                            [
                                'type' => 'object',
                                'additionalProperties' => false,
                                'properties' => [
                                    'date' => ['type' => 'string'],
                                    'uses_schedule_details' => ['type' => 'boolean'],
                                ],
                                'required' => ['date', 'uses_schedule_details'],
                            ],
                        ],
                    ],
                    'reason' => [
                        'type' => ['string', 'null'],
                    ],
                ],
                'required' => [
                    'disposition',
                    'answer',
                    'follow_up_actions',
                    'action',
                    'calendar_reference',
                    'reason',
                ],
            ],
        ];
    }
}
