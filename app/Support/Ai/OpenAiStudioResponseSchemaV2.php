<?php

namespace App\Support\Ai;

class OpenAiStudioResponseSchemaV2 extends OpenAiStudioResponseSchema
{
    /**
     * @return array<string, mixed>
     */
    public function format(): array
    {
        $format = parent::format();
        $format['name'] = 'ladna_studio_assistant_v2';
        $format['description'] = 'A validated Ladna studio assistant answer, localized rejection, or confirmation-required action proposal.';

        return $format;
    }
}
