<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PlatformAdminResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'profile' => ProfileResource::make($this->whenLoaded('profile')),
            'roles' => $this->roles->pluck('name')->values(),
            'isPlatformAdmin' => $this->is_platform_admin,
        ];
    }
}
