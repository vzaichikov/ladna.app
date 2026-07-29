<?php

namespace App\Support\Ai;

class OpenAiStudioResponseSchemaV3 extends OpenAiStudioResponseSchemaV2
{
    /**
     * @return array<string, mixed>
     */
    public function format(): array
    {
        $format = parent::format();
        $format['name'] = 'ladna_studio_assistant_v3';
        $format['description'] = 'A validated Ladna studio assistant result with compact visual memory for multimodal turns.';
        $format['schema']['properties']['visual_context'] = [
            'type' => ['string', 'null'],
            'maxLength' => 2000,
        ];
        $format['schema']['required'][] = 'visual_context';

        return $format;
    }
}
