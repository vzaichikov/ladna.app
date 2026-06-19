<?php

namespace App\Actions;

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Storage;

class UpdateUserProfile
{
    /**
     * @param  array<string, mixed>  $validated
     */
    public function execute(User $user, array $validated, ?UploadedFile $avatar = null): void
    {
        $user->fill(Arr::except($validated, ['avatar', 'password', 'password_confirmation']));

        if (filled($validated['password'] ?? null)) {
            $user->password = $validated['password'];
        }

        if ($avatar) {
            if ($user->avatar_path) {
                Storage::disk('public')->delete($user->avatar_path);
            }

            $user->avatar_path = $avatar->store('user-avatars/'.$user->id, 'public');
        }

        $user->save();
    }
}
