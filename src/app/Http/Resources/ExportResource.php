<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;

class ExportResource extends JsonResource
{
    public function toArray($request)
    {
        $data = [
            'id' => $this->id,
            'version_id' => $this->version_id,
            'status' => $this->status,
            'format' => $this->format,
            'progress' => $this->progress,
            'attempt' => $this->attempts,
            'error_message' => $this->error_message,
            'created_at' => $this->created_at,
            'completed_at' => $this->completed_at,
        ];

        if ($this->status === 'completed') {
            $data['download_url'] = url("/api/v1/exports/{$this->id}/download");
        }

        if ($this->status === 'retrying') {
            $data['next_retry_at'] = $this->next_retry_at;
            $data['retry_after_seconds'] = $this->next_retry_at
                ? max(0, Carbon::now()->diffInSeconds($this->next_retry_at, false))
                : null;
        }

        return $data;
    }
}
