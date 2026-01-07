<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;
use App\Models\Message;

class ConversationResource extends JsonResource
{
    protected $viewer;

    /**
     * Injecter l’utilisateur courant
     */
    public function forUser($user)
    {
        $this->viewer = $user;
        return $this;
    }

    public function toArray($request)
    {
        $user = $this->viewer ?? $request->user();

        // Participant courant
        $participant = $this->participants
            ->firstWhere('user_id', $user->id);

        $lastReadAt = $participant?->last_read_at;

        // 🔢 messages non lus
        $unreadCount = Message::where('conversation_id', $this->id)
            ->where('sender_id', '!=', $user->id)
            ->when($lastReadAt, fn ($q) =>
                $q->where('created_at', '>', $lastReadAt)
            )
            ->count();

        // 🧠 TITRE UX
        $title = match ($this->type) {

            'private' => optional(
                $this->participants
                    ->firstWhere('user_id', '!=', $user->id)
            )->user?->full_name
                ?? optional(
                    $this->participants
                        ->firstWhere('user_id', '!=', $user->id)
                )->user?->name,

            'group' => $this->title,

            'system' => $user->enterprises->first()?->name ?? 'Système',

            default => 'Conversation',
        };

        return [
            'id' => $this->id,
            'type' => $this->type,
            'title' => $title,
            'last_message' => $this->messages->first(),
            'unread_count' => $unreadCount,
        ];
    }
}
