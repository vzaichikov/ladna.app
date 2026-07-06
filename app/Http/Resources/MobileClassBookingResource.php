<?php

namespace App\Http\Resources;

use App\Support\Mobile\MobileScheduledClassPayload;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MobileClassBookingResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $scheduledClass = $this->whenLoaded('scheduledClass');
        $customer = $this->whenLoaded('customer');
        $reservation = $this->whenLoaded('classPassReservation');

        return [
            'id' => $this->id,
            'status' => $this->status->value,
            'attended_at' => $this->attended_at?->toIso8601String(),
            'notes' => $this->notes,
            'customer' => $customer ? [
                'id' => $customer->id,
                'name' => $customer->name,
                'email' => $customer->email,
                'phone' => $customer->phone,
            ] : null,
            'scheduled_class' => $scheduledClass ? app(MobileScheduledClassPayload::class)->forClass($scheduledClass) : null,
            'class_pass' => $reservation?->customerClassPass ? [
                'id' => $reservation->customerClassPass->id,
                'code' => $reservation->customerClassPass->code,
                'plan_name' => $reservation->customerClassPass->plan_name,
            ] : null,
        ];
    }
}
